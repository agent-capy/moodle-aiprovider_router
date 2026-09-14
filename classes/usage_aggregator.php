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
 * not worth keeping for years. A summary is not: it counts requests by day, course,
 * target and model, and holds nobody's identity, so it can be kept indefinitely and is
 * what a report covering last year is drawn from.
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
                'actionname' => (string) $row->actionname,
                'targetid' => $row->targetid === null ? null : (int) $row->targetid,
                'targetname' => $row->targetname,
                'targetprovider' => $row->targetprovider,
                'model' => $row->model,
                'keysource' => (string) $row->keysource,
                'currency' => $row->currency,
                'requests' => (int) $row->requests,
                'failures' => (int) $row->failures,
                'prompttokens' => (int) $row->prompttokens,
                'completiontokens' => (int) $row->completiontokens,
                'cost' => $row->cost === null ? null : (float) $row->cost,
                'costedrequests' => (int) $row->costedrequests,
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
     * only some requests were covered by a rate reports the part that was known rather
     * than treating the rest as free. How many rows that was is counted beside it.
     *
     * Who paid is one of the groups. Money somebody spent out of their own pocket is not
     * the site's expenditure, and a figure that added the two together would answer
     * nobody's question about what anything cost.
     *
     * @return string The SQL.
     */
    protected function get_summary_sql(): string {
        return 'SELECT courseid, actionname, targetid, targetname, targetprovider, model, keysource, currency,
                       COUNT(*) AS requests,
                       SUM(CASE WHEN success = 1 THEN 0 ELSE 1 END) AS failures,
                       SUM(CASE WHEN prompttokens IS NULL THEN 0 ELSE prompttokens END) AS prompttokens,
                       SUM(CASE WHEN completiontokens IS NULL THEN 0 ELSE completiontokens END) AS completiontokens,
                       SUM(cost) AS cost,
                       SUM(CASE WHEN cost IS NULL THEN 0 ELSE 1 END) AS costedrequests
                  FROM {' . usage_logger::TABLE . '}
                 WHERE timecreated >= :start AND timecreated < :end
              GROUP BY courseid, actionname, targetid, targetname, targetprovider, model, keysource, currency';
    }
}
