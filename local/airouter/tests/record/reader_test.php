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
 * The reader's one contract: every finished fact is counted once, from the summary
 * if it has been applied and from the detail if it has not, whatever day it is from.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(reader::class)]
final class reader_test extends \advanced_testcase {
    /** @var int A fixed now: 10:00 server time. */
    private int $now;

    /** @var \local_airouter_generator The generator. */
    private \local_airouter_generator $generator;

    /** @var reader The reader under test. */
    private reader $reader;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->now = make_timestamp(2026, 9, 20, 10, 0, 0);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $this->reader = new reader($DB);
    }

    /**
     * A finished request: a failed, charged call on target 1, then an answer from target 2.
     *
     * @param int $ended When it ended.
     * @param int $userid Who asked.
     * @param int $courseid Where.
     * @param string $keysource Whose key the request was to be paid with.
     * @return \stdClass The request.
     */
    private function request(int $ended, int $userid = 5, int $courseid = 7, string $keysource = 'site'): \stdClass {
        $request = $this->generator->create_request([
            'userid' => $userid, 'courseid' => $courseid, 'answeredby' => 2, 'keysource' => $keysource,
            'timestarted' => $ended - 10, 'timeended' => $ended,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'targetid' => 1, 'targetname' => 'One', 'model' => 'm1',
            'state' => attempt_state::FAILED, 'errorcode' => 500, 'keysource' => $keysource,
            'prompttokens' => 100, 'completiontokens' => 0, 'cost' => 0.001, 'currency' => 'USD',
            'timestarted' => $ended - 8, 'timeended' => $ended - 5,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'targetid' => 2, 'targetname' => 'Two', 'model' => 'm2',
            'keysource' => $keysource, 'prompttokens' => 100, 'completiontokens' => 50, 'cost' => 0.003,
            'currency' => 'USD', 'timestarted' => $ended - 4, 'timeended' => $ended,
        ]);

        return $request;
    }

    /**
     * The whole period the tests look at: a week up to an hour after now.
     *
     * @return int[] From and to.
     */
    private function week(): array {
        return [summariser::add_days(summariser::day_of($this->now), -6), $this->now + HOURSECS];
    }

    public function test_a_fact_is_counted_once_whether_or_not_it_has_been_applied(): void {
        global $DB;
        $this->request($this->now - DAYSECS);
        $this->request($this->now);
        [$from, $to] = $this->week();

        // Before the summariser has run, everything comes from the detail.
        $before = reader::total($this->reader->get_series($from, $to));
        $this->assertSame(2, $before->requests);
        $this->assertSame(4, $before->calls);
        $this->assertSame(4, $before->knowncalls);
        $this->assertSame(500, $before->prompttokens + $before->completiontokens);
        $this->assertEqualsWithDelta(0.008, $before->cost, 0.000001);
        $this->assertSame(4, $before->costedcalls);

        // After it, yesterday comes from the summary and today from the detail.
        (new summariser($DB))->run($this->now);
        $this->assertSame(3, $DB->count_records(usage_recorder::ATTEMPT_TABLE, ['applied' => 1])
            + $DB->count_records(usage_recorder::REQUEST_TABLE, ['applied' => 1]));
        $after = reader::total($this->reader->get_series($from, $to));
        $this->assertEquals($before, $after, 'Applying a fact moves it; it does not count it again.');

        // And the day each landed on is the day it ended.
        $series = $this->reader->get_series($from, $to);
        $this->assertSame(1, $series[summariser::day_of($this->now - DAYSECS)]->requests);
        $this->assertSame(1, $series[summariser::day_of($this->now)]->requests);
        $this->assertSame(0, $series[summariser::day_of($this->now - 3 * DAYSECS)]->requests);
        $this->assertNull($series[summariser::day_of($this->now - 3 * DAYSECS)]->cost, 'No money is not zero money.');
    }

    public function test_a_fact_applied_late_is_read_from_the_detail_until_then(): void {
        global $DB;
        // Three days ago, but never applied: the task was down.
        $this->request($this->now - 3 * DAYSECS);
        [$from, $to] = $this->week();

        $series = $this->reader->get_series($from, $to);
        $threedaysago = summariser::day_of($this->now - 3 * DAYSECS);
        $this->assertSame(1, $series[$threedaysago]->requests, 'Old and unapplied is still counted.');

        (new summariser($DB))->run($this->now);
        $this->assertEquals($series, $this->reader->get_series($from, $to));
    }

    public function test_what_has_not_ended_is_not_counted(): void {
        $request = $this->generator->create_request([
            'state' => request_state::OPEN, 'timestarted' => $this->now - 60, 'timeended' => null,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'state' => attempt_state::STARTED, 'timestarted' => $this->now - 50,
            'timeended' => null, 'prompttokens' => null, 'completiontokens' => null, 'usageknown' => 0,
        ]);
        [$from, $to] = $this->week();

        $this->assertSame(0, reader::total($this->reader->get_series($from, $to))->requests);
        $this->assertSame(0, reader::total($this->reader->get_series($from, $to))->calls);
    }

    public function test_breakdowns_group_the_same_way_before_and_after_applying(): void {
        global $DB;
        $this->request($this->now - DAYSECS, 5, 7, 'site');
        $this->request($this->now, 6, 8, 'user');
        [$from, $to] = $this->week();
        $expect = [];
        foreach ([reader::BY_TARGET, reader::BY_ACTION, reader::BY_MODEL, reader::BY_KEYSOURCE, reader::BY_USER] as $by) {
            $expect[$by] = $this->reader->get_breakdown($by, $from, $to);
        }

        // By target: requests sit with the target that answered, calls with each target.
        $bytarget = array_column($expect[reader::BY_TARGET], null, 'targetid');
        $this->assertSame(2, $bytarget[2]->requests);
        $this->assertSame(0, $bytarget[1]->requests);
        $this->assertSame(2, $bytarget[1]->calls);
        $this->assertSame('One', $bytarget[1]->targetname);
        $this->assertEqualsWithDelta(0.002, $bytarget[1]->cost, 0.000001);
        // By model: a request counts under the model that answered it.
        $bymodel = array_column($expect[reader::BY_MODEL], null, 'model');
        $this->assertSame(2, $bymodel['m2']->requests);
        $this->assertSame(2, $bymodel['m1']->calls);
        // By payer: each person's money stays theirs.
        $bykey = array_column($expect[reader::BY_KEYSOURCE], null, 'keysource');
        $this->assertSame(1, $bykey['site']->requests);
        $this->assertSame(1, $bykey['user']->requests);
        $this->assertEqualsWithDelta(0.004, $bykey['user']->cost, 0.000001);
        // By person.
        $byuser = array_column($expect[reader::BY_USER], null, 'userid');
        $this->assertSame(1, $byuser[6]->requests);

        (new summariser($DB))->run($this->now);
        foreach ($expect as $by => $rows) {
            $this->assertEquals($rows, $this->reader->get_breakdown($by, $from, $to), "Breakdown by $by changed on applying.");
        }
    }

    public function test_filters_by_course_and_by_payer(): void {
        global $DB;
        $this->request($this->now - DAYSECS, 5, 7, 'site');
        $this->request($this->now, 6, 8, 'user');
        (new summariser($DB))->run($this->now);
        [$from, $to] = $this->week();

        $this->assertSame(1, reader::total($this->reader->get_series($from, $to, 7))->requests);
        $this->assertSame(2, reader::total($this->reader->get_series($from, $to, 7))->calls);
        $this->assertSame(0, reader::total($this->reader->get_series($from, $to, 9))->requests);
        $this->assertSame(1, reader::total($this->reader->get_series($from, $to, null, 'user'))->requests);
        $this->assertSame(2, reader::total($this->reader->get_series($from, $to, null, reader::KEYSOURCE_ALL))->requests);
    }

    public function test_the_currency_is_the_one_the_money_is_in_or_none_when_it_is_in_two(): void {
        global $DB;
        [$from, $to] = $this->week();
        $this->assertSame(
            \local_airouter\price_book::get_currency(),
            $this->reader->currency_for($from, $to),
            'Nothing priced: the site currency, and the figures read as not known.'
        );

        $this->request($this->now - DAYSECS);
        $this->assertSame('USD', $this->reader->currency_for($from, $to));
        (new summariser($DB))->run($this->now);
        $this->assertSame('USD', $this->reader->currency_for($from, $to));

        $other = $this->generator->create_request(['timeended' => $this->now, 'answeredby' => 1]);
        $this->generator->create_attempt([
            'requestid' => $other->id, 'cost' => 100, 'currency' => 'JPY', 'timeended' => $this->now,
        ]);
        $this->assertNull($this->reader->currency_for($from, $to), 'Two currencies are not one figure.');
        $this->assert_same_values(['USD', 'JPY'], $this->reader->get_currencies($from, $to));
    }

    public function test_failure_reasons_come_from_every_request_still_recorded(): void {
        global $DB;
        [$from, $to] = $this->week();
        $this->generator->create_request([
            'state' => request_state::DECLINED, 'reason' => 'norule', 'timeended' => $this->now - DAYSECS,
        ]);
        $this->generator->create_request(['state' => request_state::DECLINED, 'reason' => 'norule', 'timeended' => $this->now]);
        $this->generator->create_request(['state' => request_state::FAILED, 'reason' => 'allfailed', 'timeended' => $this->now]);
        $this->generator->create_request(['state' => request_state::SUCCEEDED, 'timeended' => $this->now]);
        (new summariser($DB))->run($this->now);

        $reasons = $this->reader->get_failure_reasons($from, $to);
        $this->assertSame(['norule', 'allfailed'], array_column($reasons, 'reason'));
        $this->assertSame([2, 1], array_map('intval', array_column($reasons, 'requests')));
        $this->assertNotNull($this->reader->get_detail_from(), 'The detail still reaches back to these.');
    }

    /**
     * Two arrays hold the same values, in any order.
     *
     * @param array $expected The values.
     * @param array $actual The values found.
     */
    private function assert_same_values(array $expected, array $actual): void {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}
