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

use local_airouter\price_book;

/**
 * Reads the requests and attempts as one history: the summary for what has been
 * counted, the detail for what has not.
 *
 * Every fact is in exactly one of the two. A request or attempt that has been added
 * into the summary is marked applied and is not read from the detail; one that has
 * not is read from the detail whatever day it belongs to, today or a day the summary
 * has long since covered. There is no boundary day and nothing to line up: the mark
 * on the row is the whole of the contract, and a fact that has ended is counted once.
 *
 * A request or attempt that has not ended is not counted at all. What it will have
 * cost is not known yet, and a total that guessed would have to be corrected later.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reader {
    /** @var string Break the figures down by delegation target. */
    public const BY_TARGET = 'target';

    /** @var string Break the figures down by action. */
    public const BY_ACTION = 'action';

    /** @var string Break the figures down by the model that answered. */
    public const BY_MODEL = 'model';

    /** @var string Break the figures down by who paid. */
    public const BY_KEYSOURCE = 'keysource';

    /** @var string Break the figures down by the person who asked. */
    public const BY_USER = 'user';

    /** @var string Every payer at once, as a filter value. */
    public const KEYSOURCE_ALL = 'all';

    /** @var array Which fields each breakdown groups on. */
    protected const GROUPS = [
        self::BY_TARGET => ['targetid', 'targetname'],
        self::BY_ACTION => ['actionname'],
        self::BY_MODEL => ['model'],
        self::BY_KEYSOURCE => ['keysource'],
        self::BY_USER => ['userid', 'keysource'],
    ];

    /**
     * The figures every row carries.
     *
     * Requests and calls are different counts: one request that fell through to a
     * second provider is one request and two calls, and the money is on the calls.
     * Of the calls, knowncalls is how many reported their tokens and costedcalls how
     * many had a rate; the token and cost totals are totals of those alone.
     *
     * @var string[]
     */
    public const METRICS = [
        'requests', 'failures', 'calls', 'knowncalls', 'prompttokens', 'completiontokens', 'cost', 'costedcalls',
    ];

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to read.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
    ) {
    }

    /**
     * How far back the detail still goes.
     *
     * Worth showing next to anything drawn from the detail alone, such as the failure
     * reasons, which say nothing about the days the purge has removed.
     *
     * @return int|null The earliest request still recorded, or null when there is none.
     */
    public function get_detail_from(): ?int {
        $earliest = $this->db->get_field_sql('SELECT MIN(timestarted) FROM {' . usage_recorder::REQUEST_TABLE . '}');

        return empty($earliest) ? null : (int) $earliest;
    }

    /**
     * One row per day in the period, whether or not anything happened that day.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @return \stdClass[] Rows keyed by the midnight of their day, oldest first.
     */
    public function get_series(int $from, int $to, ?int $courseid = null, ?string $keysource = null): array {
        $series = [];
        $lastday = summariser::day_of($to);
        for ($day = summariser::day_of($from); $day <= $lastday; $day = summariser::add_days($day, 1)) {
            $series[$day] = self::blank();
        }

        foreach ($this->summarised(['daystart'], $from, $to, $courseid, $keysource) as $row) {
            // Which day a stored figure belongs to is worked out again rather than taken
            // as the key it was written under: a site that changes its timezone has rows
            // stamped to a midnight that no longer exists, and two old days can land in
            // one new one, so they are added rather than one replacing the other.
            $day = summariser::day_of((int) $row->daystart);
            if (isset($series[$day])) {
                $series[$day] = self::total([$series[$day], $row]);
            }
        }
        foreach ($this->detailed(['day'], $from, $to, $courseid, $keysource) as $row) {
            if (isset($series[$row->day])) {
                $series[$row->day] = self::total([$series[$row->day], $row]);
            }
        }

        return $series;
    }

    /**
     * The figures broken down one way, largest first.
     *
     * @param string $by One of the BY_ constants.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @return \stdClass[] Rows carrying the group fields and the metrics.
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
        $rows = [];
        $this->collect($rows, $fields, $this->summarised($fields, $from, $to, $courseid, $keysource));
        $this->collect($rows, $fields, $this->detailed($fields, $from, $to, $courseid, $keysource));
        uasort($rows, fn($a, $b) => $b->requests <=> $a->requests);

        return array_values($rows);
    }

    /**
     * The one currency the period's money is in, if it is in one.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @return string|null The currency, or null when the period mixes two.
     */
    public function currency_for(int $from, int $to, ?int $courseid = null, ?string $keysource = null): ?string {
        $found = $this->get_currencies($from, $to, $courseid, $keysource);

        return match (count($found)) {
            // Nothing in the period was priced, so the site's own currency is as good
            // an answer as any and the figures will all read "not known" anyway.
            0 => price_book::get_currency(),
            1 => (string) reset($found),
            default => null,
        };
    }

    /**
     * The currencies any money in the period is in.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @return string[] The currencies, in no particular order.
     */
    public function get_currencies(int $from, int $to, ?int $courseid = null, ?string $keysource = null): array {
        $found = [];
        foreach ($this->summarised(['currency'], $from, $to, $courseid, $keysource) as $row) {
            if ((int) $row->costedcalls > 0 && $row->currency !== '-') {
                $found[$row->currency] = true;
            }
        }
        foreach ($this->detailed(['currency'], $from, $to, $courseid, $keysource) as $row) {
            if ((int) $row->costedcalls > 0 && $row->currency !== null && $row->currency !== '-') {
                $found[$row->currency] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * How many requests did not succeed, for each reason, largest first.
     *
     * From the detail alone: the summary does not keep reasons. So this speaks only
     * for the days the detail still covers, and get_detail_from() says which those are.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @return \stdClass[] Rows of reason and requests.
     */
    public function get_failure_reasons(
        int $from,
        int $to,
        ?int $courseid = null,
        ?string $keysource = null,
    ): array {
        $where = 'state <> :open AND state <> :succeeded AND timeended >= :from AND timeended < :to';
        $params = ['open' => request_state::OPEN, 'succeeded' => request_state::SUCCEEDED, 'from' => $from, 'to' => $to];
        if ($courseid !== null) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($keysource !== null && $keysource !== self::KEYSOURCE_ALL) {
            $where .= ' AND keysource = :keysource';
            $params['keysource'] = $keysource;
        }

        return array_values($this->db->get_records_sql(
            'SELECT reason, COUNT(*) AS requests
               FROM {' . usage_recorder::REQUEST_TABLE . '}
              WHERE ' . $where . '
           GROUP BY reason
           ORDER BY COUNT(*) DESC',
            $params,
        ));
    }

    /**
     * How much of the site's AI traffic went through the router at all, by core's count.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass|null total, routed and share, or null where core keeps no register.
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
        $routed = isset($counts['local_airouter']) ? (int) $counts['local_airouter']->requests : 0;

        return (object) [
            'total' => $total,
            'routed' => $routed,
            'share' => $total > 0 ? $routed / $total : null,
        ];
    }

    /**
     * Add rows up.
     *
     * A cost that nothing priced is null, and stays null however many such rows are
     * added: a total of nothing known is not zero.
     *
     * @param \stdClass[] $rows The rows.
     * @return \stdClass One row of totals.
     */
    public static function total(array $rows): \stdClass {
        $total = self::blank();
        foreach ($rows as $row) {
            foreach (self::METRICS as $metric) {
                $total->$metric = self::add($total->$metric, $row->$metric ?? null);
            }
        }

        return $total;
    }

    /**
     * A row of nothing.
     *
     * @return \stdClass The row.
     */
    public static function blank(): \stdClass {
        $row = new \stdClass();
        foreach (self::METRICS as $metric) {
            $row->$metric = $metric === 'cost' ? null : 0;
        }

        return $row;
    }

    /**
     * The summary, grouped, for a period.
     *
     * @param string[] $fields Summary columns to group on.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @return \stdClass[] Normalised rows carrying the group fields and the metrics.
     */
    protected function summarised(array $fields, int $from, int $to, ?int $courseid, ?string $keysource): array {
        $where = 'daystart >= :from AND daystart < :to';
        $params = ['from' => $from, 'to' => $to];
        if ($courseid !== null) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($keysource !== null && $keysource !== self::KEYSOURCE_ALL) {
            $where .= ' AND keysource = :keysource';
            $params['keysource'] = $keysource;
        }
        // The currency has to be part of every grouping: money in two currencies is
        // never one figure, and a row that mixed them could not be read.
        $groups = array_values(array_unique(array_merge($fields, ['currency'])));
        $select = implode(', ', $groups);
        // The name a target carries is the same on every row of a target, so any of
        // them will do; grouping on it as well would only split rows on a rename.
        if (in_array('targetname', $groups, true)) {
            $groups = array_values(array_diff($groups, ['targetname']));
            $select = implode(', ', $groups) . ', MIN(targetname) AS targetname';
        }
        // A recordset, because the first column of a grouped query is not unique and
        // get_records_sql() would drop rows that share it.
        $recordset = $this->db->get_recordset_sql(
            'SELECT ' . $select . ',
                    SUM(requests) AS requests, SUM(failures) AS failures,
                    SUM(calls) AS calls, SUM(knowncalls) AS knowncalls,
                    SUM(prompttokens) AS prompttokens, SUM(completiontokens) AS completiontokens,
                    SUM(cost) AS cost, SUM(costedcalls) AS costedcalls
               FROM {' . summariser::TABLE . '}
              WHERE ' . $where . '
           GROUP BY ' . implode(', ', $groups),
            $params,
        );
        $rows = [];
        foreach ($recordset as $row) {
            $rows[] = self::normalise($row);
        }
        $recordset->close();

        return $rows;
    }

    /**
     * The detail not yet in the summary, grouped, for a period.
     *
     * Read row by row and added up here: what has not been applied is at most a day
     * or so of traffic plus whatever ended late, and the day a fact belongs to is a
     * timezone calculation the database is not asked to make.
     *
     * @param string[] $fields Fields to group on; day for the day the fact ended.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @return \stdClass[] Normalised rows carrying the group fields and the metrics.
     */
    protected function detailed(array $fields, int $from, int $to, ?int $courseid, ?string $keysource): array {
        $params = ['from' => $from, 'to' => $to];
        $rwhere = 'r.applied = 0 AND r.state <> :open AND r.timeended >= :from AND r.timeended < :to';
        $awhere = 'a.applied = 0 AND a.state <> :started AND a.timeended >= :from AND a.timeended < :to';
        if ($courseid !== null) {
            $rwhere .= ' AND r.courseid = :courseid';
            $awhere .= ' AND r.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($keysource !== null && $keysource !== self::KEYSOURCE_ALL) {
            $rwhere .= ' AND r.keysource = :keysource';
            $awhere .= ' AND a.keysource = :keysource';
            $params['keysource'] = $keysource;
        }

        $facts = [];
        // A request sits with the target and model that answered it, as in the summary.
        $requests = $this->db->get_records_sql(
            'SELECT r.id, r.userid, r.courseid, r.actionname, r.keysource, r.state, r.timeended,
                    r.answeredby AS targetid, s.targetname, s.model
               FROM {' . usage_recorder::REQUEST_TABLE . '} r
          LEFT JOIN {' . usage_recorder::ATTEMPT_TABLE . '} s ON s.requestid = r.id AND s.state = :succeeded
              WHERE ' . $rwhere,
            $params + ['open' => request_state::OPEN, 'succeeded' => attempt_state::SUCCEEDED],
        );
        foreach ($requests as $request) {
            $facts[] = (object) [
                'day' => summariser::day_of((int) $request->timeended),
                'userid' => (int) $request->userid,
                'courseid' => (int) ($request->courseid ?? 0),
                'actionname' => $request->actionname,
                'targetid' => (int) ($request->targetid ?? 0),
                'targetname' => $request->targetname,
                'model' => $request->model,
                'keysource' => $request->keysource,
                'currency' => '-',
                'requests' => 1,
                'failures' => $request->state === request_state::SUCCEEDED ? 0 : 1,
                'calls' => 0, 'knowncalls' => 0, 'prompttokens' => 0, 'completiontokens' => 0,
                'cost' => null, 'costedcalls' => 0,
            ];
        }
        $attempts = $this->db->get_records_sql(
            'SELECT a.id, r.userid, r.courseid, r.actionname, a.targetid, a.targetname, a.model, a.keysource,
                    a.currency, a.usageknown, a.prompttokens, a.completiontokens, a.cost, a.timeended
               FROM {' . usage_recorder::ATTEMPT_TABLE . '} a
               JOIN {' . usage_recorder::REQUEST_TABLE . '} r ON r.id = a.requestid
              WHERE ' . $awhere,
            $params + ['started' => attempt_state::STARTED],
        );
        foreach ($attempts as $attempt) {
            $known = (int) $attempt->usageknown === 1;
            $facts[] = (object) [
                'day' => summariser::day_of((int) $attempt->timeended),
                'userid' => (int) $attempt->userid,
                'courseid' => (int) ($attempt->courseid ?? 0),
                'actionname' => $attempt->actionname,
                'targetid' => (int) $attempt->targetid,
                'targetname' => $attempt->targetname,
                'model' => $attempt->model,
                'keysource' => $attempt->keysource,
                'currency' => $attempt->currency ?? '-',
                'requests' => 0, 'failures' => 0,
                'calls' => 1,
                'knowncalls' => $known ? 1 : 0,
                'prompttokens' => $known ? (int) $attempt->prompttokens : 0,
                'completiontokens' => $known ? (int) $attempt->completiontokens : 0,
                'cost' => $attempt->cost === null ? null : (float) $attempt->cost,
                'costedcalls' => $attempt->cost === null ? 0 : 1,
            ];
        }

        $rows = [];
        $this->collect($rows, array_values(array_unique(array_merge($fields, ['currency']))), $facts);

        return array_values($rows);
    }

    /**
     * Add rows into groups.
     *
     * @param array $rows The groups so far, by key.
     * @param string[] $fields The fields that make a group.
     * @param \stdClass[] $found Rows to add.
     */
    protected function collect(array &$rows, array $fields, array $found): void {
        foreach ($found as $row) {
            $key = [];
            foreach ($fields as $field) {
                $key[] = ($row->$field ?? null) === null ? "\0" : (string) $row->$field;
            }
            $key = implode('|', $key);
            if (!isset($rows[$key])) {
                $rows[$key] = self::blank();
                foreach ($fields as $field) {
                    $rows[$key]->$field = $row->$field ?? null;
                }
            }
            foreach (self::METRICS as $metric) {
                $rows[$key]->$metric = self::add($rows[$key]->$metric, $row->$metric ?? null);
            }
        }
    }

    /**
     * The metrics as numbers, with an unpriced cost as null rather than as zero.
     *
     * @param \stdClass $row A row from the summary.
     * @return \stdClass The row, normalised.
     */
    protected static function normalise(\stdClass $row): \stdClass {
        $out = clone $row;
        foreach (self::METRICS as $metric) {
            $out->$metric = (int) ($row->$metric ?? 0);
        }
        $out->cost = (int) $out->costedcalls > 0 ? (float) $row->cost : null;
        if (isset($out->model) && $out->model === '-') {
            $out->model = null;
        }

        return $out;
    }

    /**
     * Add two figures, keeping an unknown unknown.
     *
     * @param int|float|null $carried What there was.
     * @param int|float|null $addition What to add.
     * @return int|float|null The sum, or null when neither was known.
     */
    protected static function add(int|float|null $carried, int|float|null $addition): int|float|null {
        if ($addition === null) {
            return $carried;
        }

        return ($carried ?? 0) + $addition;
    }
}
