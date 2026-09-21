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

use local_airouter\check\actionconflict;
use local_airouter\check\declinereach;
use local_airouter\check\managedboundary;
use local_airouter\check\routerfirst;
use local_airouter\condition\budget;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\responses\response_base;
use core_ai\manager;
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');

/**
 * What happens when the site, rather than the provider order, decides who answers.
 *
 * The order is a preference. A provider above the router answers first and the router
 * is never asked, so a site can have rules, budgets and keys people brought and have
 * none of them consulted, with nothing anywhere saying so. Placing an action under the
 * router replaces that preference with a decision: for that action the router is asked,
 * wherever it happens to sit in the order, and no other provider is offered the request
 * afterwards.
 *
 * These tests enter through the manager the container hands out, which is the same
 * object every placement in core asks for, and they keep a second provider in front of
 * the router throughout. If the boundary ever stops holding, that provider answers and
 * says so in the text it returns.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(routing_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(single_router_dispatch::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(managed_policy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(response_factory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(managedboundary::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_airouter\check\base::class)]
final class routing_manager_test extends \advanced_testcase {
    /** @var manager The manager a placement would be given. */
    protected manager $manager;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
        $this->manager = \core\di::get(manager::class);
    }

    /**
     * Place generate_text under the router.
     */
    protected function manage_text(): void {
        managed_policy::set_managed_actions([generate_text::class]);
    }

    /**
     * Create an ordinary provider instance.
     *
     * Instances are appended to the site order as they are created, so whichever is
     * created first is the one core would reach first.
     *
     * @param string $name Its name.
     * @param array $config Scenario settings for the mock.
     * @return ai_provider The instance.
     */
    protected function add_target(string $name, array $config = []): ai_provider {
        return $this->manager->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: $name,
            enabled: true,
            config: $config + ['scenario' => \aiprovider_mock\provider::SUCCESS],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
    }

    /**
     * Create the router instance, behind whatever already exists.
     *
     * @param array $config The instance configuration.
     * @param bool $enabled Whether the instance is turned on.
     * @return provider The instance.
     */
    protected function add_router(array $config = [], bool $enabled = true): provider {
        /** @var provider $instance */
        $instance = $this->manager->create_provider_instance(
            classname: provider::INSTANCE_CLASS,
            name: 'Router',
            enabled: $enabled,
            config: $config,
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        provider::get_instance_ids(true);

        return $instance;
    }

    /**
     * A rule sending everything to one target.
     *
     * @param int $targetid Where it delegates.
     */
    protected function add_rule(int $targetid): void {
        global $DB;

        $rule = new rule();
        $rule->set('name', 'Everything');
        $rule->set('targetid', $targetid);
        (new rule_repository($DB))->save($rule);
    }

    /**
     * A rule that carries requests only while the site is under its allowance.
     *
     * Counted in requests rather than money, because a count is known whatever rates
     * the site has entered.
     *
     * @param int $targetid Where the rule delegates.
     * @param int $allowance How many requests the site may make.
     */
    protected function add_budget_rule(int $targetid, int $allowance): void {
        global $DB;

        $rule = new rule();
        $rule->set('name', 'While there is room');
        $rule->set('targetid', $targetid);

        (new rule_repository($DB))->save($rule, [
            'budget' => [
                'scope' => spend_ledger::SCOPE_SITE,
                'direction' => budget::DIRECTION_UNDER,
                'amount' => $allowance,
                'metric' => spend_ledger::METRIC_REQUESTS,
                'period' => spend_ledger::PERIOD_ROLLING,
                'days' => 30,
            ],
        ]);
    }

    /**
     * Record requests already made, so that a budget has something to weigh.
     *
     * @param int $count How many.
     */
    protected function spend(int $count): void {
        global $DB;

        for ($i = 0; $i < $count; $i++) {
            $DB->insert_record(usage_logger::TABLE, (object) [
                'timecreated' => time() - HOURSECS,
                'userid' => get_admin()->id,
                'contextid' => \context_system::instance()->id,
                'actionname' => 'generate_text',
                'targetid' => 1,
                'targetname' => 'Target',
                'targetprovider' => 'aiprovider_mock',
                'success' => 1,
                'attempts' => 1,
                'keysource' => usage_logger::KEY_SITE,
            ]);
        }
        \core_cache\helper::purge_by_definition('local_airouter', spend_ledger::CACHE_AREA);
    }

    /**
     * Ask for some text, the way a placement asks.
     *
     * The site order is left exactly as the instances were created, so the provider
     * created first is in front of the router for every one of these tests.
     *
     * @return response_base What the site produced.
     */
    protected function ask(): response_base {
        return $this->manager->process_action(new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        ));
    }

    public function test_the_container_hands_out_this_plugins_manager(): void {
        // Everything else here rests on this: core builds its manager through the
        // container, and a definition registered by the plugin is what puts this class
        // in its place. Asserting it through a direct instantiation would prove nothing.
        $this->assertInstanceOf(routing_manager::class, \core\di::get(manager::class));
    }

    public function test_without_a_managed_action_the_site_behaves_as_it_always_did(): void {
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $target = $this->add_target('Routed', ['content' => 'Answered through the router']);
        $this->add_router();
        $this->add_rule((int) $target->id);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered by the first provider', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_managed_action_reaches_the_router_from_behind_the_whole_order(): void {
        // The same site as the test above, with one thing changed. Nothing was moved in
        // the provider order and nothing about the other provider changed; the site
        // simply says this action is the router's.
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $target = $this->add_target('Routed', ['content' => 'Answered through the router']);
        $this->add_router();
        $this->add_rule((int) $target->id);
        $this->manage_text();

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered through the router', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_spent_budget_is_an_ordinary_failure_that_nobody_else_answers(): void {
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $target = $this->add_target('Metered', ['content' => 'Within budget']);
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);
        $this->manage_text();

        $response = $this->ask();

        // A failure, and a failure that stays one: no provider behind the router is
        // offered the request, and the refusal is not dressed up as a success to stop
        // core looking further.
        $this->assertFalse($response->get_success());
        $this->assertSame(503, $response->get_errorcode());
        $this->assertNull($response->get_response_data()['generatedcontent']);
    }

    public function test_the_refusal_arrives_as_a_response_so_core_can_record_it(): void {
        global $DB;

        // What the exception costs, stated as a test. Throwing is how a provider stops
        // core trying the next one, and the price is that core never gets to write down
        // what happened. With one candidate there is no next one to stop, so the
        // refusal can be an ordinary answer and the site keeps its own record of it.
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $target = $this->add_target('Metered', ['content' => 'Within budget']);
        $router = $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);
        $this->manage_text();

        $this->ask();

        $records = $DB->get_records('ai_action_register');
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertEquals(0, $record->success);
        $this->assertSame($router->get_name(), $record->provider);

        // And what core records includes the prompt, because that is what its own
        // storage does with any request that failed. Nothing was sent anywhere, so
        // nothing came back, but the asking is now written down where it never was.
        $stored = $DB->get_records('ai_action_generate_text');
        $this->assertCount(1, $stored);
        $this->assertSame('Hello', reset($stored)->prompt);
        $this->assertNull(reset($stored)->generatedcontent);
    }

    public function test_keeping_core_behaviour_no_longer_opens_a_way_round_the_budget(): void {
        // The setting exists because throwing is heavy handed and a site may not want
        // it. Until now turning it off meant the next provider answered the requests a
        // budget had refused. Under management there is no next provider to answer
        // them, so the same setting no longer decides whether the budget holds.
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $target = $this->add_target('Metered', ['content' => 'Within budget']);
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE, 'strictdecline' => 0]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);
        $this->manage_text();

        $response = $this->ask();

        $this->assertFalse($response->get_success());
    }

    public function test_a_managed_action_with_no_usable_router_is_refused_not_redirected(): void {
        global $DB;

        // The site said this action is the router's. The router is switched off. The
        // one thing that must not happen is the request quietly going somewhere else,
        // because that is the case the whole arrangement exists to prevent -- and it is
        // the case a site is most likely to arrive at by accident.
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $this->add_router(enabled: false);
        $this->manage_text();

        $response = $this->ask();

        $this->assertFalse($response->get_success());
        $this->assertSame(503, $response->get_errorcode());
        // No provider ran, so there is nothing to attribute a record to and none is
        // invented. The refusal is the site's, not any provider's.
        $this->assertSame(0, $DB->count_records('ai_action_register'));
    }

    /**
     * A router that could actually answer: something to delegate to, and a rule saying so.
     */
    protected function add_working_router(): void {
        $target = $this->add_target('Routed', ['content' => 'Answered through the router']);
        $this->add_router();
        $this->add_rule((int) $target->id);
    }

    public function test_the_status_check_stands_down_when_nothing_is_managed(): void {
        $this->add_working_router();

        $this->assertSame(\core\check\result::NA, (new managedboundary())->get_result()->get_status());
    }

    public function test_the_status_check_confirms_a_managed_action_reaches_the_router(): void {
        $this->add_working_router();
        $this->manage_text();

        $this->assertSame(\core\check\result::OK, (new managedboundary())->get_result()->get_status());
    }

    public function test_the_status_check_reports_a_managed_action_with_nowhere_to_go(): void {
        // Turned off is one way to get here. A router with no rules and no default
        // target is the other, and it reads the same from outside: the site says this
        // action is the router's and no request for it can be answered.
        $this->add_router(enabled: false);
        $this->manage_text();

        $this->assertSame(\core\check\result::ERROR, (new managedboundary())->get_result()->get_status());
    }

    public function test_the_status_check_reports_a_router_with_nothing_to_delegate_to(): void {
        $this->add_router();
        $this->manage_text();

        $this->assertSame(\core\check\result::ERROR, (new managedboundary())->get_result()->get_status());
    }

    public function test_the_status_check_notices_another_plugin_taking_the_manager(): void {
        global $DB;

        // The failure nothing else would show. Whoever defines the container entry last
        // wins and no warning is produced, so a site would go on displaying its rules
        // and budgets with none of them being consulted.
        $this->add_working_router();
        $this->manage_text();
        \core\di::set(manager::class, new manager($DB));

        $this->assertSame(\core\check\result::ERROR, (new managedboundary())->get_result()->get_status());
    }

    public function test_the_order_checks_stand_down_when_every_action_is_managed(): void {
        // Three of the checks are questions about the provider order: is the router
        // first, is something in front of it, and who is behind it to pick up a
        // refusal. A request that no longer goes through the order cannot be answered
        // by any of them, and reporting the order as a fault anyway would be telling an
        // administrator to fix something that has stopped deciding anything.
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $this->add_working_router();
        managed_policy::set_managed_actions(provider::get_action_list());

        $inspector = new order_inspector();
        foreach ([routerfirst::class, actionconflict::class, declinereach::class] as $class) {
            $result = (new $class($inspector))->get_result();
            $this->assertSame(\core\check\result::OK, $result->get_status(), $class);
            $this->assertSame(
                get_string('check:ordernotused', 'local_airouter'),
                $result->get_summary(),
                $class,
            );
        }
    }

    public function test_the_order_checks_say_which_actions_they_still_cover(): void {
        // With only some placed under the router, the order still decides the rest, so
        // the finding stands -- but it is now about fewer actions than it looks.
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $this->add_working_router();
        $this->manage_text();

        $result = (new routerfirst(new order_inspector()))->get_result();

        $this->assertSame(\core\check\result::ERROR, $result->get_status());
        $this->assertStringContainsString('summarise_text', $result->get_details());
        $this->assertStringNotContainsString('generate_text', $result->get_details());
    }

    public function test_an_action_the_router_does_not_carry_is_left_alone(): void {
        // Naming an action the router cannot carry would otherwise refuse every request
        // for it while offering nothing in its place.
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $this->add_router();
        managed_policy::set_managed_actions(['core_ai\\aiactions\\futureaction']);

        $this->assertSame([], managed_policy::managed_actions());
        $this->assertTrue($this->ask()->get_success());
    }
}
