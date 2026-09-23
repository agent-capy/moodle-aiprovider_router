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
     * Put every rate of a provider in one currency.
     *
     * A provider bills in one currency, so the currency is changed for the provider
     * rather than for a rate at a time. Costs already recorded keep the currency they
     * were recorded in: this relabels rates, and a recorded cost is not a rate.
     *
     * @param string $provider The provider component.
     * @param string $currency The currency, already normalised.
     * @return int How many rates were in another currency until now.
     */
    public function set_provider_currency(string $provider, string $currency): int {
        $changed = $this->db->count_records_select(
            price::TABLE,
            'provider = :provider AND currency <> :currency',
            ['provider' => $provider, 'currency' => $currency],
        );
        if ($changed > 0) {
            $this->db->set_field_select(
                price::TABLE,
                'currency',
                $currency,
                'provider = :provider AND currency <> :currency',
                ['provider' => $provider, 'currency' => $currency],
            );
        }

        return $changed;
    }
}
