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

namespace local_airouter\record;

/**
 * What something was spent over a period, provider by provider, and how much of it is known.
 *
 * A figure on its own would be read as the answer, and here it often is not one. Costs
 * are worked out from the rates entered for each provider, so a call whose model has
 * no rate contributes nothing at all rather than a cost of zero, and a provider with no
 * rates entered is spent nothing on, for ever, however much it is used. Anything
 * deciding on the strength of a figure has to be able to tell that from a genuine
 * zero, which is why the figure is never handed over by itself.
 *
 * Money is one figure per provider, in the currency that provider bills in, and is
 * never added across providers. A limit in money therefore names a provider, and is
 * weighed against that provider's figure alone. A count of requests has none of these
 * difficulties: it is counted rather than worked out, so it is always known, and it is
 * counted over everything in the period whatever provider answered.
 *
 * Money and requests are counted over different things. A request is what somebody
 * asked for, and there is one of those however many providers it took. A call is one
 * provider being asked, and a call is what carries a price. So a provider's amount is
 * the amount of the calls to it, and whether it is known is a question about those
 * calls; the request count stands on its own beside it.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spend {
    /**
     * Constructor.
     *
     * @param int $requests How many requests the period held.
     * @param int $calls How many calls the period held, to every provider.
     * @param int $from The first moment counted.
     * @param int $to The first moment not counted.
     * @param \stdClass[] $providers One entry per provider called, keyed by component,
     *                               each carrying provider, currency, amount, calls,
     *                               costedcalls and comparable. The amount is null
     *                               where no call to that provider had a rate, and
     *                               comparable is false where the amount is in a
     *                               currency the provider's limits are not written in.
     * @param int $coveredfrom The first moment the site can still account for, or
     *                         zero when it has discarded nothing. A period that
     *                         starts before it is counted over part of itself.
     */
    public function __construct(
        /** @var int How many requests the period held. */
        public readonly int $requests,
        /** @var int How many calls the period held. */
        public readonly int $calls,
        /** @var int The first moment counted. */
        public readonly int $from,
        /** @var int The first moment not counted. */
        public readonly int $to,
        /** @var \stdClass[] The providers called, keyed by component. */
        public readonly array $providers = [],
        /** @var int The first moment the site can still account for. */
        public readonly int $coveredfrom = 0,
    ) {
    }

    /**
     * Whether the period was counted over part of itself.
     *
     * The site discarded history from inside the period before it was measured, so
     * every figure here is a floor: not unknown, and not to be made unknown, since a
     * limit that cannot be measured stops restricting anything; simply smaller than
     * the spending was. Anywhere the figures are shown should say so.
     *
     * @return bool True when the earlier part of the period cannot be accounted for.
     */
    public function is_partial(): bool {
        return $this->coveredfrom > $this->from;
    }

    /**
     * The moment the figures are counted from.
     *
     * @return int The start of the period, or the later moment the record begins.
     */
    public function get_covered_from(): int {
        return max($this->from, $this->coveredfrom);
    }

    /**
     * What one provider was paid, if it was called.
     *
     * @param string $provider The provider component.
     * @return \stdClass|null The entry, or null when nothing in the period went to it.
     */
    public function get_provider(string $provider): ?\stdClass {
        return $this->providers[$provider] ?? null;
    }

    /**
     * The one provider the period's calls went to, where they went to one.
     *
     * A key is registered for one target, so the calls it paid for go to one provider,
     * and that is the provider its limit is weighed against.
     *
     * @return string The component, or an empty string where there were none or several.
     */
    public function sole_provider(): string {
        return count($this->providers) === 1 ? (string) array_key_first($this->providers) : '';
    }

    /**
     * Whether the figure means anything.
     *
     * A period with no calls to a provider has genuinely been spent nothing on at that
     * provider, and that is known. A period with calls to it and no rate covering any
     * of them is not a period that cost nothing; it is one nobody can price. An amount
     * in a currency the provider's limits are not written in is not knowable either,
     * because nothing here converts between currencies.
     *
     * A count of requests is counted rather than worked out, so it is always known,
     * including on a site that has entered no rates at all.
     *
     * @param string $metric Which figure is being asked about.
     * @param string $provider The provider a money figure is about; ignored for requests.
     * @return bool True when the figure can be compared against a limit.
     */
    public function is_known(string $metric, string $provider = ''): bool {
        if ($metric === ledger::METRIC_REQUESTS) {
            return true;
        }
        $entry = $this->get_provider($provider);
        if ($entry === null) {
            // Nothing went there, so nothing was spent there, and that is known.
            return true;
        }
        if (!$entry->comparable) {
            return false;
        }

        return $entry->amount !== null || $entry->calls === 0;
    }

    /**
     * The currency a provider's figure is in.
     *
     * @param string $provider The provider component.
     * @return string|null The currency, or null where nothing at that provider was priced.
     */
    public function get_currency(string $provider): ?string {
        return $this->get_provider($provider)?->currency;
    }

    /**
     * How many calls went to a provider.
     *
     * @param string $provider The provider component.
     * @return int The calls.
     */
    public function get_calls_to(string $provider): int {
        return (int) ($this->get_provider($provider)?->calls ?? 0);
    }

    /**
     * How many of the calls to a provider had a rate.
     *
     * @param string $provider The provider component.
     * @return int The calls.
     */
    public function get_costed_calls(string $provider): int {
        return (int) ($this->get_provider($provider)?->costedcalls ?? 0);
    }

    /**
     * Whether every call to a provider was priced.
     *
     * A partly priced period gives a known figure that is an understatement, which is
     * worth saying out loud wherever the figure is shown.
     *
     * @param string $provider The provider component.
     * @return bool True when nothing is missing from the amount.
     */
    public function is_complete(string $provider): bool {
        return $this->get_calls_to($provider) === $this->get_costed_calls($provider);
    }

    /**
     * The share of a provider's calls a rate covered.
     *
     * @param string $provider The provider component.
     * @return float|null Between zero and one, or null when there were no calls.
     */
    public function get_coverage(string $provider): ?float {
        $calls = $this->get_calls_to($provider);
        if ($calls === 0) {
            return null;
        }

        return $this->get_costed_calls($provider) / $calls;
    }

    /**
     * A provider's amount, with nothing standing in for not knowing.
     *
     * @param string $provider The provider component.
     * @return float The amount, or zero when nothing was priced.
     */
    public function get_amount(string $provider): float {
        return (float) ($this->get_provider($provider)?->amount ?? 0.0);
    }

    /**
     * The figure a limit of this kind is weighed against.
     *
     * @param string $metric Which figure is wanted.
     * @param string $provider The provider a money figure is about; ignored for requests.
     * @return float The amount spent at the provider, or the number of requests made.
     */
    public function get_measure(string $metric, string $provider = ''): float {
        return $metric === ledger::METRIC_REQUESTS
            ? (float) $this->requests
            : $this->get_amount($provider);
    }

    /**
     * Whether a limit has been reached.
     *
     * Reaching the limit counts as reaching it. A limit of ten is a statement about how
     * much may be spent, and ten has been spent.
     *
     * @param float $limit The limit to compare against.
     * @param string $metric What the limit is counted in.
     * @param string $provider The provider a money limit is about; ignored for requests.
     * @return bool|null True or false, or null when the figure is not known.
     */
    public function has_reached(float $limit, string $metric, string $provider = ''): ?bool {
        if (!$this->is_known($metric, $provider)) {
            return null;
        }

        return $this->get_measure($metric, $provider) >= $limit;
    }

    /**
     * What is left of a limit.
     *
     * @param float $limit The limit.
     * @param string $metric What the limit is counted in.
     * @param string $provider The provider a money limit is about; ignored for requests.
     * @return float|null What remains, never below zero, or null when the figure is not known.
     */
    public function get_remaining(float $limit, string $metric, string $provider = ''): ?float {
        if (!$this->is_known($metric, $provider)) {
            return null;
        }

        return max(0.0, $limit - $this->get_measure($metric, $provider));
    }
}
