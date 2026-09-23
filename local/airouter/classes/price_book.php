<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_airouter;

use local_airouter\record\attempt_state;
use local_airouter\record\generation;
use local_airouter\record\ledger;
use local_airouter\record\summariser;
use local_airouter\record\usage_recorder;

/**
 * Finds the rate that applied to a request, and the currency each provider bills in.
 *
 * Lookups prefer a rate entered for the exact model, and fall back to one entered for
 * the provider as a whole. Where neither exists the request has no cost recorded at
 * all, rather than a cost of zero.
 *
 * Currency belongs to the provider, not to the site. Each rate carries the currency
 * its provider bills in, a cost is recorded in the currency of the rate that produced
 * it, and nothing converts between currencies: picking exchange rates would mean
 * choosing a source, a moment and a rounding rule, and would lay a second layer of
 * error over a figure that is already an estimate.
 *
 * A provider's currency does not change while a site is in use. It changes once, if
 * at all, when a provisional entry is corrected, and then every cost recorded for
 * that provider is worked out again at the rates now in force, so that nothing of
 * the provisional figure survives. That is the one time a recorded cost is revised.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class price_book {
    /** @var string What the old ledger falls back to when the rates do not name one currency. */
    public const DEFAULT_CURRENCY = 'USD';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to read rates from.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
    ) {
    }

    /**
     * The rate that applied to a provider and model at a given moment.
     *
     * @param string $provider The provider component.
     * @param string|null $model The model the target reported, if it reported one.
     * @param int $when The time of the request.
     * @return price|null The rate, or null when none covers this.
     */
    public function find(string $provider, ?string $model, int $when): ?price {
        if ($provider === '') {
            return null;
        }
        foreach ([(string) $model, ''] as $candidate) {
            $record = $this->db->get_records_select(
                price::TABLE,
                'provider = :provider AND model = :model AND timefrom <= :when',
                ['provider' => $provider, 'model' => $candidate, 'when' => $when],
                'timefrom DESC',
                '*',
                0,
                1,
            );
            if ($record) {
                return new price(0, reset($record));
            }
            if ($candidate === '') {
                break;
            }
        }

        return null;
    }

    /**
     * Every rate on the site, newest first within each provider and model.
     *
     * @return price[] The rates, keyed by id.
     */
    public function get_all(): array {
        $prices = [];
        foreach ($this->db->get_records(price::TABLE, null, 'provider ASC, model ASC, timefrom DESC') as $record) {
            $prices[(int) $record->id] = new price(0, $record);
        }

        return $prices;
    }

    /**
     * The currency a provider bills in, as its rates say.
     *
     * Every rate of a provider is entered in one currency, so this is simply the
     * currency on its rates. Should the rows ever disagree, the rate entered most
     * recently wins, so that the answer is one currency and the same one each time.
     *
     * @param string $provider The provider component.
     * @return string|null The currency, or null when the provider has no rates.
     */
    public function currency_of(string $provider): ?string {
        if ($provider === '') {
            return null;
        }
        $records = $this->db->get_records(
            price::TABLE,
            ['provider' => $provider],
            'timemodified DESC, id DESC',
            'id, currency',
            0,
            1,
        );
        if (!$records) {
            return null;
        }

        return (string) reset($records)->currency;
    }

    /**
     * The currency of every provider that has rates.
     *
     * @return string[] Currency codes keyed by provider component.
     */
    public function get_provider_currencies(): array {
        $currencies = [];
        foreach ($this->db->get_records(price::TABLE, null, 'timemodified ASC, id ASC', 'id, provider, currency') as $row) {
            // Later rows overwrite earlier ones, so a provider whose rows disagree is
            // reported the way currency_of() reports it.
            $currencies[(string) $row->provider] = (string) $row->currency;
        }
        ksort($currencies);

        return $currencies;
    }

    /**
     * Every currency any rate is in.
     *
     * @return string[] The currency codes, sorted.
     */
    public function get_currencies(): array {
        $currencies = [];
        foreach ($this->db->get_fieldset_select(price::TABLE, 'DISTINCT currency', '1 = 1') as $currency) {
            $currencies[] = (string) $currency;
        }
        sort($currencies);

        return $currencies;
    }

    /**
     * Save a rate, and correct the provider when the rate changes its currency.
     *
     * The rate, the relabelling of the provider's other rates and the recalculation
     * of its recorded costs are one operation: either all of it happens or none of
     * it does. Saving the rate first and correcting afterwards left a rate in the new
     * currency beside records in the old one whenever the correction could not run,
     * and a second attempt then saw nothing to correct. The record is also corrected
     * when it disagrees with the rates already, however that came about, so that
     * saving the rate again is the way to put it right.
     *
     * Whether a correction is due is decided under the same locks, in the same
     * transaction, as the save and any correction. A rate saved as it is, because
     * nothing was due, would otherwise land after a correction that finished in
     * between, and put the currency of this one rate back: the provider's other rates
     * and its recorded costs in one currency, this rate in the other.
     *
     * @param price $rate The rate, with what it is to become already set on it.
     * @param bool $isnew Whether it is being created rather than updated.
     * @return array recosted, and when true the counts recost_provider() gives.
     * @throws \moodle_exception When the record is busy.
     */
    public function save_rate(price $rate, bool $isnew): array {
        $provider = (string) $rate->get('provider');
        $currency = (string) $rate->get('currency');

        return $this->under_record_locks(function () use ($rate, $isnew, $provider, $currency): array {
            $before = $this->currency_of($provider);
            $isnew ? $rate->create() : $rate->update();
            if (($before === null || $before === $currency) && !$this->has_costs_in_another_currency($provider, $currency)) {
                return ['recosted' => false];
            }

            return ['recosted' => true] + $this->correct($provider, $currency);
        });
    }

    /**
     * Whether anything of a provider's is in a currency other than the one given.
     *
     * Its other rates, its recorded calls, or its summarised days. Any of them means
     * the provider's money is not one figure, and a correction is due.
     *
     * @param string $provider The provider component.
     * @param string $currency The currency everything should be in.
     * @return bool True when something is in another currency.
     */
    public function has_costs_in_another_currency(string $provider, string $currency): bool {
        $params = ['provider' => $provider, 'currency' => $currency];
        if ($this->db->record_exists_select(price::TABLE, 'provider = :provider AND currency <> :currency', $params)) {
            return true;
        }
        if (
            $this->db->record_exists_select(
                usage_recorder::ATTEMPT_TABLE,
                'targetprovider = :provider AND currency IS NOT NULL AND currency <> :currency',
                $params,
            )
        ) {
            return true;
        }

        return $this->db->record_exists_select(
            summariser::TABLE,
            'targetprovider = :provider AND currency <> :dash AND currency <> :currency',
            $params + ['dash' => '-'],
        );
    }

    /**
     * Put every rate of a provider in one currency, and work its recorded costs out again.
     *
     * A provider bills in one currency, so the currency is changed for the provider
     * rather than for a rate at a time. The change is the correction of a provisional
     * entry, and the costs recorded under the provisional entry are as provisional as
     * it was, so every one of them is worked out again from what the call used, at the
     * rate now in force for its time, and in the new currency: attempts by call,
     * summary rows by day. A summary row is priced as one call of its
     * size at the rate in force at the start of its day, images included, which is as
     * near as a day whose detail has been purged can be brought.
     *
     * Taken under the record lock and the summariser's lock, in one transaction, so
     * that a request being recorded or a run of the summariser cannot interleave.
     *
     * @param string $provider The provider component.
     * @param string $currency The currency, already normalised.
     * @param \Closure|null $first Something to do first, inside the same transaction and
     *                             under the same locks, such as saving the rate that
     *                             brought the change; it is undone with the rest.
     * @return int[] How many rates, attempts and summary rows were touched.
     * @throws \moodle_exception When the record is busy.
     */
    public function recost_provider(string $provider, string $currency, ?\Closure $first = null): array {
        return $this->under_record_locks(function () use ($provider, $currency, $first): array {
            if ($first !== null) {
                $first();
            }

            return $this->correct($provider, $currency);
        });
    }

    /**
     * Put a provider's rates in one currency and work its record out again, inside a transaction under the record locks.
     *
     * @param string $provider The provider component.
     * @param string $currency The currency, already normalised.
     * @return int[] How many rates, attempts and summary rows were touched.
     */
    protected function correct(string $provider, string $currency): array {
        $this->db->set_field(price::TABLE, 'currency', $currency, ['provider' => $provider]);
        $counts = [
            'rates' => $this->db->count_records(price::TABLE, ['provider' => $provider]),
            'attempts' => $this->recost_attempts($provider),
            'summaries' => $this->recost_summaries($provider),
        ];
        // The record has changed underneath any reader in the middle of reading it.
        // Moved inside the transaction, so that a reader sees the new number exactly
        // when it sees the new figures. The figures held for the routing path carry
        // the number they were read at, and are not found again once it has moved.
        generation::bump();

        return $counts;
    }

    /**
     * Change the rates in one transaction, under the record lock and the summariser's lock.
     *
     * So that a request being recorded, a run of the summariser, a correction and a
     * rate being saved cannot interleave with each other.
     *
     * @param \Closure $change The change, run inside the transaction; what it returns is returned.
     * @return mixed What the change returned.
     * @throws \moodle_exception When the record is busy.
     */
    protected function under_record_locks(\Closure $change): mixed {
        $factory = \core\lock\lock_config::get_lock_factory('local_airouter');
        $recordlock = $factory->get_lock(usage_recorder::LOCK, usage_recorder::LOCK_TIMEOUT);
        if (!$recordlock) {
            throw new \moodle_exception('rates:error:busy', 'local_airouter');
        }
        $summarylock = $factory->get_lock(summariser::LOCK, usage_recorder::LOCK_TIMEOUT);
        if (!$summarylock) {
            $recordlock->release();
            throw new \moodle_exception('rates:error:busy', 'local_airouter');
        }
        try {
            $transaction = $this->db->start_delegated_transaction();
            try {
                $result = $change();
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            // An ending that could not take the lock while this ran was written
            // without a price. Still holding the lock, price it now, at the rates
            // as they are now; anything that arrives later is priced by whoever
            // holds the lock next.
            (new usage_recorder($this->db, $this))->price_deferred(true);
        } finally {
            $summarylock->release();
            $recordlock->release();
        }
        // Figures held for the routing path may have been worked out in the old
        // currency. They would not be found again under the new generation; the
        // purge frees the space they take.
        \core_cache\helper::purge_by_definition('local_airouter', ledger::CACHE_AREA);

        return $result;
    }

    /**
     * Work every recorded attempt to a provider out again.
     *
     * @param string $provider The provider component.
     * @return int How many attempts were touched.
     */
    protected function recost_attempts(string $provider): int {
        $count = 0;
        $attempts = $this->db->get_recordset_select(
            usage_recorder::ATTEMPT_TABLE,
            'targetprovider = :provider AND state <> :started',
            ['provider' => $provider, 'started' => attempt_state::STARTED],
            'id ASC',
            'id, model, prompttokens, completiontokens, images, timestarted, timeended',
        );
        foreach ($attempts as $attempt) {
            $price = $this->find($provider, $attempt->model, (int) ($attempt->timeended ?? $attempt->timestarted));
            $this->db->update_record(usage_recorder::ATTEMPT_TABLE, (object) [
                'id' => $attempt->id,
                'cost' => $price?->cost(
                    self::tokens($attempt->prompttokens),
                    self::tokens($attempt->completiontokens),
                    (int) $attempt->images,
                ),
                'currency' => $price?->get('currency'),
                // Priced now, whatever it was waiting for.
                'unpriced' => 0,
            ]);
            $count++;
        }
        $attempts->close();

        return $count;
    }

    /**
     * Work every summary row of a provider out again, by day.
     *
     * A row's new currency can make its key another row's, when the day was already
     * partly in the new currency; the two are then added into one.
     *
     * @param string $provider The provider component.
     * @return int How many rows were touched.
     */
    protected function recost_summaries(string $provider): int {
        $count = 0;
        $ids = $this->db->get_fieldset_select(summariser::TABLE, 'id', 'targetprovider = :provider', ['provider' => $provider]);
        sort($ids);
        foreach ($ids as $id) {
            // Read now rather than at the start: a row earlier in this loop may have
            // been folded into this one, or this one into another and deleted.
            $row = $this->db->get_record(summariser::TABLE, ['id' => $id]);
            if ($row === false) {
                continue;
            }
            $price = $this->find($provider, $row->model === '-' ? null : $row->model, (int) $row->daystart);
            // A rate that exists is not a rate that can price this: a rate for images
            // alone says nothing about a day of text. What cannot be priced is not
            // free, so it stays unpriced, exactly as it would in the detail.
            $cost = $price === null || ((int) $row->knowncalls === 0 && (int) $row->images === 0)
                ? null
                : $price->cost((int) $row->prompttokens, (int) $row->completiontokens, (int) $row->images);
            $priced = $cost !== null;
            $row->cost = $priced ? (float) $cost : 0.0;
            // Which calls the figure covers: the ones with known usage when tokens are
            // priced, every call when only images are, since a day priced by its
            // images alone was a day of image calls.
            $tokenspriced = $price !== null && ($price->get('promptrate') !== null || $price->get('completionrate') !== null);
            $row->costedcalls = $priced ? ($tokenspriced ? (int) $row->knowncalls : (int) $row->calls) : 0;
            $row->currency = $priced ? $price->get('currency') : '-';

            $key = [];
            foreach (summariser::KEY as $field) {
                $key[$field] = $row->$field;
            }
            $twin = $this->db->get_record(summariser::TABLE, $key);
            if ($twin && (int) $twin->id !== (int) $row->id) {
                foreach (summariser::COUNTERS as $column) {
                    $twin->$column += $row->$column;
                }
                $twin->targetname ??= $row->targetname;
                $this->db->update_record(summariser::TABLE, $twin);
                $this->db->delete_records(summariser::TABLE, ['id' => $row->id]);
            } else {
                $this->db->update_record(summariser::TABLE, $row);
            }
            $count++;
        }

        return $count;
    }

    /**
     * A stored token count as the rate wants it: null where none was reported.
     *
     * @param mixed $stored The stored count.
     * @return int|null The count, or null.
     */
    protected static function tokens(mixed $stored): ?int {
        return $stored === null ? null : (int) $stored;
    }
}
