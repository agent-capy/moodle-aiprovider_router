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
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spend {
    /**
     * Constructor.
     *
     * @param float|null $amount What the costed requests came to, or null when none had a rate.
     * @param int $requests How many requests the period held.
     * @param int $costedrequests How many of them a rate covered.
     * @param int $from The first moment counted.
     * @param int $to The first moment not counted.
     * @param string $currency The currency the amount is in.
     * @param bool $mixedcurrency Whether costs in more than one currency were found.
     */
    public function __construct(
        /** @var float|null What the costed requests came to. */
        public readonly ?float $amount,
        /** @var int How many requests the period held. */
        public readonly int $requests,
        /** @var int How many of them a rate covered. */
        public readonly int $costedrequests,
        /** @var int The first moment counted. */
        public readonly int $from,
        /** @var int The first moment not counted. */
        public readonly int $to,
        /** @var string The currency the amount is in. */
        public readonly string $currency,
        /** @var bool Whether costs in more than one currency were found. */
        public readonly bool $mixedcurrency = false,
    ) {
    }

    /**
     * Whether the amount means anything.
     *
     * A period with no requests in it has genuinely been spent nothing on, and that is
     * known. A period with requests in it and no rate covering any of them is not a
     * period that cost nothing; it is one nobody can price.
     *
     * Costs recorded in more than one currency are not knowable either. Nothing here
     * converts between currencies, so adding them would produce a number in no
     * currency at all.
     *
     * @return bool True when the amount can be compared against a limit.
     */
    public function is_known(): bool {
        if ($this->mixedcurrency) {
            return false;
        }

        return $this->requests === 0 || $this->costedrequests > 0;
    }

    /**
     * Whether every request in the period was priced.
     *
     * A partly priced period gives a known figure that is an understatement, which is
     * worth saying out loud wherever the figure is shown.
     *
     * @return bool True when nothing is missing from the amount.
     */
    public function is_complete(): bool {
        return $this->requests === $this->costedrequests;
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
     * Whether a limit has been reached.
     *
     * Reaching the limit counts as reaching it. A limit of ten is a statement about how
     * much may be spent, and ten has been spent.
     *
     * @param float $limit The limit to compare against.
     * @return bool|null True or false, or null when the spending is not known.
     */
    public function has_reached(float $limit): ?bool {
        if (!$this->is_known()) {
            return null;
        }

        return $this->get_amount() >= $limit;
    }

    /**
     * What is left of a limit.
     *
     * @param float $limit The limit.
     * @return float|null What remains, never below zero, or null when the spending is not known.
     */
    public function get_remaining(float $limit): ?float {
        if (!$this->is_known()) {
            return null;
        }

        return max(0.0, $limit - $this->get_amount());
    }

    /**
     * The share of requests a rate covered.
     *
     * @return float|null Between zero and one, or null when there were no requests.
     */
    public function get_coverage(): ?float {
        if ($this->requests === 0) {
            return null;
        }

        return $this->costedrequests / $this->requests;
    }
}
