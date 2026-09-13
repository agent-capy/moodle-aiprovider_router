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
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');
require_once(__DIR__ . '/fixtures/mock/process_summarise_text.php');
require_once(__DIR__ . '/fixtures/mock/process_explain_text.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_image.php');

/**
 * Tests for the history the router keeps of what it handled.
 *
 * Everything here goes through the real rules, the real resolver and core's own
 * dispatch, because a history assembled from anything less would not be the history a
 * real request produces.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_logger::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(abstract_processor::class)]
final class usage_logger_test extends \advanced_testcase {
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
     * @return \aiprovider_mock\provider The target.
     */
    protected function target(int $id, string $scenario, array $settings = []): \aiprovider_mock\provider {
        return new \aiprovider_mock\provider(
            enabled: true,
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
     * @return rule The saved rule.
     */
    protected function add(string $name, int $targetid): rule {
        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);

        return $this->repository->save($rule);
    }

    /**
     * Route a request through the rules and the real delegation chain.
     *
     * @param ai_provider[] $instances The provider instances the site has.
     * @param array $config The router instance configuration.
     * @param \context|null $context Where the request is raised.
     * @return object The response the router produced.
     */
    protected function route(
        array $instances,
        array $config = ['defaulttarget' => 7],
        ?\context $context = null,
        ?usage_logger $logger = null,
    ): object {
        global $DB;

        $router = new provider(enabled: true, name: 'Router', config: json_encode($config), id: 1);
        $action = new generate_text(
            contextid: ($context ?? \context_system::instance())->id,
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

        $processor = new class ($router, $action, $resolver, new delegator($DB), $logger) extends process_generate_text {
            /**
             * Constructor.
             *
             * @param provider $provider The router instance.
             * @param generate_text $action The action being processed.
             * @param target_resolver $testresolver The resolver to use.
             * @param delegator $testdelegator The delegator to use.
             * @param usage_logger|null $testlogger The logger to use, or null for the real one.
             */
            public function __construct(
                provider $provider,
                generate_text $action,
                /** @var target_resolver The injected resolver. */
                public target_resolver $testresolver,
                /** @var delegator The injected delegator. */
                public delegator $testdelegator,
                /** @var usage_logger|null The injected logger. */
                public ?usage_logger $testlogger = null,
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

            #[\Override]
            protected function get_logger(): usage_logger {
                return $this->testlogger ?? parent::get_logger();
            }
        };

        return $processor->process();
    }

    /**
     * The single row the router recorded.
     *
     * @return \stdClass The row.
     */
    protected function logged(): \stdClass {
        global $DB;
        $records = $DB->get_records(usage_logger::TABLE, null, 'id ASC');
        $this->assertCount(1, $records);

        return reset($records);
    }

    public function test_a_successful_request_records_where_it_went(): void {
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi'])]);

        $row = $this->logged();

        // Core records aiprovider_router as the provider for all of this, so where a
        // request actually went exists nowhere but here.
        $this->assertSame('Mock 7', $row->targetname);
        $this->assertSame('aiprovider_mock', $row->targetprovider);
        $this->assertSame('mock-1', $row->model);
        $this->assertSame('1', (string) $row->success);
        $this->assertSame('generate_text', $row->actionname);
        $this->assertSame('2', (string) $row->userid);
    }

    public function test_the_rule_that_chose_is_recorded_by_name_as_well_as_id(): void {
        $rule = $this->add('to eight', 8);

        $this->route([
            $this->target(7, \aiprovider_mock\provider::SUCCESS),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi']),
        ]);

        $row = $this->logged();
        $this->assertSame((int) $rule->get('id'), (int) $row->ruleid);
        // Rules get renamed and deleted. A history that reads "rule 14 sent this to
        // instance 7" some months later is not one anybody can use.
        $this->assertSame('to eight', $row->rulename);
    }

    public function test_the_tokens_the_target_reported_are_recorded(): void {
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, [
            'content' => 'Hi',
            'prompttokens' => 120,
            'completiontokens' => 45,
        ])]);

        $row = $this->logged();
        $this->assertSame(120, (int) $row->prompttokens);
        $this->assertSame(45, (int) $row->completiontokens);
    }

    public function test_the_cost_is_worked_out_from_the_rate_in_force(): void {
        $rate = new price();
        $rate->set('provider', 'aiprovider_mock');
        $rate->set('promptrate', 1.0);
        $rate->set('completionrate', 2.0);
        $rate->create();

        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, [
            'content' => 'Hi',
            'prompttokens' => 1000000,
            'completiontokens' => 1000000,
        ])]);

        $row = $this->logged();
        $this->assertEqualsWithDelta(3.0, (float) $row->cost, 0.000001);
        $this->assertSame(price_book::DEFAULT_CURRENCY, $row->currency);
    }

    public function test_a_request_nothing_prices_is_recorded_without_a_cost(): void {
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi'])]);

        // Not zero. Zero says the request was free.
        $this->assertNull($this->logged()->cost);
    }

    public function test_a_refusal_is_recorded_and_says_so(): void {
        $this->route(
            [$this->target(7, \aiprovider_mock\provider::SUCCESS)],
            ['defaulttarget' => 7, 'mode' => provider::MODE_COEXIST],
        );

        $row = $this->logged();
        $this->assertSame('0', (string) $row->success);
        // How often a site turns requests down is a number its owner needs, and it has
        // to be countable apart from targets breaking.
        $this->assertSame(abstract_processor::REASON_DECLINED, $row->reason);
        $this->assertNull($row->targetid);
        $this->assertSame(0, (int) $row->attempts);
    }

    public function test_a_failure_records_the_reason_and_how_many_targets_were_tried(): void {
        $this->add('to eight', 8);

        $this->route([
            $this->target(7, \aiprovider_mock\provider::FAILURE, ['errorcode' => 500]),
            $this->target(8, \aiprovider_mock\provider::FAILURE, ['errorcode' => 500]),
        ]);

        $row = $this->logged();
        $this->assertSame('0', (string) $row->success);
        $this->assertSame(abstract_processor::REASON_ALL_FAILED, $row->reason);
        $this->assertSame(500, (int) $row->errorcode);
        // Both the rule's target and the default behind it were tried.
        $this->assertSame(2, (int) $row->attempts);
    }

    public function test_a_target_that_threw_is_recorded_without_repeating_what_it_said(): void {
        $this->route([$this->target(7, \aiprovider_mock\provider::EXCEPTION)]);

        $row = $this->logged();
        $this->assertSame(abstract_processor::REASON_TARGET_THREW, $row->reason);
        $this->assertDebuggingCalled();
    }

    public function test_the_course_is_resolved_the_way_the_rules_resolve_it(): void {
        $course = $this->getDataGenerator()->create_course();

        $this->route(
            [$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi'])],
            ['defaulttarget' => 7],
            \context_course::instance($course->id),
        );

        $this->assertSame((int) $course->id, (int) $this->logged()->courseid);
    }

    public function test_a_request_from_outside_a_course_records_no_course(): void {
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi'])]);

        $this->assertNull($this->logged()->courseid);
    }

    public function test_a_request_is_charged_to_the_site_until_byok_arrives(): void {
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi'])]);

        // The column exists now so that history does not have a hole in it later.
        $this->assertSame(usage_logger::KEY_SITE, $this->logged()->keysource);
    }

    public function test_a_history_that_cannot_be_written_does_not_stop_the_request(): void {
        // A monitor is a tool for running a site, not an obstacle on the path of every
        // AI request. Losing the history is bad; losing the answer is worse.
        $db = $this->createStub(\moodle_database::class);
        $db->method('insert_record')->willThrowException(new \dml_exception('error'));

        $response = $this->route(
            [$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Still works'])],
            logger: new usage_logger($db),
        );

        $this->assertTrue($response->get_success());
        $this->assertSame('Still works', $response->get_response_data()['generatedcontent']);
        $this->assertDebuggingCalled();
    }
}
