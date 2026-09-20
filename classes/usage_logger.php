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
     * @param array|null $spilled What earlier attempts at the same request already used,
     *                              as prompttokens, completiontokens and cost. Null where
     *                              only one target was tried, which is the ordinary case.
     */
    public function record(\stdClass $entry, int $images = 0, ?array $spilled = null): void {
        try {
            $entry->currency = price_book::get_currency();
            // Priced before the earlier attempts are folded in, because they were
            // charged at their own provider's rate and this row's model is not theirs.
            $cost = $this->cost($entry, $images);
            if ($spilled !== null) {
                $entry->prompttokens = self::added($entry->prompttokens ?? null, (int) $spilled['prompttokens']);
                $entry->completiontokens = self::added(
                    $entry->completiontokens ?? null,
                    (int) $spilled['completiontokens'],
                );
            }
            // Not ?? here. The whole point of that value is that it may be null,
            // meaning "one of the earlier attempts could not be priced", and ?? would
            // read that as nothing having been spent.
            $entry->cost = $this->total_cost($cost, $spilled === null ? 0.0 : $spilled['cost']);
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
     * One token figure plus another, where the first may be unknown.
     *
     * @param int|null $counted What the answering target reported.
     * @param int $spilled What the targets before it reported.
     * @return int|null The total, or null when nothing at all was reported.
     */
    protected static function added(?int $counted, int $spilled): ?int {
        if ($counted === null) {
            return $spilled > 0 ? $spilled : null;
        }

        return $counted + $spilled;
    }

    /**
     * The whole cost of a request, including the attempts that produced nothing.
     *
     * Unknown wins. This plugin keeps "nobody can say" apart from "nothing" everywhere
     * else -- a budget refuses to route rather than read a missing rate as room to
     * spend -- and a total that quietly dropped the part it could not price would be
     * the one place that did not.
     *
     * @param float|null $answered What the target that answered cost.
     * @param float|null $spent What the targets before it cost.
     * @return float|null The total, or null when any part of it is unknown.
     */
    protected function total_cost(?float $answered, ?float $spent): ?float {
        if ($answered === null || $spent === null) {
            return null;
        }

        return $answered + $spent;
    }

    /**
     * What one attempt cost, for an attempt whose answer is not the one being recorded.
     *
     * Priced against the provider and model that produced it rather than the one that
     * finally answered, since a fallback chain crosses providers and their rates are
     * not the same.
     *
     * @param string $provider The component the attempt went to.
     * @param string|null $model The model that produced it.
     * @param int $when When it happened.
     * @param int|null $prompttokens Tokens it was charged for.
     * @param int|null $completiontokens Tokens it generated.
     * @param int $images Images it produced.
     * @return float|null The cost, or null when no rate covered it.
     */
    public function attempt_cost(
        string $provider,
        ?string $model,
        int $when,
        ?int $prompttokens,
        ?int $completiontokens,
        int $images = 0,
    ): ?float {
        $price = $this->prices->find($provider, $model, $when);

        return $price?->cost($prompttokens, $completiontokens, $images);
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
