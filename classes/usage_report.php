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
 * Answers the questions the monitor asks, from whichever table still holds the answer.
 *
 * Usage lives in two places at once. Finished days have been summarised and their detail
 * may already have been purged; today, and any day the scheduled task has not reached
 * yet, exist only as detail rows. A report that read one table would either lose last
 * year or lose this morning, so every figure here is assembled from the summaries up to
 * the point they reach, and from the detail beyond it.
 *
 * Every figure can also be narrowed to one payer. Requests paid for with keys people
 * brought cost the site nothing, so a cost adding them in would be nobody's expenditure:
 * not the site's, and not any one person's either.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_report {
    /** @var string Break the figures down by the instance that answered. */
    public const BY_TARGET = 'target';

    /** @var string Break the figures down by the action that was asked for. */
    public const BY_ACTION = 'action';

    /** @var string Break the figures down by the model that answered. */
    public const BY_MODEL = 'model';

    /** @var string Break the figures down by whose key paid. */
    public const BY_KEYSOURCE = 'keysource';

    /** @var string Asked for in place of a key source, to leave the figures unfiltered. */
    public const KEYSOURCE_ALL = 'all';

    /** @var array Which columns each breakdown groups on. */
    protected const GROUPS = [
        self::BY_TARGET => ['targetid', 'targetname'],
        self::BY_ACTION => ['actionname'],
        self::BY_MODEL => ['model'],
        self::BY_KEYSOURCE => ['keysource'],
    ];

    /** @var string[] The figures every row of every report carries. */
    protected const METRICS = [
        'requests',
        'failures',
        'prompttokens',
        'completiontokens',
        'cost',
        'costedrequests',
    ];

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to read.
     * @param usage_aggregator|null $aggregator The aggregator, for its calendar and its progress.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var usage_aggregator|null The aggregator. */
        protected ?usage_aggregator $aggregator = null,
    ) {
        $this->aggregator ??= new usage_aggregator($db);
    }

    /**
     * The first moment the summaries do not cover, which is where the detail takes over.
     *
     * @return int The timestamp, or zero when nothing has been summarised yet.
     */
    public function get_boundary(): int {
        $last = (int) get_config('aiprovider_router', usage_aggregator::LAST_SETTING);

        return $last > 0 ? $this->aggregator->add_days($last, 1) : 0;
    }

    /**
     * How far back the detail rows still go.
     *
     * Worth showing, because anything drawn from the detail alone, such as the failure
     * reasons, says nothing about the days that have been purged.
     *
     * @return int|null The earliest moment still recorded, or null when the log is empty.
     */
    public function get_detail_from(): ?int {
        $earliest = $this->db->get_field_sql('SELECT MIN(timecreated) FROM {' . usage_logger::TABLE . '}');

        return empty($earliest) ? null : (int) $earliest;
    }

    /**
     * One row per day in the period, whether or not anything happened that day.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to requests one payer covered, or null for all.
     * @return \stdClass[] Rows keyed by the midnight of their day, oldest first.
     */
    public function get_series(int $from, int $to, ?int $courseid = null, ?string $keysource = null): array {
        $series = [];
        $lastday = $this->aggregator->day_of($to);
        for ($day = $this->aggregator->day_of($from); $day <= $lastday; $day = $this->aggregator->add_days($day, 1)) {
            $series[$day] = $this->blank();
        }

        $boundary = $this->get_boundary();
        if ($from < $boundary) {
            $where = 'daystart >= :from AND daystart < :to';
            $params = ['from' => $from, 'to' => min($to, $boundary)];
            foreach ($this->summarised(['daystart'], $where, $params, $courseid, $keysource) as $row) {
                $day = (int) $row->daystart;
                if (isset($series[$day])) {
                    $series[$day] = $this->normalise($row);
                }
            }
        }

        // Days the task has not reached are counted one at a time. On a site whose cron
        // is running that is today and nothing else.
        foreach (array_keys($series) as $day) {
            if ($day < $boundary) {
                continue;
            }
            $end = min($this->aggregator->add_days($day, 1), $to);
            $rows = $this->detailed([], 'timecreated >= :from AND timecreated < :to', [
                'from' => max($day, $from),
                'to' => $end,
            ], $courseid, $keysource);
            // An aggregate over no rows still comes back, as one row of nothing, so what
            // arrives is normalised rather than trusted.
            if ($rows) {
                $series[$day] = $this->normalise(reset($rows));
            }
        }

        return $series;
    }

    /**
     * The figures for the period, grouped by target, action or model.
     *
     * @param string $by One of the BY_ constants.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to requests one payer covered, or null for all.
     * @return \stdClass[] Rows, busiest first.
     */
    public function get_breakdown(
        string $by,
        int $from,
        int $to,
        ?int $courseid = null,
        ?string $keysource = null,
    ): array {
        if (!isset(self::GROUPS[$by])) {
            throw new \coding_exception('Unknown breakdown: ' . $by);
        }
        $fields = self::GROUPS[$by];
        $boundary = $this->get_boundary();

        $rows = [];
        if ($from < $boundary) {
            $this->collect($rows, $fields, $this->summarised(
                $fields,
                'daystart >= :from AND daystart < :to',
                ['from' => $from, 'to' => min($to, $boundary)],
                $courseid,
                $keysource,
            ));
        }
        if ($to > $boundary) {
            $this->collect($rows, $fields, $this->detailed(
                $fields,
                'timecreated >= :from AND timecreated < :to',
                ['from' => max($from, $boundary), 'to' => $to],
                $courseid,
                $keysource,
            ));
        }

        uasort($rows, fn($a, $b) => $b->requests <=> $a->requests);

        return array_values($rows);
    }

    /**
     * Everything in the period added together.
     *
     * @param \stdClass[] $rows Rows from any of the reports above.
     * @return \stdClass The totals.
     */
    public static function total(array $rows): \stdClass {
        $total = new \stdClass();
        foreach (self::METRICS as $metric) {
            $total->$metric = $metric === 'cost' ? null : 0;
        }
        foreach ($rows as $row) {
            foreach (self::METRICS as $metric) {
                $total->$metric = self::add($total->$metric, $row->$metric ?? null);
            }
        }

        return $total;
    }

    /**
     * How many requests failed for each reason.
     *
     * Only the detail rows carry a reason, so this covers the period the detail reaches
     * back to and no further. That is the right trade: reasons are for working out what
     * is wrong with a site now, not for a report on the year.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to requests one payer covered, or null for all.
     * @return \stdClass[] Rows of reason and count, commonest first.
     */
    public function get_failure_reasons(
        int $from,
        int $to,
        ?int $courseid = null,
        ?string $keysource = null,
    ): array {
        $where = 'success = 0 AND timecreated >= :from AND timecreated < :to';
        $params = ['from' => $from, 'to' => $to];
        [$where, $params] = $this->for_course($where, $params, $courseid);
        [$where, $params] = $this->for_keysource($where, $params, $keysource);

        return array_values($this->db->get_records_sql(
            'SELECT reason, COUNT(*) AS requests
               FROM {' . usage_logger::TABLE . '}
              WHERE ' . $where . '
           GROUP BY reason
           ORDER BY COUNT(*) DESC',
            $params,
        ));
    }

    /**
     * How much of the site's AI use went through the router at all.
     *
     * Read from core's own register, where the provider column holds the component of
     * whichever provider the manager called. Counting by that column answers the
     * question without matching individual rows, which the two logs cannot reliably do:
     * core records when the action was created and this plugin records when it finished.
     *
     * A low figure means requests are reaching another provider before the router does,
     * which is a matter of the site's provider order.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass|null Total, routed and the share, or null when core keeps no register.
     */
    public function get_passthrough(int $from, int $to): ?\stdClass {
        if (!$this->db->get_manager()->table_exists('ai_action_register')) {
            return null;
        }

        $counts = $this->db->get_records_sql(
            'SELECT provider, COUNT(*) AS requests
               FROM {ai_action_register}
              WHERE timecreated >= :from AND timecreated < :to
           GROUP BY provider',
            ['from' => $from, 'to' => $to],
        );

        $total = 0;
        foreach ($counts as $row) {
            $total += (int) $row->requests;
        }
        $routed = isset($counts['aiprovider_router']) ? (int) $counts['aiprovider_router']->requests : 0;

        return (object) [
            'total' => $total,
            'routed' => $routed,
            'share' => $total > 0 ? $routed / $total : null,
        ];
    }

    /**
     * A row with nothing in it, for a day where nothing happened.
     *
     * @return \stdClass The row.
     */
    protected function blank(): \stdClass {
        $row = new \stdClass();
        foreach (self::METRICS as $metric) {
            $row->$metric = $metric === 'cost' ? null : 0;
        }

        return $row;
    }

    /**
     * Read grouped figures out of the summaries.
     *
     * @param string[] $fields The columns to group on.
     * @param string $where The period clause.
     * @param array $params Its parameters.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to requests one payer covered, or null for all.
     * @return \stdClass[] The rows.
     */
    protected function summarised(
        array $fields,
        string $where,
        array $params,
        ?int $courseid,
        ?string $keysource = null,
    ): array {
        [$where, $params] = $this->for_course($where, $params, $courseid);
        [$where, $params] = $this->for_keysource($where, $params, $keysource);
        $select = $fields ? implode(', ', $fields) . ',' : '';
        $group = $fields ? ' GROUP BY ' . implode(', ', $fields) : '';

        return $this->db->get_records_sql(
            'SELECT ' . $select . '
                    SUM(requests) AS requests,
                    SUM(failures) AS failures,
                    SUM(prompttokens) AS prompttokens,
                    SUM(completiontokens) AS completiontokens,
                    SUM(cost) AS cost,
                    SUM(costedrequests) AS costedrequests
               FROM {' . usage_aggregator::TABLE . '}
              WHERE ' . $where . $group,
            $params,
        );
    }

    /**
     * Read the same grouped figures out of the detail rows.
     *
     * @param string[] $fields The columns to group on.
     * @param string $where The period clause.
     * @param array $params Its parameters.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to requests one payer covered, or null for all.
     * @return \stdClass[] The rows.
     */
    protected function detailed(
        array $fields,
        string $where,
        array $params,
        ?int $courseid,
        ?string $keysource = null,
    ): array {
        [$where, $params] = $this->for_course($where, $params, $courseid);
        [$where, $params] = $this->for_keysource($where, $params, $keysource);
        $select = $fields ? implode(', ', $fields) . ',' : '';
        $group = $fields ? ' GROUP BY ' . implode(', ', $fields) : '';

        return $this->db->get_records_sql(
            'SELECT ' . $select . '
                    COUNT(*) AS requests,
                    SUM(CASE WHEN success = 1 THEN 0 ELSE 1 END) AS failures,
                    SUM(CASE WHEN prompttokens IS NULL THEN 0 ELSE prompttokens END) AS prompttokens,
                    SUM(CASE WHEN completiontokens IS NULL THEN 0 ELSE completiontokens END) AS completiontokens,
                    SUM(cost) AS cost,
                    SUM(CASE WHEN cost IS NULL THEN 0 ELSE 1 END) AS costedrequests
               FROM {' . usage_logger::TABLE . '}
              WHERE ' . $where . $group,
            $params,
        );
    }

    /**
     * Narrow a query to one course.
     *
     * @param string $where The clause so far.
     * @param array $params Its parameters.
     * @param int|null $courseid The course, or null for the whole site.
     * @return array The clause and parameters.
     */
    protected function for_course(string $where, array $params, ?int $courseid): array {
        if ($courseid !== null) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        return [$where, $params];
    }

    /**
     * Narrow a query to the requests one payer covered.
     *
     * Both tables carry the column, so the same clause works on either side of the point
     * where the summaries hand over to the detail.
     *
     * @param string $where The clause so far.
     * @param array $params Its parameters.
     * @param string|null $keysource The payer, or null for all of them.
     * @return array The clause and parameters.
     */
    protected function for_keysource(string $where, array $params, ?string $keysource): array {
        if ($keysource !== null && $keysource !== self::KEYSOURCE_ALL) {
            $where .= ' AND keysource = :keysource';
            $params['keysource'] = $keysource;
        }

        return [$where, $params];
    }

    /**
     * Add a set of rows into the figures gathered so far.
     *
     * The same target appears once from the summaries and once from the detail whenever
     * a period spans the point where one hands over to the other, and the two have to
     * become one row.
     *
     * @param array $rows The figures so far, by key. Modified in place.
     * @param string[] $fields The columns being grouped on.
     * @param \stdClass[] $found The rows to add.
     */
    protected function collect(array &$rows, array $fields, array $found): void {
        foreach ($found as $row) {
            $row = $this->normalise($row);
            $key = [];
            foreach ($fields as $field) {
                $key[] = $row->$field === null ? "\0" : (string) $row->$field;
            }
            $key = implode('|', $key);

            if (!isset($rows[$key])) {
                $rows[$key] = $this->blank();
                foreach ($fields as $field) {
                    $rows[$key]->$field = $row->$field;
                }
            }
            foreach (self::METRICS as $metric) {
                $rows[$key]->$metric = self::add($rows[$key]->$metric, $row->$metric);
            }
        }
    }

    /**
     * Give a row from the database the types the rest of the report expects.
     *
     * Counts come back as strings from some drivers, and an aggregate over no rows comes
     * back as nulls rather than zeros. Costs keep their null, because a request no rate
     * covered has no cost rather than a cost of nothing.
     *
     * @param \stdClass $row The row as the database returned it.
     * @return \stdClass The row, with numbers in it.
     */
    protected function normalise(\stdClass $row): \stdClass {
        $out = clone $row;
        foreach (self::METRICS as $metric) {
            $value = $row->$metric ?? null;
            $out->$metric = $metric === 'cost'
                ? ($value === null ? null : (float) $value)
                : (int) $value;
        }

        return $out;
    }

    /**
     * Add two figures, where null means "not known" rather than zero.
     *
     * Costs are the reason. A period no rate covered has no cost at all, and reporting
     * that as zero would say the requests were free.
     *
     * @param int|float|null $carried What has been added up so far.
     * @param int|float|null $addition What to add.
     * @return int|float|null The total.
     */
    protected static function add(int|float|null $carried, int|float|null $addition): int|float|null {
        if ($addition === null) {
            return $carried;
        }

        return ($carried ?? 0) + $addition;
    }
}
