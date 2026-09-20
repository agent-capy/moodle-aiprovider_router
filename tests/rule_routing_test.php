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

use aiprovider_router\exception\declined_request;
use core_ai\aiactions\generate_text;
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');
require_once(__DIR__ . '/fixtures/mock/process_summarise_text.php');
require_once(__DIR__ . '/fixtures/mock/process_explain_text.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_image.php');

/**
 * Rules driving real delegation, against targets that misbehave.
 *
 * The resolver tests check which candidates come back, and the evaluator tests check
 * which rules match. Neither says what happens when a rule sends a request to a target
 * that then falls over, which is the case that matters on a live site and the one that
 * hid a defect in the fallback chain the last time it was left to unit tests alone.
 * Everything here goes through the real rules, the real resolver, the real delegator
 * and core's own dispatch.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(target_resolver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rule_evaluator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(abstract_processor::class)]
final class rule_routing_test extends \advanced_testcase {
    /** @var rule_repository Where the rules live. */
    protected rule_repository $repository;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
        $this->repository = new rule_repository($DB);
    }

    /**
     * A target that behaves as the given scenario says.
     *
     * @param int $id The instance id.
     * @param string $scenario One of the mock provider's scenario constants.
     * @param array $settings Extra settings for the scenario.
     * @param bool $enabled Whether the instance is switched on.
     * @return \aiprovider_mock\provider The target.
     */
    protected function target(
        int $id,
        string $scenario,
        array $settings = [],
        bool $enabled = true,
    ): \aiprovider_mock\provider {
        return new \aiprovider_mock\provider(
            enabled: $enabled,
            name: "Mock {$id}",
            config: json_encode(['scenario' => $scenario] + $settings),
            id: $id,
        );
    }

    /**
     * Save a rule.
     *
     * @param string $name The rule name.
     * @param int $targetid Where it delegates.
     * @param array[] $conditions Configuration keyed by condition type.
     * @return rule The saved rule.
     */
    protected function add(string $name, int $targetid, array $conditions = []): rule {
        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);

        return $this->repository->save($rule, $conditions);
    }

    /**
     * Route a generate_text request through the rules.
     *
     * @param ai_provider[] $instances The provider instances the site has.
     * @param array $config The router instance configuration.
     * @return \core_ai\aiactions\responses\response_base The response the router produced.
     */
    protected function route(array $instances, array $config = ['defaulttarget' => 7]): object {
        global $DB;

        $router = new provider(enabled: true, name: 'Router', config: json_encode($config), id: 1);
        $action = new generate_text(
            contextid: \context_system::instance()->id,
            userid: 2,
            prompttext: 'Hello',
        );

        $resolver = new class ($router, $instances) extends target_resolver {
            /**
             * Constructor.
             *
             * @param provider $router The router instance.
             * @param ai_provider[] $testinstances The instances the site has.
             */
            public function __construct(
                provider $router,
                /** @var ai_provider[] The injected instances. */
                public array $testinstances,
            ) {
                parent::__construct($router);
            }

            #[\Override]
            protected function get_instances(): array {
                return $this->testinstances;
            }
        };

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

    public function test_a_rule_sends_the_request_somewhere_other_than_the_default(): void {
        $this->add('to eight', 8);

        $response = $this->route([
            $this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Default answered']),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, ['content' => 'Rule answered']),
        ]);

        $this->assertTrue($response->get_success());
        $this->assertSame('Rule answered', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_rule_target_that_throws_does_not_take_the_request_with_it(): void {
        // The same shape of defect that unit tests missed in the delegation chain, now
        // reachable through a rule: an unreachable endpoint raises a ConnectException,
        // which core's own providers do not catch and core itself does not catch either.
        $this->add('to eight', 8);

        $response = $this->route([
            $this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Default answered']),
            $this->target(8, \aiprovider_mock\provider::EXCEPTION),
        ]);

        $this->assertTrue($response->get_success());
        $this->assertSame('Default answered', $response->get_response_data()['generatedcontent']);
        $this->assertDebuggingCalled();
    }

    public function test_a_rule_target_that_fails_falls_back_to_the_default_target(): void {
        $this->add('to eight', 8);

        $response = $this->route([
            $this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Default answered']),
            $this->target(8, \aiprovider_mock\provider::FAILURE, ['errorcode' => 500]),
        ]);

        $this->assertTrue($response->get_success());
        $this->assertSame('Default answered', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_rule_whose_target_is_switched_off_hands_the_request_to_the_next_rule(): void {
        $this->add('to a disabled instance', 8);
        $this->add('to nine', 9);

        $response = $this->route([
            $this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Default answered']),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, ['content' => 'Never reached'], enabled: false),
            $this->target(9, \aiprovider_mock\provider::SUCCESS, ['content' => 'Second rule answered']),
        ]);

        $this->assertTrue($response->get_success());
        $this->assertSame('Second rule answered', $response->get_response_data()['generatedcontent']);
    }

    public function test_declining_keeps_a_failed_rule_target_from_reaching_the_default(): void {
        $this->add('to eight', 8);

        $response = $this->route(
            [
                $this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Must not be spent']),
                $this->target(8, \aiprovider_mock\provider::EXCEPTION),
            ],
            ['defaulttarget' => 7, 'nomatch' => provider::NOMATCH_DECLINE],
        );

        // The administrator kept unclaimed requests away from the default target. A
        // failure is not a reason to spend money there behind their back.
        $this->assertFalse($response->get_success());
        $this->assertDebuggingCalled();
    }

    public function test_a_declined_request_is_reported_as_a_decision_not_a_fault(): void {
        $response = $this->route(
            [$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Never reached'])],
            ['defaulttarget' => 7, 'mode' => provider::MODE_COEXIST],
        );

        $this->assertFalse($response->get_success());
        // Alongside other providers this is how the router says "not my request", and
        // core carries on to the next provider in its own order.
        $this->assertSame(503, $response->get_errorcode());
        $this->assertSame(
            get_string('error:norulematched', 'aiprovider_router'),
            $response->get_errormessage(),
        );
        // The user is told nothing about the site's providers or its rules.
        $this->assertStringNotContainsString('Mock', $response->get_errormessage());
    }

    public function test_a_request_declined_and_one_misconfigured_are_told_apart(): void {
        $declined = $this->route(
            [$this->target(7, \aiprovider_mock\provider::SUCCESS)],
            ['defaulttarget' => 7, 'nomatch' => provider::NOMATCH_DECLINE],
        );

        // One is the site working as configured, and the request may well be somebody
        // else's, so it is handed back as an ordinary failure. The other is a site
        // waiting to be fixed, where carrying on would make the gap invisible.
        $this->assertFalse($declined->get_success());
        $this->assertSame(
            get_string('error:norulematched', 'aiprovider_router'),
            $declined->get_errormessage(),
        );

        try {
            $this->route(
                [$this->target(7, \aiprovider_mock\provider::SUCCESS, [], enabled: false)],
                ['defaulttarget' => 7, 'nomatch' => provider::NOMATCH_DELEGATE],
            );
            $this->fail('Expected a router with nowhere to send the request to stop it.');
        } catch (declined_request $e) {
            $this->assertSame(abstract_processor::REASON_NO_TARGET, $e->get_reason());
        }
    }

    public function test_a_condition_decides_which_target_answers(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->add('long prompts only', 8, ['promptlength' => ['operator' => 'gte', 'characters' => 1000]]);
        $this->add('that course only', 9, ['course' => ['courseids' => [(int) $course->id]]]);

        $response = $this->route([
            $this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Default answered']),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, ['content' => 'Never reached']),
            $this->target(9, \aiprovider_mock\provider::SUCCESS, ['content' => 'Never reached either']),
        ]);

        // A short prompt from outside that course matches neither rule.
        $this->assertTrue($response->get_success());
        $this->assertSame('Default answered', $response->get_response_data()['generatedcontent']);
    }
}
