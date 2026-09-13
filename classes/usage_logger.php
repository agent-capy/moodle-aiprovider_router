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
 * Writes one row per request the router handled.
 *
 * Core keeps its own record, but it records the router as the provider for everything
 * that comes through here: ai_action_register.provider holds the component name of the
 * provider the manager called, which is always aiprovider_router. Core's own usage
 * report in 5.3 shows the provider, the action, the tokens and whether it worked, and
 * does not show the model at all. So on a site using the router, core can say how much
 * AI was used and not where any of it went. That is what this exists to answer.
 *
 * Names are copied alongside ids. Rules get renamed and deleted, provider instances get
 * deleted, and a history that reads "rule 14 sent this to instance 7" some months later
 * is not a history anybody can use.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_logger {
    /** @var string The table holding the log. */
    public const TABLE = 'aiprovider_router_log';

    /** @var string Recorded against a request paid for with the site's own key. */
    public const KEY_SITE = 'site';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to write to.
     * @param price_book|null $prices Where rates are looked up, or null for the site's.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var price_book|null The rates. */
        protected ?price_book $prices = null,
    ) {
        $this->prices ??= new price_book($db);
    }

    /**
     * Record what happened to a request.
     *
     * Nothing here may stop the request. A monitor is a tool for running a site, not an
     * obstacle on the path of every AI request, so a failure to write the history is
     * reported to the developer log and otherwise swallowed.
     *
     * @param \stdClass $entry The row to write, without the cost.
     * @param int $images How many images the request produced, for actions costed per image.
     */
    public function record(\stdClass $entry, int $images = 0): void {
        try {
            $entry->currency = price_book::get_currency();
            $entry->cost = $this->cost($entry, $images);
            $entry->keysource = $entry->keysource ?? self::KEY_SITE;
            $this->db->insert_record(self::TABLE, $entry);
        } catch (\Throwable $e) {
            debugging(
                'aiprovider_router: could not record usage: ' . get_class($e) . ': ' . $e->getMessage(),
                DEBUG_NORMAL,
            );
        }
    }

    /**
     * What the request cost, according to the rate in force when it happened.
     *
     * Worked out now and kept, never recalculated. Rates are revised, and a report that
     * quietly restates last month in this month's prices is of no use to anybody trying
     * to account for what was spent.
     *
     * @param \stdClass $entry The row being written.
     * @param int $images How many images the request produced.
     * @return float|null The cost, or null when no rate covered it.
     */
    protected function cost(\stdClass $entry, int $images): ?float {
        $provider = (string) ($entry->targetprovider ?? '');
        if ($provider === '') {
            return null;
        }
        $price = $this->prices->find($provider, $entry->model ?? null, (int) $entry->timecreated);
        if ($price === null) {
            return null;
        }

        return $price->cost(
            $entry->prompttokens === null ? null : (int) $entry->prompttokens,
            $entry->completiontokens === null ? null : (int) $entry->completiontokens,
            $images,
        );
    }
}
