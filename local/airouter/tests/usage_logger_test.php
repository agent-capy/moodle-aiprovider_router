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

use local_airouter\exception\declined_request;
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
 * @package    local_airouter
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

        set_config(eligibility_policy::ACCESS_SETTING, eligibility_policy::ACCESS_EVERYBODY, 'local_airouter');
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

        $router = new \aiprovider_router\provider(enabled: true, name: 'Router', config: json_encode($config), id: 1);
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
        // The row for the request itself. A fallback chain writes one row per attempt
        // so that what each one used lands on the target and the key that paid for
        // it, and only one of them is the request.
        $records = $DB->get_records(usage_logger::TABLE, ['counted' => 1], 'id ASC');
        $this->assertCount(1, $records);

        return reset($records);
    }

    /**
     * The rows for the attempts that did not answer, oldest first.
     *
     * @return \stdClass[] The rows.
     */
    protected function attempts(): array {
        global $DB;

        return array_values($DB->get_records(usage_logger::TABLE, ['counted' => 0], 'id ASC'));
    }

    public function test_a_successful_request_records_where_it_went(): void {
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, ['content' => 'Hi'])]);

        $row = $this->logged();

        // Core records local_airouter as the provider for all of this, so where a
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
        $rate->set('currency', 'JPY');
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
        // In the currency of the rate, which is the provider's, not a site default.
        $this->assertSame('JPY', $row->currency);
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

    public function test_what_a_target_used_before_answering_with_nothing_is_not_lost(): void {
        $this->add('to seven', 7);
        // The first target succeeds, says what it charged for, and returns nothing
        // anybody can be shown. The request moves on; the money does not come back.
        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::EMPTY_CONTENT, [
                'prompttokens' => 1000,
                'completiontokens' => 200,
            ]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, [
                'content' => 'The second one answered',
                'prompttokens' => 10,
                'completiontokens' => 20,
            ]),
        ]);

        // The request names the target that answered and what that one used.
        $row = $this->logged();
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame(10, (int) $row->prompttokens);
        $this->assertSame(20, (int) $row->completiontokens);

        // What the first target used is not lost, and is not attributed to the
        // second: it has its own row, against the target that really used it.
        $attempts = $this->attempts();
        $this->assertCount(1, $attempts);
        $this->assertSame(1000, (int) $attempts[0]->prompttokens);
        $this->assertSame(200, (int) $attempts[0]->completiontokens);
        $this->assertSame(7, (int) $attempts[0]->targetid);
    }

    public function test_it_is_still_one_request(): void {
        global $DB;

        $this->add('to seven', 7);
        // One row is one request everywhere in these reports, and a budget counted in
        // requests counts rows. A fallback must not start counting as a second request.
        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::EMPTY_CONTENT, ['prompttokens' => 1000]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, ['content' => 'Answered']),
        ]);

        // Two rows, because two targets were asked and both used something. One
        // request, because the person asked once and that is what a budget counted
        // in requests must see.
        $this->assertSame(2, $DB->count_records(usage_logger::TABLE));
        $this->assertSame(1, $DB->count_records(usage_logger::TABLE, ['counted' => 1]));
    }

    public function test_each_attempt_is_priced_at_its_own_rate(): void {
        $this->add('to seven', 7);
        $rate = new price();
        $rate->set('provider', 'aiprovider_mock');
        $rate->set('currency', 'USD');
        $rate->set('model', 'expensive');
        $rate->set('promptrate', 10.0);
        $rate->create();

        $cheap = new price();
        $cheap->set('provider', 'aiprovider_mock');
        $cheap->set('currency', 'USD');
        $cheap->set('model', 'cheap');
        $cheap->set('promptrate', 1.0);
        $cheap->create();

        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::EMPTY_CONTENT, [
                'model' => 'expensive',
                'prompttokens' => 1000000,
                'completiontokens' => 0,
            ]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, [
                'model' => 'cheap',
                'content' => 'Answered',
                'prompttokens' => 1000000,
                'completiontokens' => 0,
            ]),
        ]);

        // A fallback chain crosses providers and models, so the attempt that produced
        // nothing is priced against what produced it, not against what answered.
        $this->assertEqualsWithDelta(1.0, (float) $this->logged()->cost, 0.000001);
        $this->assertEqualsWithDelta(10.0, (float) $this->attempts()[0]->cost, 0.000001);
    }

    public function test_a_cost_nobody_can_work_out_stays_unknown(): void {
        $this->add('to seven', 7);
        $cheap = new price();
        $cheap->set('provider', 'aiprovider_mock');
        $cheap->set('currency', 'USD');
        $cheap->set('model', 'cheap');
        $cheap->set('promptrate', 1.0);
        $cheap->create();

        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::EMPTY_CONTENT, [
                'model' => 'unpriced',
                'prompttokens' => 1000000,
            ]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, [
                'model' => 'cheap',
                'content' => 'Answered',
                'prompttokens' => 1000000,
            ]),
        ]);

        // The attempt nothing priced is unknown, and says so on its own row rather
        // than making the answering target's cost unknown too. A budget adds what it
        // can and reports that part of the period could not be priced.
        $this->assertEqualsWithDelta(1.0, (float) $this->logged()->cost, 0.000001);
        $this->assertNull($this->attempts()[0]->cost);
    }

    public function test_each_attempt_is_charged_to_the_key_that_paid_for_it(): void {
        // The case that made this worth splitting into rows. Somebody holds a key at
        // both targets. The first uses a great deal and answers with nothing; the
        // second answers cheaply. Charging the first one's spending to the second
        // key blocks a key that has spent almost nothing and lets through the one
        // that has spent its whole limit.
        $rate = new price();
        $rate->set('provider', 'aiprovider_mock');
        $rate->set('currency', 'USD');
        $rate->set('promptrate', 1.0);
        $rate->create();

        $this->add_byok('to seven', 7, rule::KEYSOURCE_USER);
        $first = $this->bring_key(7, 'their-key-at-seven');
        $second = $this->bring_key(8, 'their-key-at-eight');

        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::EMPTY_CONTENT, ['prompttokens' => 1000000]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, [
                'content' => 'Answered',
                'prompttokens' => 10000,
            ]),
        ]);

        $attempts = $this->attempts();
        $this->assertCount(1, $attempts);
        $this->assertSame((int) $first->get('id'), (int) $attempts[0]->keyid);
        $this->assertEqualsWithDelta(1.0, (float) $attempts[0]->cost, 0.000001);

        $row = $this->logged();
        $this->assertSame((int) $second->get('id'), (int) $row->keyid);
        $this->assertEqualsWithDelta(0.01, (float) $row->cost, 0.000001);
    }

    public function test_an_attempt_that_reported_nothing_gets_no_row(): void {
        global $DB;

        // A row saying that a target used an unknown amount is a row about nothing.
        $this->add('to seven', 7);
        $this->route(config: ['defaulttarget' => 8], instances: [
            $this->target(7, \aiprovider_mock\provider::EMPTY_CONTENT, [
                'prompttokens' => 'plenty',
                'completiontokens' => 'lots',
            ]),
            $this->target(8, \aiprovider_mock\provider::SUCCESS, ['content' => 'Answered']),
        ]);

        $this->assertSame(1, $DB->count_records(usage_logger::TABLE));
    }

    public function test_a_model_name_too_long_for_the_column_does_not_take_the_row_with_it(): void {
        global $DB;

        // The name comes from outside and nothing checks it. One that will not fit
        // used to make the insert fail, and the failure is swallowed so that
        // recording can never break a request -- so the request happened and left no
        // trace at all: no tokens, no cost, nothing for a budget to count.
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, [
            'content' => 'Hi',
            'model' => str_repeat('m', 101),
            'prompttokens' => 120,
        ])]);

        $this->assertSame(1, $DB->count_records(usage_logger::TABLE));
        $row = $this->logged();
        // Not shortened. Two models whose names differ only past the hundredth
        // character would become one, and the rate table matches on the name.
        $this->assertNull($row->model);
        $this->assertSame(120, (int) $row->prompttokens);
    }

    public function test_a_model_name_that_fits_is_kept(): void {
        $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS, [
            'content' => 'Hi',
            'model' => str_repeat('m', 100),
        ])]);

        $this->assertSame(str_repeat('m', 100), $this->logged()->model);
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
