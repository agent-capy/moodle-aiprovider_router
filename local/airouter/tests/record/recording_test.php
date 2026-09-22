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

use local_airouter\abstract_processor;
use local_airouter\rule;
use local_airouter\routing_harness;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/routing_harness.php');

/**
 * What a routed request leaves in the request and attempt tables.
 *
 * Each test is one of the acceptance rows C01 to C10 of the redesign check sheet,
 * and each goes through the real rules, resolver and delegation chain.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_recorder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(abstract_processor::class)]
final class recording_test extends \advanced_testcase {
    use routing_harness;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \local_airouter\provider::get_instance_ids(true);
    }

    /**
     * The one request row.
     *
     * @return \stdClass The row.
     */
    private function request(): \stdClass {
        global $DB;
        $rows = $DB->get_records(usage_recorder::REQUEST_TABLE);
        $this->assertCount(1, $rows, 'A routed request is always exactly one request row.');

        return reset($rows);
    }

    /**
     * The attempt rows of the one request, in order.
     *
     * @return \stdClass[] The rows.
     */
    private function attempts(): array {
        global $DB;

        return array_values($DB->get_records(usage_recorder::ATTEMPT_TABLE, ['requestid' => $this->request()->id], 'seq ASC'));
    }

    // C01: one candidate, success.
    public function test_c01_a_request_one_target_answers_is_one_request_and_one_attempt(): void {
        $this->rate('m', 1.0, 2.0);
        $this->add('to seven', 7);
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, [
            'model' => 'm', 'prompttokens' => 1000000, 'completiontokens' => 500000,
        ])]);

        $request = $this->request();
        $this->assertSame(request_state::SUCCEEDED, $request->state);
        $this->assertSame(7, (int) $request->answeredby);
        $this->assertSame(1, (int) $request->attempts);
        $this->assertSame('to seven', $request->rulename);
        $this->assertSame(rule::KEYSOURCE_SITE, $request->keysource);
        $this->assertNull($request->reason);
        $this->assertNotNull($request->timeended);
        $this->assertSame(36, strlen($request->correlation));

        $attempts = $this->attempts();
        $this->assertCount(1, $attempts);
        $this->assertSame(attempt_state::SUCCEEDED, $attempts[0]->state);
        $this->assertSame(7, (int) $attempts[0]->targetid);
        $this->assertSame('Mock 7', $attempts[0]->targetname);
        $this->assertSame('aiprovider_mock', $attempts[0]->targetprovider);
        $this->assertSame('m', $attempts[0]->model);
        $this->assertSame(1000000, (int) $attempts[0]->prompttokens);
        $this->assertSame(1, (int) $attempts[0]->usageknown);
        // One million prompt tokens at 1.0 and half a million completion tokens at 2.0.
        $this->assertEqualsWithDelta(2.0, (float) $attempts[0]->cost, 0.000001);
        $this->assertSame(rule::KEYSOURCE_SITE, $attempts[0]->keysource);
    }

    // C02: an empty answer that was charged for, then a success. Both charges remain.
    public function test_c02_an_empty_answer_and_the_answer_after_it_are_two_priced_attempts(): void {
        $this->rate('expensive', 0.2);
        $this->rate('cheap', 0.4);
        $this->add('to seven', 7);
        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::EMPTY_CONTENT, [
                'model' => 'expensive', 'prompttokens' => 1000000, 'completiontokens' => 0,
            ]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, [
                'model' => 'cheap', 'prompttokens' => 1000000, 'completiontokens' => 0,
            ]),
        ]);

        $request = $this->request();
        $this->assertSame(request_state::SUCCEEDED, $request->state);
        $this->assertSame(8, (int) $request->answeredby);
        $this->assertSame(2, (int) $request->attempts);

        $attempts = $this->attempts();
        $this->assertCount(2, $attempts);
        $this->assertSame([attempt_state::EMPTY, attempt_state::SUCCEEDED], array_column($attempts, 'state'));
        $this->assertSame([7, 8], array_map('intval', array_column($attempts, 'targetid')));
        $this->assertEqualsWithDelta(0.2, (float) $attempts[0]->cost, 0.000001);
        $this->assertEqualsWithDelta(0.4, (float) $attempts[1]->cost, 0.000001);
        $this->assertEqualsWithDelta(0.6, (float) $attempts[0]->cost + (float) $attempts[1]->cost, 0.000001);
    }

    // C03: turned down before anything was tried.
    public function test_c03_a_request_refused_before_delegation_has_no_attempt(): void {
        // No rule and nowhere to fall back to: the site has nowhere to send it.
        $this->refused([], ['defaulttarget' => 0]);

        $request = $this->request();
        $this->assertSame(request_state::DECLINED, $request->state);
        $this->assertSame(abstract_processor::REASON_NO_TARGET, $request->reason);
        $this->assertSame(503, (int) $request->errorcode);
        $this->assertSame(0, (int) $request->attempts);
        $this->assertNull($request->answeredby);
        $this->assertSame([], $this->attempts());
    }

    // C04: the first target throws, the second answers.
    public function test_c04_a_target_that_threw_is_an_attempt_with_unknown_usage(): void {
        $this->add('to seven', 7);
        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::EXCEPTION),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, ['prompttokens' => 10, 'completiontokens' => 5]),
        ]);
        $this->assertDebuggingCalledCount(1);

        $this->assertSame(request_state::SUCCEEDED, $this->request()->state);
        $attempts = $this->attempts();
        $this->assertCount(2, $attempts);
        $this->assertSame(attempt_state::THREW, $attempts[0]->state);
        // Not zero: nobody said what it used.
        $this->assertNull($attempts[0]->prompttokens);
        $this->assertNull($attempts[0]->completiontokens);
        $this->assertSame(0, (int) $attempts[0]->usageknown);
        $this->assertNull($attempts[0]->cost);
        $this->assertNotNull($attempts[0]->timeended);
        $this->assertSame(attempt_state::SUCCEEDED, $attempts[1]->state);
    }

    // C05: every target fails. One failed request, as many attempts as there were calls.
    public function test_c05_when_every_target_fails_the_request_fails_once(): void {
        $this->add('to seven', 7);
        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::FAILURE, ['errorcode' => 500]),
            $this->target(8, \aiprovider_mock\provider::EXCEPTION),
        ]);
        $this->assertDebuggingCalledCount(1);

        $request = $this->request();
        $this->assertSame(request_state::FAILED, $request->state);
        $this->assertSame(abstract_processor::REASON_ALL_FAILED, $request->reason);
        $this->assertSame(2, (int) $request->attempts);
        $this->assertNull($request->answeredby);

        $attempts = $this->attempts();
        $this->assertSame([attempt_state::FAILED, attempt_state::THREW], array_column($attempts, 'state'));
        $this->assertSame(500, (int) $attempts[0]->errorcode);
    }

    // C06: free, zero usage, unknown usage and unknown rate are four different rows.
    public function test_c06_zero_and_unknown_are_kept_apart(): void {
        // A rate of zero is a known price: free is not the same as unpriced.
        $this->rate('free', 0.0, 0.0);
        $this->rate('priced', 1.0, 0.0);
        $this->add('to seven', 7);

        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, [
            'model' => 'free', 'prompttokens' => 100, 'completiontokens' => 100,
        ])]);
        $free = $this->attempts()[0];
        $this->assertSame(1, (int) $free->usageknown);
        $this->assertSame(0.0, (float) $free->cost, 'Free is a cost of zero, not no cost.');
        $this->assertNotNull($free->cost);
        $this->assertNotNull($free->currency);

        $this->reset_history();
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, [
            'model' => 'priced', 'prompttokens' => 0, 'completiontokens' => 0,
        ])]);
        $zero = $this->attempts()[0];
        $this->assertSame(1, (int) $zero->usageknown);
        $this->assertSame(0, (int) $zero->prompttokens);
        $this->assertSame(0.0, (float) $zero->cost, 'Nothing used at a known rate costs zero.');

        $this->reset_history();
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, [
            'model' => 'priced', 'reportusage' => false,
        ])]);
        $unknown = $this->attempts()[0];
        $this->assertSame(0, (int) $unknown->usageknown);
        $this->assertNull($unknown->prompttokens);
        $this->assertNull($unknown->cost, 'A rate applied to an unknown count is not a cost.');

        $this->reset_history();
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, [
            'model' => 'unpriced', 'prompttokens' => 100, 'completiontokens' => 100,
        ])]);
        $unpriced = $this->attempts()[0];
        $this->assertSame(1, (int) $unpriced->usageknown);
        $this->assertNull($unpriced->cost, 'No rate is no cost, not a cost of zero.');
        $this->assertNull($unpriced->currency);
    }

    // C07: an empty answer that reported nothing at all is still a call that was made.
    public function test_c07_an_empty_answer_with_no_usage_is_still_recorded(): void {
        $this->add('to seven', 7);
        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::EMPTY_CONTENT, ['reportusage' => false]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS),
        ]);

        $attempts = $this->attempts();
        $this->assertCount(2, $attempts, 'The old record dropped this row for having nothing to attribute.');
        $this->assertSame(attempt_state::EMPTY, $attempts[0]->state);
        $this->assertSame(0, (int) $attempts[0]->usageknown);
    }

    // C08: the same call cannot become two attempts. The row is written before the call
    // and the (request, seq) pair is unique, so a second write of the same attempt is refused.
    public function test_c08_a_request_and_sequence_number_identify_one_attempt(): void {
        global $DB;
        $this->add('to seven', 7);
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS)]);
        $attempt = $this->attempts()[0];

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record(usage_recorder::ATTEMPT_TABLE, (object) [
            'requestid' => $attempt->requestid,
            'seq' => $attempt->seq,
            'targetid' => 7,
            'targetprovider' => 'aiprovider_mock',
        ]);
    }

    // C09: a write that fails is not the request's problem, and is counted.
    public function test_c09_a_record_that_cannot_be_written_does_not_fail_the_request(): void {
        global $CFG, $DB;
        $this->add('to seven', 7);
        // The attempt table gone: the attempt cannot be written, and the request itself
        // still has to succeed and still has to be closed.
        $DB->get_manager()->drop_table(new \xmldb_table(usage_recorder::ATTEMPT_TABLE));
        try {
            $response = $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS)]);
            $this->assertTrue($response->get_success());
            // One write that could not happen. Closing the attempt is skipped, because
            // there is nothing to close, and closing the request does not need the table.
            $this->assertDebuggingCalledCount(1);
            $this->assertSame(1, usage_recorder::get_failure_count());
        } finally {
            $DB->get_manager()->install_one_table_from_xmldb_file(
                $CFG->dirroot . '/local/airouter/db/install.xml',
                usage_recorder::ATTEMPT_TABLE,
            );
        }
        // The request was closed as what it was, a success, and says a target was
        // asked. That the attempt row is missing is what the failure count is for.
        $request = $this->request();
        $this->assertSame(request_state::SUCCEEDED, $request->state);
        $this->assertSame(1, (int) $request->attempts);
        $this->assertSame([], $this->attempts());
    }

    // C10: whose key each attempt was made with. A request paid for with a brought
    // key is only ever tried where the person holds a key, and each attempt names the
    // key it used, so a failure at one of their keys and an answer at another are two
    // rows that each say which.
    public function test_c10_each_attempt_names_the_key_it_was_made_with(): void {
        $this->add('theirs', 7, rule::KEYSOURCE_USER);
        $first = $this->bring_key(7, 'first-key');
        $second = $this->bring_key(8, 'second-key');
        $this->route(config: ['defaulttarget' => 0], instances: [
            $this->target(7, \aiprovider_mock\provider::FAILURE, ['errorcode' => 500]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS),
        ]);

        $request = $this->request();
        $this->assertSame(request_state::SUCCEEDED, $request->state);
        $this->assertSame(rule::KEYSOURCE_USER, $request->keysource);
        $this->assertSame(8, (int) $request->answeredby);

        $attempts = $this->attempts();
        $this->assertCount(2, $attempts);
        $this->assertSame([attempt_state::FAILED, attempt_state::SUCCEEDED], array_column($attempts, 'state'));
        $this->assertSame([rule::KEYSOURCE_USER, rule::KEYSOURCE_USER], array_column($attempts, 'keysource'));
        $this->assertSame((int) $first->get('id'), (int) $attempts[0]->keyid);
        $this->assertSame((int) $second->get('id'), (int) $attempts[1]->keyid);
        // And nothing here was charged to the site.
        $this->assertNotContains(rule::KEYSOURCE_SITE, array_column($attempts, 'keysource'));
    }

    /**
     * Forget what the last routed request recorded, for a test that routes several.
     */
    private function reset_history(): void {
        global $DB;
        $DB->delete_records(usage_recorder::ATTEMPT_TABLE);
        $DB->delete_records(usage_recorder::REQUEST_TABLE);
        $DB->delete_records(\local_airouter\usage_logger::TABLE);
    }
}
