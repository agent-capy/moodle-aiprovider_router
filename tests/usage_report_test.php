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
 * Tests for the figures the monitor reports.
 *
 * The thing most likely to go wrong is the seam: every period the dashboard offers spans
 * the point where the summaries stop and the detail takes over, and a figure that counted
 * that day twice, or lost it, would look entirely plausible.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_report::class)]
final class usage_report_test extends \advanced_testcase {
    /** @var usage_aggregator The aggregator the report reads through. */
    protected usage_aggregator $aggregator;

    /** @var usage_report The report under test. */
    protected usage_report $report;

    /** @var int A fixed moment to measure days from. */
    protected int $now;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        self::setTimezone('UTC', 'UTC');
        $this->aggregator = new usage_aggregator($DB);
        $this->report = new usage_report($DB, $this->aggregator);
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
            'userid' => 0,
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
     * The whole of the last week.
     *
     * @return int The start of the period.
     */
    protected function week(): int {
        return $this->aggregator->add_days($this->aggregator->day_of($this->now), -6);
    }

    public function test_every_day_of_the_period_is_present_even_when_empty(): void {
        $series = $this->report->get_series($this->week(), $this->now);

        $this->assertCount(7, $series);
        $this->assertSame(array_keys($series), array_map([$this, 'day'], range(6, 0)));
        foreach ($series as $row) {
            $this->assertSame(0, $row->requests);
            $this->assertNull($row->cost);
        }
    }

    public function test_a_period_spanning_the_seam_counts_each_day_once(): void {
        $this->log($this->day(3) + HOURSECS);
        $this->log($this->day(3) + 2 * HOURSECS);
        $this->log($this->day(0) + HOURSECS);
        $this->aggregator->run($this->now);

        $series = $this->report->get_series($this->week(), $this->now);

        // The summarised day comes from the summary, today from the detail, and the
        // detail rows still sitting behind the summary are not counted a second time.
        $this->assertSame(2, $series[$this->day(3)]->requests);
        $this->assertSame(1, $series[$this->day(0)]->requests);
        $this->assertSame(3, usage_report::total($series)->requests);
    }

    public function test_the_figures_survive_the_detail_being_purged(): void {
        global $DB;
        $this->log($this->day(3) + HOURSECS);
        $this->aggregator->run($this->now);
        $DB->delete_records(usage_logger::TABLE);

        $series = $this->report->get_series($this->week(), $this->now);

        $this->assertSame(1, $series[$this->day(3)]->requests);
        $this->assertEquals(0.5, usage_report::total($series)->cost);
    }

    public function test_a_breakdown_adds_both_sides_of_the_seam_together(): void {
        $this->log($this->day(3) + HOURSECS);
        $this->log($this->day(0) + HOURSECS);
        $this->log($this->day(0) + 2 * HOURSECS, ['targetid' => 2, 'targetname' => 'Target two']);
        $this->aggregator->run($this->now);

        $rows = $this->report->get_breakdown(usage_report::BY_TARGET, $this->week(), $this->now);

        $this->assertCount(2, $rows);
        $this->assertSame('Target one', $rows[0]->targetname);
        $this->assertSame(2, $rows[0]->requests);
        $this->assertSame('Target two', $rows[1]->targetname);
        $this->assertSame(1, $rows[1]->requests);
    }

    public function test_a_breakdown_by_model_carries_the_tokens_and_the_cost(): void {
        $this->log($this->day(0) + HOURSECS);
        $this->log($this->day(0) + 2 * HOURSECS, ['model' => 'gpt-4o-mini', 'cost' => 0.1]);

        $rows = $this->report->get_breakdown(usage_report::BY_MODEL, $this->week(), $this->now);

        $this->assertCount(2, $rows);
        $this->assertSame('gpt-4o', $rows[0]->model);
        $this->assertSame(100, $rows[0]->prompttokens);
        $this->assertEquals(0.5, $rows[0]->cost);
        $this->assertEquals(0.1, $rows[1]->cost);
    }

    public function test_a_period_nothing_priced_has_no_cost_rather_than_a_cost_of_nothing(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => null]);

        $totals = usage_report::total($this->report->get_series($this->week(), $this->now));

        $this->assertSame(1, $totals->requests);
        $this->assertNull($totals->cost);
        $this->assertSame(0, $totals->costedrequests);
    }

    public function test_a_course_sees_only_itself(): void {
        $this->log($this->day(0) + HOURSECS, ['courseid' => 5]);
        $this->log($this->day(0) + 2 * HOURSECS, ['courseid' => 6]);
        $this->log($this->day(3) + HOURSECS, ['courseid' => 5]);
        $this->aggregator->run($this->now);

        $totals = usage_report::total($this->report->get_series($this->week(), $this->now, 5));

        $this->assertSame(2, $totals->requests);
    }

    public function test_failures_are_counted_by_reason(): void {
        $this->log($this->day(0) + HOURSECS, ['success' => 0, 'reason' => abstract_processor::REASON_DECLINED]);
        $this->log($this->day(0) + 2 * HOURSECS, ['success' => 0, 'reason' => abstract_processor::REASON_DECLINED]);
        $this->log($this->day(0) + 3 * HOURSECS, ['success' => 0, 'reason' => abstract_processor::REASON_ALL_FAILED]);
        $this->log($this->day(0) + 4 * HOURSECS);

        $rows = $this->report->get_failure_reasons($this->week(), $this->now);

        $this->assertCount(2, $rows);
        $this->assertSame(abstract_processor::REASON_DECLINED, $rows[0]->reason);
        $this->assertSame('2', (string) $rows[0]->requests);
    }

    public function test_how_much_of_the_site_reached_the_router(): void {
        global $DB;
        // Core makes (actionname, actionid) unique, so each row points at its own action.
        foreach (['aiprovider_router', 'aiprovider_router', 'aiprovider_openai'] as $index => $provider) {
            $DB->insert_record('ai_action_register', (object) [
                'actionname' => 'generate_text',
                'actionid' => $index + 1,
                'success' => 1,
                'userid' => 0,
                'contextid' => 0,
                'provider' => $provider,
                'timecreated' => $this->day(0) + HOURSECS,
            ]);
        }

        $passthrough = $this->report->get_passthrough($this->week(), $this->now);

        $this->assertSame(3, $passthrough->total);
        $this->assertSame(2, $passthrough->routed);
        $this->assertEqualsWithDelta(2 / 3, $passthrough->share, 0.0001);
    }

    public function test_nothing_to_compare_against_is_not_a_rate_of_zero(): void {
        $passthrough = $this->report->get_passthrough($this->week(), $this->now);

        $this->assertSame(0, $passthrough->total);
        // Reporting nought per cent would say the router is being bypassed, which is a
        // different thing from a site that has made no AI requests at all.
        $this->assertNull($passthrough->share);
    }

    public function test_an_unknown_breakdown_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);

        $this->report->get_breakdown('placement', $this->week(), $this->now);
    }
}
