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

use aiprovider_router\condition\budget;
use aiprovider_router\exception\declined_request;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\responses\response_base;
use core_ai\manager;
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');

/**
 * What a site really does with a request, entered where a placement enters.
 *
 * Every other test here starts inside the router. That is where its decisions are made,
 * but it is not where they take effect: core walks the whole provider order and stops at
 * the first success, so what the router returns is only half the story. A refusal that
 * reads perfectly well on its own is, from one step further out, an invitation to the
 * next provider to answer the same request on the site's own key -- which is how a
 * budget, a rule and a choice of who pays can all be true and none of them hold.
 *
 * These tests start at core_ai\manager::process_action(), with real provider instances
 * in the database and core's own dispatch in between, because that is the only vantage
 * point from which that could be seen at all.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(abstract_processor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(declined_request::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class core_dispatch_test extends \advanced_testcase {
    /** @var manager The AI manager, as a placement would get it. */
    protected manager $manager;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
        $this->manager = \core\di::get(manager::class);
    }

    /**
     * Create the router instance, first in the site's order.
     *
     * @param array $config The instance configuration.
     * @return provider The instance.
     */
    protected function add_router(array $config): provider {
        /** @var provider $instance */
        $instance = $this->manager->create_provider_instance(
            classname: provider::class,
            name: 'Router',
            enabled: true,
            config: $config,
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        provider::get_instance_ids(true);

        return $instance;
    }

    /**
     * Create an ordinary provider instance behind the router.
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
     * Ask for some text, the way a placement asks.
     *
     * @return response_base What the site produced.
     */
    protected function ask(): response_base {
        // Creating an instance puts it last in the site's order, so each test creates
        // the providers in the order core should try them and the router is moved to
        // the front here, which is where its own status check asks for it.
        set_config('provider_order', (new order_inspector())->build_promoted_order(), 'core_ai');

        return $this->manager->process_action(new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        ));
    }

    /**
     * A rule that carries everything until the site has spent its allowance.
     *
     * Counted in requests rather than money, because a count is always known and a
     * site with no rates entered has spent nothing however much it has used.
     *
     * @param int $targetid Where the rule delegates.
     * @param int $allowance How many requests the site may make.
     */
    protected function add_budget_rule(
        int $targetid,
        int $allowance,
        string $direction = budget::DIRECTION_UNDER,
    ): void {
        global $DB;

        $rule = new rule();
        $rule->set('name', 'While there is money left');
        $rule->set('targetid', $targetid);

        (new rule_repository($DB))->save($rule, [
            'budget' => [
                'scope' => spend_ledger::SCOPE_SITE,
                'direction' => $direction,
                'amount' => $allowance,
                'metric' => spend_ledger::METRIC_REQUESTS,
                'period' => spend_ledger::PERIOD_ROLLING,
                'days' => 30,
            ],
        ]);
    }

    /**
     * A rule sending the request on the asker's own key.
     *
     * @param int $targetid Where it delegates.
     */
    protected function add_byok_rule(int $targetid): void {
        global $DB;

        set_config('byokaccess', eligibility_policy::ACCESS_EVERYBODY, 'aiprovider_router');
        (new target_settings($DB))->set_key_field($targetid, 'apikey');
        (new key_repository($DB))->save(key::SCOPE_USER, (int) get_admin()->id, $targetid, 'their-own-key-abcd');

        $rule = new rule();
        $rule->set('name', 'Charged to whoever asked');
        $rule->set('targetid', $targetid);
        $rule->set('keysource', rule::KEYSOURCE_USER);
        (new rule_repository($DB))->save($rule);
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
        \core_cache\helper::purge_by_definition('aiprovider_router', spend_ledger::CACHE_AREA);
    }

    public function test_a_spent_budget_is_not_handed_to_the_next_provider_to_pay_for(): void {
        global $DB;

        // The finding this whole arrangement exists for. A rule says "while there is
        // room, use this provider"; the room runs out; core, which cannot be told that
        // a failure was a decision, offers the same request to the next provider and it
        // is answered on the site's own key. The limit would hold only until somebody
        // asked twice.
        $this->add_target('Site provider', ['content' => 'Answered on the site key']);
        $target = $this->add_target('Metered', ['content' => 'Within budget']);
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);

        try {
            $this->ask();
            $this->fail('A spent budget should have stopped the request.');
        } catch (declined_request $e) {
            $this->assertSame(abstract_processor::REASON_BUDGET_SPENT, $e->get_reason());
        }

        // Core writes one of these for every provider it calls that returns, so an
        // empty table says nobody behind the router was given a turn.
        $this->assertSame(0, $DB->count_records('ai_action_generate_text'));
    }

    public function test_a_budget_with_room_left_routes_as_the_rule_says(): void {
        $target = $this->add_target('Metered', ['content' => 'Within budget']);
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(1);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Within budget', $response->get_response_data()['generatedcontent']);
    }

    public function test_turning_the_setting_off_lets_the_next_provider_pay_for_it_instead(): void {
        // What core does with a provider that cannot say "final", kept as a setting so
        // that a site can have it back, and kept as a test so that what it costs is
        // written down rather than remembered.
        $this->add_target('Site provider', ['content' => 'Answered on the site key']);
        $target = $this->add_target('Metered', ['content' => 'Within budget']);
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE, 'strictdecline' => 0]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered on the site key', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_request_no_rule_was_ever_about_is_still_somebody_elses(): void {
        // The other half of the setting, and the reason a refusal is not final on its
        // own. Running the router alongside other providers means exactly this: the
        // router handles what its rules describe and says nothing about the rest.
        $this->add_target('Site provider', ['content' => 'Answered by the next provider']);
        $target = $this->add_target('Metered', ['content' => 'Never reached']);
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE, 'defaulttarget' => $target->id]);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered by the next provider', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_limit_nobody_has_reached_does_not_stop_anybody(): void {
        // A rule that says "once the site has passed a hundred requests, send them
        // somewhere cheaper". Before the hundredth request that rule simply does not
        // apply, and a coexisting site expects its other providers to carry on. The
        // router used to read every way that rule could fail as the money having run
        // out, so writing one rule of this shape stopped every request the site made.
        $this->add_target('Site provider', ['content' => 'Answered by the next provider']);
        $cheaper = $this->add_target('Cheaper', ['content' => 'Never reached']);
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE, 'defaulttarget' => $cheaper->id]);
        $this->add_budget_rule((int) $cheaper->id, 100, budget::DIRECTION_OVER);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered by the next provider', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_limit_that_has_been_passed_sends_the_request_where_the_rule_says(): void {
        // The same rule, doing the job it was written for. Nothing here is refused, so
        // nothing here tests the refusal: it is the other end of the test above, kept
        // beside it so that neither can be broken to make the other pass.
        $this->add_target('Site provider', ['content' => 'Never reached']);
        $cheaper = $this->add_target('Cheaper', ['content' => 'Answered by the cheaper one']);
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE, 'defaulttarget' => $cheaper->id]);
        $this->add_budget_rule((int) $cheaper->id, 1, budget::DIRECTION_OVER);
        $this->spend(2);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered by the cheaper one', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_target_that_merely_broke_still_lets_the_next_provider_try(): void {
        // Core's fallback is worth having, and only a decision is taken away from it: a
        // target that was simply down is passed over exactly as before.
        $this->add_target('Working', ['content' => 'The second one answered']);
        $broken = $this->add_target('Broken', ['scenario' => \aiprovider_mock\provider::FAILURE]);
        $this->add_router(['defaulttarget' => $broken->id]);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('The second one answered', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_site_that_is_not_finished_being_set_up_stops_rather_than_drifting(): void {
        global $DB;

        // Rules exist, none of them claims this request, and there is nowhere for it to
        // go by default. Being stopped is more use than being quietly answered, because
        // a half configured site is one somebody is still working on.
        $rule = new rule();
        $rule->set('name', 'Something else');
        $rule->set('targetid', 424242);
        (new rule_repository($DB))->save($rule, []);

        $this->add_target('Site provider', ['content' => 'Answered on the site key']);
        $this->add_router([]);

        try {
            $this->ask();
            $this->fail('A router with nowhere to send the request should have stopped it.');
        } catch (declined_request $e) {
            $this->assertSame(abstract_processor::REASON_NO_TARGET, $e->get_reason());
        }
    }

    public function test_the_routers_own_rate_limit_is_not_a_way_round_its_refusals(): void {
        // Core checks a provider's rate limit before it calls the provider at all, and
        // returns a plain failure. Everything this plugin does happens after that
        // point, so the router was never asked -- and the request it would have refused
        // on a spent budget went to the next provider on the site's own key instead.
        // Reaching the limit used to unlock everything the router was there to stop.
        $this->add_target('Site provider', ['content' => 'Answered by the next provider']);
        $target = $this->add_target('Metered', ['content' => 'Never reached']);
        $this->add_router([
            'defaulttarget' => $target->id,
            'enableuserratelimit' => 1,
            'userratelimit' => 1,
        ]);
        $this->add_budget_rule((int) $target->id, 1);

        // The first request uses the one request the limiter allows.
        $this->ask();

        $this->expectException(declined_request::class);
        $this->ask();
    }

    public function test_a_rate_limited_request_is_written_down_like_any_other_refusal(): void {
        global $DB;

        $this->add_target('Site provider', ['content' => 'Answered by the next provider']);
        $target = $this->add_target('Metered', ['content' => 'Answered by the router']);
        $this->add_router([
            'defaulttarget' => $target->id,
            'enableuserratelimit' => 1,
            'userratelimit' => 1,
        ]);

        $this->ask();
        try {
            $this->ask();
        } catch (declined_request $e) {
            $this->assertSame(abstract_processor::REASON_RATE_LIMITED, $e->get_reason());
        }

        $refused = $DB->get_records(usage_logger::TABLE, ['success' => 0]);
        $this->assertCount(1, $refused);
        $this->assertSame(
            abstract_processor::REASON_RATE_LIMITED,
            reset($refused)->reason,
        );
    }

    public function test_a_site_that_would_rather_keep_cores_behaviour_still_can(): void {
        $this->add_target('Site provider', ['content' => 'Answered by the next provider']);
        $target = $this->add_target('Metered', ['content' => 'Answered by the router']);
        $this->add_router([
            'defaulttarget' => $target->id,
            'strictdecline' => 0,
            'enableuserratelimit' => 1,
            'userratelimit' => 1,
        ]);

        $this->ask();
        $response = $this->ask();

        // Core's own answer, unchanged: the next provider takes it.
        $this->assertTrue($response->get_success());
        $this->assertSame('Answered by the next provider', $response->get_response_data()['generatedcontent']);
    }

    public function test_one_request_spends_one_of_the_allowance(): void {
        // The limiter counts a request as it allows it, so reading its answer twice
        // would spend two of the allowance for one request and halve every limit on
        // the site. Three requests against a limit of three must all get through.
        $target = $this->add_target('Metered', ['content' => 'Answered by the router']);
        $this->add_router([
            'defaulttarget' => $target->id,
            'enableuserratelimit' => 1,
            'userratelimit' => 3,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->ask()->get_success(), "request {$i} should have been allowed");
        }
    }

    public function test_a_request_somebody_pays_for_is_not_finished_on_the_sites_money(): void {
        // A target that spends its token budget and returns nothing is ordinarily just
        // a target that did not work, and core trying the next one is right. It stops
        // being right when the request was being charged to somebody's own key: the
        // next provider answers on the site's key, so the request the person asked to
        // pay for is paid for by the site, quietly, with nothing to say it happened.
        $this->add_target('Site provider', ['content' => 'Answered on the site key']);
        $theirs = $this->add_target('Theirs', ['scenario' => \aiprovider_mock\provider::TRUNCATED]);
        $this->add_router(['defaulttarget' => $theirs->id]);
        $this->add_byok_rule((int) $theirs->id);

        $this->expectException(declined_request::class);
        $this->ask();
    }

    public function test_the_same_failure_on_the_sites_own_money_still_falls_through(): void {
        // The other half: nobody brought a key, so nobody is being charged for
        // something they did not ask for, and core's fallback is worth having.
        $this->add_target('Site provider', ['content' => 'Answered on the site key']);
        $empty = $this->add_target('Empty', ['scenario' => \aiprovider_mock\provider::TRUNCATED]);
        $this->add_router(['defaulttarget' => $empty->id]);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered on the site key', $response->get_response_data()['generatedcontent']);
    }

    public function test_the_refusal_is_written_down_before_it_is_thrown(): void {
        global $DB;

        $target = $this->add_target('Metered');
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);
        $before = $DB->count_records(usage_logger::TABLE);

        try {
            $this->ask();
        } catch (declined_request $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Throwing takes core's own record of the action away, so the router's has to
        // be written first. A refusal nobody can count is a refusal nobody can audit.
        $rows = $DB->get_records(usage_logger::TABLE, null, 'id DESC', '*', 0, 1);
        $this->assertSame($before + 1, $DB->count_records(usage_logger::TABLE));
        $row = reset($rows);
        $this->assertSame(0, (int) $row->success);
        $this->assertSame(abstract_processor::REASON_BUDGET_SPENT, $row->reason);
    }

    public function test_core_keeps_no_record_of_its_own_when_the_refusal_is_thrown(): void {
        global $DB;

        $target = $this->add_target('Metered');
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);

        try {
            $this->ask();
        } catch (declined_request $e) {
            unset($e);
        }

        // What the workaround costs, stated rather than discovered later: core writes
        // ai_action_register after the provider returns, and nothing returns here.
        $this->assertSame(0, $DB->count_records('ai_action_register'));
    }

    public function test_the_message_the_person_sees_names_nothing_about_the_site(): void {
        $target = $this->add_target('Expensive provider');
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);

        try {
            $this->ask();
            $this->fail('Expected a spent budget to stop the request.');
        } catch (declined_request $e) {
            $this->assertStringNotContainsString('Expensive provider', $e->getMessage());
            $this->assertStringNotContainsString('While there is money left', $e->getMessage());
            $this->assertSame(
                get_string('error:budgetexhausted', 'aiprovider_router'),
                $e->getMessage(),
            );
        }
    }
}
