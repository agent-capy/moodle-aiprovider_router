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

namespace local_airouter\setup;

use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_ai\manager;
use core_ai\provider as ai_provider;
use local_airouter\condition\budget;
use local_airouter\managed_policy;
use local_airouter\provider;
use local_airouter\record\ledger;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\target_resolver;
use local_airouter\target_settings;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/mock/provider.php');
require_once(__DIR__ . '/../fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/../fixtures/mock/process_generate_text.php');
require_once(__DIR__ . '/../fixtures/fixture_text_provider.php');
require_once(__DIR__ . '/../fixtures/fixture_unconfigured_provider.php');
require_once(__DIR__ . '/../fixtures/fixture_dropped_action.php');

/**
 * Tests for what the settings are said to offer each action.
 *
 * The first thing these hold is that the words never run ahead of the router: a
 * state the inspector reports has to be the state a request meets. Where that can be
 * shown directly, a request is sent through the real manager to the mock provider
 * and the outcome is compared with what was reported.
 *
 * The second is the case the self-review got wrong and the independent review
 * caught: a usable default target does not mean every request is answered, because
 * requests no rule claims may be declined.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(route_inspector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_inspection::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_rule::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(target_resolver::class)]
final class route_inspector_test extends \advanced_testcase {
    /** @var manager The manager a placement would be given. */
    protected manager $manager;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->manager = \core\di::get(manager::class);
    }

    /**
     * A provider instance that answers generate_text.
     *
     * @param string $name Its name.
     * @param bool $enabled Whether it is switched on.
     * @param array $actions The actions switched on for it.
     * @return ai_provider The instance.
     */
    protected function target(string $name, bool $enabled = true, array $actions = [generate_text::class]): ai_provider {
        $actionconfig = [];
        foreach ($actions as $action) {
            $actionconfig[$action] = ['enabled' => true];
        }

        return $this->manager->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: $name,
            enabled: $enabled,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS, 'content' => "Answered by {$name}"],
            actionconfig: $actionconfig,
        );
    }

    /**
     * A rule delegating to one target.
     *
     * @param int $targetid Where it delegates.
     * @param array $conditions Its conditions, keyed by type.
     * @param array $fields Other fields of the rule.
     * @return rule The rule.
     */
    protected function rule(int $targetid, array $conditions = [], array $fields = []): rule {
        global $DB;

        $rule = new rule();
        $rule->set('name', $fields['name'] ?? 'Rule to ' . $targetid);
        $rule->set('targetid', $targetid);
        foreach ($fields as $field => $value) {
            if ($field !== 'name') {
                $rule->set($field, $value);
            }
        }

        return (new rule_repository($DB))->save($rule, $conditions);
    }

    /**
     * What the settings offer an action now.
     *
     * @param string $action The action class.
     * @return route_inspection The answer.
     */
    protected function inspect(string $action = generate_text::class): route_inspection {
        global $DB;

        return (new route_inspector($DB))->inspect($action);
    }

    /**
     * Send one generate_text request through the real manager.
     *
     * @return \core_ai\aiactions\responses\response_base The response.
     */
    protected function ask(): \core_ai\aiactions\responses\response_base {
        return $this->manager->process_action(new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        ));
    }

    public function test_an_action_not_routed_is_left_to_moodle_and_says_who_answers(): void {
        $this->target('First');
        $this->target('Second');

        $inspection = $this->inspect();

        $this->assertSame(route_inspection::APPLIED_NOT_ROUTED, $inspection->applied);
        // Core tries them in the order they were added.
        $this->assertSame('First', $inspection->corefirst);
    }

    public function test_switching_routing_off_hands_every_action_back(): void {
        $target = $this->target('Cloud');
        set_config('defaulttarget', $target->id, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);
        set_config(managed_policy::SWITCH, 0, 'local_airouter');

        $this->assertSame(route_inspection::APPLIED_SWITCHED_OFF, $this->inspect()->applied);
    }

    public function test_an_action_the_router_does_not_declare_is_said_to_be_refused(): void {
        managed_policy::set_managed_actions([\local_airouter\fixture_dropped_action::class]);

        $inspection = $this->inspect(\local_airouter\fixture_dropped_action::class);

        $this->assertSame(route_inspection::APPLIED_NO_PROCESSOR, $inspection->applied);
    }

    public function test_a_usable_default_target_that_unclaimed_requests_reach_is_a_target_available(): void {
        $target = $this->target('Cloud');
        set_config('defaulttarget', $target->id, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);

        $inspection = $this->inspect();

        $this->assertSame(route_inspection::APPLIED_ROUTED, $inspection->applied);
        $this->assertSame(route_inspection::STATE_READY, $inspection->state);
        $this->assertTrue($inspection->has_default_route());
        // And the request agrees.
        $this->assertTrue($this->ask()->get_success());
    }

    public function test_rules_with_unclaimed_requests_declined_is_not_a_fault(): void {
        // A site that routes only by rule and declines the rest is configured
        // correctly. It must not be told it needs attention.
        $target = $this->target('Local');
        $this->rule((int) $target->id);
        set_config('nomatch', provider::NOMATCH_DECLINE, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);

        $inspection = $this->inspect();

        $this->assertSame(route_inspection::STATE_CONDITIONAL, $inspection->state);
        $this->assertTrue($inspection->declines);
        $this->assertNull($inspection->defaulttargetid);
    }

    public function test_a_usable_default_target_does_not_mean_requests_are_answered(): void {
        // The reviewer's counterexample. The default target is fine, unclaimed
        // requests are declined, and no rule applies: every request is refused.
        $target = $this->target('Cloud');
        set_config('defaulttarget', $target->id, 'local_airouter');
        set_config('nomatch', provider::NOMATCH_DECLINE, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);

        $inspection = $this->inspect();

        $this->assertSame(route_inspection::STATE_POLICY_REFUSED, $inspection->state);
        $this->assertFalse($inspection->has_default_route());
        $this->assertFalse($this->ask()->get_success());
    }

    public function test_nothing_to_send_to_needs_attention_and_is_refused(): void {
        managed_policy::set_managed_actions([generate_text::class]);

        $inspection = $this->inspect();

        $this->assertSame(route_inspection::STATE_ACTION_NEEDED, $inspection->state);
        $this->assertFalse($this->ask()->get_success());
    }

    public function test_a_deleted_default_target_with_a_working_rule_is_not_called_a_stop(): void {
        // The self-review said "every request is refused" here, and it is not: the
        // rule still carries what it claims.
        $gone = $this->target('Gone');
        $local = $this->target('Local');
        $this->rule((int) $local->id);
        set_config('defaulttarget', $gone->id, 'local_airouter');
        $this->manager->delete_provider_instance($gone);
        managed_policy::set_managed_actions([generate_text::class]);

        $inspection = $this->inspect();

        $this->assertSame(route_inspection::STATE_CONDITIONAL, $inspection->state);
        $this->assertTrue($inspection->default_is_broken());
        $this->assertSame(target_resolver::PROBLEM_MISSING, $inspection->defaultproblem);
        $this->assertNull($inspection->defaulttargetname);
        $this->assertTrue($this->ask()->get_success());
    }

    /**
     * Default targets that cannot carry generate_text, and why.
     *
     * @return array<string, array{string, string}> The case and the problem expected.
     */
    public static function broken_defaults(): array {
        return [
            'switched off' => ['disabled', target_resolver::PROBLEM_DISABLED],
            'key field empty' => ['unconfigured', target_resolver::PROBLEM_UNCONFIGURED],
            'action not offered' => ['noaction', target_resolver::PROBLEM_NOACTION],
            'action switched off' => ['actionoff', target_resolver::PROBLEM_ACTIONOFF],
            'kept for brought keys' => ['byokonly', target_resolver::PROBLEM_BYOKONLY],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broken_defaults')]
    public function test_a_default_target_that_cannot_be_used_is_named_with_its_reason(string $case, string $problem): void {
        global $DB;

        $target = match ($case) {
            'disabled' => $this->target('Off', false),
            'unconfigured' => $this->manager->create_provider_instance(
                classname: \local_airouter\fixture_unconfigured_provider::class,
                name: 'Empty key',
                enabled: true,
                config: [],
                actionconfig: [generate_text::class => ['enabled' => true]],
            ),
            'noaction' => $this->manager->create_provider_instance(
                classname: \local_airouter\fixture_text_provider::class,
                name: 'Text only',
                enabled: true,
                config: [],
                actionconfig: [summarise_text::class => ['enabled' => true]],
            ),
            'actionoff' => $this->target('Summaries only', true, [summarise_text::class]),
            'byokonly' => $this->target('Brought keys only'),
        };
        if ($case === 'byokonly') {
            $settings = new target_settings($DB);
            $settings->set_key_field((int) $target->id, 'apikey');
            $settings->set_mode((int) $target->id, target_settings::MODE_ONLY);
        }
        set_config('defaulttarget', $target->id, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class, summarise_text::class]);

        $inspection = $this->inspect($case === 'noaction' ? summarise_text::class : generate_text::class);

        $this->assertSame($problem, $inspection->defaultproblem);
        $this->assertSame(route_inspection::STATE_ACTION_NEEDED, $inspection->state);
    }

    public function test_only_rules_in_force_for_this_action_count(): void {
        $target = $this->target('Local', true, [generate_text::class, summarise_text::class]);
        // Switched off.
        $this->rule((int) $target->id, [], ['name' => 'Off', 'enabled' => 0]);
        // Over.
        $this->rule((int) $target->id, [], ['name' => 'Expired', 'timeend' => time() - DAYSECS]);
        // For another action.
        $this->rule((int) $target->id, ['action' => ['actions' => [summarise_text::class]]], ['name' => 'Summaries']);
        managed_policy::set_managed_actions([generate_text::class]);

        $inspection = $this->inspect();

        // The router itself counts every rule when it asks whether it is set up at
        // all, and would call this site configured. The inspection says what a
        // request would actually meet.
        $this->assertSame([], $inspection->rules);
        $this->assertSame(route_inspection::STATE_ACTION_NEEDED, $inspection->state);
        $this->assertFalse($this->ask()->get_success());
        $this->assertCount(1, $this->inspect(summarise_text::class)->rules);
    }

    public function test_rules_paid_for_with_brought_keys_serve_only_key_holders(): void {
        global $DB;

        $target = $this->target('Own key');
        (new target_settings($DB))->set_key_field((int) $target->id, 'apikey');
        $this->rule((int) $target->id, [], ['keysource' => rule::KEYSOURCE_USER]);
        set_config('nomatch', provider::NOMATCH_DECLINE, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);

        $inspection = $this->inspect();

        $this->assertSame(route_inspection::STATE_CONDITIONAL, $inspection->state);
        $this->assertTrue($inspection->served_only_by_brought_keys());
        $this->assertTrue($inspection->rules[0]->brought);
    }

    public function test_a_brought_key_rule_at_a_provider_taking_no_brought_keys_is_named(): void {
        global $DB;

        $target = $this->target('No brought keys');
        $settings = new target_settings($DB);
        $settings->set_key_field((int) $target->id, 'apikey');
        $settings->set_mode((int) $target->id, target_settings::MODE_DISALLOWED);
        $this->rule((int) $target->id, [], ['keysource' => rule::KEYSOURCE_USER]);
        managed_policy::set_managed_actions([generate_text::class]);

        $inspection = $this->inspect();

        $this->assertSame(target_resolver::PROBLEM_BYOKDISALLOWED, $inspection->rules[0]->problem);
        $this->assertSame([], $inspection->usable_rules());
    }

    public function test_a_budget_rule_is_noticed(): void {
        $target = $this->target('Metered');
        $this->rule((int) $target->id, ['budget' => [
            'scope' => ledger::SCOPE_SITE,
            'direction' => budget::DIRECTION_UNDER,
            'amount' => 10,
            'metric' => ledger::METRIC_REQUESTS,
            'period' => ledger::PERIOD_ROLLING,
            'days' => 30,
        ]]);
        managed_policy::set_managed_actions([generate_text::class]);

        $this->assertTrue($this->inspect()->has_budget_rule());
    }

    public function test_reading_the_settings_sends_nothing_and_writes_nothing(): void {
        global $DB;

        $target = $this->target('Cloud');
        set_config('defaulttarget', $target->id, 'local_airouter');
        $this->rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);
        $requests = $DB->count_records('local_airouter_request');
        $register = $DB->count_records('ai_action_register');

        (new route_inspector($DB))->inspect_managed();

        $this->assertSame($requests, $DB->count_records('local_airouter_request'));
        $this->assertSame($register, $DB->count_records('ai_action_register'));
    }
}
