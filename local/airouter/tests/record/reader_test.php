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

    public function test_money_is_kept_by_provider_and_never_added_across_them(): void {
        global $DB;
        [$from, $to] = $this->week();
        $this->assertSame([], $this->reader->get_money($from, $to), 'Nothing priced: no money, not zero money.');
        $this->assertNull(reader::total($this->reader->get_series($from, $to))->cost);

        $this->request($this->now - DAYSECS);
        $total = reader::total($this->reader->get_series($from, $to));
        $this->assertSame('aiprovider_mock', $total->provider, 'One provider: one figure, with its currency.');
        $this->assertSame('USD', $total->currency);
        $this->assertEqualsWithDelta(0.004, $total->cost, 0.000001);

        // A second provider, billed in yen. Its money is laid beside the first's,
        // not added to it, before and after the summariser has run.
        $other = $this->generator->create_request(['timeended' => $this->now, 'answeredby' => 1]);
        $this->generator->create_attempt([
            'requestid' => $other->id, 'targetprovider' => 'aiprovider_sakuraaiengine', 'cost' => 100,
            'currency' => 'JPY', 'timeended' => $this->now,
        ]);
        $expected = [
            'aiprovider_mock|USD' => ['provider' => 'aiprovider_mock', 'currency' => 'USD', 'amount' => 0.004],
            'aiprovider_sakuraaiengine|JPY' => ['provider' => 'aiprovider_sakuraaiengine', 'currency' => 'JPY', 'amount' => 100.0],
        ];
        $before = $this->reader->get_money($from, $to);
        $this->assertEqualsWithDelta($expected, $before, 0.000001);
        $total = reader::total($this->reader->get_series($from, $to));
        $this->assertNull($total->cost, 'Two providers are not one figure.');
        $this->assertNull($total->currency);
        $this->assertEqualsWithDelta($expected, $total->costs, 0.000001);

        (new summariser($DB))->run($this->now);
        $this->assertEqualsWithDelta($expected, $this->reader->get_money($from, $to), 0.000001, 'Applying moves nothing.');

        // The breakdown by provider is the table a site adds up by hand, or does not.
        $byprovider = $this->reader->get_breakdown(reader::BY_PROVIDER, $from, $to);
        $this->assertSame(['aiprovider_mock', 'aiprovider_sakuraaiengine'], array_column($byprovider, 'targetprovider'));
        $this->assertSame('USD', $byprovider[0]->currency);
        $this->assertSame(2, $byprovider[0]->calls);
        $this->assertSame('JPY', $byprovider[1]->currency);
        $this->assertEqualsWithDelta(100.0, $byprovider[1]->cost, 0.000001);
    }

    public function test_two_providers_billed_alike_are_still_not_added(): void {
        // Whether a budget is one figure for all AI or one per provider is the site's
        // to decide, so the figures are laid side by side for it to add or not.
        [$from, $to] = $this->week();
        $this->request($this->now);
        $other = $this->generator->create_request(['timeended' => $this->now, 'answeredby' => 1]);
        $this->generator->create_attempt([
            'requestid' => $other->id, 'targetprovider' => 'aiprovider_openai', 'cost' => 1, 'currency' => 'USD',
            'timeended' => $this->now,
        ]);

        $total = reader::total($this->reader->get_series($from, $to));

        $this->assertCount(2, $total->costs);
        $this->assertNull($total->cost);
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

    /**
     * Run something on a second database connection, as another process would.
     *
     * @param \Closure $operation What to run; it is given the other connection.
     * @return mixed What it returned.
     */
    private function separately(\Closure $operation): mixed {
        global $DB, $CFG;
        $original = $DB;
        $other = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $other->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
        try {
            $DB = $other;

            return $operation($other);
        } finally {
            $DB = $original;
            $other->dispose();
        }
    }

    public function test_a_summariser_committing_between_the_summary_and_the_detail_is_read_again(): void {
        global $DB;
        // Another connection commits a real run, so the test's own rows have to be
        // committed too, which the ordinary rollback based reset does not do.
        $this->preventResetByRollback();
        $this->request($this->now - DAYSECS);
        [$from, $to] = $this->week();
        $passes = 0;
        $reader = new reader($DB, function (string $point) use (&$passes): void {
            // The first time the summary has been read, apply everything from another
            // connection: the fact is then neither in the summary just read nor in
            // the detail about to be read, unless the read notices and starts over.
            if ($point === 'summarised' && $passes++ === 0) {
                $this->separately(fn($db) => (new summariser($db))->run($this->now));
            }
        });

        $money = $reader->get_money($from, $to);
        $total = reader::total($reader->get_series($from, $to));

        $this->assertEqualsWithDelta(0.004, $money['aiprovider_mock|USD']['amount'] ?? null, 0.000001);
        $this->assertSame(1, $total->requests);
        $this->assertSame(2, $total->calls);
        $this->assertTrue($reader->was_consistent());
        $this->assertSame(2, $DB->count_records(summariser::TABLE, ['currency' => 'USD']), 'It was applied: a row per call.');
    }
}
