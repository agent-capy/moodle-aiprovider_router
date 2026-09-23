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
 * The summary and the detail are two tables read in two statements, and the
 * summariser can commit between them: a fact that was in neither the summary just
 * read nor the detail read a moment later has been counted nowhere. So every read
 * that pairs the two notes the summary's generation before and after, and reads
 * again when it moved. A database snapshot would do the same on some databases and
 * not on others -- PostgreSQL gives each statement its own view unless told
 * otherwise -- and this works the same way everywhere.
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

    /** @var string Break the figures down by the provider plugin behind the target. */
    public const BY_PROVIDER = 'provider';

    /** @var string Every payer at once, as a filter value. */
    public const KEYSOURCE_ALL = 'all';

    /** @var array Which fields each breakdown groups on. */
    protected const GROUPS = [
        self::BY_TARGET => ['targetid', 'targetname'],
        self::BY_ACTION => ['actionname'],
        self::BY_MODEL => ['model'],
        self::BY_KEYSOURCE => ['keysource'],
        self::BY_USER => ['userid', 'keysource'],
        self::BY_PROVIDER => ['targetprovider'],
    ];

    /**
     * The counts every row carries.
     *
     * Requests and calls are different counts: one request that fell through to a
     * second provider is one request and two calls, and the money is on the calls.
     * Of the calls, knowncalls is how many reported their tokens and costedcalls how
     * many had a rate; the token totals and the money are totals of those alone.
     *
     * The money is not here, because it is not one number. Every row carries costs:
     * one entry per provider, each an amount in the currency that provider bills in,
     * present only when at least one call to that provider was priced. A known zero
     * is a zero in there, and nothing priced is an empty array. Money is never added
     * across providers, not even when two bill in the same currency: whether a site's
     * budget is one figure or one per provider is the site's to decide, so the
     * figures are laid side by side for it to add or not. For the common case a row
     * also carries cost, currency and provider, which are the one entry's when the
     * row holds exactly one, and null otherwise; they are worked out from costs and
     * never the other way round.
     *
     * @var string[]
     */
    public const METRICS = [
        'requests', 'failures', 'calls', 'knowncalls', 'prompttokens', 'completiontokens', 'costedcalls',
    ];

    /** @var int How many times a read is taken again when the summary keeps moving under it. */
    public const MAX_READS = 5;

    /** @var bool Whether the last paired read came back the same way twice, and so can be kept. */
    protected bool $consistent = true;

    /** @var int|null The generation the last paired read belongs to, or null when it belongs to none. */
    protected ?int $generation = null;

    /** @var int|null The generation the pass under way began in, for what the pass reads to be filed by. */
    protected ?int $passgeneration = null;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to read.
     * @param \Closure|null $stop Called with the name of each point a read passes. Tests
     *                            use it to commit something on another connection in
     *                            between the summary and the detail; nothing else does.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var \Closure|null The stop hook. */
        protected readonly ?\Closure $stop = null,
    ) {
    }

    /**
     * Whether the last paired read is one to keep.
     *
     * False only when the summary changed under every one of MAX_READS reads, which
     * a summariser running once a day does not do; then the figures are returned
     * rather than nothing, but a cache should not hold them.
     *
     * @return bool True when the summary did not move during the read.
     */
    public function was_consistent(): bool {
        return $this->consistent;
    }

    /**
     * Which state of the record the last paired read is of.
     *
     * Whatever is kept of the read beyond the moment -- a cache entry -- is to be kept
     * under this, so that it is not taken for the record once the record has moved on,
     * however late it is stored.
     *
     * @return int|null The generation the read began and ended in, or null when it did
     *                  not end in the one it began in.
     */
    public function get_read_generation(): ?int {
        return $this->generation;
    }

    /**
     * Read the summary and the detail as one.
     *
     * The generation is read from the database before and after, and the two reads
     * are taken again when it moved. A read that straddles a summariser commit
     * would otherwise miss every fact that commit applied: not yet in the summary
     * when the summary was read, no longer unapplied when the detail was read.
     *
     * @param \Closure $read Runs the paired reads and returns what they found.
     * @return mixed What the reads found, from a pass the summary did not move under.
     */
    protected function consistently(\Closure $read): mixed {
        $this->consistent = true;
        for ($pass = 1; $pass <= self::MAX_READS; $pass++) {
            $before = generation::get($this->db);
            $this->passgeneration = $before;
            $result = $read();
            if (generation::get($this->db) === $before) {
                $this->generation = $before;

                return $result;
            }
        }
        $this->consistent = false;
        $this->generation = null;

        return $result;
    }

    /**
     * Let a test hold a read at a point.
     *
     * @param string $point Which point was reached.
     */
    protected function at(string $point): void {
        if ($this->stop !== null) {
            ($this->stop)($point);
        }
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

        [$summary, $detail] = $this->consistently(fn() => [
            $this->summarised(['daystart'], $from, $to, $courseid, $keysource),
            $this->detailed(['day'], $from, $to, $courseid, $keysource),
        ]);
        foreach ($summary as $row) {
            // Which day a stored figure belongs to is worked out again rather than taken
            // as the key it was written under: a site that changes its timezone has rows
            // stamped to a midnight that no longer exists, and two old days can land in
            // one new one, so they are added rather than one replacing the other.
            $day = summariser::day_of((int) $row->daystart);
            if (isset($series[$day])) {
                $series[$day] = self::total([$series[$day], $row]);
            }
        }
        foreach ($detail as $row) {
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
        [$summary, $detail] = $this->consistently(fn() => [
            $this->summarised($fields, $from, $to, $courseid, $keysource),
            $this->detailed($fields, $from, $to, $courseid, $keysource),
        ]);
        $rows = [];
        $this->collect($rows, $fields, $summary);
        $this->collect($rows, $fields, $detail);
        // Busiest first, and among equals by their key: the database hands grouped
        // rows out in no particular order, and the same figures should read the same
        // way before and after they have been summarised.
        $requests = array_map(fn($row) => (int) $row->requests, $rows);
        uksort($rows, fn($a, $b) => ($requests[$b] <=> $requests[$a]) ?: strcmp((string) $a, (string) $b));

        return array_values($rows);
    }

    /**
     * The money in the period, by provider.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @return array[] Entries of provider, currency and amount, as a row's costs.
     */
    public function get_money(int $from, int $to, ?int $courseid = null, ?string $keysource = null): array {
        $rows = $this->consistently(fn() => array_merge(
            $this->summarised(['targetprovider'], $from, $to, $courseid, $keysource),
            $this->detailed(['targetprovider'], $from, $to, $courseid, $keysource),
        ));

        return self::total($rows)->costs;
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
     * Money is added within each currency and never across them. A currency that no
     * row was priced in stays absent, so a total of nothing known is not zero.
     *
     * @param \stdClass[] $rows The rows.
     * @return \stdClass One row of totals.
     */
    public static function total(array $rows): \stdClass {
        $total = self::blank();
        foreach ($rows as $row) {
            foreach (self::METRICS as $metric) {
                $total->$metric += (int) ($row->$metric ?? 0);
            }
            $total->costs = self::add_costs($total->costs, $row->costs ?? []);
        }
        self::settle($total);

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
            $row->$metric = 0;
        }
        $row->costs = [];
        self::settle($row);

        return $row;
    }

    /**
     * One entry of a row's money: what a provider was paid, in its currency.
     *
     * @param string $provider The provider component.
     * @param string $currency The currency the amount is in.
     * @param float $amount The amount.
     * @return array The entry.
     */
    public static function money(string $provider, string $currency, float $amount): array {
        return [self::money_key($provider, $currency) => [
            'provider' => $provider,
            'currency' => $currency,
            'amount' => $amount,
        ]];
    }

    /**
     * The key an entry of money sits under in a row's costs.
     *
     * Provider and currency both, so that should a provider's rows ever disagree
     * about its currency the two are shown apart rather than added.
     *
     * @param string $provider The provider component.
     * @param string $currency The currency.
     * @return string The key.
     */
    public static function money_key(string $provider, string $currency): string {
        return $provider . '|' . $currency;
    }

    /**
     * Add money to money, provider by provider.
     *
     * @param array[] $carried Entries of money, by key.
     * @param array[] $addition Entries of money to add, by key.
     * @return array[] Entries of money, by key, sorted by key.
     */
    public static function add_costs(array $carried, array $addition): array {
        foreach ($addition as $key => $entry) {
            if (isset($carried[$key])) {
                $carried[$key]['amount'] += (float) $entry['amount'];
            } else {
                $carried[$key] = $entry;
            }
        }
        ksort($carried);

        return $carried;
    }

    /**
     * Give a row the one figure view of its money, where it has one.
     *
     * @param \stdClass $row A row carrying costs.
     */
    protected static function settle(\stdClass $row): void {
        $costs = $row->costs ?? [];
        $entry = count($costs) === 1 ? reset($costs) : null;
        $row->provider = $entry === null ? null : (string) $entry['provider'];
        $row->currency = $entry === null ? null : (string) $entry['currency'];
        $row->cost = $entry === null ? null : (float) $entry['amount'];
    }

    /**
     * The summary, grouped, for a period.
     *
     * @param string[] $fields Summary columns to group on.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @param int|null $userid Limit to one person, or null for everybody.
     * @param int|null $targetid Limit to one delegation target, or null for all.
     * @param int|null $walletid Limit to what one wallet paid for, or null for all.
     * @return \stdClass[] Normalised rows carrying the group fields and the metrics.
     */
    protected function summarised(
        array $fields,
        int $from,
        int $to,
        ?int $courseid,
        ?string $keysource,
        ?int $userid = null,
        ?int $targetid = null,
        ?int $walletid = null,
    ): array {
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
        if ($userid !== null) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $userid;
        }
        if ($targetid !== null) {
            $where .= ' AND targetid = :targetid';
            $params['targetid'] = $targetid;
        }
        if ($walletid !== null) {
            $where .= ' AND walletid = :walletid';
            $params['walletid'] = $walletid;
        }
        // The provider and its currency have to be part of every grouping: money is
        // one figure per provider, and a row that mixed two could not be read.
        $groups = array_values(array_unique(array_merge($fields, ['targetprovider', 'currency'])));
        $select = implode(', ', $groups);
        // When rows are grouped by target id, the name a target carries is the same on
        // every one of its rows, so any will do; grouping on the name as well would
        // only split a target on a rename. Asked for by name alone, the name is the group.
        if (in_array('targetname', $groups, true) && in_array('targetid', $groups, true)) {
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
        $this->at('summarised');

        return $rows;
    }

    /**
     * What picks out the detail not yet in the summary, for requests and for attempts.
     *
     * One place for it, so that the detail read row by row and the detail added up by
     * the database are the same detail.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @param int|null $userid Limit to one person, or null for everybody.
     * @param int|null $targetid Limit to one delegation target, or null for all.
     * @param int|null $walletid Limit to what one wallet paid for, or null for all.
     * @return array The condition on requests (r, with the answering attempt as s), the
     *               condition on attempts (a, with its request as r), and their parameters.
     */
    protected function detail_conditions(
        int $from,
        int $to,
        ?int $courseid,
        ?string $keysource,
        ?int $userid = null,
        ?int $targetid = null,
        ?int $walletid = null,
    ): array {
        $params = ['from' => $from, 'to' => $to];
        $rwhere = 'r.applied = 0 AND r.state <> :open AND r.timeended >= :from AND r.timeended < :to';
        $awhere = 'a.applied = 0 AND a.state <> :started AND a.timeended >= :from AND a.timeended < :to';
        if ($targetid !== null) {
            $rwhere .= ' AND r.answeredby = :targetid';
            $awhere .= ' AND a.targetid = :targetid';
            $params['targetid'] = $targetid;
        }
        if ($walletid !== null) {
            $rwhere .= ' AND s.walletid = :walletid';
            $awhere .= ' AND a.walletid = :walletid';
            $params['walletid'] = $walletid;
        }
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
        if ($userid !== null) {
            $rwhere .= ' AND r.userid = :userid';
            $awhere .= ' AND r.userid = :userid';
            $params['userid'] = $userid;
        }

        return [$rwhere, $awhere, $params];
    }

    /**
     * The detail not yet in the summary, added up by the database, provider by provider.
     *
     * The same figures detailed() gives when grouped on the provider alone, for the one
     * question asked on the path of a request: what a budget or a key has spent. There
     * is no day to work out, so nothing needs reading row by row, and a busy day's
     * detail comes back as a row or two per provider rather than as every call in it.
     *
     * The grouping is on columns and constants only: a grouped expression carrying a
     * parameter is not the same expression twice to every database.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @param int|null $userid Limit to one person, or null for everybody.
     * @param int|null $targetid Limit to one delegation target, or null for all.
     * @param int|null $walletid Limit to what one wallet paid for, or null for all.
     * @return \stdClass[] Rows carrying the provider, the currency and the metrics.
     */
    protected function detailed_by_provider(
        int $from,
        int $to,
        ?int $courseid,
        ?string $keysource,
        ?int $userid = null,
        ?int $targetid = null,
        ?int $walletid = null,
    ): array {
        [$rwhere, $awhere, $params] = $this->detail_conditions($from, $to, $courseid, $keysource, $userid, $targetid, $walletid);
        $params += [
            'open' => request_state::OPEN,
            'started' => attempt_state::STARTED,
            'succeeded' => attempt_state::SUCCEEDED,
            'answered' => request_state::SUCCEEDED,
        ];
        $rows = [];

        // A request sits with the provider that answered it, as in the summary.
        $recordset = $this->db->get_recordset_sql(
            'SELECT s.targetprovider, COUNT(1) AS requests,
                    SUM(CASE WHEN r.state = :answered THEN 0 ELSE 1 END) AS failures
               FROM {' . usage_recorder::REQUEST_TABLE . '} r
          LEFT JOIN {' . usage_recorder::ATTEMPT_TABLE . '} s ON s.requestid = r.id AND s.state = :succeeded
              WHERE ' . $rwhere . '
           GROUP BY s.targetprovider',
            $params,
        );
        foreach ($recordset as $group) {
            $row = self::blank();
            $row->targetprovider = $group->targetprovider ?? '-';
            $row->currency = '-';
            $row->requests = (int) $group->requests;
            $row->failures = (int) $group->failures;
            $rows[] = $row;
        }
        $recordset->close();

        $recordset = $this->db->get_recordset_sql(
            'SELECT a.targetprovider, a.currency, CASE WHEN a.cost IS NULL THEN 0 ELSE 1 END AS costed,
                    COUNT(1) AS calls, SUM(a.usageknown) AS knowncalls,
                    SUM(CASE WHEN a.usageknown = 1 THEN a.prompttokens ELSE 0 END) AS prompttokens,
                    SUM(CASE WHEN a.usageknown = 1 THEN a.completiontokens ELSE 0 END) AS completiontokens,
                    SUM(a.cost) AS cost
               FROM {' . usage_recorder::ATTEMPT_TABLE . '} a
               JOIN {' . usage_recorder::REQUEST_TABLE . '} r ON r.id = a.requestid
              WHERE ' . $awhere . '
           GROUP BY a.targetprovider, a.currency, CASE WHEN a.cost IS NULL THEN 0 ELSE 1 END',
            $params,
        );
        foreach ($recordset as $group) {
            $costed = (int) $group->costed === 1;
            $provider = (string) ($group->targetprovider ?? '-');
            $currency = $costed ? (string) ($group->currency ?? '-') : '-';
            $row = self::blank();
            $row->targetprovider = $provider;
            $row->currency = $currency;
            $row->calls = (int) $group->calls;
            $row->knowncalls = (int) $group->knowncalls;
            $row->prompttokens = (int) $group->prompttokens;
            $row->completiontokens = (int) $group->completiontokens;
            $row->costedcalls = $costed ? (int) $group->calls : 0;
            $row->costs = $costed ? self::money($provider, $currency, (float) $group->cost) : [];
            self::settle($row);
            $rows[] = $row;
        }
        $recordset->close();
        $this->at('detailed');

        return $rows;
    }

    /**
     * The detail not yet in the summary, grouped, for a period.
     *
     * Read row by row and added up here: what has not been applied is at most a day
     * or so of traffic plus whatever ended late, and the day a fact belongs to is a
     * timezone calculation the database is not asked to make.
     *
     * @param string[] $fields Fields to group on; day or daystart for the day the fact ended.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @param int|null $courseid Limit to one course, or null for the whole site.
     * @param string|null $keysource Limit to one payer, or null for all.
     * @param int|null $userid Limit to one person, or null for everybody.
     * @param int|null $targetid Limit to one delegation target, or null for all. A
     *                           request counts as that target's when it answered.
     * @param int|null $walletid Limit to what one wallet paid for, or null for all. A
     *                           request counts as a wallet's when a call it paid for
     *                           answered, as in the summary.
     * @return \stdClass[] Normalised rows carrying the group fields and the metrics.
     */
    protected function detailed(
        array $fields,
        int $from,
        int $to,
        ?int $courseid,
        ?string $keysource,
        ?int $userid = null,
        ?int $targetid = null,
        ?int $walletid = null,
    ): array {
        [$rwhere, $awhere, $params] = $this->detail_conditions($from, $to, $courseid, $keysource, $userid, $targetid, $walletid);

        $facts = [];
        // A request sits with the target and model that answered it, as in the summary.
        $requests = $this->db->get_records_sql(
            'SELECT r.id, r.userid, r.courseid, r.actionname, r.keysource, r.state, r.timeended,
                    r.answeredby AS targetid, s.targetname, s.targetprovider, s.model, s.walletid
               FROM {' . usage_recorder::REQUEST_TABLE . '} r
          LEFT JOIN {' . usage_recorder::ATTEMPT_TABLE . '} s ON s.requestid = r.id AND s.state = :succeeded
              WHERE ' . $rwhere,
            $params + ['open' => request_state::OPEN, 'succeeded' => attempt_state::SUCCEEDED],
        );
        foreach ($requests as $request) {
            $day = summariser::day_of((int) $request->timeended);
            $facts[] = (object) [
                'day' => $day,
                'daystart' => $day,
                'userid' => (int) $request->userid,
                'courseid' => (int) ($request->courseid ?? 0),
                'actionname' => $request->actionname,
                'targetid' => (int) ($request->targetid ?? 0),
                'targetname' => $request->targetname,
                'targetprovider' => $request->targetprovider ?? '-',
                'model' => $request->model,
                'keysource' => $request->keysource,
                'walletid' => (int) ($request->walletid ?? 0),
                'currency' => '-',
                'requests' => 1,
                'failures' => $request->state === request_state::SUCCEEDED ? 0 : 1,
                'calls' => 0, 'knowncalls' => 0, 'prompttokens' => 0, 'completiontokens' => 0,
                'costs' => [], 'costedcalls' => 0,
            ];
        }
        $attempts = $this->db->get_records_sql(
            'SELECT a.id, r.userid, r.courseid, r.actionname, a.targetid, a.targetname, a.targetprovider, a.model,
                    a.keysource, a.walletid, a.currency, a.usageknown, a.prompttokens, a.completiontokens, a.cost,
                    a.timeended
               FROM {' . usage_recorder::ATTEMPT_TABLE . '} a
               JOIN {' . usage_recorder::REQUEST_TABLE . '} r ON r.id = a.requestid
              WHERE ' . $awhere,
            $params + ['started' => attempt_state::STARTED],
        );
        foreach ($attempts as $attempt) {
            $known = (int) $attempt->usageknown === 1;
            $day = summariser::day_of((int) $attempt->timeended);
            $facts[] = (object) [
                'day' => $day,
                'daystart' => $day,
                'userid' => (int) $attempt->userid,
                'courseid' => (int) ($attempt->courseid ?? 0),
                'actionname' => $attempt->actionname,
                'targetid' => (int) $attempt->targetid,
                'targetname' => $attempt->targetname,
                'targetprovider' => $attempt->targetprovider ?? '-',
                'model' => $attempt->model,
                'keysource' => $attempt->keysource,
                'walletid' => (int) ($attempt->walletid ?? 0),
                'currency' => $attempt->cost === null ? '-' : ($attempt->currency ?? '-'),
                'requests' => 0, 'failures' => 0,
                'calls' => 1,
                'knowncalls' => $known ? 1 : 0,
                'prompttokens' => $known ? (int) $attempt->prompttokens : 0,
                'completiontokens' => $known ? (int) $attempt->completiontokens : 0,
                'costs' => $attempt->cost === null ? [] : self::money(
                    (string) ($attempt->targetprovider ?? '-'),
                    (string) ($attempt->currency ?? '-'),
                    (float) $attempt->cost,
                ),
                'costedcalls' => $attempt->cost === null ? 0 : 1,
            ];
        }

        $rows = [];
        $this->collect($rows, array_values(array_unique(array_merge($fields, ['targetprovider', 'currency']))), $facts);
        $this->at('detailed');

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
                $rows[$key]->$metric += (int) ($row->$metric ?? 0);
            }
            $rows[$key]->costs = self::add_costs($rows[$key]->costs, $row->costs ?? []);
        }
        foreach ($rows as $row) {
            self::settle($row);
        }
    }

    /**
     * A summary row as the reader hands rows out: counts as integers, money by currency.
     *
     * A summary row belongs to one provider and is in one currency, since both are
     * part of its key, and carries money only when something on it was priced.
     *
     * @param \stdClass $row A row from the summary.
     * @return \stdClass The row, normalised.
     */
    protected static function normalise(\stdClass $row): \stdClass {
        $out = clone $row;
        foreach (self::METRICS as $metric) {
            $out->$metric = (int) ($row->$metric ?? 0);
        }
        $out->costs = $out->costedcalls > 0 && (string) ($row->currency ?? '-') !== '-'
            ? self::money((string) ($row->targetprovider ?? '-'), (string) $row->currency, (float) $row->cost)
            : [];
        if (isset($out->model) && $out->model === '-') {
            $out->model = null;
        }
        self::settle($out);

        return $out;
    }
}
