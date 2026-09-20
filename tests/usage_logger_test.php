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
    /** @var int The user every routed request is made by, which is the site administrator. */
    protected const REQUESTER = 2;

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
     * A rule that asks somebody other than the site to pay.
     *
     * @param string $name The rule name.
     * @param int $targetid Where it delegates.
     * @param string $keysource Whose key pays.
     * @return rule The saved rule.
     */
    protected function add_byok(string $name, int $targetid, string $keysource): rule {
        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);
        $rule->set('keysource', $keysource);

        return $this->repository->save($rule);
    }

    /**
     * Register a key for the person the routed requests are made by.
     *
     * @param int $targetid The instance the key is for.
     * @param string $secret The key.
     * @return key The stored key.
     */
    protected function bring_key(int $targetid, string $secret = 'their-own-key'): key {
        global $DB;

        set_config(eligibility_policy::ACCESS_SETTING, eligibility_policy::ACCESS_EVERYBODY, 'aiprovider_router');
        eligibility_policy::purge();
        (new target_settings($DB))->set_key_field($targetid, 'apikey');

        return (new key_repository($DB))->save(key::SCOPE_USER, self::REQUESTER, $targetid, $secret);
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
            userid: self::REQUESTER,
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
     * Route a request the router is expected to refuse for good.
     *
     * A refusal the site decided on is thrown rather than returned, because core would
     * otherwise carry on and let the next provider answer it. What was recorded on the
     * way out is still the subject here, so the exception is caught and handed back.
     *
     * @param ai_provider[] $instances The provider instances the site has.
     * @param array $config The router instance configuration.
     * @return declined_request What the router threw.
     */
    protected function refused(array $instances, array $config = ['defaulttarget' => 7]): declined_request {
        try {
            $this->route($instances, $config);
        } catch (declined_request $e) {
            return $e;
        }

        $this->fail('Expected the router to stop the request rather than pass it on.');
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

    public function test_a_request_no_rule_asked_anybody_to_pay_for_is_the_sites(): void {
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi'])]);

        $row = $this->logged();
        $this->assertSame(usage_logger::KEY_SITE, $row->keysource);
        $this->assertNull($row->keyid);
    }

    public function test_a_request_paid_for_with_a_brought_key_records_which_one(): void {
        $key = $this->bring_key(7);
        $this->add_byok('their own key', 7, rule::KEYSOURCE_USER);

        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi'])]);

        $row = $this->logged();
        $this->assertSame(rule::KEYSOURCE_USER, $row->keysource);
        // Which key, so that one the provider keeps refusing can be found again. The
        // daily summary deliberately does not keep this.
        $this->assertSame((int) $key->get('id'), (int) $row->keyid);
    }

    public function test_a_key_that_cannot_be_read_is_a_fault_and_is_recorded_as_one(): void {
        global $DB;
        $key = $this->bring_key(7);
        $DB->set_field(key::TABLE, 'secret', 'nonsense', ['id' => $key->get('id')]);
        $this->add_byok('their own key', 7, rule::KEYSOURCE_USER);
        $this->add('the site pays', 8);

        $refusal = $this->refused([
            $this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi']),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi']),
        ]);

        // Not an absent key, so not a rule that quietly hands on to the one below it,
        // and not something for the next provider in the site's order either.
        $this->assertSame(abstract_processor::REASON_KEY_UNREADABLE, $refusal->get_reason());
        $row = $this->logged();
        $this->assertSame(abstract_processor::REASON_KEY_UNREADABLE, $row->reason);
        $this->assertSame(rule::KEYSOURCE_USER, $row->keysource);
        $this->assertSame((int) $key->get('id'), (int) $row->keyid);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertDebuggingCalled();
    }

    public function test_a_key_the_provider_refuses_stops_the_request_there(): void {
        $this->bring_key(7);
        $this->bring_key(8, 'their-other-key');
        $this->add_byok('their own key', 7, rule::KEYSOURCE_USER);

        $refusal = $this->refused([
            $this->target(7, \aiprovider_mock\provider::FAILURE, ['errorcode' => 401]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi']),
        ]);

        // Their other key would have worked, and using it would have hidden the fact
        // that one of their keys has stopped being accepted. They are the only person
        // who can put that right, so they are told.
        $this->assertSame(401, $refusal->get_statuscode());
        $row = $this->logged();
        $this->assertSame(abstract_processor::REASON_KEY_REJECTED, $row->reason);
        $this->assertSame(1, (int) $row->attempts);
    }

    public function test_running_out_of_brought_keys_is_its_own_reason(): void {
        $this->bring_key(7);
        $this->bring_key(8, 'their-other-key');
        $this->add_byok('their own key', 7, rule::KEYSOURCE_USER);

        $this->refused([
            $this->target(7, \aiprovider_mock\provider::FAILURE, ['errorcode' => 500]),
            $this->target(8, \aiprovider_mock\provider::FAILURE, ['errorcode' => 500]),
        ]);

        $row = $this->logged();
        // Different from every target failing: it says the chain was short because of
        // who was paying, not that the site's providers are all down.
        $this->assertSame(abstract_processor::REASON_NO_KEY_LEFT, $row->reason);
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame(rule::KEYSOURCE_USER, $row->keysource);
    }

    public function test_the_site_is_not_asked_to_pay_when_a_brought_key_fails(): void {
        $this->bring_key(7);
        $this->add_byok('their own key', 7, rule::KEYSOURCE_USER);

        $this->refused([
            $this->target(7, \aiprovider_mock\provider::FAILURE, ['errorcode' => 500]),
            $this->target(9, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi']),
        ], ['defaulttarget' => 9]);

        // Instance 9 is the default target and would have answered. Sending the request
        // there would have moved the cost onto the site without anybody saying so.
        $row = $this->logged();
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(abstract_processor::REASON_NO_KEY_LEFT, $row->reason);
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
