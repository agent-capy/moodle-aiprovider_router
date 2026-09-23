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

namespace local_airouter\check;

use local_airouter\fixture_text_provider;
use local_airouter\eligibility_policy;
use local_airouter\key;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\price;
use local_airouter\record\ledger;
use local_airouter\record\summariser;
use local_airouter\record\usage_recorder;
use local_airouter\key_repository;
use local_airouter\order_inspector;
use local_airouter\provider;
use core\check\result;
use core_ai\manager;
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/fixture_action.php');
require_once(__DIR__ . '/../fixtures/fixture_text_provider.php');
require_once(__DIR__ . '/../fixtures/fixture_other_provider.php');
require_once(__DIR__ . '/../fixtures/fixture_unconfigured_provider.php');

/**
 * Tests for the status checks the router reports on the site status report.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(routerlisted::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(routerfirst::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(staleentries::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(actionconflict::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(declinereach::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(singleinstance::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(byokkeys::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(byokeligibility::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(budgetrates::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(budgethistory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(staleactions::class)]
final class check_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
    }

    /**
     * Every check the plugin reports.
     *
     * @return string[] The check class names.
     */
    protected static function all_checks(): array {
        return [
            routerlisted::class,
            routerfirst::class,
            staleentries::class,
            actionconflict::class,
            declinereach::class,
            singleinstance::class,
        ];
    }

    /**
     * Build an inspector over a made up site.
     *
     * @param ai_provider[] $instances Instances keyed by id.
     * @param string $order The provider_order value to store.
     * @return order_inspector The inspector.
     */
    protected function inspector(array $instances, string $order): order_inspector {
        set_config('provider_order', $order, 'core_ai');
        $manager = $this->createStub(manager::class);
        $manager->method('get_provider_instances')->willReturn($instances);

        return new order_inspector($manager);
    }

    /**
     * A router instance.
     *
     * @param int $id The instance id.
     * @param string $mode One of the provider MODE_ constants.
     * @return provider The router.
     */
    protected function router(int $id, string $mode = provider::MODE_FULL, array $extra = []): provider {
        return new \aiprovider_router\provider(
            enabled: true,
            name: "Router {$id}",
            config: json_encode($extra + ['mode' => $mode, 'defaulttarget' => 99]),
            id: $id,
        );
    }

    /**
     * A configured provider handling the same action as the router.
     *
     * @param int $id The instance id.
     * @return ai_provider The instance.
     */
    protected function other(int $id): ai_provider {
        return new fixture_text_provider(enabled: true, name: "Provider {$id}", config: '{}', id: $id);
    }

    public function test_every_check_stands_down_until_a_router_exists(): void {
        $inspector = $this->inspector([9 => $this->other(9)], ',9');

        foreach (self::all_checks() as $class) {
            $check = new $class($inspector);
            $this->assertSame(result::NA, $check->get_result()->get_status(), $class);
        }
    }

    /**
     * The checks that are about a stored provider rather than about the site.
     *
     * @return string[] The check class names.
     */
    protected static function provider_checks(): array {
        return [
            routerlisted::class,
            routerfirst::class,
            actionconflict::class,
            declinereach::class,
            singleinstance::class,
            staleactions::class,
        ];
    }

    public function test_a_site_routing_without_an_instance_is_still_watched(): void {
        // Asking for a provider instance turned every check off on a site that has no
        // instance and does route: the rules, the budgets and the keys were being used
        // and nothing was watching any of them.
        set_config('defaulttarget', 9, 'local_airouter');
        $inspector = $this->inspector([9 => $this->other(9)], ',9');

        $result = (new staleentries($inspector))->get_result();

        $this->assertNotSame(result::NA, $result->get_status());
    }

    public function test_the_checks_about_a_provider_stand_down_without_one(): void {
        // These ask where the router sits in the site order, whether two of it exist,
        // and what its stored settings say. None of that is about a site that routes
        // without registering a provider at all.
        set_config('defaulttarget', 9, 'local_airouter');
        $inspector = $this->inspector([9 => $this->other(9)], ',9');

        foreach (self::provider_checks() as $class) {
            $result = (new $class($inspector))->get_result();
            $this->assertSame(result::NA, $result->get_status(), $class);
            $this->assertSame(
                get_string('check:notaprovider', 'local_airouter'),
                $result->get_summary(),
                $class,
            );
        }
    }

    public function test_a_site_that_has_set_nothing_up_is_left_alone(): void {
        // No instance, no rules, no default target. Nothing has been asked for yet,
        // and a site that has not started is not a site with a problem.
        $inspector = $this->inspector([9 => $this->other(9)], ',9');

        $result = (new staleentries($inspector))->get_result();

        $this->assertSame(result::NA, $result->get_status());
        $this->assertSame(get_string('check:norouter', 'local_airouter'), $result->get_summary());
    }

    public function test_a_router_at_the_front_passes_every_check(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',5,9');

        foreach (self::all_checks() as $class) {
            $check = new $class($inspector);
            $this->assertSame(result::OK, $check->get_result()->get_status(), $class);
        }
    }

    public function test_a_router_missing_from_the_order_is_an_error(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9');

        $this->assertSame(result::ERROR, (new routerlisted($inspector))->get_result()->get_status());
    }

    public function test_a_router_that_is_not_first_is_an_error_in_router_only_mode(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');

        $this->assertSame(result::ERROR, (new routerfirst($inspector))->get_result()->get_status());
    }

    public function test_a_router_that_is_not_first_is_only_noted_in_coexist_mode(): void {
        // An administrator running the router alongside other providers may have put one in
        // front deliberately, so reporting it as broken would be wrong.
        $instances = [5 => $this->router(5, provider::MODE_COEXIST), 9 => $this->other(9)];
        $inspector = $this->inspector($instances, ',9,5');

        $this->assertSame(result::INFO, (new routerfirst($inspector))->get_result()->get_status());
    }

    public function test_the_position_is_spelled_out_when_the_router_is_not_first(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');

        $this->assertStringContainsString('2', (new routerfirst($inspector))->get_result()->get_details());
    }

    public function test_leftover_entries_are_a_warning(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',5,9,77');
        $result = (new staleentries($inspector))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('77', $result->get_details());
    }

    public function test_a_provider_answering_first_is_a_warning_that_names_it(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');
        $result = (new actionconflict($inspector))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('Provider 9', $result->get_details());
    }

    public function test_a_provider_behind_the_router_is_named_even_when_refusals_are_final(): void {
        // Not a problem, but not nothing either: it is the answer to "if the router says
        // no, does anything else say yes", and an administrator should be able to read it
        // off the status report rather than reason about the provider order.
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',5,9');
        $result = (new declinereach($inspector))->get_result();

        $this->assertSame(result::OK, $result->get_status());
        $this->assertStringContainsString('Provider 9', $result->get_details());
    }

    public function test_a_provider_behind_the_router_is_a_warning_once_refusals_are_not_final(): void {
        $instances = [5 => $this->router(5, extra: ['strictdecline' => 0]), 9 => $this->other(9)];
        $inspector = $this->inspector($instances, ',5,9');
        $result = (new declinereach($inspector))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('Provider 9', $result->get_details());
    }

    public function test_a_router_with_nobody_behind_it_has_nothing_to_report(): void {
        $inspector = $this->inspector([5 => $this->router(5)], ',5');
        $result = (new declinereach($inspector))->get_result();

        $this->assertSame(result::OK, $result->get_status());
        $this->assertSame('', $result->get_details());
    }

    public function test_a_provider_ahead_of_the_router_is_not_counted_as_being_behind_it(): void {
        // It answers first and the router is never reached, which is a different
        // complaint with a check of its own.
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');
        $result = (new declinereach($inspector))->get_result();

        $this->assertSame(result::OK, $result->get_status());
        $this->assertSame('', $result->get_details());
    }

    public function test_a_second_router_is_an_error_that_says_which_one_to_delete(): void {
        $inspector = $this->inspector([4 => $this->router(4), 7 => $this->router(7)], ',4,7');
        $result = (new singleinstance($inspector))->get_result();

        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('Router 7', $result->get_details());
        $this->assertStringContainsString('Router 4', $result->get_details());
    }

    public function test_keys_nobody_has_brought_are_nothing_to_report_on(): void {
        $check = new byokkeys($this->inspector([5 => $this->router(5)], ',5'));

        $this->assertSame(result::NA, $check->get_result()->get_status());
    }

    public function test_keys_that_can_be_read_are_reported_as_such(): void {
        global $DB;
        (new key_repository($DB))->save(key::SCOPE_USER, 7, 3, 'sk-a-key-abcd');

        $result = (new byokkeys($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }

    public function test_keys_that_cannot_be_read_are_an_error_rather_than_a_warning(): void {
        global $DB;
        $repository = new key_repository($DB);
        $repository->save(key::SCOPE_USER, 7, 3, 'sk-a-key-abcd');
        $broken = $repository->save(key::SCOPE_USER, 8, 3, 'sk-another-efgh');
        // A site restored from a database backup without its encryption key file.
        $DB->set_field(key::TABLE, 'secret', 'nonsense', ['id' => $broken->get('id')]);

        $result = (new byokkeys($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        // Every request those keys were meant to pay for is failing, so this is not a
        // thing to mention in passing.
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('1', $result->get_summary());
    }

    public function test_a_budget_over_history_the_site_discarded_is_reported(): void {
        global $DB;

        // The site ran with a short retention, the purge threw the older part away,
        // and only afterwards was a thirty day budget written. That order is the
        // whole of it: once the budget exists the purge protects what it needs, so
        // the only way to lose the history is to lose it first. The budget then reads
        // a real figure that is smaller than the spending was, and nothing else on
        // the site says so.
        $this->rate();
        $this->request(1.0, time() - 10 * DAYSECS);
        set_config('logretentiondays', 1, 'local_airouter');
        set_config('summaryretentiondays', 2, 'local_airouter');
        (new summariser($DB))->run(time());
        set_config('summaryretentiondays', 60, 'local_airouter');
        $this->budget_rule();

        // Both tables are empty now, which is the shape of a site that has never used
        // its AI at all. It is not one.
        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE));
        $this->assertSame(0, $DB->count_records(summariser::TABLE));

        $result = (new budgethistory($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertNotSame('', $result->get_details());
    }

    public function test_a_site_that_has_discarded_nothing_is_not_reported(): void {
        $this->budget_rule();
        $this->request(1.0);

        $result = (new budgethistory($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        // One day of history and a thirty day budget. Nothing is missing: the site
        // has everything that ever happened, which is all a budget can ask for.
        $this->assertSame(result::OK, $result->get_status());
    }

    public function test_a_budget_inside_what_the_site_still_covers_is_not_reported(): void {
        global $DB;

        // Something was discarded, but long enough ago that no budget reaches it.
        $this->rate();
        $this->budget_rule();
        $this->request(1.0, time() - 200 * DAYSECS);
        set_config('logretentiondays', 1, 'local_airouter');
        set_config('summaryretentiondays', 2, 'local_airouter');
        (new summariser($DB))->run(time() - 150 * DAYSECS);

        $result = (new budgethistory($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }

    public function test_without_a_budget_there_is_nothing_to_report(): void {
        $this->request(1.0);

        $result = (new budgethistory($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::NA, $result->get_status());
    }

    /**
     * Give the site a rule that routes by budget.
     *
     * @param string $metric What the budget counts.
     */
    protected function budget_rule(string $metric = ledger::METRIC_COST): void {
        global $DB;
        $rule = new rule();
        $rule->set('name', 'While there is money left');
        $rule->set('targetid', 3);
        (new rule_repository($DB))->save($rule, ['budget' => [
            'scope' => ledger::SCOPE_SITE,
            'direction' => 'under',
            'metric' => $metric,
            'provider' => $metric === ledger::METRIC_COST ? 'aiprovider_openai' : '',
            'amount' => 100.0,
            'period' => ledger::PERIOD_ROLLING,
            'days' => 30,
        ]]);
    }

    /**
     * Write one recorded request, as the request and attempt records hold it.
     *
     * @param float|null $cost What it cost, or null when no rate covered it.
     * @param int|null $when When it ended, or null for an hour ago.
     */
    protected function request(?float $cost, ?int $when = null): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $when ??= time() - HOURSECS;
        $request = $generator->create_request([
            'userid' => 5,
            'answeredby' => 1,
            'timestarted' => $when,
            'timeended' => $when,
        ]);
        $generator->create_attempt([
            'requestid' => $request->id,
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'cost' => $cost,
            'currency' => $cost === null ? null : 'USD',
            'timestarted' => $when,
            'timeended' => $when,
        ]);
    }

    /**
     * Enter a rate for the provider the requests above went to, in dollars.
     */
    protected function rate(): void {
        $rate = new price();
        $rate->set('provider', 'aiprovider_openai');
        $rate->set('currency', 'USD');
        $rate->set('promptrate', 1.0);
        $rate->create();
    }

    public function test_an_instance_made_before_an_action_existed_is_reported(): void {
        // The action list is read fresh on every request; the instance's action
        // configuration is written once, when the instance is made. Installing a
        // plugin that defines a new action leaves the two disagreeing, and the
        // provider settings screen cannot put it right.
        // A brand new instance agrees with itself: the constructor fills its action
        // configuration from the list the provider offers right now.
        $fresh = $this->router(5);
        $this->assertSame(
            result::OK,
            (new staleactions($this->inspector([5 => $fresh], ',5')))->get_result()->get_status(),
        );

        // One the site has had for a while, from before an action arrived.
        $actions = provider::get_action_list();
        $stale = new \aiprovider_router\provider(
            enabled: true,
            name: 'Router 5',
            config: json_encode(['mode' => provider::MODE_FULL, 'defaulttarget' => 99]),
            actionconfig: json_encode([
                reset($actions) => ['enabled' => true, 'settings' => []],
            ]),
            id: 5,
        );
        $result = (new staleactions($this->inspector([5 => $stale], ',5')))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('recreat', strtolower($result->get_details()));
    }

    public function test_rates_are_only_load_bearing_once_a_rule_routes_by_budget(): void {
        $this->request(null);

        $result = (new budgetrates($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        // Rates are worth having anyway, for the monitor. Nothing here depends on them.
        $this->assertSame(result::NA, $result->get_status());
    }

    public function test_a_budget_rule_with_no_rates_at_all_is_an_error(): void {
        $this->budget_rule();
        $this->request(null);
        $this->request(null);

        $result = (new budgetrates($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        // The rule is enabled, looks right, and can never match. Nothing else on the
        // site would say so, and it says which provider has no rates.
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('OpenAI', $result->get_summary());
    }

    public function test_a_site_routing_by_request_counts_needs_no_rates(): void {
        $this->budget_rule(ledger::METRIC_REQUESTS);
        $this->request(null);
        $this->request(null);

        $result = (new budgetrates($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        // Requests are counted, not priced. Telling this site its budgets can never
        // match would send somebody looking for a problem it does not have.
        $this->assertSame(result::NA, $result->get_status());
    }

    public function test_a_mostly_unpriced_site_is_told_its_budgets_understate(): void {
        $this->rate();
        $this->budget_rule();
        $this->request(1.0);
        $this->request(null);
        $this->request(null);
        $this->request(null);

        $result = (new budgetrates($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
    }

    public function test_a_priced_site_with_budget_rules_is_fine(): void {
        $this->rate();
        $this->budget_rule();
        $this->request(1.0);
        $this->request(2.0);

        $result = (new budgetrates($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }

    public function test_a_site_that_has_recorded_nothing_is_not_told_off(): void {
        $this->rate();
        $this->budget_rule();

        $result = (new budgetrates($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::NA, $result->get_status());
    }

    public function test_a_budget_at_a_provider_with_no_rates_is_an_error_before_any_traffic(): void {
        // The budget names a provider, and its currency is the currency of that
        // provider's rates. With none, the budget has no currency to be in and can
        // never be measured, whatever the rest of the site has priced.
        $this->budget_rule();

        $result = (new budgetrates($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('OpenAI', $result->get_summary());
    }

    public function test_every_check_offers_somewhere_to_go_and_has_a_name(): void {
        $inspector = $this->inspector([5 => $this->router(5)], ',5');

        foreach ([...self::all_checks(), byokkeys::class, budgetrates::class] as $class) {
            $check = new $class($inspector);
            $this->assertNotEmpty($check->get_name(), $class);
            $this->assertNotNull($check->get_action_link(), $class);
        }
    }

    /**
     * A custom profile field with the visibility settings given.
     *
     * @param string $shortname Its short name.
     * @param array $settings visible / locked / signup, over the defaults.
     */
    protected function profile_field(string $shortname, array $settings): void {
        global $DB;

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => $shortname,
            'name' => ucfirst($shortname),
        ]);
        foreach ($settings as $name => $value) {
            $DB->set_field('user_info_field', $name, $value, ['shortname' => $shortname]);
        }
    }

    public function test_a_site_deciding_by_no_conditions_has_no_eligibility_to_report_on(): void {
        $check = new byokeligibility($this->inspector([5 => $this->router(5)], ',5'));

        $this->assertSame(result::NA, $check->get_result()->get_status());
    }

    public function test_a_policy_on_a_field_only_staff_can_change_is_fine(): void {
        $this->profile_field('checked', ['visible' => 1, 'locked' => 1, 'signup' => 0]);
        (new eligibility_policy())->save(eligibility_policy::ACCESS_CONDITIONS, [
            'profilefield' => ['field' => 'checked', 'values' => ['staff']],
        ]);

        $check = new byokeligibility($this->inspector([5 => $this->router(5)], ',5'));

        $this->assertSame(result::OK, $check->get_result()->get_status());
    }

    public function test_a_policy_people_can_admit_themselves_to_is_a_warning(): void {
        $this->profile_field('selfsaid', ['visible' => 1, 'locked' => 0, 'signup' => 0]);
        (new eligibility_policy())->save(eligibility_policy::ACCESS_CONDITIONS, [
            'profilefield' => ['field' => 'selfsaid', 'values' => ['staff']],
        ]);

        $result = (new byokeligibility($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('selfsaid', $result->get_details());
    }

    public function test_requiring_every_condition_makes_a_self_declared_field_worth_less(): void {
        // With every condition required, the self declared one narrows the policy
        // rather than opening it: somebody still has to satisfy the others.
        $this->profile_field('selfsaid', ['visible' => 1, 'locked' => 0, 'signup' => 0]);
        (new eligibility_policy())->save(
            eligibility_policy::ACCESS_CONDITIONS,
            ['profilefield' => ['field' => 'selfsaid', 'values' => ['staff']]],
            eligibility_policy::MATCH_ALL,
        );

        $result = (new byokeligibility($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::INFO, $result->get_status());
    }


    public function test_calls_recorded_in_another_currency_than_the_rates_are_reported(): void {
        global $DB;
        $this->rate();
        $this->budget_rule();
        $this->request(1.0);
        // One call recorded in yen against dollar rates: a correction that did not
        // reach it, or one that it slipped past.
        $DB->set_field(usage_recorder::ATTEMPT_TABLE, 'currency', 'JPY', ['model' => 'gpt-4o']);

        $result = (new budgetrates($this->inspector([5 => $this->router(5)], ',5')))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('OpenAI', $result->get_summary());
    }
}
