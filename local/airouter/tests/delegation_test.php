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

use core_ai\aiactions\generate_text;
use core_ai\manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture_router.php');
require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');
require_once(__DIR__ . '/fixtures/mock/process_summarise_text.php');
require_once(__DIR__ . '/fixtures/mock/process_explain_text.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_image.php');

/**
 * Delegation exercised through core's own dispatch, against a misbehaving target.
 *
 * The other tests stand a double in for the delegator, which is right for checking the
 * router's own branching but says nothing about what core does on the way to a target.
 * These go through \core_ai\manager::call_action_provider() for real.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(abstract_processor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(delegator::class)]
final class delegation_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A target that behaves as the given scenario says.
     *
     * @param string $scenario One of the mock provider's scenario constants.
     * @param array $settings Extra settings for the scenario.
     * @param int $id The instance id.
     * @return \aiprovider_mock\provider The target.
     */
    protected function target(string $scenario, array $settings = [], int $id = 9): \aiprovider_mock\provider {
        return new \aiprovider_mock\provider(
            enabled: true,
            name: "Mock {$id}",
            config: json_encode(['scenario' => $scenario] + $settings),
            id: $id,
        );
    }

    /**
     * Run generate_text through the router against the given targets.
     *
     * @param \core_ai\provider[]|candidate[] $targets The candidates, in order.
     * @return \core_ai\aiactions\responses\response_base The response the router produced.
     */
    protected function route(array $targets): \core_ai\aiactions\responses\response_base {
        global $DB;

        $router = new \local_airouter\fixture_router(enabled: true, name: 'Router', config: '{"defaulttarget":9}', id: 1);
        $action = new generate_text(contextid: \context_system::instance()->id, userid: 2, prompttext: 'Hello');

        $resolver = $this->createStub(target_resolver::class);
        $resolver->method('get_candidates')->willReturn(array_map(
            static fn($target) => $target instanceof candidate ? $target : new candidate($target),
            $targets,
        ));
        // A stub answers a string method with the empty string, which is not one of the
        // key sources and would send every exhausted chain down the brought key path.
        $resolver->method('get_keysource')->willReturn(rule::KEYSOURCE_SITE);

        $processor = new class ($router, $action, $resolver, new delegator($DB)) extends process_generate_text {
            /**
             * Constructor.
             *
             * @param provider $provider The router instance.
             * @param generate_text $action The action being processed.
             * @param target_resolver $testresolver The resolver to use.
             * @param delegator $testdelegator The delegator to use.
             */
            public function __construct(
                provider $provider,
                generate_text $action,
                /** @var target_resolver The injected resolver. */
                public target_resolver $testresolver,
                /** @var delegator The injected delegator. */
                public delegator $testdelegator,
            ) {
                parent::__construct($provider, $action);
            }

            #[\Override]
            protected function get_resolver(): target_resolver {
                return $this->testresolver;
            }

            #[\Override]
            protected function get_delegator(): delegator {
                return $this->testdelegator;
            }
        };

        return $processor->process();
    }

    public function test_a_target_that_answers_is_passed_through(): void {
        $response = $this->route([$this->target(\aiprovider_mock\provider::SUCCESS, ['content' => 'Hi there'])]);

        $this->assertTrue($response->get_success());
        $this->assertSame('Hi there', $response->get_response_data()['generatedcontent']);
        $this->assertSame('mock-1', $response->get_response_data()['model']);
    }

    public function test_a_target_that_throws_does_not_take_the_request_with_it(): void {
        // Guzzle's ConnectException does not extend RequestException, so the catch inside
        // core's own providers misses it and it escapes their query_ai_api(). Core does
        // not catch it either, so without containment here one unreachable endpoint ends
        // the whole request and the remaining candidates are never tried.
        $response = $this->route([
            $this->target(\aiprovider_mock\provider::EXCEPTION, [], 8),
            $this->target(\aiprovider_mock\provider::SUCCESS, ['content' => 'Second one worked'], 9),
        ]);

        $this->assertTrue($response->get_success());
        $this->assertSame('Second one worked', $response->get_response_data()['generatedcontent']);
        // The site owner still gets told, through the developer log rather than the user.
        $this->assertDebuggingCalled();
    }

    public function test_every_target_throwing_still_produces_a_response(): void {
        $response = $this->route([$this->target(\aiprovider_mock\provider::EXCEPTION)]);

        $this->assertFalse($response->get_success());
        $this->assertSame(502, $response->get_errorcode());
        // Nothing the target said reaches the user.
        $this->assertStringNotContainsString('Connection refused', $response->get_errormessage());
        $this->assertDebuggingCalled();
    }

    public function test_a_rate_limited_target_keeps_its_status_code(): void {
        $response = $this->route([$this->target(\aiprovider_mock\provider::RATELIMIT)]);

        $this->assertFalse($response->get_success());
        $this->assertSame(429, $response->get_errorcode());
    }

    public function test_a_failing_target_falls_through_to_the_next(): void {
        $response = $this->route([
            $this->target(\aiprovider_mock\provider::FAILURE, ['errorcode' => 500], 8),
            $this->target(\aiprovider_mock\provider::SUCCESS, ['content' => 'Recovered'], 9),
        ]);

        $this->assertTrue($response->get_success());
        $this->assertSame('Recovered', $response->get_response_data()['generatedcontent']);
    }

    public function test_an_empty_answer_is_not_shown_to_the_user(): void {
        $response = $this->route([$this->target(\aiprovider_mock\provider::EMPTY_CONTENT)]);

        $this->assertFalse($response->get_success());
    }

    public function test_a_target_out_of_tokens_is_reported_not_retried(): void {
        $response = $this->route([
            $this->target(\aiprovider_mock\provider::TRUNCATED, [], 8),
            $this->target(\aiprovider_mock\provider::SUCCESS, ['content' => 'Never reached'], 9),
        ]);

        $this->assertFalse($response->get_success());
        $this->assertSame(502, $response->get_errorcode());
    }

    public function test_a_malformed_answer_is_treated_as_a_failed_target(): void {
        $response = $this->route([
            $this->target(\aiprovider_mock\provider::MALFORMED, [], 8),
            $this->target(\aiprovider_mock\provider::SUCCESS, ['content' => 'Recovered'], 9),
        ]);

        $this->assertTrue($response->get_success());
        $this->assertSame('Recovered', $response->get_response_data()['generatedcontent']);
    }
}
