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

use local_airouter\record\ledger;
use local_airouter\check\managedboundary;
use local_airouter\condition\budget;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\responses\response_base;
use core_ai\manager;
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');
require_once(__DIR__ . '/fixtures/fixture_dropped_action.php');
require_once(__DIR__ . '/fixtures/mock/process_fixture_dropped_action.php');

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
     * Set the router up with the given settings.
     *
     * @param array $config The router's settings: defaulttarget, nomatch.
     * @return provider The router a request would get.
     */
    protected function add_router(array $config = []): provider {
        foreach ($config as $name => $value) {
            set_config($name, $value, 'local_airouter');
        }

        return adapter_provider::create();
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
                'scope' => ledger::SCOPE_SITE,
                'direction' => budget::DIRECTION_UNDER,
                'amount' => $allowance,
                'metric' => ledger::METRIC_REQUESTS,
                'period' => ledger::PERIOD_ROLLING,
                'days' => 30,
            ],
        ]);
    }

    /**
     * Record requests already made, as the records hold them, so that a budget has something to weigh.
     *
     * @param int $count How many.
     */
    protected function spend(int $count): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $when = time() - HOURSECS;
        for ($i = 0; $i < $count; $i++) {
            $request = $generator->create_request([
                'userid' => (int) get_admin()->id,
                'contextid' => \context_system::instance()->id,
                'keysource' => 'site',
                'answeredby' => 1,
                'timestarted' => $when,
                'timeended' => $when,
            ]);
            $generator->create_attempt([
                'requestid' => $request->id,
                'targetid' => 1,
                'targetname' => 'Target',
                'targetprovider' => 'aiprovider_mock',
                'keysource' => 'site',
                'timestarted' => $when,
                'timeended' => $when,
            ]);
        }
        \core_cache\helper::purge_by_definition('local_airouter', ledger::CACHE_AREA);
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

    public function test_an_action_the_router_stopped_declaring_stays_managed(): void {
        // The policy is a decision the site made. Reading it back through what the
        // router can carry today would undo that decision the moment a plugin upgrade
        // changed the list, and undo it silently, which is the one way the boundary
        // can be lost without anybody choosing to lose it.
        $this->add_router();
        managed_policy::set_managed_actions([fixture_dropped_action::class, generate_text::class]);

        $this->assertContains(fixture_dropped_action::class, managed_policy::managed_actions());
        $this->assertTrue(managed_policy::is_managed(fixture_dropped_action::class));
        $this->assertSame([fixture_dropped_action::class], managed_policy::unsupported_actions());
    }

    public function test_a_request_for_an_action_the_router_stopped_declaring_is_refused(): void {
        global $DB;

        // The provider ahead of the router answers this action perfectly well, which
        // is what makes the leak worth closing: the request would succeed, on the
        // site's own key, with no rule and no budget consulted, and look like success.
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $this->add_working_router();
        managed_policy::set_managed_actions([fixture_dropped_action::class]);

        $response = $this->manager->process_action(
            new fixture_dropped_action(\context_system::instance()->id),
        );

        // The provider ahead would have answered with its own content, so a refusal
        // carrying none of it is the evidence that nothing outside was asked.
        $this->assertFalse($response->get_success());
        $this->assertSame(503, $response->get_errorcode());
        $this->assertNull($response->get_response_data()['generatedcontent']);
        // No provider ran, so there is nothing to attribute a record to and none is
        // invented. The refusal is the site's, not any provider's.
        $this->assertSame(0, $DB->count_records('ai_action_register'));
    }

    public function test_the_status_check_names_an_action_whose_class_has_gone(): void {
        // A managed class can be uninstalled outright. The screen and the check are
        // where an administrator finds out, so neither may ask the class anything.
        $this->add_working_router();
        managed_policy::set_managed_actions(['local_airouter\\action_that_was_uninstalled']);

        $result = (new managedboundary())->get_result();

        $this->assertSame(\core\check\result::ERROR, $result->get_status());
        $this->assertStringContainsString('action_that_was_uninstalled', $result->get_summary());
    }

    public function test_an_unmanaged_action_is_still_left_alone(): void {
        // The other half: what the site did not place under the router keeps working
        // through the provider order exactly as it did before.
        $this->add_target('Ahead', ['content' => 'Answered by the first provider']);
        $this->add_router();
        managed_policy::set_managed_actions([fixture_dropped_action::class]);

        $this->assertTrue($this->ask()->get_success());
    }

    public function test_a_provider_changed_between_two_requests_is_seen_by_the_next(): void {
        // The instances are read once for each request, not once for the manager. The
        // manager is shared for as long as the process runs, so anything kept on it
        // would hold a change an administrator saved back from every request after.
        $target = $this->add_target('Routed', ['content' => 'Before the change']);
        $this->add_router();
        $this->add_rule((int) $target->id);
        $this->manage_text();
        $this->assertSame('Before the change', $this->ask()->get_response_data()['generatedcontent']);

        $this->manager->update_provider_instance(
            $target,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS, 'content' => 'After the change'],
        );

        $this->assertSame('After the change', $this->ask()->get_response_data()['generatedcontent']);
    }
}
