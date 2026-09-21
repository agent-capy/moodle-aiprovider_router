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
 * Summarises finished days of the log, and removes detail rows that are past keeping.
 *
 * The two belong together and in this order. A detail row holds what somebody asked an
 * AI to do, in a context and at a time, so it is close to personal information and is
 * not worth keeping for years. A summary holds how much was used, by whom, on what day:
 * enough for a site to account for its AI spending a year later, and not enough to say
 * what anybody asked for.
 *
 * Both have a retention period. The summary's is unlimited by default, which is what it
 * was before it named anybody, and a site that would rather not keep person level
 * history for ever can now say so.
 *
 * Nothing is ever purged that has not been summarised first, whatever the retention
 * period says. A site whose cron has been stopped for a month would otherwise come back
 * and delete a month of history it never counted.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_aggregator {
    /** @var string The table holding the summaries. */
    public const TABLE = 'aiprovider_router_daily';

    /** @var int Days of detail kept when the site has not chosen. */
    public const DEFAULT_RETENTION = 90;

    /** @var int How many days one run will summarise. */
    public const MAX_DAYS_PER_RUN = 60;

    /** @var string Config holding the last day summarised, as a midnight timestamp. */
    public const LAST_SETTING = 'lastaggregated';

    /** @var string Config holding how many days of detail to keep. */
    public const RETENTION_SETTING = 'logretentiondays';

    /** @var string Config holding how many days of summary to keep. */
    public const SUMMARY_RETENTION_SETTING = 'summaryretentiondays';

    /** @var int Days of summary kept when the site has not chosen. */
    public const DEFAULT_SUMMARY_RETENTION = 0;

    /**
     * Config holding the first moment the site can still vouch for.
     *
     * Raised whenever summaries are discarded, and never lowered. Without it there is
     * no way to tell a site that has never used its AI from a site whose history was
     * thrown away: both have two empty tables, and the difference between them is the
     * difference between a budget that is measuring everything and a budget that is
     * measuring what is left.
     *
     * @var string The setting name.
     */
    public const HISTORY_SETTING = 'historyfrom';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to work on.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
    ) {
    }

    /**
     * Summarise every finished day not yet summarised, then purge what is past keeping.
     *
     * @param int $now The current time.
     * @return array Counts of days summarised, summary rows written and detail rows removed.
     */
    public function run(int $now): array {
        $written = 0;
        $days = $this->get_days_to_summarise($now);
        foreach ($days as $day) {
            $written += $this->summarise_day($day);
            // Written one day at a time, so that a run interrupted half way through
            // resumes at the day it stopped on rather than starting again.
            set_config(self::LAST_SETTING, $day, 'aiprovider_router');
        }

        return [
            'days' => count($days),
            'rows' => $written,
            'purged' => $this->purge($now),
            'purgedsummaries' => $this->purge_summaries($now),
        ];
    }

    /**
     * The finished days that have not been summarised yet.
     *
     * Today is never included: it is still being written to, and a summary of a day that
     * is still growing would be wrong from the moment it was written.
     *
     * @param int $now The current time.
     * @return int[] Midnight timestamps, oldest first.
     */
    public function get_days_to_summarise(int $now): array {
        $last = (int) get_config('aiprovider_router', self::LAST_SETTING);
        if ($last > 0) {
            $from = $this->add_days($last, 1);
        } else {
            $earliest = $this->db->get_field_sql(
                'SELECT MIN(timecreated) FROM {' . usage_logger::TABLE . '}',
            );
            if (empty($earliest)) {
                return [];
            }
            $from = $this->day_of((int) $earliest);
        }

        $until = $this->add_days($this->day_of($now), -1);
        $days = [];
        for ($day = $from; $day <= $until; $day = $this->add_days($day, 1)) {
            $days[] = $day;
            if (count($days) >= self::MAX_DAYS_PER_RUN) {
                // A site coming back after a long outage catches up over several runs
                // rather than holding one cron run open for as long as it takes.
                break;
            }
        }

        return $days;
    }

    /**
     * Summarise one day, replacing any summary it already has.
     *
     * @param int $day Midnight of the day, in the server timezone.
     * @return int How many summary rows were written.
     */
    public function summarise_day(int $day): int {
        $this->db->delete_records(self::TABLE, ['daystart' => $day]);

        $now = time();
        $records = [];
        $recordset = $this->db->get_recordset_sql($this->get_summary_sql(), [
            'start' => $day,
            'end' => $this->add_days($day, 1),
        ]);
        foreach ($recordset as $row) {
            $records[] = (object) [
                'daystart' => $day,
                'courseid' => $row->courseid === null ? null : (int) $row->courseid,
                'userid' => $row->userid === null ? null : (int) $row->userid,
                'actionname' => (string) $row->actionname,
                'targetid' => $row->targetid === null ? null : (int) $row->targetid,
                'targetname' => $row->targetname,
                'targetprovider' => $row->targetprovider,
                'model' => $row->model,
                'keysource' => (string) $row->keysource,
                'currency' => $row->currency,
                'requests' => (int) $row->requests,
                'failures' => (int) $row->failures,
                'calls' => (int) $row->calls,
                'prompttokens' => (int) $row->prompttokens,
                'completiontokens' => (int) $row->completiontokens,
                'cost' => $row->cost === null ? null : (float) $row->cost,
                'costedcalls' => (int) $row->costedcalls,
                'timecreated' => $now,
            ];
        }
        $recordset->close();

        if ($records) {
            $this->db->insert_records(self::TABLE, $records);
        }

        return count($records);
    }

    /**
     * Remove detail rows older than the retention period.
     *
     * The cut never runs past the last day summarised, so that turning cron back on
     * after an outage counts the missed days before anything is removed.
     *
     * @param int $now The current time.
     * @return int How many detail rows were removed.
     */
    public function purge(int $now): int {
        $days = $this->get_retention_days();
        if ($days <= 0) {
            // Zero means keep everything. A retention of no days would otherwise read as
            // an instruction to delete today's log, which nobody means by it.
            return 0;
        }
        $last = (int) get_config('aiprovider_router', self::LAST_SETTING);
        if ($last <= 0) {
            return 0;
        }

        $cutoff = min($this->add_days($this->day_of($now), -$days), $this->add_days($last, 1));
        $params = ['cutoff' => $cutoff];
        $count = $this->db->count_records_select(usage_logger::TABLE, 'timecreated < :cutoff', $params);
        if ($count > 0) {
            $this->db->delete_records_select(usage_logger::TABLE, 'timecreated < :cutoff', $params);
        }

        return $count;
    }

    /**
     * Remove summary rows older than the summary retention period.
     *
     * A summary is never removed while the day it describes still has detail rows.
     * Reports read the summaries up to the day the task has reached and the detail
     * beyond it, so a day whose summary had gone but whose detail was still there would
     * be reported as nothing having happened. Nothing would look wrong about it.
     *
     * Keeping every summary remains the default, which is what they did before they
     * named anybody.
     *
     * @param int $now The current time.
     * @return int How many summary rows were removed.
     */
    public function purge_summaries(int $now): int {
        $days = $this->get_summary_retention_days();
        $detaildays = $this->get_retention_days();
        if ($days <= 0 || $detaildays <= 0) {
            // Either summaries are kept for ever, or the detail is, and a site keeping
            // every detail row has nothing to gain from dropping the summary of a day
            // it can still see in full.
            return 0;
        }

        $today = $this->day_of($now);
        $cutoff = min($this->add_days($today, -$days), $this->add_days($today, -$detaildays));

        // Nothing a budget still reaches back into is removed, whatever the retention
        // settings say. The settings form refuses the combination when it can see it,
        // but a budget can be written after the retention was shortened, and a limit
        // on a brought key is set on a screen that knows nothing about either. Losing
        // that history does not make the spending unknown, it makes it smaller, and a
        // limit that had been reached comes back under the line.
        $reach = spend_ledger::longest_reach_days($this->db);
        if ($reach > 0) {
            $cutoff = min($cutoff, $this->add_days($today, -$reach));
        }

        $params = ['cutoff' => $cutoff];
        $count = $this->db->count_records_select(self::TABLE, 'daystart < :cutoff', $params);
        if ($count > 0) {
            $this->db->delete_records_select(self::TABLE, 'daystart < :cutoff', $params);
            // What has gone, has gone, and afterwards nothing in either table says it
            // was ever there. Written down here, where it is still known, so that a
            // budget counting a period that reaches back past this point can be told
            // it is counting less than was spent.
            $this->record_history_from($cutoff);
        }

        return $count;
    }

    /**
     * The first moment this site can still vouch for having a full record of.
     *
     * @return int The moment, or zero where nothing has ever been discarded.
     */
    public function get_history_from(): int {
        return max(0, (int) get_config('aiprovider_router', self::HISTORY_SETTING));
    }

    /**
     * Note that everything before a moment has been discarded.
     *
     * Only ever moves forward. Two purges under different retention settings must not
     * let the later, shorter one say the site remembers more than it does.
     *
     * @param int $from The first moment still covered.
     */
    protected function record_history_from(int $from): void {
        if ($from > $this->get_history_from()) {
            set_config(self::HISTORY_SETTING, $from, 'aiprovider_router');
        }
    }

    /**
     * How many days of detail the site keeps.
     *
     * @return int The number of days, or zero to keep everything.
     */
    public function get_retention_days(): int {
        $configured = get_config('aiprovider_router', self::RETENTION_SETTING);
        if ($configured === false || $configured === '') {
            return self::DEFAULT_RETENTION;
        }

        return max(0, (int) $configured);
    }

    /**
     * How many days of summary the site keeps.
     *
     * @return int The number of days, or zero to keep everything.
     */
    public function get_summary_retention_days(): int {
        $configured = get_config('aiprovider_router', self::SUMMARY_RETENTION_SETTING);
        if ($configured === false || $configured === '') {
            return self::DEFAULT_SUMMARY_RETENTION;
        }

        return max(0, (int) $configured);
    }

    /**
     * Midnight of the day a moment falls in, in the server timezone.
     *
     * A site's day is the day its administrators live in, so the boundaries follow the
     * server timezone rather than UTC.
     *
     * @param int $time The moment.
     * @return int Midnight of that day.
     */
    public function day_of(int $time): int {
        return $this->at($time)->setTime(0, 0)->getTimestamp();
    }

    /**
     * Midnight of the first day of the month a moment falls in.
     *
     * Worked out through the calendar rather than by counting days, for the same reason
     * add_days() is: months are not all the same length, and a budget counted by the
     * calendar month has to start where the calendar says it does.
     *
     * @param int $time The moment.
     * @return int Midnight of the first of that month.
     */
    public function month_of(int $time): int {
        return $this->at($time)->modify('first day of this month')->setTime(0, 0)->getTimestamp();
    }

    /**
     * The same time of day, a number of days away.
     *
     * Done through the calendar rather than by adding 86400 seconds, because on the two
     * days a year a timezone changes offset those are not the same thing, and a day
     * boundary that drifts by an hour would leave rows counted twice or not at all.
     *
     * @param int $time The moment.
     * @param int $days How many days to move, which may be negative.
     * @return int The moved moment.
     */
    public function add_days(int $time, int $days): int {
        $sign = $days < 0 ? '-' : '+';

        return $this->at($time)->modify($sign . abs($days) . ' days')->getTimestamp();
    }

    /**
     * A moment, as a date in the server timezone.
     *
     * @param int $time The moment.
     * @return \DateTimeImmutable The date.
     */
    protected function at(int $time): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . $time))
            ->setTimezone(\core_date::get_server_timezone_object());
    }

    /**
     * The query one day is summarised with.
     *
     * Grouping includes the copied names, so a target renamed in the middle of a day
     * produces two rows for that day under its two names. That is the honest answer, and
     * every report adds the rows of a day together anyway.
     *
     * Costs are added with SUM, which ignores the rows that have none, so a day where
     * only some calls were covered by a rate reports the part that was known rather
     * than treating the rest as free. How many rows that was is counted beside it.
     *
     * Two counts come out of this and they are not the same count. A request is what
     * somebody asked for, and a request that fell through to a second provider is
     * still one of those; the counted column says which row carries it. A call is one
     * provider being asked, which is one row, and a call is what has a price. The cost
     * here is summed over every row, so the count that says whether the cost is known
     * has to be over every row as well. Counting the priced rows among the requests
     * instead left a day holding a cost of 1.20 and saying nothing had been priced,
     * and a budget reading that let the spending through.
     *
     * Who paid is one of the groups. Money somebody spent out of their own pocket is not
     * the site's expenditure, and a figure that added the two together would answer
     * nobody's question about what anything cost.
     *
     * So is who asked. A site that has to account for its AI spending needs to be able
     * to say where it went long after the detail rows are gone, and the summary is the
     * only thing left by then. Which screens may show a name is a separate question,
     * settled by capability rather than by leaving the column out.
     *
     * @return string The SQL.
     */
    protected function get_summary_sql(): string {
        return 'SELECT courseid, userid, actionname, targetid, targetname, targetprovider, model, keysource, currency,
                       SUM(counted) AS requests,
                       SUM(CASE WHEN counted = 1 AND success = 0 THEN 1 ELSE 0 END) AS failures,
                       COUNT(1) AS calls,
                       SUM(CASE WHEN prompttokens IS NULL THEN 0 ELSE prompttokens END) AS prompttokens,
                       SUM(CASE WHEN completiontokens IS NULL THEN 0 ELSE completiontokens END) AS completiontokens,
                       SUM(cost) AS cost,
                       SUM(CASE WHEN cost IS NULL THEN 0 ELSE 1 END) AS costedcalls
                  FROM {' . usage_logger::TABLE . '}
                 WHERE timecreated >= :start AND timecreated < :end
              GROUP BY courseid, userid, actionname, targetid, targetname, targetprovider, model,
                       keysource, currency';
    }
}
