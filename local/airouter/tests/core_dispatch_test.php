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
use local_airouter\condition\budget;
use local_airouter\record\usage_recorder;
use local_airouter\record\request_state;
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
 * but it is not where they take effect: the request comes in through core's AI manager,
 * with real provider instances in the database and core's own processing around the
 * router. A refusal that reads perfectly well on its own has to stay a refusal from
 * there too, or a budget, a rule and a choice of who pays can all be true and none of
 * them hold.
 *
 * The site has placed the action under the router. Every request for it therefore
 * reaches the router whatever the provider order says, and nothing else is offered it
 * afterwards; the provider created first in each test is the one core would have asked
 * otherwise, and seeing it never answer is the point.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(abstract_processor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class core_dispatch_test extends \advanced_testcase {
    /** @var manager The AI manager, as a placement would get it. */
    protected manager $manager;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->manager = \core\di::get(manager::class);
    }

    /**
     * Set the router up and place generate_text under it.
     *
     * @param array $config The router's settings: defaulttarget, nomatch.
     */
    protected function add_router(array $config): void {
        foreach ($config as $name => $value) {
            set_config($name, $value, 'local_airouter');
        }
        managed_policy::set_managed_actions([generate_text::class]);
    }

    /**
     * Create an ordinary provider instance.
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
                'scope' => ledger::SCOPE_SITE,
                'direction' => $direction,
                'amount' => $allowance,
                'metric' => ledger::METRIC_REQUESTS,
                'period' => ledger::PERIOD_ROLLING,
                'days' => 30,
            ],
        ]);
    }

    /**
     * A rule sending every request to one target.
     *
     * @param int $targetid Where it delegates.
     */
    protected function add_rule_to(int $targetid): void {
        global $DB;

        $rule = new rule();
        $rule->set('name', 'Everything');
        $rule->set('targetid', $targetid);
        (new rule_repository($DB))->save($rule);
    }

    /**
     * A rule sending the request on the asker's own key.
     *
     * @param int $targetid Where it delegates.
     */
    protected function add_byok_rule(int $targetid): void {
        global $DB;

        set_config('byokaccess', eligibility_policy::ACCESS_EVERYBODY, 'local_airouter');
        (new target_settings($DB))->set_key_field($targetid, 'apikey');
        (new key_repository($DB))->save(key::SCOPE_USER, (int) get_admin()->id, $targetid, 'their-own-key-abcd');

        $rule = new rule();
        $rule->set('name', 'Charged to whoever asked');
        $rule->set('targetid', $targetid);
        $rule->set('keysource', rule::KEYSOURCE_USER);
        (new rule_repository($DB))->save($rule);
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
     * The router's own record of the last request.
     *
     * @return \stdClass The request row.
     */
    protected function last_request(): \stdClass {
        global $DB;

        $rows = $DB->get_records(usage_recorder::REQUEST_TABLE, null, 'id DESC', '*', 0, 1);

        return reset($rows);
    }

    public function test_a_spent_budget_is_not_handed_to_the_next_provider_to_pay_for(): void {
        global $DB;

        // The finding this whole arrangement exists for. A rule says "while there is
        // room, use this provider"; the room runs out; a provider that core would
        // otherwise try answers the same request on the site's own key, and the limit
        // holds only until somebody asks twice.
        $this->add_target('Site provider', ['content' => 'Answered on the site key']);
        $target = $this->add_target('Metered', ['content' => 'Within budget']);
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);

        $response = $this->ask();

        $this->assertFalse($response->get_success());
        $this->assertNull($response->get_response_data()['generatedcontent']);
        $this->assertSame(abstract_processor::REASON_BUDGET_SPENT, $this->last_request()->reason);
        // Core stores one record for the refusal, with nothing in it: nobody answered.
        $stored = $DB->get_records('ai_action_generate_text');
        $this->assertCount(1, $stored);
        $this->assertNull(reset($stored)->generatedcontent);
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

    public function test_a_limit_nobody_has_reached_does_not_stop_anybody(): void {
        // A rule that says "once the site has passed a hundred requests, send them
        // somewhere cheaper". Before the hundredth request that rule simply does not
        // apply, and the request goes where requests go by default. The router used to
        // read every way that rule could fail as the money having run out, so writing
        // one rule of this shape stopped every request the site made.
        $site = $this->add_target('Site provider', ['content' => 'Answered by the default target']);
        $cheaper = $this->add_target('Cheaper', ['content' => 'Never reached']);
        $this->add_router(['defaulttarget' => $site->id]);
        $this->add_budget_rule((int) $cheaper->id, 100, budget::DIRECTION_OVER);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered by the default target', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_limit_that_has_been_passed_sends_the_request_where_the_rule_says(): void {
        // The same rule, doing the job it was written for. Nothing here is refused, so
        // nothing here tests the refusal: it is the other end of the test above, kept
        // beside it so that neither can be broken to make the other pass.
        $site = $this->add_target('Site provider', ['content' => 'Never reached']);
        $cheaper = $this->add_target('Cheaper', ['content' => 'Answered by the cheaper one']);
        $this->add_router(['defaulttarget' => $site->id]);
        $this->add_budget_rule((int) $cheaper->id, 1, budget::DIRECTION_OVER);
        $this->spend(2);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered by the cheaper one', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_target_that_merely_broke_still_lets_the_next_one_try(): void {
        // Falling back is worth having, and only a decision is taken away from it: a
        // target that was simply down is passed over for the next one the router may
        // use, here the default target behind the rule.
        $working = $this->add_target('Working', ['content' => 'The second one answered']);
        $broken = $this->add_target('Broken', ['scenario' => \aiprovider_mock\provider::FAILURE]);
        $this->add_router(['defaulttarget' => $working->id]);
        $this->add_rule_to((int) $broken->id);

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

        $response = $this->ask();

        $this->assertFalse($response->get_success());
        $this->assertNull($response->get_response_data()['generatedcontent']);
        $this->assertSame(abstract_processor::REASON_NO_TARGET, $this->last_request()->reason);
    }

    public function test_a_request_somebody_pays_for_is_not_finished_on_the_sites_money(): void {
        // A target that spends its token budget and returns nothing, when the request
        // was being charged to somebody's own key. Another target would answer on the
        // site's key, so the request the person asked to pay for would be paid for by
        // the site, quietly, with nothing to say it happened.
        $site = $this->add_target('Site provider', ['content' => 'Answered on the site key']);
        $theirs = $this->add_target('Theirs', ['scenario' => \aiprovider_mock\provider::TRUNCATED]);
        $this->add_router(['defaulttarget' => $site->id]);
        $this->add_byok_rule((int) $theirs->id);

        $response = $this->ask();

        $this->assertFalse($response->get_success());
        $this->assertNull($response->get_response_data()['generatedcontent']);
    }

    public function test_the_refusal_is_written_down(): void {
        global $DB;

        $target = $this->add_target('Metered');
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);
        $before = $DB->count_records(usage_recorder::REQUEST_TABLE);

        $this->ask();

        // A refusal nobody can count is a refusal nobody can audit.
        $this->assertSame($before + 1, $DB->count_records(usage_recorder::REQUEST_TABLE));
        $row = $this->last_request();
        $this->assertSame(request_state::DECLINED, $row->state);
        $this->assertSame(abstract_processor::REASON_BUDGET_SPENT, $row->reason);
    }

    public function test_the_message_the_person_sees_names_nothing_about_the_site(): void {
        $target = $this->add_target('Expensive provider');
        $this->add_router(['nomatch' => provider::NOMATCH_DECLINE]);
        $this->add_budget_rule((int) $target->id, 2);
        $this->spend(2);

        $message = $this->ask()->get_errormessage();

        $this->assertStringNotContainsString('Expensive provider', $message);
        $this->assertStringNotContainsString('While there is money left', $message);
        $this->assertSame(get_string('error:budgetexhausted', 'local_airouter'), $message);
    }
}
