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
     * The one currency the rates are in, for what still thinks a site has one.
     *
     * Transitional. The old spending ledger, the budget conditions and the key limits
     * were written when the site had one currency, and read their limits as figures in
     * it. Until they read money by currency they are given the currency every rate is
     * entered in, and the default when there are no rates or more than one currency:
     * on such a site their comparisons are not sound, and they were not before either.
     * This goes when they do.
     *
     * @return string The currency code.
     */
    public static function legacy_currency(): string {
        global $DB;

        $currencies = (new self($DB))->get_currencies();

        return count($currencies) === 1 ? (string) reset($currencies) : self::DEFAULT_CURRENCY;
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
     * Put every rate of a provider in one currency, and work its recorded costs out again.
     *
     * A provider bills in one currency, so the currency is changed for the provider
     * rather than for a rate at a time. The change is the correction of a provisional
     * entry, and the costs recorded under the provisional entry are as provisional as
     * it was, so every one of them is worked out again from what the call used, at the
     * rate now in force for its time, and in the new currency: attempts and the older
     * log by call, summary rows by day. A summary row is priced as one call of its
     * size at the rate in force at the start of its day, images included, which is as
     * near as a day whose detail has been purged can be brought.
     *
     * Taken under the record lock and the summariser's lock, in one transaction, so
     * that a request being recorded or a run of the summariser cannot interleave.
     *
     * @param string $provider The provider component.
     * @param string $currency The currency, already normalised.
     * @return int[] How many rates, attempts, log rows and summary rows were touched.
     * @throws \moodle_exception When the record is busy.
     */
    public function recost_provider(string $provider, string $currency): array {
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
                $this->db->set_field(price::TABLE, 'currency', $currency, ['provider' => $provider]);
                $counts = [
                    'rates' => $this->db->count_records(price::TABLE, ['provider' => $provider]),
                    'attempts' => $this->recost_attempts($provider),
                    'logs' => $this->recost_log($provider),
                    'summaries' => $this->recost_summaries($provider),
                ];
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $summarylock->release();
            $recordlock->release();
        }

        return $counts;
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
            ]);
            $count++;
        }
        $attempts->close();

        return $count;
    }

    /**
     * Work every row of the older log for a provider out again.
     *
     * The older log kept no image count, so an image is not priced here. It goes when
     * the log does.
     *
     * @param string $provider The provider component.
     * @return int How many rows were touched.
     */
    protected function recost_log(string $provider): int {
        $count = 0;
        $rows = $this->db->get_recordset_select(
            usage_logger::TABLE,
            'targetprovider = :provider',
            ['provider' => $provider],
            'id ASC',
            'id, model, prompttokens, completiontokens, timecreated',
        );
        foreach ($rows as $row) {
            $price = $this->find($provider, $row->model, (int) $row->timecreated);
            $this->db->update_record(usage_logger::TABLE, (object) [
                'id' => $row->id,
                'cost' => $price?->cost(self::tokens($row->prompttokens), self::tokens($row->completiontokens)),
                'currency' => $price?->get('currency'),
            ]);
            $count++;
        }
        $rows->close();

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
            $priced = $price !== null && ((int) $row->knowncalls > 0 || (int) $row->images > 0);
            $row->cost = $priced
                ? (float) ($price->cost((int) $row->prompttokens, (int) $row->completiontokens, (int) $row->images) ?? 0.0)
                : 0.0;
            $row->costedcalls = $priced ? max((int) $row->knowncalls, (int) $row->images > 0 ? (int) $row->calls : 0) : 0;
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
