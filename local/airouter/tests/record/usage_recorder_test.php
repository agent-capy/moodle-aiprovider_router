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

use local_airouter\evaluation_context;
use local_airouter\rule;
use core_ai\aiactions\generate_text;

/**
 * The recorder on its own: what it writes at each of its four moments.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_recorder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(usage::class)]
final class usage_recorder_test extends \advanced_testcase {
    /** @var int The clock the recorder reads. */
    private int $clock = 1_800_000_000;

    /** @var usage_recorder The recorder under test. */
    private usage_recorder $recorder;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->recorder = new usage_recorder($DB, null, fn(): int => $this->clock);
    }

    /**
     * A context for a request by the admin in the system context.
     *
     * @return evaluation_context The context.
     */
    private function context(): evaluation_context {
        $action = new generate_text(contextid: \context_system::instance()->id, userid: 2, prompttext: 'Hi');

        return new evaluation_context($action, placement: 'aiplacement_editor');
    }

    /**
     * A provider instance to be asked.
     *
     * @return \core_ai\provider The instance.
     */
    private function target(): \core_ai\provider {
        return new \aiprovider_openai\provider(enabled: true, name: 'Named target', config: '{}', id: 42);
    }

    public function test_a_request_opens_with_what_is_known_and_nothing_else(): void {
        global $DB;
        $id = $this->recorder->begin_request($this->context());

        $row = $DB->get_record(usage_recorder::REQUEST_TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(request_state::OPEN, $row->state);
        $this->assertSame(2, (int) $row->userid);
        $this->assertSame('generate_text', $row->actionname);
        $this->assertSame('aiplacement_editor', $row->placement);
        $this->assertNull($row->courseid);
        $this->assertSame($this->clock, (int) $row->timestarted);
        $this->assertNull($row->timeended);
        $this->assertNull($row->ruleid, 'Nothing has been decided yet.');
    }

    public function test_an_attempt_is_on_record_as_started_before_it_ends(): void {
        global $DB;
        $request = $this->recorder->begin_request($this->context());
        $id = $this->recorder->begin_attempt($request, 1, $this->target(), 'aiprovider_openai', rule::KEYSOURCE_COURSE, 9);

        $row = $DB->get_record(usage_recorder::ATTEMPT_TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(attempt_state::STARTED, $row->state);
        $this->assertSame(42, (int) $row->targetid);
        $this->assertSame('Named target', $row->targetname);
        $this->assertSame(rule::KEYSOURCE_COURSE, $row->keysource);
        $this->assertSame(9, (int) $row->keyid);
        $this->assertNull($row->timeended);
        $this->assertSame(0, (int) $row->usageknown);
    }

    public function test_ending_an_attempt_keeps_the_time_it_ended_not_the_time_it_started(): void {
        global $DB;
        $request = $this->recorder->begin_request($this->context());
        $id = $this->recorder->begin_attempt($request, 1, $this->target(), 'aiprovider_openai', rule::KEYSOURCE_SITE, null);
        $this->clock += 90;
        $this->recorder->end_attempt($id, attempt_state::SUCCEEDED, new usage(3, 4), 'gpt', null, 'aiprovider_openai');

        $row = $DB->get_record(usage_recorder::ATTEMPT_TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame($this->clock - 90, (int) $row->timestarted);
        $this->assertSame($this->clock, (int) $row->timeended);
        $this->assertSame(1, (int) $row->usageknown);
        $this->assertSame('gpt', $row->model);
    }

    public function test_an_attempt_cannot_end_in_a_state_that_is_not_an_ending(): void {
        $request = $this->recorder->begin_request($this->context());
        $id = $this->recorder->begin_attempt($request, 1, $this->target(), 'aiprovider_openai', rule::KEYSOURCE_SITE, null);

        $this->expectException(\coding_exception::class);
        $this->recorder->end_attempt($id, attempt_state::STARTED, usage::unknown(), null, null, 'aiprovider_openai');
    }

    public function test_a_request_cannot_end_open(): void {
        $request = $this->recorder->begin_request($this->context());

        $this->expectException(\coding_exception::class);
        $this->recorder->end_request($request, request_state::OPEN, null, null, null, null, rule::KEYSOURCE_SITE, 0);
    }

    public function test_a_request_that_could_not_be_opened_is_let_go_of_quietly(): void {
        global $DB;
        // Nothing can be written for a request that has no row, and nothing tries to.
        $this->recorder->end_attempt(null, attempt_state::LOST, usage::unknown(), null, null, 'aiprovider_openai');
        $this->recorder->end_request(null, request_state::FAILED, 'x', 500, null, null, rule::KEYSOURCE_SITE, 0);
        $this->assertSame(null, $this->recorder->begin_attempt(null, 1, $this->target(), 'aiprovider_openai', 'site', null));
        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE));
        $this->assertDebuggingNotCalled();
    }

    /**
     * A rate for the openai provider, any model.
     *
     * @param float $promptrate Per million prompt tokens.
     * @param int $timefrom When the rate takes effect.
     * @param string $currency What the rate is in.
     */
    private function rate(float $promptrate, int $timefrom = 0, string $currency = 'USD'): void {
        $rate = new \local_airouter\price();
        $rate->set('provider', 'aiprovider_openai');
        $rate->set('currency', $currency);
        $rate->set('model', '');
        $rate->set('promptrate', $promptrate);
        $rate->set('timefrom', $timefrom);
        $rate->create();
    }

    public function test_the_same_ending_sent_again_changes_nothing(): void {
        global $DB;
        $this->rate(1.0);
        $request = $this->recorder->begin_request($this->context());
        $id = $this->recorder->begin_attempt($request, 1, $this->target(), 'aiprovider_openai', rule::KEYSOURCE_SITE, null);
        $this->recorder->end_attempt($id, attempt_state::SUCCEEDED, new usage(1000000, 0), 'm', null, 'aiprovider_openai');
        $before = $DB->get_record(usage_recorder::ATTEMPT_TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(1.0, (float) $before->cost, 0.000001);

        // A day later, at nine times the price, a retried process sends the same ending.
        $this->rate(9.0, $this->clock + HOURSECS);
        $this->clock += DAYSECS;
        $this->recorder->end_attempt($id, attempt_state::SUCCEEDED, new usage(1000000, 0), 'm', null, 'aiprovider_openai');

        $after = $DB->get_record(usage_recorder::ATTEMPT_TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals($before, $after, 'An ending is written once. Rates and days move on; the record does not.');
        $this->assertDebuggingNotCalled();
    }

    public function test_a_cost_is_recorded_in_the_currency_of_the_rate_that_produced_it(): void {
        global $DB;
        // The provider bills in yen, so its rate is in yen, and so is the cost. There
        // is no site currency for the row to be labelled with instead.
        $this->rate(100.0, 0, 'JPY');
        $request = $this->recorder->begin_request($this->context());
        $id = $this->recorder->begin_attempt($request, 1, $this->target(), 'aiprovider_openai', rule::KEYSOURCE_SITE, null);

        $this->recorder->end_attempt($id, attempt_state::SUCCEEDED, new usage(1000000, 0), 'm', null, 'aiprovider_openai');

        $row = $DB->get_record(usage_recorder::ATTEMPT_TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(100.0, (float) $row->cost, 0.000001);
        $this->assertSame('JPY', $row->currency);
    }

    public function test_a_different_ending_sent_later_is_not_believed_either(): void {
        global $DB;
        $request = $this->recorder->begin_request($this->context());
        $id = $this->recorder->begin_attempt($request, 1, $this->target(), 'aiprovider_openai', rule::KEYSOURCE_SITE, null);
        $this->recorder->end_attempt($id, attempt_state::SUCCEEDED, new usage(3, 4), 'm', null, 'aiprovider_openai');
        $this->recorder->end_attempt($id, attempt_state::FAILED, usage::unknown(), null, 500, 'aiprovider_openai');

        $row = $DB->get_record(usage_recorder::ATTEMPT_TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(attempt_state::SUCCEEDED, $row->state);
        $this->assertSame(3, (int) $row->prompttokens);
        $this->assertNull($row->errorcode);
    }

    public function test_a_request_closed_again_keeps_the_day_it_was_closed_on(): void {
        global $DB;
        $request = $this->recorder->begin_request($this->context());
        $this->recorder->end_request($request, request_state::DECLINED, 'norulematched', 503, null, null, rule::KEYSOURCE_SITE, 0);
        $before = $DB->get_record(usage_recorder::REQUEST_TABLE, ['id' => $request], '*', MUST_EXIST);

        $this->clock += DAYSECS;
        $this->recorder->end_request($request, request_state::DECLINED, 'norulematched', 503, null, null, rule::KEYSOURCE_SITE, 0);

        $after = $DB->get_record(usage_recorder::REQUEST_TABLE, ['id' => $request], '*', MUST_EXIST);
        $this->assertSame((int) $before->timeended, (int) $after->timeended);
        $this->assertEquals($before, $after);
    }

    public function test_an_attempt_is_not_attached_to_a_request_that_has_been_forgotten(): void {
        global $DB;
        $request = $this->recorder->begin_request($this->context());
        $DB->delete_records(usage_recorder::REQUEST_TABLE, ['id' => $request]);

        $id = $this->recorder->begin_attempt($request, 1, $this->target(), 'aiprovider_openai', rule::KEYSOURCE_SITE, null);

        $this->assertNull($id);
        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE));
        // Not a failure: the request was forgotten on purpose.
        $this->assertDebuggingNotCalled();
        $this->assertSame(0, usage_recorder::get_failure_count());
    }

    public function test_an_ending_for_a_row_that_has_gone_recreates_nothing(): void {
        global $DB;
        $request = $this->recorder->begin_request($this->context());
        $id = $this->recorder->begin_attempt($request, 1, $this->target(), 'aiprovider_openai', rule::KEYSOURCE_SITE, null);
        $DB->delete_records(usage_recorder::ATTEMPT_TABLE);
        $DB->delete_records(usage_recorder::REQUEST_TABLE);

        $this->recorder->end_attempt($id, attempt_state::SUCCEEDED, new usage(1, 1), 'm', null, 'aiprovider_openai');
        $this->recorder->end_request($request, request_state::SUCCEEDED, null, null, 42, null, rule::KEYSOURCE_SITE, 1);

        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE));
        $this->assertSame(0, $DB->count_records(usage_recorder::REQUEST_TABLE));
        $this->assertDebuggingNotCalled();
    }

    public function test_usage_knows_when_it_is_unknown(): void {
        $this->assertFalse(usage::unknown()->is_known());
        $this->assertFalse((new usage(5, null))->is_known());
        $this->assertTrue((new usage(0, 0))->is_known(), 'Zero is a count.');
    }
}
