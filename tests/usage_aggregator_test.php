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
 * Tests for summarising finished days of the log and purging the detail behind them.
 *
 * The dangerous half of this is the purge, so most of what is checked here is about what
 * must survive it: days nobody counted, and the summaries themselves.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_aggregator::class)]
final class usage_aggregator_test extends \advanced_testcase {
    /** @var usage_aggregator The aggregator under test. */
    protected usage_aggregator $aggregator;

    /** @var int A fixed moment to measure days from. */
    protected int $now;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        self::setTimezone('UTC', 'UTC');
        $this->aggregator = new usage_aggregator($DB);
        $this->now = make_timestamp(2026, 9, 13, 10, 30, 0);
    }

    /**
     * Midnight a number of days before the fixed moment.
     *
     * @param int $ago How many days back, zero being today.
     * @return int The timestamp.
     */
    protected function day(int $ago): int {
        return $this->aggregator->add_days($this->aggregator->day_of($this->now), -$ago);
    }

    /**
     * Write one detail row.
     *
     * @param int $time When the request happened.
     * @param array $fields What to record, over the defaults.
     * @return int The row id.
     */
    protected function log(int $time, array $fields = []): int {
        global $DB;

        return $DB->insert_record(usage_logger::TABLE, (object) ($fields + [
            'timecreated' => $time,
            'userid' => 3,
            'contextid' => 0,
            'courseid' => null,
            'actionname' => 'generate_text',
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'currency' => 'USD',
            'success' => 1,
            'attempts' => 1,
            'prompttokens' => 100,
            'completiontokens' => 50,
            'cost' => 0.5,
            'keysource' => usage_logger::KEY_SITE,
        ]));
    }

    /**
     * The summary rows of one day.
     *
     * @param int $day Midnight of the day.
     * @return array The rows.
     */
    protected function summaries(int $day): array {
        global $DB;

        return $DB->get_records(usage_aggregator::TABLE, ['daystart' => $day]);
    }

    public function test_a_finished_day_is_summarised(): void {
        $yesterday = $this->day(1);
        $this->log($yesterday + HOURSECS);
        $this->log($yesterday + 2 * HOURSECS);

        $result = $this->aggregator->run($this->now);

        $this->assertSame(1, $result['days']);
        $this->assertSame(1, $result['rows']);
        $rows = $this->summaries($yesterday);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(2, (int) $row->requests);
        $this->assertSame(0, (int) $row->failures);
        $this->assertSame(200, (int) $row->prompttokens);
        $this->assertSame(100, (int) $row->completiontokens);
        $this->assertEquals(1.0, (float) $row->cost);
        $this->assertSame(2, (int) $row->costedrequests);
        $this->assertSame('Target one', $row->targetname);
    }

    public function test_today_is_not_summarised_because_it_is_still_being_written_to(): void {
        $this->log($this->now);

        $result = $this->aggregator->run($this->now);

        $this->assertSame(0, $result['days']);
        $this->assertSame([], $this->summaries($this->day(0)));
    }

    public function test_each_combination_gets_its_own_row(): void {
        $yesterday = $this->day(1);
        $this->log($yesterday + HOURSECS);
        $this->log($yesterday + HOURSECS, ['model' => 'gpt-4o-mini']);
        $this->log($yesterday + HOURSECS, ['actionname' => 'summarise_text']);
        $this->log($yesterday + HOURSECS, ['courseid' => 7]);

        $this->aggregator->run($this->now);

        $this->assertCount(4, $this->summaries($yesterday));
    }

    public function test_running_twice_does_not_count_a_day_twice(): void {
        $yesterday = $this->day(1);
        $this->log($yesterday + HOURSECS);

        $this->aggregator->run($this->now);
        // The second run has nothing left to do, and a third day's worth of work would
        // show up as doubled figures rather than as an error.
        $this->aggregator->run($this->now);

        $rows = $this->summaries($yesterday);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) reset($rows)->requests);
    }

    public function test_summarising_a_day_again_replaces_what_was_there(): void {
        $yesterday = $this->day(1);
        $this->log($yesterday + HOURSECS);
        $this->aggregator->summarise_day($yesterday);

        $this->log($yesterday + 2 * HOURSECS);
        $this->aggregator->summarise_day($yesterday);

        $rows = $this->summaries($yesterday);
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) reset($rows)->requests);
    }

    public function test_failures_and_refusals_are_counted(): void {
        $yesterday = $this->day(1);
        $this->log($yesterday + HOURSECS, [
            'success' => 0,
            'reason' => abstract_processor::REASON_DECLINED,
            'targetid' => null,
            'targetname' => null,
            'targetprovider' => null,
            'model' => null,
            'prompttokens' => null,
            'completiontokens' => null,
            'cost' => null,
        ]);

        $this->aggregator->run($this->now);

        $rows = $this->summaries($yesterday);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(1, (int) $row->requests);
        $this->assertSame(1, (int) $row->failures);
        $this->assertNull($row->targetid);
        $this->assertSame(0, (int) $row->prompttokens);
        $this->assertNull($row->cost);
        $this->assertSame(0, (int) $row->costedrequests);
    }

    public function test_a_day_only_partly_covered_by_rates_says_so(): void {
        $yesterday = $this->day(1);
        $this->log($yesterday + HOURSECS, ['cost' => 0.25]);
        $this->log($yesterday + 2 * HOURSECS, ['cost' => null]);

        $this->aggregator->run($this->now);

        $rows = $this->summaries($yesterday);
        $row = reset($rows);
        // The uncosted request is not treated as free: the total is what was known, and
        // the count beside it says how much of the day that total covers.
        $this->assertEquals(0.25, (float) $row->cost);
        $this->assertSame(2, (int) $row->requests);
        $this->assertSame(1, (int) $row->costedrequests);
    }

    public function test_days_with_nothing_in_them_are_passed_over(): void {
        $this->log($this->day(3) + HOURSECS);

        $result = $this->aggregator->run($this->now);

        $this->assertSame(3, $result['days']);
        $this->assertSame(1, $result['rows']);
        $this->assertSame($this->day(1), (int) get_config('aiprovider_router', usage_aggregator::LAST_SETTING));
    }

    public function test_a_long_outage_is_caught_up_over_several_runs(): void {
        $this->log($this->day(usage_aggregator::MAX_DAYS_PER_RUN + 10) + HOURSECS);

        $days = $this->aggregator->get_days_to_summarise($this->now);

        $this->assertCount(usage_aggregator::MAX_DAYS_PER_RUN, $days);
    }

    public function test_detail_older_than_the_retention_period_is_removed(): void {
        global $DB;
        set_config(usage_aggregator::RETENTION_SETTING, 30, 'aiprovider_router');
        $old = $this->log($this->day(40) + HOURSECS);
        $recent = $this->log($this->day(2) + HOURSECS);

        $this->aggregator->run($this->now);

        $this->assertFalse($DB->record_exists(usage_logger::TABLE, ['id' => $old]));
        $this->assertTrue($DB->record_exists(usage_logger::TABLE, ['id' => $recent]));
        // What the removed rows counted towards is still there.
        $this->assertCount(1, $this->summaries($this->day(40)));
    }

    public function test_nothing_is_purged_before_it_has_been_summarised(): void {
        global $DB;
        set_config(usage_aggregator::RETENTION_SETTING, 30, 'aiprovider_router');
        $id = $this->log($this->day(40) + HOURSECS);

        // Cron has never run, so no day has been counted and the whole log must stay.
        $purged = $this->aggregator->purge($this->now);

        $this->assertSame(0, $purged);
        $this->assertTrue($DB->record_exists(usage_logger::TABLE, ['id' => $id]));
    }

    public function test_the_purge_stops_at_the_last_day_summarised(): void {
        global $DB;
        set_config(usage_aggregator::RETENTION_SETTING, 30, 'aiprovider_router');
        // A site whose cron stopped 35 days ago: everything older than 30 days is past
        // its retention, but only the part already counted may go.
        set_config(usage_aggregator::LAST_SETTING, $this->day(35), 'aiprovider_router');
        $counted = $this->log($this->day(40) + HOURSECS);
        $uncounted = $this->log($this->day(33) + HOURSECS);

        $purged = $this->aggregator->purge($this->now);

        $this->assertSame(1, $purged);
        $this->assertFalse($DB->record_exists(usage_logger::TABLE, ['id' => $counted]));
        $this->assertTrue($DB->record_exists(usage_logger::TABLE, ['id' => $uncounted]));
    }

    public function test_a_retention_of_zero_keeps_everything(): void {
        global $DB;
        set_config(usage_aggregator::RETENTION_SETTING, 0, 'aiprovider_router');
        $id = $this->log($this->day(400) + HOURSECS);

        $this->aggregator->run($this->now);

        $this->assertSame(0, $this->aggregator->get_retention_days());
        $this->assertTrue($DB->record_exists(usage_logger::TABLE, ['id' => $id]));
    }

    public function test_the_default_retention_applies_when_the_site_has_not_chosen(): void {
        $this->assertSame(usage_aggregator::DEFAULT_RETENTION, $this->aggregator->get_retention_days());
    }

    public function test_the_summary_says_who_used_it_but_not_what_they_asked(): void {
        global $DB;

        $columns = $DB->get_columns(usage_aggregator::TABLE);

        // WP4 decision J kept people out of the summary, on the grounds that removing
        // one of them would mean rebuilding it. That reasoning does not hold for a table
        // with a userid: one person's rows are deleted and everybody else's figures are
        // untouched. What it bought back is the ability to account for a year of
        // spending after the detail has been purged.
        $this->assertArrayHasKey('userid', $columns);
        // What was asked for is another matter, and is not here or in the detail either.
        $this->assertArrayNotHasKey('prompt', $columns);
        $this->assertArrayNotHasKey('contextid', $columns);
        // A user key belongs to one person, so keeping which key paid would say who they
        // were a second time, in a column nothing needs.
        $this->assertArrayNotHasKey('keyid', $columns);
        $this->assertArrayHasKey('keysource', $columns);
    }

    public function test_two_people_on_one_day_are_counted_apart(): void {
        $yesterday = $this->day(1);
        $this->log($yesterday + HOURSECS, ['userid' => 3]);
        $this->log($yesterday + HOURSECS, ['userid' => 3]);
        $this->log($yesterday + HOURSECS, ['userid' => 4]);

        $this->aggregator->run($this->now);

        $counts = [];
        foreach ($this->summaries($yesterday) as $row) {
            $counts[(int) $row->userid] = (int) $row->requests;
        }
        $this->assertSame([3 => 2, 4 => 1], $counts);
    }

    public function test_summaries_are_kept_for_ever_unless_the_site_says_otherwise(): void {
        set_config(usage_aggregator::RETENTION_SETTING, 30, 'aiprovider_router');
        $this->log($this->day(400) + HOURSECS);
        $this->aggregator->summarise_day($this->day(400));

        $purged = $this->aggregator->purge_summaries($this->now);

        $this->assertSame(0, $purged);
        $this->assertCount(1, $this->summaries($this->day(400)));
    }

    public function test_summaries_past_their_retention_are_removed(): void {
        set_config(usage_aggregator::RETENTION_SETTING, 30, 'aiprovider_router');
        set_config(usage_aggregator::SUMMARY_RETENTION_SETTING, 90, 'aiprovider_router');
        $this->log($this->day(400) + HOURSECS);
        $this->log($this->day(10) + HOURSECS);
        // Summarised directly: one run only catches up sixty days at a time, so a run
        // here would reach the old day and stop long before the recent one.
        $this->aggregator->summarise_day($this->day(400));
        $this->aggregator->summarise_day($this->day(10));

        $purged = $this->aggregator->purge_summaries($this->now);

        $this->assertSame(1, $purged);
        $this->assertCount(0, $this->summaries($this->day(400)));
        $this->assertCount(1, $this->summaries($this->day(10)));
    }

    public function test_a_summary_is_never_removed_while_its_detail_survives(): void {
        global $DB;
        // A site that asked to keep summaries for less time than the detail. Reports read
        // the summaries for the older half of a period, so obeying this literally would
        // report those days as nothing having happened, with nothing looking wrong.
        set_config(usage_aggregator::RETENTION_SETTING, 90, 'aiprovider_router');
        set_config(usage_aggregator::SUMMARY_RETENTION_SETTING, 10, 'aiprovider_router');
        $this->log($this->day(40) + HOURSECS);
        $this->aggregator->summarise_day($this->day(40));

        $purged = $this->aggregator->purge_summaries($this->now);

        $this->assertSame(0, $purged);
        $this->assertCount(1, $this->summaries($this->day(40)));
        // The detail is still there, which is exactly why the summary had to stay.
        $this->assertSame(1, $DB->count_records(usage_logger::TABLE));
    }

    public function test_keeping_every_detail_row_keeps_every_summary(): void {
        set_config(usage_aggregator::RETENTION_SETTING, 0, 'aiprovider_router');
        set_config(usage_aggregator::SUMMARY_RETENTION_SETTING, 30, 'aiprovider_router');
        $this->log($this->day(400) + HOURSECS);
        $this->aggregator->summarise_day($this->day(400));

        // Nothing is gained by dropping the summary of a day the site can still see in
        // full, and the report would lose the day either way.
        $this->assertSame(0, $this->aggregator->purge_summaries($this->now));
    }

    public function test_what_people_paid_for_themselves_is_summarised_apart(): void {
        $yesterday = $this->day(1);
        $this->log($yesterday + HOURSECS, ['cost' => 0.25]);
        $this->log($yesterday + HOURSECS, ['cost' => 4.0, 'keysource' => rule::KEYSOURCE_USER]);

        $this->aggregator->run($this->now);

        $costs = [];
        foreach ($this->summaries($yesterday) as $row) {
            $costs[$row->keysource] = (float) $row->cost;
        }

        // Added together this would read as 4.25 spent by the site, which is not a
        // figure anybody could act on: most of it came out of somebody's own pocket.
        $this->assertEqualsWithDelta(0.25, $costs[usage_logger::KEY_SITE], 0.000001);
        $this->assertEqualsWithDelta(4.0, $costs[rule::KEYSOURCE_USER], 0.000001);
    }

    public function test_the_log_the_rule_and_the_summary_agree_on_who_paid(): void {
        // Three tables and a rule name one thing between them. Were the two vocabularies
        // ever to drift apart, every BYOK row would be summarised under a heading the
        // rule that produced it does not use, and nothing would say so.
        $this->assertSame(usage_logger::KEY_SITE, rule::KEYSOURCE_SITE);
        $this->assertSame(key::SCOPE_USER, rule::KEYSOURCE_USER);
        $this->assertSame(key::SCOPE_COURSE, rule::KEYSOURCE_COURSE);
    }

    public function test_days_are_counted_through_the_calendar_not_in_seconds(): void {
        global $DB;
        // The day clocks go forward in New York in 2026, which is 23 hours long. Adding
        // 86400 seconds to its midnight lands at one in the morning of the next day, and
        // every boundary after it would be an hour out.
        self::setTimezone('America/New_York', 'America/New_York');
        $aggregator = new usage_aggregator($DB);
        $midnight = $aggregator->day_of(make_timestamp(2026, 3, 8, 12, 0, 0));

        $next = $aggregator->add_days($midnight, 1);

        $this->assertSame(23 * HOURSECS, $next - $midnight);
        $this->assertSame($next, $aggregator->day_of($next + HOURSECS));
    }

    public function test_a_purge_never_takes_history_a_budget_still_needs(): void {
        global $DB;

        // The settings form refuses this combination when it can see it, but a
        // budget can be written after the retention was shortened and a limit on a
        // brought key is set on a screen that knows about neither. Losing that
        // history does not make the spending unknown, it makes it smaller, and a
        // limit that had been reached comes back under the line.
        set_config('summaryretentiondays', 2, 'aiprovider_router');
        set_config('logretentiondays', 1, 'aiprovider_router');

        $rule = new rule();
        $rule->set('name', 'While there is money left');
        $rule->set('targetid', 3);
        (new rule_repository($DB))->save($rule, ['budget' => [
            'scope' => spend_ledger::SCOPE_SITE,
            'direction' => \aiprovider_router\condition\budget::DIRECTION_UNDER,
            'metric' => spend_ledger::METRIC_COST,
            'amount' => 100.0,
            'period' => spend_ledger::PERIOD_MONTH,
            'days' => 30,
        ]]);

        $this->log($this->day(10) + HOURSECS, ['cost' => 100.0]);
        $this->aggregator->run($this->now);
        $this->aggregator->purge_summaries($this->now);

        // Ten days back is well past a two day retention and well inside a monthly
        // budget, so it stays.
        $this->assertSame(1, $DB->count_records(usage_aggregator::TABLE));
    }
}
