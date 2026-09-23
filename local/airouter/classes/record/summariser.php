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

use local_airouter\usage_aggregator;

/**
 * Adds each finished request and attempt into its day, once, and marks it done.
 *
 * There is no watermark and no notion of a day being finished. Whatever has ended and
 * has not been applied is added into the day it ended in, whichever day that is, and
 * marked applied in the same transaction. A run that stops half way applies nothing
 * twice and nothing half; a fact whose ending arrives late is counted when it arrives;
 * what has been counted is a fact on the row and not a setting that has to be read.
 *
 * The same object closes what was left open for too long and purges what has been
 * counted and is past keeping. Nothing is purged that has not been counted, however
 * old it is: a row that has not been summarised is not old, it is unfinished.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summariser {
    /** @var string The summary table. */
    public const TABLE = 'local_airouter_summary';

    /** @var string The lock one run holds, so that two cannot overlap. */
    public const LOCK = 'summarise';

    /**
     * @var int How long an attempt may stay started before it is given up as lost.
     *
     * Longer than any AI call this plugin makes, by a wide margin. A call is made
     * within one request, and a request is over well within an hour; six hours of
     * silence means the process did not live to write the ending.
     */
    public const STALE_AFTER = 6 * HOURSECS;

    /** @var string The reason written on a request given up on. */
    public const REASON_LOST = 'lost';

    /** @var string Config holding the first moment this site can still account for. Shared with the older record. */
    public const HISTORY_SETTING = 'historyfrom';

    /**
     * @var string Config counting how many times the summary has changed.
     *
     * Bumped inside the transaction that applies facts, and after a purge, so that a
     * reader which read the summary and then the detail can tell whether anything
     * moved between the two, and read again if it did.
     */
    public const GENERATION_SETTING = 'summarygeneration';

    /** @var int How many ids one marking statement carries. */
    protected const CHUNK = 500;

    /** @var string[] The columns that make a summary row's natural key. */
    public const KEY = [
        'daystart', 'userid', 'courseid', 'actionname', 'targetprovider', 'targetid', 'model', 'keysource', 'currency',
    ];

    /** @var string[] The columns a fact can add to. */
    public const COUNTERS = [
        'requests', 'failures', 'calls', 'knowncalls', 'prompttokens', 'completiontokens', 'images', 'cost', 'costedcalls',
    ];

    /** @var array<string, array{key: array, delta: array, targetname: ?string}> Increments gathered for this run. */
    protected array $pending = [];

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param \Closure|null $stop Called with the name of each stop point. Tests use it
     *                            to interrupt a run where they choose; nothing else does.
     * @param \core\lock\lock_factory|null $locks Where the run's lock comes from, or null
     *                                            for the site's. A test hands in a row
     *                                            based factory, because the site's on MySQL
     *                                            is re-entrant within one connection and
     *                                            cannot be seen refusing from one process.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var \Closure|null The stop hook. */
        protected readonly ?\Closure $stop = null,
        /** @var \core\lock\lock_factory|null The lock factory, if injected. */
        protected readonly ?\core\lock\lock_factory $locks = null,
    ) {
    }

    /**
     * Sweep, summarise and purge, under the lock.
     *
     * @param int $now The current time.
     * @return array|false Counts of what was done, or false when another run held the lock.
     */
    public function run(int $now): array|false {
        $lock = ($this->locks ?? usage_recorder::lock_factory())->get_lock(self::LOCK, 0);
        if (!$lock) {
            return false;
        }
        try {
            $lost = $this->sweep($now);
            try {
                $applied = $this->summarise($now);
            } catch (facts_changed_underneath $e) {
                // Somebody was forgotten while the facts were being added up, and what
                // was added up would have put them back. Nothing was written; the facts
                // that remain are read again, once. A second change is left to the next run.
                $applied = $this->summarise($now);
            }
            $purged = $this->purge($now);
            $purgedsummaries = $this->purge_summaries($now);
        } finally {
            $lock->release();
        }

        return [
            'lost' => $lost,
            'applied' => $applied,
            'purged' => $purged,
            'purgedsummaries' => $purgedsummaries,
        ];
    }

    /**
     * Close what was left open for too long.
     *
     * An attempt still started after STALE_AFTER is lost: the process that made the
     * call did not live to record how it came back. What it used is unknown, and is
     * recorded as unknown rather than as nothing. A request still open that long is
     * failed for the same reason. Both are conditional on the row still being open,
     * so an ending that does arrive in between wins.
     *
     * @param int $now The current time.
     * @return int How many attempts were given up as lost.
     */
    public function sweep(int $now): int {
        $cutoff = $now - self::STALE_AFTER;
        $stale = $this->db->count_records_select(
            usage_recorder::ATTEMPT_TABLE,
            'state = :started AND timestarted < :cutoff',
            ['started' => attempt_state::STARTED, 'cutoff' => $cutoff],
        );
        if ($stale > 0) {
            $this->db->execute(
                'UPDATE {' . usage_recorder::ATTEMPT_TABLE . '}
                    SET state = :lost, usageknown = 0, timeended = :now
                  WHERE state = :started AND timestarted < :cutoff',
                ['lost' => attempt_state::LOST, 'now' => $now, 'started' => attempt_state::STARTED, 'cutoff' => $cutoff],
            );
        }
        $this->db->execute(
            'UPDATE {' . usage_recorder::REQUEST_TABLE . '}
                SET state = :failed, reason = :reason, timeended = :now,
                    attempts = (SELECT COUNT(1) FROM {' . usage_recorder::ATTEMPT_TABLE . '} a
                                 WHERE a.requestid = {' . usage_recorder::REQUEST_TABLE . '}.id)
              WHERE state = :open AND timestarted < :cutoff',
            ['failed' => request_state::FAILED, 'reason' => self::REASON_LOST, 'now' => $now,
                'open' => request_state::OPEN, 'cutoff' => $cutoff],
        );

        return $stale;
    }

    /**
     * Apply every finished fact not yet applied, in one transaction.
     *
     * Today is left alone: a summary of a day still being written to is wrong from the
     * moment it is read. That is a choice, not a need, and it is the same choice the
     * reports make when they read the detail for today and the summary for the rest.
     *
     * @param int $now The current time.
     * @return int How many facts were applied.
     */
    public function summarise(int $now): int {
        $today = self::day_of($now);
        $transaction = $this->db->start_delegated_transaction();
        try {
            $requests = $this->db->get_records_sql(
                'SELECT r.*, a.model AS answeredmodel, a.targetname AS answeredname,
                        a.targetprovider AS answeredprovider
                   FROM {' . usage_recorder::REQUEST_TABLE . '} r
              LEFT JOIN {' . usage_recorder::ATTEMPT_TABLE . '} a
                     ON a.requestid = r.id AND a.state = :succeeded
                  WHERE r.applied = 0 AND r.state <> :open
                    AND r.timeended IS NOT NULL AND r.timeended < :today
               ORDER BY r.id',
                ['succeeded' => attempt_state::SUCCEEDED, 'open' => request_state::OPEN, 'today' => $today],
            );
            $this->at('requests_read');
            foreach ($requests as $request) {
                $this->add_request($request);
            }

            $attempts = $this->db->get_records_sql(
                'SELECT a.*, r.userid, r.courseid, r.actionname
                   FROM {' . usage_recorder::ATTEMPT_TABLE . '} a
                   JOIN {' . usage_recorder::REQUEST_TABLE . '} r ON r.id = a.requestid
                  WHERE a.applied = 0 AND a.state <> :started
                    AND a.timeended IS NOT NULL AND a.timeended < :today
               ORDER BY a.id',
                ['started' => attempt_state::STARTED, 'today' => $today],
            );
            foreach ($attempts as $attempt) {
                $this->add_attempt($attempt);
                $this->at('attempt_added');
            }

            $this->flush();
            $this->mark(usage_recorder::REQUEST_TABLE, array_keys($requests));
            $this->mark(usage_recorder::ATTEMPT_TABLE, array_keys($attempts));
            $this->at('marked');
            if ($requests || $attempts) {
                self::bump_generation();
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $this->pending = [];
            $transaction->rollback($e);
        }

        return count($requests) + count($attempts);
    }

    /**
     * Remove detail that has been counted and is past keeping.
     *
     * Attempts first, then the requests that have no attempts left. A request whose
     * attempts are still there is not removed from under them.
     *
     * @param int $now The current time.
     * @return int How many rows were removed.
     */
    public function purge(int $now): int {
        $days = self::get_retention_days();
        if ($days <= 0) {
            return 0;
        }
        $cutoff = self::days_before($now, $days);
        $params = ['cutoff' => $cutoff];

        $attempts = $this->db->count_records_select(
            usage_recorder::ATTEMPT_TABLE,
            'applied = 1 AND timeended IS NOT NULL AND timeended < :cutoff',
            $params,
        );
        if ($attempts > 0) {
            $this->db->delete_records_select(
                usage_recorder::ATTEMPT_TABLE,
                'applied = 1 AND timeended IS NOT NULL AND timeended < :cutoff',
                $params,
            );
        }
        $select = 'applied = 1 AND timeended IS NOT NULL AND timeended < :cutoff
                   AND NOT EXISTS (SELECT 1 FROM {' . usage_recorder::ATTEMPT_TABLE . '} a
                                    WHERE a.requestid = {' . usage_recorder::REQUEST_TABLE . '}.id)';
        $requests = $this->db->count_records_select(usage_recorder::REQUEST_TABLE, $select, $params);
        if ($requests > 0) {
            $this->db->delete_records_select(usage_recorder::REQUEST_TABLE, $select, $params);
        }
        // Only applied rows go, and a reader never takes an applied row from the
        // detail, so a purge moves nothing a reader could see. Said here so that the
        // generation is understood to count changes to what can be read, not writes.

        return $attempts + $requests;
    }

    /**
     * Remove summary rows older than the summary retention period.
     *
     * @param int $now The current time.
     * @return int How many rows were removed.
     */
    public function purge_summaries(int $now): int {
        $days = self::get_summary_retention_days();
        if ($days <= 0) {
            return 0;
        }
        $params = ['cutoff' => self::days_before($now, $days)];
        $count = $this->db->count_records_select(self::TABLE, 'daystart < :cutoff', $params);
        if ($count > 0) {
            $this->db->delete_records_select(self::TABLE, 'daystart < :cutoff', $params);
            self::record_history_from($params['cutoff']);
            self::bump_generation();
        }

        return $count;
    }

    /**
     * The number of times the summary has changed, read from the database itself.
     *
     * Not through get_config(), which a process keeps a copy of: what is wanted here
     * is whether another process has changed the summary since a moment ago.
     *
     * @param \moodle_database $db The database to read.
     * @return int The generation.
     */
    public static function get_generation(\moodle_database $db): int {
        $value = $db->get_field('config_plugins', 'value', ['plugin' => 'local_airouter', 'name' => self::GENERATION_SETTING]);

        return $value === false || $value === null ? 0 : (int) $value;
    }

    /**
     * Note that the summary has changed.
     *
     * Written with set_config() so that the row exists and the config caches are
     * told, and read back directly, so that no cache stands between two processes.
     */
    protected static function bump_generation(): void {
        global $DB;

        set_config(self::GENERATION_SETTING, self::get_generation($DB) + 1, 'local_airouter');
    }

    /**
     * The first moment this site can still account for, or zero when it has discarded nothing.
     *
     * Set by the purge when it discards a summarised day, and only ever moved forward:
     * a budget looking back past it counts a period the site cannot account for in
     * full, which is worth saying rather than guessing from whatever rows are left.
     *
     * @return int The moment, or zero.
     */
    public static function get_history_from(): int {
        return max(0, (int) get_config('local_airouter', self::HISTORY_SETTING));
    }

    /**
     * Note that everything before a moment has been discarded.
     *
     * Only ever moves forward. Two purges under different retention settings must not
     * let the later, shorter one say the site remembers more than it does.
     *
     * @param int $from The first moment still covered.
     */
    protected static function record_history_from(int $from): void {
        if ($from > self::get_history_from()) {
            set_config(self::HISTORY_SETTING, $from, 'local_airouter');
        }
    }

    /**
     * Midnight of the first day of the month a moment falls in, in the server timezone.
     *
     * Worked out through the calendar rather than by counting days: months are not
     * all the same length, and a limit counted by the calendar month has to start
     * where the calendar says it does.
     *
     * @param int $time The moment.
     * @return int Midnight of the first of that month.
     */
    public static function month_of(int $time): int {
        $zone = new \DateTimeZone(\core_date::get_server_timezone());

        return (new \DateTimeImmutable('@' . $time))
            ->setTimezone($zone)
            ->modify('first day of this month')
            ->setTime(0, 0)
            ->getTimestamp();
    }

    /**
     * Midnight of the day a time falls in, in the server timezone.
     *
     * @param int $time The time.
     * @return int Midnight.
     */
    public static function day_of(int $time): int {
        return usergetmidnight($time, \core_date::get_server_timezone());
    }

    /**
     * How many days of detail the site keeps. The same setting as the older record.
     *
     * @return int Days, or zero to keep everything.
     */
    public static function get_retention_days(): int {
        $configured = get_config('local_airouter', usage_aggregator::RETENTION_SETTING);
        if ($configured === false || $configured === '') {
            return usage_aggregator::DEFAULT_RETENTION;
        }

        return max(0, (int) $configured);
    }

    /**
     * How many days of summary the site keeps. The same setting as the older record.
     *
     * @return int Days, or zero to keep everything.
     */
    public static function get_summary_retention_days(): int {
        $configured = get_config('local_airouter', usage_aggregator::SUMMARY_RETENTION_SETTING);
        if ($configured === false || $configured === '') {
            return usage_aggregator::DEFAULT_SUMMARY_RETENTION;
        }

        return max(0, (int) $configured);
    }

    /**
     * The increments a request contributes.
     *
     * Counted on the row of the target that answered, in the model that answered,
     * so that requests and the calls that answered them sit side by side. A request
     * nobody answered goes on a row with no target, which is how refusals and total
     * failures are counted.
     *
     * @param \stdClass $request The request row, with the answering attempt's model and name.
     */
    protected function add_request(\stdClass $request): void {
        $this->add([
            'daystart' => self::day_of((int) $request->timeended),
            'userid' => (int) $request->userid,
            'courseid' => (int) ($request->courseid ?? 0),
            'actionname' => $request->actionname,
            'targetprovider' => $request->answeredprovider ?? '-',
            'targetid' => (int) ($request->answeredby ?? 0),
            'model' => $request->answeredmodel ?? '-',
            'keysource' => $request->keysource,
            'currency' => '-',
        ], [
            'requests' => 1,
            'failures' => $request->state === request_state::SUCCEEDED ? 0 : 1,
        ], $request->answeredname);
    }

    /**
     * The increments an attempt contributes.
     *
     * @param \stdClass $attempt The attempt row, with its request's user, course and action.
     */
    protected function add_attempt(\stdClass $attempt): void {
        $known = (int) $attempt->usageknown === 1;
        $costed = $attempt->cost !== null;
        $this->add([
            'daystart' => self::day_of((int) $attempt->timeended),
            'userid' => (int) $attempt->userid,
            'courseid' => (int) ($attempt->courseid ?? 0),
            'actionname' => $attempt->actionname,
            'targetprovider' => $attempt->targetprovider ?? '-',
            'targetid' => (int) $attempt->targetid,
            'model' => $attempt->model ?? '-',
            'keysource' => $attempt->keysource,
            'currency' => $costed ? ($attempt->currency ?? '-') : '-',
        ], [
            'calls' => 1,
            'knowncalls' => $known ? 1 : 0,
            'prompttokens' => $known ? (int) $attempt->prompttokens : 0,
            'completiontokens' => $known ? (int) $attempt->completiontokens : 0,
            'images' => (int) $attempt->images,
            'cost' => $costed ? (float) $attempt->cost : 0,
            'costedcalls' => $costed ? 1 : 0,
        ], $attempt->targetname);
    }

    /**
     * Add to the summary row a fact belongs to, in memory. Written by flush().
     *
     * Two facts for the same row are one write, which is what keeps a run affordable:
     * a day of traffic has far fewer distinct rows than it has attempts.
     *
     * @param array $key The row's natural key.
     * @param array $delta Column increments.
     * @param string|null $targetname The target's name, carried for readability.
     */
    protected function add(array $key, array $delta, ?string $targetname): void {
        $hash = implode('/', $key);
        $this->pending[$hash] ??= ['key' => $key, 'delta' => array_fill_keys(self::COUNTERS, 0), 'targetname' => null];
        foreach ($delta as $column => $amount) {
            $this->pending[$hash]['delta'][$column] += $amount;
        }
        $this->pending[$hash]['targetname'] ??= $targetname;
    }

    /**
     * Write the gathered increments and forget them.
     */
    protected function flush(): void {
        foreach ($this->pending as $item) {
            $row = $this->db->get_record(self::TABLE, $item['key']);
            if ($row === false) {
                $this->db->insert_record(self::TABLE, (object) ($item['key'] + $item['delta'] + [
                    'targetname' => $item['targetname'],
                ]));
                continue;
            }
            foreach ($item['delta'] as $column => $amount) {
                $row->$column += $amount;
            }
            $row->targetname ??= $item['targetname'];
            $this->db->update_record(self::TABLE, $row);
        }
        $this->pending = [];
    }

    /**
     * Mark rows as applied, a chunk at a time, and make sure every one of them was there.
     *
     * The facts were read at the start of the transaction, and a privacy deletion can
     * have removed some of them, and committed, since. The summary rows already added
     * up from them would put a forgotten person back. So after marking, the marked
     * rows are counted: an update reaches only rows that exist now, so a row that has
     * gone is not counted, whichever database this is and whatever it lets a
     * transaction see. A shortfall means the run is thrown away and taken again.
     *
     * @param string $table The table.
     * @param int[] $ids The rows.
     * @throws facts_changed_underneath When a row read earlier is no longer there.
     */
    protected function mark(string $table, array $ids): void {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            [$insql, $params] = $this->db->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $this->db->set_field_select($table, 'applied', 1, "id $insql", $params);
            $marked = $this->db->count_records_select($table, "applied = 1 AND id $insql", $params);
            if ($marked !== count($chunk)) {
                throw new facts_changed_underneath($table, count($chunk) - $marked);
            }
        }
    }

    /**
     * Midnight, in the server timezone, of the day so many days before a time.
     *
     * Counted in calendar days and not in multiples of 86400 seconds, because a day
     * on which the clocks change is not 86400 seconds long, and a retention counted
     * in seconds would then cut into the day it was meant to keep.
     *
     * @param int $time The time.
     * @param int $days How many days back.
     * @return int Midnight of that day.
     */
    public static function days_before(int $time, int $days): int {
        return self::add_days(self::day_of($time), -$days);
    }

    /**
     * A time moved by so many calendar days, in the server timezone.
     *
     * Midnight stays midnight across a change of the clocks, which a multiple of
     * 86400 seconds does not manage.
     *
     * @param int $time The time.
     * @param int $days How many days, negative for earlier.
     * @return int The moved time.
     */
    public static function add_days(int $time, int $days): int {
        $timezone = new \DateTimeZone(\core_date::get_server_timezone());
        $moved = (new \DateTimeImmutable('@' . $time))->setTimezone($timezone);

        return $moved->modify(($days < 0 ? '-' : '+') . abs($days) . ' days')->getTimestamp();
    }

    /**
     * Call the stop hook, if there is one.
     *
     * @param string $point The name of the point.
     */
    protected function at(string $point): void {
        if ($this->stop !== null) {
            ($this->stop)($point);
        }
    }
}
