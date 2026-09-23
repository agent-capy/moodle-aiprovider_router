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

use local_airouter\key;
use local_airouter\price_book;
use local_airouter\rule;
use local_airouter\rule_repository;

/**
 * How much has been spent, by the site, by a course, by a person, or on one brought key.
 *
 * Everything that has to answer "is there any money left" reads it from here: the rule
 * condition that routes by budget, the cap an owner puts on their own key, the course
 * page's bar and the task that warns somebody afterwards. One place, so that none of
 * them can quietly come to mean something different by the same number. It reads the
 * request and attempt records the way every report does: the summary for what has been
 * counted, the detail for what has not, each finished fact once.
 *
 * Money is counted per provider, in the currency that provider bills in, and never
 * added across providers. A limit in money names a provider and is weighed against
 * that provider's figure; a limit in requests counts every request whatever answered it.
 *
 * Two rules decide what is counted.
 *
 * A site's budget is what the site pays for. Requests somebody covered with a key they
 * brought cost the site nothing, so they are left out of every site, course and person
 * figure here, however large they are. What a brought key has cost is a question of its
 * own, asked by its owner and answered by get_key_spend().
 *
 * A key's own spending is found by who brought it and what it is for, not by the key id
 * the history happens to carry. The id is precise and does not survive: a key replaced
 * halfway through a month would start its cap again from nothing, while the provider
 * carries on billing the same account. So the reading follows the subject and the
 * target, which is also what the summaries hold once the detail has been purged.
 *
 * Figures are cached for a short time on the routing path, because otherwise every AI
 * request would run a sum over the history. That is deliberate and it has a consequence
 * worth stating plainly: a burst of requests can carry spending past a limit by whatever
 * can be spent while one cached figure lives. A limit here is a limit to within a
 * minute, not to the last request.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledger extends reader {
    /** @var string Everything the site paid for. */
    public const SCOPE_SITE = 'site';

    /** @var string What the site paid for on behalf of one course. */
    public const SCOPE_COURSE = 'course';

    /** @var string What the site paid for on behalf of one person. */
    public const SCOPE_USER = 'user';

    /** @var string The last so many days, today included. */
    public const PERIOD_ROLLING = 'rolling';

    /** @var string This calendar month so far. */
    public const PERIOD_MONTH = 'month';

    /** @var string What a limit is counted in: money at one provider, worked out from its rates. */
    public const METRIC_COST = 'cost';

    /** @var string What a limit is counted in: how many requests were made. */
    public const METRIC_REQUESTS = 'requests';

    /** @var string The cache area holding recently measured figures. */
    public const CACHE_AREA = 'budget';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to read.
     * @param bool $cached Whether the short lived cache may answer. Anything reporting a
     *                     figure to a person, or acting on it once a day, should say no
     *                     and pay for the query.
     * @param price_book|null $prices Where each provider's currency is looked up, or
     *                                null for the site's rates.
     * @param \Closure|null $stop The reader's stop hook, for tests.
     */
    public function __construct(
        \moodle_database $db,
        /** @var bool Whether the cache may answer. */
        protected readonly bool $cached = true,
        /** @var price_book|null The rates. */
        protected ?price_book $prices = null,
        ?\Closure $stop = null,
    ) {
        parent::__construct($db, $stop);
        $this->prices ??= new price_book($db);
    }

    /**
     * The subjects a budget can be set for.
     *
     * @return string[] The scope names.
     */
    public static function get_scopes(): array {
        return [self::SCOPE_SITE, self::SCOPE_COURSE, self::SCOPE_USER];
    }

    /**
     * The ways a period can be counted.
     *
     * @return string[] The period names.
     */
    public static function get_periods(): array {
        return [self::PERIOD_ROLLING, self::PERIOD_MONTH];
    }

    /**
     * What a limit can be counted in.
     *
     * Money is the one an administrator usually means, and it is the one that cannot
     * always be worked out: it comes from the rates entered for the provider. A count
     * of requests is always available, which makes it the measure for a site whose
     * models cost nothing to run and for a provider whose free allowance is written in
     * requests rather than in money.
     *
     * @return string[] The metric names.
     */
    public static function get_metrics(): array {
        return [self::METRIC_COST, self::METRIC_REQUESTS];
    }

    /**
     * The stretch of time a period covers, ending now.
     *
     * Both kinds start at midnight, which is not a rounding: the summaries hold whole
     * days, so a period starting in the middle of one would lose that day entirely once
     * the detail behind it had been purged.
     *
     * @param string $period One of the PERIOD_ constants.
     * @param int $length How many days a rolling period counts, ignored for a month.
     * @param int $now The moment the period ends at.
     * @return int[] The first moment counted and the first moment not counted.
     */
    public static function get_window(string $period, int $length, int $now): array {
        if ($period === self::PERIOD_MONTH) {
            return [summariser::month_of($now), $now];
        }

        // Today counts, so a period of one day is today. Somebody setting a limit for
        // "the last 30 days" does not mean 31.
        $length = max(1, $length);

        return [summariser::add_days(summariser::day_of($now), -($length - 1)), $now];
    }

    /**
     * How far back the furthest limit on this site has to be able to see.
     *
     * Every limit is worked out from what is still stored, so history removed from
     * inside its period does not make the figure unknown -- which is what this plugin
     * does everywhere else it cannot measure something -- it makes it smaller. A limit
     * that had been reached comes back under the line, and the requests it was
     * stopping start going through again.
     *
     * Both kinds of limit count: the budgets a rule sets, and the limits people put on
     * keys they brought. Rules that have not started yet count too: what they will
     * measure on their first day is the history sitting in the table now.
     *
     * A calendar month is counted as 31 days, which is the most one can be.
     *
     * @param \moodle_database $db The database to read.
     * @return int Days, or zero where nothing on the site sets a limit.
     */
    public static function longest_reach_days(\moodle_database $db): int {
        $days = 0;
        foreach ((new rule_repository($db))->get_budgets(null, true) as $budget) {
            $days = max($days, self::reach_of((string) $budget->period, (int) $budget->days));
        }
        foreach ($db->get_records_select(key::TABLE, 'capamount IS NOT NULL') as $record) {
            $cap = new key(0, $record);
            $days = max($days, self::reach_of($cap->get_cap_period(), $cap->get_cap_days()));
        }

        return $days;
    }

    /**
     * How many days one limit reaches back over.
     *
     * @param string $period One of the PERIOD_ constants.
     * @param int $length How many days a rolling period counts.
     * @return int The days.
     */
    public static function reach_of(string $period, int $length): int {
        return $period === self::PERIOD_MONTH ? 31 : max(1, $length);
    }

    /**
     * What the site has spent on a subject's behalf over a period.
     *
     * @param string $scope One of the SCOPE_ constants.
     * @param int $scopeid The course or the person, ignored for the whole site.
     * @param string $period One of the PERIOD_ constants.
     * @param int $length How many days a rolling period counts.
     * @param int $now The moment the period ends at.
     * @return spend The spending.
     */
    public function get_spend(string $scope, int $scopeid, string $period, int $length, int $now): spend {
        [$from, $to] = self::get_window($period, $length, $now);

        return $this->remembered($this->filters_for($scope, $scopeid), $scope . '_' . $scopeid, $from, $to);
    }

    /**
     * What has been spent on one brought key over a period.
     *
     * @param key $key The key.
     * @param string $period One of the PERIOD_ constants.
     * @param int $length How many days a rolling period counts.
     * @param int $now The moment the period ends at.
     * @return spend The spending.
     */
    public function get_key_spend(key $key, string $period, int $length, int $now): spend {
        [$from, $to] = self::get_window($period, $length, $now);

        return $this->remembered($this->filters_for_key($key), 'key_' . $key->get('id'), $from, $to);
    }

    /**
     * What the site spent on a subject's behalf between two moments, asking the database.
     *
     * @param string $scope One of the SCOPE_ constants.
     * @param int $scopeid The course or the person, ignored for the whole site.
     * @param int $from The first moment to count, which should be a midnight.
     * @param int $to The first moment not to count.
     * @return spend The spending.
     */
    public function measure(string $scope, int $scopeid, int $from, int $to): spend {
        return $this->read($this->filters_for($scope, $scopeid), $from, $to);
    }

    /**
     * What one brought key was used for between two moments, asking the database.
     *
     * @param key $key The key.
     * @param int $from The first moment to count, which should be a midnight.
     * @param int $to The first moment not to count.
     * @return spend The spending.
     */
    public function measure_key(key $key, int $from, int $to): spend {
        return $this->read($this->filters_for_key($key), $from, $to);
    }

    /**
     * What the site spent on behalf of every course, or of every person, in a period.
     *
     * For the daily task, which has to find the subjects that have gone over a budget
     * without asking about every course on the site. Only subjects that used the AI in
     * the period appear, which is the same set as the ones that could have gone over.
     *
     * @param string $scope SCOPE_COURSE or SCOPE_USER.
     * @param int $from The first moment to count, which should be a midnight.
     * @param int $to The first moment not to count.
     * @return spend[] The spending, keyed by course or person.
     */
    public function measure_each(string $scope, int $from, int $to): array {
        $field = match ($scope) {
            self::SCOPE_COURSE => 'courseid',
            self::SCOPE_USER => 'userid',
            default => throw new \coding_exception('Cannot measure each of: ' . $scope),
        };
        $fields = [$field, 'targetprovider'];
        [$summary, $detail] = $this->consistently(fn() => [
            $this->summarised($fields, $from, $to, null, rule::KEYSOURCE_SITE),
            $this->detailed($fields, $from, $to, null, rule::KEYSOURCE_SITE),
        ]);
        $rows = [];
        $this->collect($rows, $fields, $summary);
        $this->collect($rows, $fields, $detail);

        $gathered = [];
        foreach ($rows as $row) {
            $subject = (int) ($row->$field ?? 0);
            if ($subject <= 0) {
                // Requests that belonged to no course, or whose person was not
                // recorded. Nobody's budget, so nobody to tell.
                continue;
            }
            $gathered[$subject][] = $row;
        }

        $spending = [];
        foreach ($gathered as $subject => $subjectrows) {
            $spending[$subject] = $this->build($subjectrows, $from, $to);
        }
        // In subject order: the database hands grouped rows out in no particular order.
        ksort($spending);

        return $spending;
    }

    /**
     * Which rows belong to a subject.
     *
     * @param string $scope One of the SCOPE_ constants.
     * @param int $scopeid The course or the person.
     * @return array Filters the reader understands.
     */
    protected function filters_for(string $scope, int $scopeid): array {
        // Only what the site paid for. Decided once, here, so that no caller can widen
        // a budget by reaching for the money somebody brought with them.
        $filters = ['keysource' => rule::KEYSOURCE_SITE];
        if ($scope === self::SCOPE_SITE) {
            return $filters;
        }
        if ($scopeid <= 0) {
            // Asking what an unnamed course or an unnamed person has spent is a
            // question about nothing. Whoever is asking has to decide what to do about
            // a request that belongs to no course before it gets this far.
            throw new \coding_exception('A budget for a ' . $scope . ' needs to say which one');
        }
        if ($scope === self::SCOPE_COURSE) {
            $filters['courseid'] = $scopeid;

            return $filters;
        }
        if ($scope === self::SCOPE_USER) {
            $filters['userid'] = $scopeid;

            return $filters;
        }

        throw new \coding_exception('Unknown budget scope: ' . $scope);
    }

    /**
     * Which rows belong to one brought key.
     *
     * @param key $key The key.
     * @return array Filters the reader understands.
     */
    protected function filters_for_key(key $key): array {
        $scope = (string) $key->get('scope');
        $subject = $scope === key::SCOPE_COURSE ? 'courseid' : 'userid';

        return [
            'keysource' => $scope,
            $subject => (int) $key->get('scopeid'),
            'targetid' => (int) $key->get('targetid'),
        ];
    }

    /**
     * Answer from the cache when it can, and remember what the database said when it cannot.
     *
     * The end of the window is left out of the cache key on purpose. It moves every
     * second, and a key carrying it would never be hit twice, which is the whole point
     * of caching this at all. The start is in the key, so a rolling window that has
     * just rolled over a day, or a month that has just begun, is measured again at once
     * rather than waiting for the entry to expire.
     *
     * @param array $filters Filters the reader understands.
     * @param string $subject What is being measured, for the cache key.
     * @param int $from The first moment counted.
     * @param int $to The first moment not counted.
     * @return spend The spending.
     */
    protected function remembered(array $filters, string $subject, int $from, int $to): spend {
        if (!$this->cached) {
            return $this->read($filters, $from, $to);
        }

        $cache = \core_cache\cache::make('local_airouter', self::CACHE_AREA);
        $cachekey = $subject . '_' . $from;
        $held = $cache->get($cachekey);
        if (is_array($held)) {
            $providers = [];
            foreach ($held['providers'] as $provider => $entry) {
                $providers[$provider] = (object) $entry;
            }

            return new spend($held['requests'], $held['calls'], $from, $to, $providers);
        }

        $spend = $this->read($filters, $from, $to);
        if (!$this->was_consistent()) {
            // Read while the summary kept moving. Good enough to answer with once,
            // not good enough to hold for a minute.
            return $spend;
        }
        $providers = [];
        foreach ($spend->providers as $provider => $entry) {
            $providers[$provider] = (array) $entry;
        }
        $cache->set($cachekey, ['requests' => $spend->requests, 'calls' => $spend->calls, 'providers' => $providers]);

        return $spend;
    }

    /**
     * Add up what the record holds about a subject over a period, provider by provider.
     *
     * @param array $filters Filters the reader understands.
     * @param int $from The first moment counted.
     * @param int $to The first moment not counted.
     * @return spend The spending.
     */
    protected function read(array $filters, int $from, int $to): spend {
        $courseid = $filters['courseid'] ?? null;
        $userid = $filters['userid'] ?? null;
        $targetid = $filters['targetid'] ?? null;
        $keysource = (string) $filters['keysource'];
        $fields = ['targetprovider'];
        [$summary, $detail] = $this->consistently(fn() => [
            $this->summarised($fields, $from, $to, $courseid, $keysource, $userid, $targetid),
            $this->detailed($fields, $from, $to, $courseid, $keysource, $userid, $targetid),
        ]);
        $rows = [];
        $this->collect($rows, $fields, $summary);
        $this->collect($rows, $fields, $detail);

        return $this->build(array_values($rows), $from, $to);
    }

    /**
     * Turn rows grouped by provider into one answer.
     *
     * A provider's amount is comparable with a limit only when it is in the currency
     * the provider's rates are in now, which is the currency any limit on it is written
     * in. A provider's currency is corrected once at most, and every recorded cost is
     * worked out again when it is, so the two agree unless something is wrong; when
     * they disagree, the figure is shown but not weighed.
     *
     * @param \stdClass[] $rows Rows carrying targetprovider and the metrics.
     * @param int $from The first moment counted.
     * @param int $to The first moment not counted.
     * @return spend The spending.
     */
    protected function build(array $rows, int $from, int $to): spend {
        $requests = 0;
        $calls = 0;
        $providers = [];
        foreach ($rows as $row) {
            $requests += (int) $row->requests;
            $calls += (int) $row->calls;
            $provider = (string) ($row->targetprovider ?? '-');
            if ($provider === '-' || ((int) $row->calls === 0 && !$row->costs)) {
                // Requests nobody answered carry no provider and no money.
                continue;
            }
            $entry = (object) [
                'provider' => $provider,
                'currency' => null,
                'amount' => null,
                'calls' => (int) $row->calls,
                'costedcalls' => (int) $row->costedcalls,
                'comparable' => true,
            ];
            if (count($row->costs) === 1) {
                $money = reset($row->costs);
                $entry->currency = (string) $money['currency'];
                $entry->amount = (float) $money['amount'];
            } else if ($row->costs) {
                // Money in two currencies at one provider is not one figure.
                $entry->comparable = false;
            }
            if ($entry->currency !== null && $entry->currency !== $this->prices->currency_of($provider)) {
                // Recorded in a currency the provider's limits are not written in now,
                // or the provider has no rates now and so no currency for a limit.
                $entry->comparable = false;
            }
            $providers[$provider] = $entry;
        }
        ksort($providers);

        return new spend($requests, $calls, $from, $to, $providers);
    }
}
