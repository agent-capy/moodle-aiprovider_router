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

use local_airouter\check\recordgaps;
use local_airouter\usage_aggregator;
use core\check\result;

/**
 * The summariser under the conditions the redesign asked about: interruption, a late
 * ending, purging, deletion, a second run, and what was left open.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(summariser::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recordgaps::class)]
final class summariser_test extends \advanced_testcase {
    /** @var int A fixed now: 10:00 server time, some day. */
    private int $now;

    /** @var \local_airouter_generator The generator. */
    private \local_airouter_generator $generator;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->now = make_timestamp(2026, 9, 20, 10, 0, 0);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
    }

    /**
     * A finished request with two attempts, the first failed and charged, ended at a time.
     *
     * @param int $ended When it ended.
     * @param int $userid Who asked.
     * @return \stdClass The request.
     */
    private function request(int $ended, int $userid = 5): \stdClass {
        $request = $this->generator->create_request([
            'userid' => $userid, 'courseid' => 7, 'answeredby' => 2,
            'timestarted' => $ended - 10, 'timeended' => $ended,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'targetid' => 1, 'targetname' => 'One', 'model' => 'm1',
            'state' => attempt_state::FAILED, 'errorcode' => 500,
            'prompttokens' => 100, 'completiontokens' => 0, 'cost' => 0.001, 'currency' => 'USD',
            'timestarted' => $ended - 8, 'timeended' => $ended - 5,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'targetid' => 2, 'targetname' => 'Two', 'model' => 'm2',
            'keysource' => 'user', 'keyid' => 9,
            'prompttokens' => 100, 'completiontokens' => 50, 'cost' => 0.003, 'currency' => 'USD',
            'timestarted' => $ended - 4, 'timeended' => $ended,
        ]);

        return $request;
    }

    /**
     * The summary as plain totals keyed by day, user, target, model, payer and currency.
     *
     * @return array<string, array> The rows.
     */
    private function summary(): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records(summariser::TABLE, null, 'daystart, userid, targetid, model, keysource, currency') as $row) {
            $key = implode('/', [date('Y-m-d', (int) $row->daystart), $row->userid, $row->targetid, $row->model,
                $row->keysource, $row->currency]);
            $out[$key] = [
                'requests' => (int) $row->requests,
                'failures' => (int) $row->failures,
                'calls' => (int) $row->calls,
                'knowncalls' => (int) $row->knowncalls,
                'tokens' => (int) $row->prompttokens + (int) $row->completiontokens,
                'cost' => round((float) $row->cost, 6),
                'costedcalls' => (int) $row->costedcalls,
                'targetname' => $row->targetname,
            ];
        }

        return $out;
    }

    public function test_requests_and_calls_are_counted_apart_and_payers_are_never_added(): void {
        global $DB;
        $this->request($this->now);
        $result = (new summariser($DB))->run($this->now + DAYSECS);

        $this->assertSame(['lost' => 0, 'applied' => 3, 'purged' => 0, 'purgedsummaries' => 0], $result);
        $summary = $this->summary();
        // The request sits on the row of the target and model that answered it.
        $this->assertSame(1, $summary['2026-09-20/5/2/m2/site/-']['requests']);
        $this->assertSame(0, $summary['2026-09-20/5/2/m2/site/-']['failures']);
        // Each call on its own target, in its own payer's pocket.
        $this->assertSame(['calls' => 1, 'cost' => 0.001, 'targetname' => 'One'], array_intersect_key(
            $summary['2026-09-20/5/1/m1/site/USD'],
            ['calls' => 1, 'cost' => 1, 'targetname' => 1]
        ));
        $this->assertSame(['calls' => 1, 'cost' => 0.003], array_intersect_key(
            $summary['2026-09-20/5/2/m2/user/USD'],
            ['calls' => 1, 'cost' => 1]
        ));
        $this->assertCount(3, $summary);
        // And everything that was counted says so.
        $this->assertSame(0, $DB->count_records(usage_recorder::REQUEST_TABLE, ['applied' => 0]));
        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE, ['applied' => 0]));
    }

    public function test_today_is_left_alone_until_it_is_over(): void {
        global $DB;
        $this->request($this->now);
        (new summariser($DB))->run($this->now + HOURSECS);

        $this->assertSame([], $this->summary());
        $this->assertSame(3, $DB->count_records(usage_recorder::ATTEMPT_TABLE, ['applied' => 0])
            + $DB->count_records(usage_recorder::REQUEST_TABLE, ['applied' => 0]));
    }

    public function test_an_interrupted_run_rerun_equals_a_clean_run(): void {
        global $DB;
        $this->request($this->now, 5);
        $this->request($this->now + 60, 6);
        (new summariser($DB))->run($this->now + DAYSECS);
        $clean = $this->summary();
        $DB->delete_records(summariser::TABLE);
        $DB->set_field(usage_recorder::ATTEMPT_TABLE, 'applied', 0);
        $DB->set_field(usage_recorder::REQUEST_TABLE, 'applied', 0);

        $hits = 0;
        $interrupted = new summariser($DB, function (string $at) use (&$hits): void {
            if ($at === 'attempt_added' && ++$hits === 2) {
                throw new \RuntimeException('interrupted');
            }
        });
        try {
            $interrupted->run($this->now + DAYSECS);
            $this->fail('The stop point did not fire.');
        } catch (\RuntimeException $e) {
            $this->assertSame('interrupted', $e->getMessage());
        }
        $this->assertSame([], $this->summary(), 'A half applied run leaves nothing behind.');
        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE, ['applied' => 1]));

        $this->assertNotFalse((new summariser($DB))->run($this->now + DAYSECS));
        $this->assertSame($clean, $this->summary());
        (new summariser($DB))->run($this->now + DAYSECS);
        $this->assertSame($clean, $this->summary(), 'Running again applies nothing twice.');
    }

    public function test_an_ending_that_arrives_after_its_day_was_summarised_is_still_counted(): void {
        global $DB;
        // Day 1: the request is closed, but its attempt has not come back.
        $request = $this->generator->create_request([
            'userid' => 5, 'state' => request_state::FAILED, 'reason' => 'x',
            'timestarted' => $this->now, 'timeended' => $this->now + 60,
        ]);
        $attempt = $this->generator->create_attempt([
            'requestid' => $request->id, 'state' => attempt_state::STARTED, 'usageknown' => 0,
            'prompttokens' => null, 'completiontokens' => null, 'timestarted' => $this->now, 'timeended' => null,
        ]);
        // Day 2: summarised without it.
        (new summariser($DB))->run($this->now + DAYSECS);
        $this->assertSame(0, $this->summary()['2026-09-20/5/0/-/site/-']['calls']);

        // Then its ending is written, dated inside day 1.
        $DB->update_record(usage_recorder::ATTEMPT_TABLE, (object) [
            'id' => $attempt->id, 'state' => attempt_state::LOST, 'timeended' => $this->now + 3600,
        ]);
        (new summariser($DB))->run($this->now + 2 * DAYSECS);

        $this->assertSame(1, $this->summary()['2026-09-20/5/1/-/site/-']['calls']);
        $this->assertSame(0, $this->summary()['2026-09-20/5/1/-/site/-']['knowncalls'], 'Unknown is not zero.');
    }

    public function test_purge_removes_only_what_has_been_counted(): void {
        global $DB;
        set_config(usage_aggregator::RETENTION_SETTING, 30, 'local_airouter');
        $old = $this->now - 40 * DAYSECS;
        $counted = $this->request($old, 5);
        (new summariser($DB))->run($old + DAYSECS);
        // The second was never applied: it ended, say, while the task was down for weeks.
        $notcounted = $this->request($old + 60, 6);

        // A purge on its own, as a run whose summarising keeps failing would do.
        $purged = (new summariser($DB))->purge($this->now);

        $this->assertSame(3, $purged);
        $this->assertSame(0, $DB->count_records(usage_recorder::REQUEST_TABLE, ['id' => $counted->id]));
        $this->assertSame(1, $DB->count_records(usage_recorder::REQUEST_TABLE, ['id' => $notcounted->id]));
        $this->assertSame(2, $DB->count_records(usage_recorder::ATTEMPT_TABLE, ['requestid' => $notcounted->id]));
        // And a run that does get to summarise counts it before purging it.
        $result = (new summariser($DB))->run($this->now);
        $this->assertSame(3, $result['applied']);
        $this->assertSame(3, $result['purged']);
        $this->assertSame(2, array_sum(array_column($this->summary(), 'requests')));
        $this->assertSame(0, $DB->count_records(usage_recorder::REQUEST_TABLE));
    }

    public function test_a_retention_of_zero_keeps_everything(): void {
        global $DB;
        set_config(usage_aggregator::RETENTION_SETTING, 0, 'local_airouter');
        $this->request($this->now - 400 * DAYSECS);
        $result = (new summariser($DB))->run($this->now);

        $this->assertSame(0, $result['purged']);
        $this->assertSame(1, $DB->count_records(usage_recorder::REQUEST_TABLE));
    }

    public function test_a_summary_past_its_own_retention_goes(): void {
        global $DB;
        set_config(usage_aggregator::SUMMARY_RETENTION_SETTING, 10, 'local_airouter');
        $this->request($this->now - 20 * DAYSECS);
        $this->request($this->now - 5 * DAYSECS);
        $result = (new summariser($DB))->run($this->now);

        // The retention is about the day the row describes, not the day it was written:
        // a day summarised late is not kept longer for it.
        $this->assertSame(3, $result['purgedsummaries']);
        $this->assertSame(3, $DB->count_records(summariser::TABLE));
        $this->assertSame(1, array_sum(array_column($this->summary(), 'requests')));
    }

    public function test_a_second_run_while_one_holds_the_lock_does_nothing(): void {
        global $DB;
        $this->request($this->now);
        // Row based, because the site's factory on MySQL is re-entrant within one
        // connection and a single process cannot see it refuse. What is under test is
        // that the summariser asks, and stands down when told no.
        $factory = new \core\lock\db_record_lock_factory('local_airouter');
        $held = $factory->get_lock(summariser::LOCK, 0);
        $this->assertNotFalse($held);
        try {
            $this->assertFalse((new summariser($DB, null, $factory))->run($this->now + DAYSECS));
            $this->assertSame([], $this->summary());
        } finally {
            $held->release();
        }
        $this->assertNotFalse((new summariser($DB, null, $factory))->run($this->now + DAYSECS));
        $this->assertCount(3, $this->summary());
    }

    public function test_what_was_left_open_too_long_is_given_up_as_lost(): void {
        global $DB;
        $request = $this->generator->create_request([
            'userid' => 5, 'state' => request_state::OPEN, 'timestarted' => $this->now - 7 * HOURSECS, 'timeended' => null,
        ]);
        $stale = $this->generator->create_attempt([
            'requestid' => $request->id, 'state' => attempt_state::STARTED, 'usageknown' => 1,
            'prompttokens' => 5, 'completiontokens' => 5, 'timestarted' => $this->now - 7 * HOURSECS, 'timeended' => null,
        ]);
        $recent = $this->generator->create_request([
            'userid' => 5, 'state' => request_state::OPEN, 'timestarted' => $this->now - HOURSECS, 'timeended' => null,
        ]);
        $inflight = $this->generator->create_attempt([
            'requestid' => $recent->id, 'state' => attempt_state::STARTED, 'timestarted' => $this->now - HOURSECS,
            'timeended' => null,
        ]);

        $result = (new summariser($DB))->run($this->now);

        $this->assertSame(1, $result['lost']);
        $swept = $DB->get_record(usage_recorder::ATTEMPT_TABLE, ['id' => $stale->id]);
        $this->assertSame(attempt_state::LOST, $swept->state);
        $this->assertSame(0, (int) $swept->usageknown, 'What a lost call used is unknown, whatever was written before.');
        $this->assertSame($this->now, (int) $swept->timeended);
        $closed = $DB->get_record(usage_recorder::REQUEST_TABLE, ['id' => $request->id]);
        $this->assertSame(request_state::FAILED, $closed->state);
        $this->assertSame('lost', $closed->reason);
        $this->assertSame(1, (int) $closed->attempts);
        // The one still within its time is left alone.
        $this->assertSame(attempt_state::STARTED, $DB->get_field(usage_recorder::ATTEMPT_TABLE, 'state', ['id' => $inflight->id]));
        $this->assertSame(request_state::OPEN, $DB->get_field(usage_recorder::REQUEST_TABLE, 'state', ['id' => $recent->id]));
    }

    public function test_the_check_is_quiet_when_nothing_is_missing_and_says_what_is_when_it_is(): void {
        global $DB;
        // A site that routes: a default target makes the router configured.
        set_config('defaulttarget', 1, 'local_airouter');
        $this->request($this->now);
        $this->assertSame(result::OK, (new recordgaps())->get_result()->get_status());

        // A write that failed, an attempt given up, and one open for too long.
        set_config(usage_recorder::FAILURES_SETTING, 2, 'local_airouter');
        $request = $this->generator->create_request(['userid' => 5, 'timeended' => time() - 60]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'state' => attempt_state::LOST, 'usageknown' => 0, 'timeended' => time() - 60,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'state' => attempt_state::STARTED, 'timestarted' => time() - 8 * HOURSECS,
            'timeended' => null,
        ]);

        $result = (new recordgaps())->get_result();
        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('2 write(s) failed', $result->get_details());
        $this->assertStringContainsString('1 attempt(s) in the last 30 days', $result->get_details());
        $this->assertStringContainsString('1 request(s) or attempt(s) have been open', $result->get_details());
    }

    public function test_the_check_stands_down_on_a_site_that_has_not_set_the_router_up(): void {
        $this->assertSame(result::NA, (new recordgaps())->get_result()->get_status());
    }
}
