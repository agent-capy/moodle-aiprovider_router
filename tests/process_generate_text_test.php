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

use core_ai\aiactions\generate_text;
use core_ai\aiactions\responses\response_generate_text;

/**
 * Tests for the delegation flow shared by every router action.
 *
 * The delegator and resolver are replaced with doubles so that the decisions the router
 * makes are tested on their own. Delegation against a real provider is covered by the
 * integration tests in WP6.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(abstract_processor::class)]
final class process_generate_text_test extends \advanced_testcase {
    /**
     * Run the processor with a scripted set of delegated responses.
     *
     * @param array $responses Responses the fake targets return, in order.
     * @param bool $hastarget Whether the resolver finds any candidate at all.
     * @return array The payload the router hands back to core.
     */
    protected function run_processor(array $responses, bool $hastarget = true): array {
        $provider = new provider(
            enabled: true,
            name: 'router',
            config: json_encode(['defaulttarget' => $hastarget ? 7 : 0]),
        );
        $action = new generate_text(
            contextid: (\context_system::instance())->id,
            userid: get_admin()->id,
            prompttext: 'hello',
        );

        // One stand-in target per scripted response. The fake delegator ignores which
        // one it is handed, so any provider object will do.
        $targets = $hastarget
            ? array_fill(0, count($responses), new provider(enabled: true, name: 'target', config: '{}'))
            : [];

        $resolver = $this->createStub(target_resolver::class);
        $resolver->method('get_candidates')->willReturn($targets);

        $delegator = $this->createStub(delegator::class);
        $delegator->method('delegate')->willReturnOnConsecutiveCalls(...$responses ?: [null]);

        $processor = $this->get_processor($provider, $action);
        $processor->testresolver = $resolver;
        $processor->testdelegator = $delegator;

        return (new \ReflectionMethod($processor, 'query_ai_api'))->invoke($processor);
    }

    /**
     * A processor whose collaborators can be replaced.
     *
     * @param provider $provider The router instance.
     * @param generate_text $action The action to run.
     * @return process_generate_text The processor.
     */
    protected function get_processor(provider $provider, generate_text $action): process_generate_text {
        return new class ($provider, $action) extends process_generate_text {
            /** @var target_resolver|null Replaces the real resolver. */
            public ?target_resolver $testresolver = null;

            /** @var delegator|null Replaces the real delegator. */
            public ?delegator $testdelegator = null;

            #[\Override]
            protected function get_resolver(): target_resolver {
                return $this->testresolver;
            }

            #[\Override]
            protected function get_delegator(): delegator {
                return $this->testdelegator;
            }
        };
    }

    /**
     * Build a successful delegated response.
     *
     * @param string $content The generated content.
     * @param string $finishreason The finish reason reported by the target.
     * @return response_generate_text The response.
     */
    protected function success(string $content, string $finishreason): response_generate_text {
        $response = new response_generate_text(success: true);
        $response->set_response_data([
            'generatedcontent' => $content,
            'finishreason' => $finishreason,
            'model' => 'gpt-oss-120b',
            'prompttokens' => 11,
            'completiontokens' => 22,
        ]);

        return $response;
    }

    /**
     * Test that a successful delegation passes the target's own data through.
     */
    public function test_success_passes_target_data_through(): void {
        $this->resetAfterTest();
        $result = $this->run_processor([$this->success('DELEGATION OK', 'stop')]);

        $this->assertTrue($result['success']);
        $this->assertEquals('DELEGATION OK', $result['generatedcontent']);
        // The model and token counts must survive, or the monitor in WP4 has nothing.
        $this->assertEquals('gpt-oss-120b', $result['model']);
        $this->assertEquals(11, $result['prompttokens']);
        $this->assertEquals(22, $result['completiontokens']);
    }

    /**
     * Test that no candidate target produces a 503 rather than an exception.
     */
    public function test_no_target_is_reported_not_thrown(): void {
        $this->resetAfterTest();
        $result = $this->run_processor([], hastarget: false);

        $this->assertFalse($result['success']);
        $this->assertEquals(503, $result['errorcode']);
        $this->assertEquals(
            get_string('error:nodefaulttarget', 'aiprovider_router'),
            $result['errormessage'],
        );
    }

    /**
     * Test that the first usable target wins and later ones are not tried.
     */
    public function test_first_usable_target_wins(): void {
        $this->resetAfterTest();
        $result = $this->run_processor([
            new response_generate_text(success: false, errorcode: 500, errormessage: 'boom'),
            $this->success('second', 'stop'),
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('second', $result['generatedcontent']);
    }

    /**
     * Test that the status code of the last failure is passed through.
     */
    public function test_last_failure_status_is_passed_through(): void {
        $this->resetAfterTest();
        $result = $this->run_processor([
            new response_generate_text(success: false, errorcode: 500, errormessage: 'boom'),
            new response_generate_text(success: false, errorcode: 429, errormessage: 'slow down'),
        ]);

        $this->assertFalse($result['success']);
        // A rate limit must stay a rate limit so that callers can back off.
        $this->assertEquals(429, $result['errorcode']);
        $this->assertEquals(
            get_string('error:alltargetsfailed', 'aiprovider_router'),
            $result['errormessage'],
        );
    }

    /**
     * Test that a target which spent its budget on reasoning is not retried.
     */
    public function test_truncated_empty_response_is_not_retried(): void {
        $this->resetAfterTest();
        $result = $this->run_processor([
            $this->success('', 'length'),
            $this->success('never reached', 'stop'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals(502, $result['errorcode']);
        $this->assertEquals(
            get_string('error:emptyresponse', 'aiprovider_router'),
            $result['errormessage'],
        );
    }

    /**
     * Test that an empty answer for any other reason falls through to the next target.
     */
    public function test_empty_response_without_truncation_falls_through(): void {
        $this->resetAfterTest();
        $result = $this->run_processor([
            $this->success('', 'stop'),
            $this->success('recovered', 'stop'),
        ]);

        // Handing an empty string to the placement would look like a successful answer.
        $this->assertTrue($result['success']);
        $this->assertEquals('recovered', $result['generatedcontent']);
    }

    /**
     * Test that the error message names nothing about how the site is configured.
     */
    public function test_error_messages_leak_no_configuration(): void {
        $this->resetAfterTest();
        $result = $this->run_processor([
            new response_generate_text(success: false, errorcode: 401, errormessage: 'Bad API key for acme-llm'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsStringIgnoringCase('acme-llm', $result['errormessage']);
        $this->assertStringNotContainsStringIgnoringCase('api key', $result['errormessage']);
    }
}
