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

namespace local_airouter;

/**
 * Tests for the figures the monitor reports.
 *
 * The thing most likely to go wrong is the seam: every period the dashboard offers spans
 * the point where the summaries stop and the detail takes over, and a figure that counted
 * that day twice, or lost it, would look entirely plausible.
 *
 * @package    local_airouter
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

    public function test_a_fallen_through_request_reads_the_same_on_both_sides_of_the_seam(): void {
        // One request that took two providers: the first answered with nothing and
        // has a row so that what it spent lands on the right target, the second
        // answered. The detail read that as two requests and one failure while the
        // summary read it as one request and no failures, so the figures changed
        // under the reader the morning after.
        $this->log($this->day(1) + HOURSECS, ['counted' => 0, 'success' => 0, 'cost' => 0.5]);
        $this->log($this->day(1) + 2 * HOURSECS, ['cost' => 0.5]);

        $before = usage_report::total($this->report->get_series($this->day(6), $this->now));
        $this->assertSame(1, $before->requests);
        $this->assertSame(0, $before->failures);
        $this->assertSame(2, $before->calls);
        $this->assertSame(2, $before->costedcalls);

        $this->aggregator->run($this->now);

        $after = usage_report::total($this->report->get_series($this->day(6), $this->now));
        $this->assertSame(1, $after->requests);
        $this->assertSame(0, $after->failures);
        $this->assertSame(2, $after->calls);
        $this->assertSame(2, $after->costedcalls);
        $this->assertEqualsWithDelta(1.0, (float) $after->cost, 0.000001);
    }

    public function test_a_period_nothing_priced_has_no_cost_rather_than_a_cost_of_nothing(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => null]);

        $totals = usage_report::total($this->report->get_series($this->week(), $this->now));

        $this->assertSame(1, $totals->requests);
        $this->assertNull($totals->cost);
        $this->assertSame(0, $totals->costedcalls);
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
        foreach (['local_airouter', 'local_airouter', 'aiprovider_openai'] as $index => $provider) {
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

    public function test_a_payer_can_be_asked_about_on_its_own(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => 0.25]);
        $this->log($this->day(0) + HOURSECS, ['cost' => 9.0, 'keysource' => rule::KEYSOURCE_USER]);

        $site = usage_report::total($this->report->get_series(
            $this->week(),
            $this->now,
            null,
            rule::KEYSOURCE_SITE,
        ));

        // Nine of those units were spent by somebody out of their own pocket. A site
        // asking what its AI cost is not asking about that money.
        $this->assertSame(1, (int) $site->requests);
        $this->assertEqualsWithDelta(0.25, (float) $site->cost, 0.000001);
    }

    public function test_a_payer_is_told_apart_on_both_sides_of_the_seam(): void {
        // One day summarised, one day still only in the detail. Both carry the column,
        // and a filter that worked on one of them would quietly halve the answer.
        $this->log($this->day(1) + HOURSECS, ['keysource' => rule::KEYSOURCE_COURSE]);
        $this->log($this->day(1) + HOURSECS);
        $this->aggregator->run($this->now);
        $this->log($this->day(0) + HOURSECS, ['keysource' => rule::KEYSOURCE_COURSE]);

        $rows = $this->report->get_breakdown(
            usage_report::BY_TARGET,
            $this->week(),
            $this->now,
            null,
            rule::KEYSOURCE_COURSE,
        );

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]->requests);
    }

    public function test_who_paid_is_a_breakdown_of_its_own(): void {
        $this->log($this->day(1) + HOURSECS);
        $this->aggregator->run($this->now);
        $this->log($this->day(0) + HOURSECS, ['keysource' => rule::KEYSOURCE_USER]);
        $this->log($this->day(0) + HOURSECS, ['keysource' => rule::KEYSOURCE_USER]);

        $rows = $this->report->get_breakdown(usage_report::BY_KEYSOURCE, $this->week(), $this->now);

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->keysource] = (int) $row->requests;
        }
        $this->assertSame([rule::KEYSOURCE_USER => 2, rule::KEYSOURCE_SITE => 1], $counts);
    }

    public function test_asking_for_every_payer_narrows_nothing(): void {
        $this->log($this->day(0) + HOURSECS);
        $this->log($this->day(0) + HOURSECS, ['keysource' => rule::KEYSOURCE_USER]);

        $all = usage_report::total($this->report->get_series(
            $this->week(),
            $this->now,
            null,
            usage_report::KEYSOURCE_ALL,
        ));

        $this->assertSame(2, (int) $all->requests);
    }

    public function test_failures_can_be_narrowed_to_one_payer_too(): void {
        $this->log($this->day(0) + HOURSECS, [
            'success' => 0,
            'reason' => 'byok_key_rejected',
            'keysource' => rule::KEYSOURCE_USER,
        ]);
        $this->log($this->day(0) + HOURSECS, ['success' => 0, 'reason' => 'all_targets_failed']);

        $reasons = $this->report->get_failure_reasons(
            $this->week(),
            $this->now,
            null,
            rule::KEYSOURCE_USER,
        );

        $this->assertCount(1, $reasons);
        $this->assertSame('byok_key_rejected', $reasons[0]->reason);
    }

    public function test_a_summarised_day_survives_the_site_changing_timezone(): void {
        // A summary is stamped with the midnight in force when the task ran. Change
        // the site's timezone afterwards and that stamp is not a midnight any more, so
        // looking rows up by it found nothing: the chart and the headline total read
        // as an empty week while the breakdown beside them still counted everything.
        $this->log($this->day(3) + HOURSECS);
        $this->log($this->day(2) + HOURSECS);
        $this->aggregator->run($this->now);

        self::setTimezone('Pacific/Honolulu', 'Pacific/Honolulu');
        $moved = new usage_aggregator($GLOBALS['DB']);
        $report = new usage_report($GLOBALS['DB'], $moved);
        $from = $moved->add_days($moved->day_of($this->now), -6);

        $series = $report->get_series($from, $this->now);
        $breakdown = $report->get_breakdown(usage_report::BY_TARGET, $from, $this->now);

        // The two have to agree. Either figure on its own looks plausible; the pair
        // disagreeing is the only thing that shows something is wrong.
        $this->assertSame(2, (int) usage_report::total($series)->requests);
        $this->assertSame(2, (int) usage_report::total($breakdown)->requests);
    }

    public function test_two_old_days_landing_on_one_new_day_are_added_up(): void {
        // Moving the clock far enough can put two stored days inside one of the new
        // ones. The later must not simply replace the earlier.
        $this->log($this->day(3) + HOURSECS);
        $this->log($this->day(3) + 2 * HOURSECS);
        $this->aggregator->run($this->now);

        self::setTimezone('Pacific/Kiritimati', 'Pacific/Kiritimati');
        $moved = new usage_aggregator($GLOBALS['DB']);
        $report = new usage_report($GLOBALS['DB'], $moved);
        $from = $moved->add_days($moved->day_of($this->now), -6);

        $series = $report->get_series($from, $this->now);

        $this->assertSame(2, (int) usage_report::total($series)->requests);
    }

    public function test_a_period_in_one_currency_says_which(): void {
        $this->log($this->day(1) + HOURSECS, ['currency' => 'JPY', 'cost' => 100.0]);

        $this->assertSame(['JPY'], $this->report->get_currencies($this->week(), $this->now));
    }

    public function test_a_period_that_priced_nothing_names_no_currency(): void {
        $this->log($this->day(1) + HOURSECS, ['currency' => 'JPY', 'cost' => null]);

        // Not JPY. Nothing here was priced, so nothing here is in a currency.
        $this->assertSame([], $this->report->get_currencies($this->week(), $this->now));
    }

    public function test_a_period_holding_two_currencies_says_both(): void {
        // A site that changed its currency after it started using AI. Costs are worked
        // out when a request happens and kept, and nothing is ever converted.
        $this->log($this->day(3) + HOURSECS, ['currency' => 'JPY', 'cost' => 1000.0]);
        $this->log($this->day(1) + HOURSECS, ['currency' => 'USD', 'cost' => 10.0]);

        $found = $this->report->get_currencies($this->week(), $this->now);
        sort($found);

        $this->assertSame(['JPY', 'USD'], $found);
    }

    public function test_the_currencies_are_found_on_both_sides_of_the_seam(): void {
        global $DB;

        $this->log($this->day(3) + HOURSECS, ['currency' => 'JPY', 'cost' => 1000.0]);
        $this->aggregator->run($this->now);
        $DB->delete_records(usage_logger::TABLE);
        $this->log($this->day(0) + HOURSECS, ['currency' => 'USD', 'cost' => 10.0]);

        $found = $this->report->get_currencies($this->week(), $this->now);
        sort($found);

        $this->assertSame(['JPY', 'USD'], $found);
    }

    public function test_a_period_in_one_currency_names_it(): void {
        $this->log($this->day(1) + HOURSECS, ['currency' => 'JPY', 'cost' => 100.0]);

        $this->assertSame('JPY', $this->report->currency_for($this->week(), $this->now));
    }

    public function test_a_period_in_two_currencies_names_none(): void {
        // Null is the answer every table, the chart and the exported file read as
        // "there is no figure to give". Decided once so that they cannot disagree.
        $this->log($this->day(3) + HOURSECS, ['currency' => 'JPY', 'cost' => 1000.0]);
        $this->log($this->day(1) + HOURSECS, ['currency' => 'USD', 'cost' => 10.0]);

        $this->assertNull($this->report->currency_for($this->week(), $this->now));
    }

    public function test_a_period_that_priced_nothing_falls_back_to_the_sites_currency(): void {
        $this->log($this->day(1) + HOURSECS, ['currency' => 'JPY', 'cost' => null]);

        $this->assertSame(
            price_book::get_currency(),
            $this->report->currency_for($this->week(), $this->now),
        );
    }

    public function test_the_part_of_a_shifted_day_that_was_never_summarised_is_counted(): void {
        // Move the clock east and a day now begins before the point the summariser
        // reached. Such a day holds a summarised part and a part that is still in
        // the detail, and skipping it whole for beginning before that point lost the
        // second: the chart said one thing and the breakdown beside it said another,
        // with nothing on the screen to say which was right.
        $this->log($this->day(1) + HOURSECS);
        $this->aggregator->run($this->now);
        $this->log($this->day(0) + HOURSECS);

        self::setTimezone('Asia/Tokyo', 'Asia/Tokyo');
        $moved = new usage_aggregator($GLOBALS['DB']);
        $report = new usage_report($GLOBALS['DB'], $moved);
        $from = $moved->add_days($moved->day_of($this->now), -6);

        $series = $report->get_series($from, $this->now);
        $breakdown = $report->get_breakdown(usage_report::BY_TARGET, $from, $this->now);

        $this->assertSame(2, (int) usage_report::total($series)->requests);
        $this->assertSame(2, (int) usage_report::total($breakdown)->requests);
    }

    public function test_an_unknown_breakdown_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);

        $this->report->get_breakdown('placement', $this->week(), $this->now);
    }
}
