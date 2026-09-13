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

namespace aiprovider_router;

/**
 * Finds the rate that applied to a request, and the currency the site works in.
 *
 * Lookups prefer a rate entered for the exact model, and fall back to one entered for
 * the provider as a whole. Where neither exists the request has no cost recorded at
 * all, rather than a cost of zero.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class price_book {
    /** @var string The currency used when the site has not chosen one. */
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
     * The currency every rate and every recorded cost is expressed in.
     *
     * One currency for the whole site, and no conversion. Picking exchange rates would
     * mean choosing a source, a moment and a rounding rule, and would lay a second
     * layer of error over a figure that is already an estimate. An administrator who
     * thinks in yen sets the currency to JPY and enters the rates in yen.
     *
     * @return string The currency code.
     */
    public static function get_currency(): string {
        $currency = trim((string) get_config('aiprovider_router', 'currency'));

        return $currency === '' ? self::DEFAULT_CURRENCY : $currency;
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
}
