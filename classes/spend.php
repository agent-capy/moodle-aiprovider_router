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
 * What something cost over a period, and how much of that is actually known.
 *
 * A figure on its own would be read as the answer, and here it often is not one. Costs
 * are worked out from the site's own rate table, so a request whose model has no rate
 * entered contributes nothing at all rather than a cost of zero. A site that has never
 * entered a rate therefore spends nothing, for ever, however much it uses.
 *
 * Anything deciding on the strength of a figure has to be able to tell that case from a
 * genuine zero, which is why the figure is never handed over by itself. Not knowing is
 * treated as not knowing: a budget that cannot be measured is not a budget with room
 * left in it.
 *
 * The count of requests beside it has none of that difficulty, because it is counted
 * rather than worked out. It is the figure a budget falls back on where money cannot be
 * said: a model somebody runs themselves, or an allowance a provider writes in requests.
 *
 * Money and requests are counted over different things, and the difference shows once a
 * request has been through more than one provider. A request is what somebody asked
 * for, and there is one of those however many providers it took. A call is one provider
 * being asked, and a call is what carries a price. So the amount here is the amount of
 * the calls, and whether it is known is a question about the calls; the request count
 * stands on its own beside it.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spend {
    /**
     * Constructor.
     *
     * @param float|null $amount What the costed calls came to, or null when none had a rate.
     * @param int $requests How many requests the period held.
     * @param int $costedcalls How many calls a rate covered.
     * @param int $from The first moment counted.
     * @param int $to The first moment not counted.
     * @param string $currency The currency the amount is in.
     * @param bool $mixedcurrency Whether costs in more than one currency were found.
     * @param int|null $calls How many calls the period held, or null to read it off
     *                        the request count. Figures written before calls were
     *                        counted apart hold one call per request, which is what
     *                        they were.
     */
    public function __construct(
        /** @var float|null What the costed calls came to. */
        public readonly ?float $amount,
        /** @var int How many requests the period held. */
        public readonly int $requests,
        /** @var int How many calls a rate covered. */
        public readonly int $costedcalls,
        /** @var int The first moment counted. */
        public readonly int $from,
        /** @var int The first moment not counted. */
        public readonly int $to,
        /** @var string The currency the amount is in. */
        public readonly string $currency,
        /** @var bool Whether costs in more than one currency were found. */
        public readonly bool $mixedcurrency = false,
        /** @var int|null How many calls the period held, or null when only requests were counted. */
        public readonly ?int $calls = null,
    ) {
    }

    /**
     * Whether the figure means anything.
     *
     * A period with no requests in it has genuinely been spent nothing on, and that is
     * known. A period with requests in it and no rate covering any of them is not a
     * period that cost nothing; it is one nobody can price.
     *
     * Costs recorded in more than one currency are not knowable either. Nothing here
     * converts between currencies, so adding them would produce a number in no
     * currency at all.
     *
     * A count of requests has none of these difficulties. It is counted rather than
     * worked out, so it is always known, including on a site that has entered no rates
     * at all and on one whose models cost nothing.
     *
     * Asked of the amount itself rather than of any count beside it. An amount exists
     * because something was priced; that is what an amount is. Deciding it from a count
     * of priced rows instead put the answer at the mercy of whether that count had been
     * worked out the same way as the amount, and twice it had not: the count was taken
     * over the requests while the amount was taken over every row, so a period could
     * hold a cost of 1.20 and report that nothing in it had been priced. A budget
     * reading that stopped refusing.
     *
     * The counts are still here, and they still say how much of the period the amount
     * covers. What they no longer decide is whether there is an amount at all.
     *
     * @param string $metric Which figure is being asked about.
     * @return bool True when the figure can be compared against a limit.
     */
    public function is_known(string $metric = spend_ledger::METRIC_COST): bool {
        if ($metric === spend_ledger::METRIC_REQUESTS) {
            return true;
        }
        if (!$this->is_comparable()) {
            return false;
        }

        // A period with nothing in it cost nothing, and that is known.
        return $this->amount !== null || $this->get_calls() === 0;
    }

    /**
     * Whether the amount is in the currency this site's limits are written in.
     *
     * Nothing here converts between currencies, and every limit -- a budget on a rule,
     * a cap somebody put on a key they brought -- is a figure in whatever the site
     * currency is now. An amount recorded before the currency was changed is a number
     * in the old one, and weighing that against a limit in the new one compares two
     * different things while looking exactly like a comparison.
     *
     * @return bool True when the amount and the site's limits are in one currency.
     */
    public function is_comparable(): bool {
        if ($this->mixedcurrency) {
            return false;
        }

        return $this->amount === null || $this->currency === price_book::get_currency();
    }

    /**
     * How many calls the period held.
     *
     * @return int The calls, falling back on the request count for a figure recorded
     *             before the two were counted apart, where they were the same thing.
     */
    public function get_calls(): int {
        return $this->calls ?? $this->requests;
    }

    /**
     * Whether every call in the period was priced.
     *
     * A partly priced period gives a known figure that is an understatement, which is
     * worth saying out loud wherever the figure is shown.
     *
     * @return bool True when nothing is missing from the amount.
     */
    public function is_complete(): bool {
        return $this->get_calls() === $this->costedcalls;
    }

    /**
     * The amount, with nothing standing in for not knowing.
     *
     * @return float The amount, or zero when nothing was priced.
     */
    public function get_amount(): float {
        return $this->amount ?? 0.0;
    }

    /**
     * The figure a budget of this kind is weighed against.
     *
     * @param string $metric Which figure is wanted.
     * @return float The amount spent, or the number of requests made.
     */
    public function get_measure(string $metric = spend_ledger::METRIC_COST): float {
        return $metric === spend_ledger::METRIC_REQUESTS
            ? (float) $this->requests
            : $this->get_amount();
    }

    /**
     * Whether a limit has been reached.
     *
     * Reaching the limit counts as reaching it. A limit of ten is a statement about how
     * much may be spent, and ten has been spent.
     *
     * @param float $limit The limit to compare against.
     * @param string $metric What the limit is counted in.
     * @return bool|null True or false, or null when the figure is not known.
     */
    public function has_reached(float $limit, string $metric = spend_ledger::METRIC_COST): ?bool {
        if (!$this->is_known($metric)) {
            return null;
        }

        return $this->get_measure($metric) >= $limit;
    }

    /**
     * What is left of a limit.
     *
     * @param float $limit The limit.
     * @param string $metric What the limit is counted in.
     * @return float|null What remains, never below zero, or null when the figure is not known.
     */
    public function get_remaining(float $limit, string $metric = spend_ledger::METRIC_COST): ?float {
        if (!$this->is_known($metric)) {
            return null;
        }

        return max(0.0, $limit - $this->get_measure($metric));
    }

    /**
     * The share of calls a rate covered.
     *
     * @return float|null Between zero and one, or null when there were no calls.
     */
    public function get_coverage(): ?float {
        if ($this->get_calls() === 0) {
            return null;
        }

        return $this->costedcalls / $this->get_calls();
    }
}
