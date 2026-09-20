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
 * How much has been spent, by the site, by a course, by a person, or on one brought key.
 *
 * Everything that has to answer "is there any money left" reads it from here: the rule
 * condition that routes by budget, the cap an owner puts on their own key, and the task
 * that warns somebody afterwards. One place, so that the three cannot quietly come to
 * mean different things by the same number.
 *
 * Two rules decide what is counted.
 *
 * A site's budget is what the site pays for. Requests somebody covered with a key they
 * brought cost the site nothing, so they are left out of every site, course and person
 * figure here, however large they are. A brought key is not the site's wallet, and a
 * budget that included it would stop a site's own spending on the strength of money
 * somebody else spent. What a brought key has cost is a question of its own, asked by
 * its owner and answered by get_key_spend().
 *
 * A key's own spending is found by who brought it and what it is for, not by the key id
 * the history happens to carry. The id is precise and does not survive: a key replaced
 * halfway through a month would start its cap again from nothing, while the provider
 * carries on billing the same account. So the reading follows the subject and the
 * target, which is also what the summaries still hold once the detail has been purged.
 *
 * ⚠ Figures are cached for a short time on the routing path, because otherwise every AI
 * request would run a sum over the history. That is deliberate and it has a consequence
 * worth stating plainly: a burst of requests can carry spending past a limit by whatever
 * can be spent while one cached figure lives. A limit here is a limit to within a
 * minute, not to the last request.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spend_ledger extends usage_report {
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

    /** @var string The cache area holding recently measured figures. */
    public const CACHE_AREA = 'budget';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to read.
     * @param usage_aggregator|null $aggregator The aggregator, for its calendar and its progress.
     * @param bool $cached Whether the short lived cache may answer. Anything reporting a
     *                     figure to a person, or acting on it once a day, should say no
     *                     and pay for the query.
     */
    public function __construct(
        \moodle_database $db,
        ?usage_aggregator $aggregator = null,
        /** @var bool Whether the cache may answer. */
        protected readonly bool $cached = true,
    ) {
        parent::__construct($db, $aggregator);
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
    public function get_window(string $period, int $length, int $now): array {
        if ($period === self::PERIOD_MONTH) {
            return [$this->aggregator->month_of($now), $now];
        }

        // Today counts, so a period of one day is today. Somebody setting a limit for
        // "the last 30 days" does not mean 31.
        $length = max(1, $length);
        $today = $this->aggregator->day_of($now);

        return [$this->aggregator->add_days($today, -($length - 1)), $now];
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
        [$from, $to] = $this->get_window($period, $length, $now);

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
        [$from, $to] = $this->get_window($period, $length, $now);
        $filters = $this->filters_for_key($key);

        return $this->remembered($filters, 'key_' . $key->get('id'), $from, $to);
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

        $clause = ' AND keysource = :keysource';
        $params = ['keysource' => rule::KEYSOURCE_SITE];
        $fields = [$field, 'currency'];
        $boundary = $this->get_boundary();
        $rows = [];
        if ($from < $boundary) {
            $rows = array_merge($rows, $this->summarised(
                $fields,
                'daystart >= :from AND daystart < :to' . $clause,
                ['from' => $from, 'to' => min($to, $boundary)] + $params,
                null,
            ));
        }
        if ($to > $boundary) {
            $rows = array_merge($rows, $this->detailed(
                $fields,
                'timecreated >= :from AND timecreated < :to' . $clause,
                ['from' => max($from, $boundary), 'to' => $to] + $params,
                null,
            ));
        }

        $gathered = [];
        foreach ($rows as $row) {
            $subject = $row->$field === null ? 0 : (int) $row->$field;
            if ($subject <= 0) {
                // Requests that belonged to no course, or whose person was not
                // recorded. Nobody's budget, so nobody to tell.
                continue;
            }
            $gathered[$subject][] = $row;
        }

        $spending = [];
        foreach ($gathered as $subject => $subjectrows) {
            $spending[$subject] = $this->total_of($subjectrows, $from, $to);
        }

        return $spending;
    }

    /**
     * Which rows belong to a subject.
     *
     * @param string $scope One of the SCOPE_ constants.
     * @param int $scopeid The course or the person.
     * @return array Column and value pairs both tables understand.
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
     * @return array Column and value pairs both tables understand.
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
     * @param array $filters Column and value pairs.
     * @param string $subject What is being measured, for the cache key.
     * @param int $from The first moment counted.
     * @param int $to The first moment not counted.
     * @return spend The spending.
     */
    protected function remembered(array $filters, string $subject, int $from, int $to): spend {
        if (!$this->cached) {
            return $this->read($filters, $from, $to);
        }

        $cache = \core_cache\cache::make('aiprovider_router', self::CACHE_AREA);
        $cachekey = $subject . '_' . $from;
        $held = $cache->get($cachekey);
        if (is_array($held)) {
            return new spend(
                amount: $held['amount'],
                requests: $held['requests'],
                costedrequests: $held['costedrequests'],
                from: $from,
                to: $to,
                currency: $held['currency'],
                mixedcurrency: $held['mixedcurrency'],
            );
        }

        $spend = $this->read($filters, $from, $to);
        $cache->set($cachekey, [
            'amount' => $spend->amount,
            'requests' => $spend->requests,
            'costedrequests' => $spend->costedrequests,
            'currency' => $spend->currency,
            'mixedcurrency' => $spend->mixedcurrency,
        ]);

        return $spend;
    }

    /**
     * Add up what the two tables hold about a subject over a period.
     *
     * Read across the same seam as every other report: the summaries as far as the
     * scheduled task has reached, the detail beyond it. Grouped by currency, so that a
     * site that changed currency partway through a period is told that the figure means
     * nothing rather than being handed the sum of two different currencies.
     *
     * @param array $filters Column and value pairs both tables understand.
     * @param int $from The first moment counted.
     * @param int $to The first moment not counted.
     * @return spend The spending.
     */
    protected function read(array $filters, int $from, int $to): spend {
        $clause = '';
        $params = [];
        foreach ($filters as $column => $value) {
            $clause .= ' AND ' . $column . ' = :' . $column;
            $params[$column] = $value;
        }

        $boundary = $this->get_boundary();
        $rows = [];
        if ($from < $boundary) {
            $rows = array_merge($rows, $this->summarised(
                ['currency'],
                'daystart >= :from AND daystart < :to' . $clause,
                ['from' => $from, 'to' => min($to, $boundary)] + $params,
                null,
            ));
        }
        if ($to > $boundary) {
            $rows = array_merge($rows, $this->detailed(
                ['currency'],
                'timecreated >= :from AND timecreated < :to' . $clause,
                ['from' => max($from, $boundary), 'to' => $to] + $params,
                null,
            ));
        }

        return $this->total_of($rows, $from, $to);
    }

    /**
     * Add a set of grouped rows into one figure.
     *
     * @param \stdClass[] $rows Rows carrying a currency and the usual metrics.
     * @param int $from The first moment counted.
     * @param int $to The first moment not counted.
     * @return spend The spending.
     */
    protected function total_of(array $rows, int $from, int $to): spend {
        $currencies = [];
        $counted = [];
        foreach ($rows as $row) {
            $row = $this->normalise($row);
            if ($row->cost !== null) {
                $currencies[(string) $row->currency] = true;
            }
            $counted[] = $row;
        }
        $total = self::total($counted);

        return new spend(
            amount: $total->cost === null ? null : (float) $total->cost,
            requests: (int) $total->requests,
            costedrequests: (int) $total->costedrequests,
            from: $from,
            to: $to,
            currency: count($currencies) === 1 ? (string) array_key_first($currencies) : price_book::get_currency(),
            mixedcurrency: count($currencies) > 1,
        );
    }
}
