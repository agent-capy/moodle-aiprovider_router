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
 * Tests for the report that answers who used the AI.
 *
 * Two things here are easy to get wrong and hard to notice. One is the seam: a person's
 * figures come from the summaries for the older half of a period and from the detail for
 * the rest, and a day counted twice or lost would look entirely plausible. The other is
 * keeping what the site paid apart from what people paid themselves, which is the whole
 * reason the report exists rather than one total per person.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(user_report::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(user_report_formatter::class)]
final class user_report_test extends \advanced_testcase {
    /** @var usage_aggregator The aggregator the report reads through. */
    protected usage_aggregator $aggregator;

    /** @var user_report The report under test. */
    protected user_report $report;

    /** @var int A fixed moment to measure days from. */
    protected int $now;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        self::setTimezone('UTC', 'UTC');
        $this->aggregator = new usage_aggregator($DB);
        $this->report = new user_report($DB, $this->aggregator);
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
     * The whole of the last week.
     *
     * @return int The start of the period.
     */
    protected function week(): int {
        return $this->aggregator->add_days($this->aggregator->day_of($this->now), -6);
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
            'userid' => 5,
            'contextid' => 0,
            'courseid' => null,
            'actionname' => 'generate_text',
            'placement' => 'aiplacement_editor',
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

    public function test_people_are_listed_busiest_first(): void {
        $this->log($this->day(0) + HOURSECS, ['userid' => 5]);
        $this->log($this->day(0) + HOURSECS, ['userid' => 5]);
        $this->log($this->day(0) + HOURSECS, ['userid' => 6]);

        $people = $this->report->get_people($this->week(), $this->now);

        $this->assertSame([5, 6], array_column($people, 'userid'));
        $this->assertSame(2, (int) $people[0]->requests);
    }

    public function test_what_the_site_paid_is_kept_apart_from_what_somebody_paid(): void {
        $this->log($this->day(0) + HOURSECS, ['userid' => 5, 'cost' => 0.25]);
        $this->log($this->day(0) + HOURSECS, [
            'userid' => 5,
            'cost' => 9.0,
            'keysource' => rule::KEYSOURCE_USER,
        ]);

        $people = $this->report->get_people($this->week(), $this->now);

        // Added together this reads as the site having spent 9.25, which it did not.
        $this->assertEqualsWithDelta(0.25, (float) $people[0]->sitecost, 0.000001);
        $this->assertEqualsWithDelta(9.0, (float) $people[0]->broughtcost, 0.000001);
        $this->assertSame(1, (int) $people[0]->broughtrequests);
        $this->assertSame(2, (int) $people[0]->requests);
    }

    public function test_a_person_is_counted_once_across_the_seam(): void {
        // One summarised day and one that is still only in the detail. The report reads
        // a different table for each, and adding the two must not double the person.
        $this->log($this->day(1) + HOURSECS, ['userid' => 5]);
        $this->aggregator->run($this->now);
        $this->log($this->day(0) + HOURSECS, ['userid' => 5]);

        $people = $this->report->get_people($this->week(), $this->now);

        $this->assertCount(1, $people);
        $this->assertSame(2, (int) $people[0]->requests);
    }

    public function test_a_persons_days_come_from_both_sides_of_the_seam(): void {
        $this->log($this->day(1) + HOURSECS, ['userid' => 5]);
        $this->aggregator->run($this->now);
        $this->log($this->day(0) + HOURSECS, ['userid' => 5]);
        $this->log($this->day(0) + 2 * HOURSECS, ['userid' => 5]);

        $days = $this->report->get_days(5, $this->week(), $this->now);

        // Most recent first, and today's two requests gathered into one row.
        $this->assertCount(2, $days);
        $this->assertSame($this->day(0), (int) $days[0]->daystart);
        $this->assertSame(2, (int) $days[0]->requests);
        $this->assertSame(1, (int) $days[1]->requests);
    }

    public function test_one_persons_fallen_through_request_is_one_request_on_both_sides(): void {
        // Somebody asked once and the first provider answered with nothing, so there
        // are two rows. Their own day listing counted rows, so it showed them two
        // requests and one failure until the day was summarised and it became one
        // request and none. The same day, read twice, is the same day.
        $this->log($this->day(1) + HOURSECS, ['userid' => 5, 'counted' => 0, 'success' => 0]);
        $this->log($this->day(1) + 2 * HOURSECS, ['userid' => 5]);

        $before = $this->report->get_days(5, $this->week(), $this->now);
        $this->assertCount(1, $before);
        $this->assertSame(1, (int) $before[0]->requests);
        $this->assertSame(0, (int) $before[0]->failures);
        $this->assertSame(2, (int) $before[0]->calls);

        $this->aggregator->run($this->now);

        $after = $this->report->get_days(5, $this->week(), $this->now);
        $this->assertCount(1, $after);
        $this->assertSame(1, (int) $after[0]->requests);
        $this->assertSame(0, (int) $after[0]->failures);
        $this->assertSame(2, (int) $after[0]->calls);
    }

    public function test_one_persons_days_leave_everybody_else_out(): void {
        $this->log($this->day(0) + HOURSECS, ['userid' => 5]);
        $this->log($this->day(0) + HOURSECS, ['userid' => 6]);

        $days = $this->report->get_days(5, $this->week(), $this->now);

        $this->assertCount(1, $days);
        $this->assertSame(1, (int) $days[0]->requests);
    }

    public function test_individual_requests_say_when_and_where(): void {
        $this->log($this->day(0) + HOURSECS, ['userid' => 5, 'placement' => 'aiplacement_courseassist']);

        $requests = $this->report->get_requests(5, $this->week(), $this->now);

        $this->assertCount(1, $requests);
        $this->assertSame('aiplacement_courseassist', $requests[0]->placement);
        $this->assertSame($this->day(0) + HOURSECS, (int) $requests[0]->timecreated);
    }

    public function test_summaries_written_before_anybody_was_named_are_not_user_zero(): void {
        global $DB;
        $DB->insert_record(usage_aggregator::TABLE, (object) [
            'daystart' => $this->day(1),
            'courseid' => null,
            'userid' => null,
            'actionname' => 'generate_text',
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'keysource' => usage_logger::KEY_SITE,
            'currency' => 'USD',
            'requests' => 7,
            'failures' => 0,
            'calls' => 7,
            'prompttokens' => 10,
            'completiontokens' => 5,
            'cost' => 1.0,
            'costedcalls' => 7,
            'timecreated' => $this->now,
        ]);
        set_config(usage_aggregator::LAST_SETTING, $this->day(1), 'aiprovider_router');

        $people = $this->report->get_people($this->week(), $this->now);

        // They are shown, because the spending happened, and they are shown as belonging
        // to nobody rather than being quietly attributed to an account.
        $this->assertCount(1, $people);
        $this->assertSame(0, (int) $people[0]->userid);
        $this->assertStringContainsString(
            get_string('report:unattributed', 'aiprovider_router'),
            user_report_formatter::person(0, [], new \moodle_url('/')),
        );
    }

    public function test_a_deleted_account_is_said_to_be_gone_rather_than_named(): void {
        $output = user_report_formatter::person(999999, [], new \moodle_url('/'));

        $this->assertStringContainsString('999999', $output);
        $this->assertStringNotContainsString('<a ', $output);
    }

    public function test_the_key_holders_carry_nothing_of_the_key_itself(): void {
        global $DB;
        (new key_repository($DB))->save(key::SCOPE_USER, 5, 3, 'sk-secret-value-wxyz');

        $holders = $this->report->get_key_holders();

        $this->assertCount(1, $holders);
        $encoded = json_encode($holders);
        $this->assertStringNotContainsString('sk-secret-value-wxyz', $encoded);
        // Not even the hint: an administrator asking who brings keys has no use for a
        // fragment of somebody's credential.
        $this->assertStringNotContainsString('wxyz', $encoded);
        $this->assertObjectNotHasProperty('hint', $holders[0]);
    }

    public function test_what_each_brought_key_was_used_for(): void {
        $this->log($this->day(0) + HOURSECS, ['keysource' => rule::KEYSOURCE_USER, 'keyid' => 12]);
        $this->log($this->day(0) + HOURSECS, ['keysource' => rule::KEYSOURCE_USER, 'keyid' => 12]);
        $this->log($this->day(0) + HOURSECS);

        $usage = $this->report->get_key_usage($this->week(), $this->now);

        $this->assertCount(1, $usage);
        $this->assertSame(2, (int) $usage[12]->requests);
    }

    public function test_who_may_see_who_used_the_ai(): void {
        // Decision G. The same figures narrowed to one course are a record of what each
        // learner did, and whether anybody at course level should hold that is a question
        // for the site to answer rather than an assumption made here.
        $this->assertTrue(has_capability(
            'aiprovider/router:viewuserusage',
            \context_system::instance(),
            get_admin(),
        ));

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->assertFalse(has_capability(
            'aiprovider/router:viewuserusage',
            \context_course::instance($course->id),
            $teacher,
        ));
    }

    public function test_the_exported_rows_carry_the_same_split_as_the_screen(): void {
        $this->log($this->day(0) + HOURSECS, ['userid' => 5, 'cost' => 0.25]);
        $this->log($this->day(0) + HOURSECS, [
            'userid' => 5,
            'cost' => 9.0,
            'keysource' => rule::KEYSOURCE_COURSE,
        ]);

        $rows = user_report_formatter::people_rows(
            $this->report->get_people($this->week(), $this->now),
            [5 => 'Ada Lovelace'],
            'USD',
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Ada Lovelace', $rows[0][1]);
        $this->assertCount(count(user_report_formatter::export_columns()), $rows[0]);
        $this->assertEqualsWithDelta(0.25, $rows[0][5], 0.000001);
        $this->assertEqualsWithDelta(9.0, $rows[0][6], 0.000001);
    }

    public function test_a_request_is_shown_in_the_currency_it_was_recorded_in(): void {
        // A site that changed its currency after it started using AI. Costs are worked
        // out when a request happens and kept, so the old rows are still in the old
        // currency and relabelling them is a different number, not the same one again.
        $table = user_report_formatter::requests(
            [(object) [
                'timecreated' => $this->now,
                'actionname' => 'generate_text',
                'placement' => null,
                'targetname' => 'Target one',
                'targetprovider' => 'aiprovider_openai',
                'model' => 'gpt-4o',
                'keysource' => 'site',
                'prompttokens' => 100,
                'completiontokens' => 50,
                'cost' => 1000.0,
                'currency' => 'JPY',
                'success' => 1,
                'reason' => null,
                'courseid' => null,
            ]],
            'USD',
        );

        $this->assertStringContainsString('JPY', $table->data[0]->cells[7]);
        $this->assertStringNotContainsString('USD', $table->data[0]->cells[7]);
    }
}
