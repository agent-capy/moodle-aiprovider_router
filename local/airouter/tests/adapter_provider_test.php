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

use core_ai\aiactions\generate_text;
use core_ai\manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');
require_once(__DIR__ . '/fixtures/fixture_text_provider.php');

/**
 * Routing a site that has no row in ai_providers for the router.
 *
 * The router is a policy. Keeping a provider row to hold it put the settings outside
 * the administration tree, listed the router among the things that answer requests,
 * and let a site create two of it. Core does not need the row: while it runs an
 * action it asks the provider for its name, its action list and its settings, and
 * builds the processor from the first part of the class name.
 *
 * These are the connection tests for that. They say nothing about whether the stored
 * instance can be removed, which is a separate decision with its own conditions.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(adapter_provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(routing_manager::class)]
final class adapter_provider_test extends \advanced_testcase {
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
     * An ordinary provider instance, of the kind the router delegates to.
     *
     * @param string $name Its name.
     * @param string $content What it answers with.
     * @return \core_ai\provider The instance.
     */
    protected function add_target(string $name, string $content): \core_ai\provider {
        return $this->manager->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: $name,
            enabled: true,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS, 'content' => $content],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
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
     * Ask for some text, the way a placement asks.
     *
     * @return \core_ai\aiactions\responses\response_base What the site produced.
     */
    protected function ask(): \core_ai\aiactions\responses\response_base {
        return $this->manager->process_action(new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        ));
    }

    public function test_a_site_with_no_router_row_still_routes(): void {
        global $DB;

        // P-1. Nothing was created for the router, and nothing is created by asking.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);

        $response = $this->ask();

        $this->assertTrue($response->get_success());
        $this->assertSame('Answered through the router', $response->get_response_data()['generatedcontent']);
        $this->assertSame(0, $DB->count_records('ai_providers', ['provider' => provider::INSTANCE_CLASS]));
    }

    public function test_the_router_is_not_something_a_site_creates(): void {
        // The other half of P-1. The AI provider screen offers exactly the installed
        // aiprovider plugins, so what this plugin can contribute to that list is the
        // connector and nothing else: no arrangement of settings, and nothing about
        // the adapter, can put the router there. Removing the connector is therefore
        // the whole of the work, with nothing left to check here afterwards.
        $creatable = \core_plugin_manager::instance()->get_plugins_of_type('aiprovider');

        foreach (array_keys($creatable) as $name) {
            $this->assertStringStartsNotWith('airouter', $name);
        }
        $this->assertArrayNotHasKey('local_airouter', $creatable);

        // Named, so that this test fails rather than quietly passing if the connector
        // is removed without the claim above being revisited.
        $this->assertArrayHasKey('router', $creatable);
    }

    public function test_the_managed_request_does_not_reach_the_provider_ahead(): void {
        // P-2. The provider created first is the one core reaches first, and it
        // answers this action perfectly well. The site said the router answers it.
        $this->add_target('Ahead', 'Answered by the first provider');
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);

        $response = $this->ask();

        $this->assertSame('Answered through the router', $response->get_response_data()['generatedcontent']);
    }

    public function test_core_records_the_result_against_this_plugin(): void {
        global $DB;

        // The row core writes says which component processed the request. That is
        // this plugin, and it is true whether or not a row exists to point at.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);

        $this->ask();

        $records = $DB->get_records('ai_action_register');
        $this->assertCount(1, $records);
        $this->assertSame('local_airouter', reset($records)->provider);
        $this->assertEquals(1, reset($records)->success);
    }

    public function test_an_unmanaged_request_is_left_to_the_provider_order(): void {
        $this->add_target('Ahead', 'Answered by the first provider');
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);

        $response = $this->ask();

        $this->assertSame('Answered by the first provider', $response->get_response_data()['generatedcontent']);
    }

    public function test_a_router_with_nowhere_to_send_anything_refuses(): void {
        // P-3. No rule and no default target. The request is not handed back to the
        // provider order, which is the whole point of having placed it here.
        $this->add_target('Ahead', 'Answered by the first provider');
        managed_policy::set_managed_actions([generate_text::class]);

        $response = $this->ask();

        $this->assertFalse($response->get_success());
        $this->assertSame(503, $response->get_errorcode());
        $this->assertNull($response->get_response_data()['generatedcontent']);
    }

    public function test_the_refusal_is_recorded_rather_than_disappearing(): void {
        global $DB;

        // A refused request is one the site made. Stopping before anything ran left
        // it out of core's record altogether, so a site that refused everything and
        // a site nobody used looked the same afterwards.
        $this->add_target('Ahead', 'Answered by the first provider');
        managed_policy::set_managed_actions([generate_text::class]);

        $this->ask();

        $records = $DB->get_records('ai_action_register');
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertEquals(0, $record->success);
        $this->assertSame('local_airouter', $record->provider);
    }

    public function test_the_refusal_says_which_reason_it_was(): void {
        // A refusal could only say that the router was unavailable, because the code
        // that knows the difference had not been reached. There are several reasons
        // and they need different things done about them.
        $this->add_target('Ahead', 'Answered by the first provider');
        managed_policy::set_managed_actions([generate_text::class]);

        $response = $this->ask();

        $this->assertSame(
            get_string('error:nodefaulttarget', 'local_airouter'),
            $response->get_errormessage(),
        );
    }

    public function test_only_the_managed_actions_are_answered(): void {
        // What the adapter says it carries is the managed policy, read back in the
        // shape core reads it. Holding that twice would let the two disagree.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);

        /** @var routing_manager $manager */
        $manager = $this->manager;

        $this->assertInstanceOf(adapter_provider::class, $manager->find_router(generate_text::class));
        $this->assertNull($manager->find_router(\core_ai\aiactions\summarise_text::class));
    }

    public function test_a_stored_instance_still_wins_while_one_exists(): void {
        // The plugin is moving away from the stored row, not ignoring it. A site that
        // configured the router in the old place keeps the settings it can see.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        $this->manager->create_provider_instance(
            classname: provider::INSTANCE_CLASS,
            name: 'Router',
            enabled: true,
            config: [],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        provider::get_instance_ids(true);
        managed_policy::set_managed_actions([generate_text::class]);

        /** @var routing_manager $manager */
        $manager = $this->manager;
        $found = $manager->find_router(generate_text::class);

        $this->assertNotInstanceOf(adapter_provider::class, $found);
        $this->assertNotNull($found?->id);
    }

    public function test_choosing_an_action_for_the_first_time_is_not_called_stuck(): void {
        // The management screen warns before placing an action under a router that
        // cannot answer it, because that stops the action working across the site.
        // Asking whether the router answers it *now* makes every first choice look
        // like that, since it is the saving that puts it under the router.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);

        /** @var routing_manager $manager */
        $manager = $this->manager;

        $this->assertTrue($manager->would_answer(generate_text::class, [generate_text::class]));

        // And the answer has to be true, not merely reassuring.
        managed_policy::set_managed_actions([generate_text::class]);
        $this->assertTrue($this->ask()->get_success());
    }

    public function test_an_action_with_nowhere_to_go_is_still_called_stuck(): void {
        // The warning has to keep working, or it becomes something to click through.
        $this->add_target('Ahead', 'Answered by the first provider');

        /** @var routing_manager $manager */
        $manager = $this->manager;

        $this->assertFalse($manager->would_answer(generate_text::class, [generate_text::class]));
    }

    public function test_a_stored_instance_answers_the_question_itself(): void {
        // With an instance, what it carries is its own setting, not the policy being
        // saved, and the screen must report what will actually happen.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        $this->manager->create_provider_instance(
            classname: provider::INSTANCE_CLASS,
            name: 'Router',
            enabled: true,
            config: [],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        provider::get_instance_ids(true);

        /** @var routing_manager $manager */
        $manager = $this->manager;

        $this->assertTrue($manager->would_answer(generate_text::class, [generate_text::class]));
        $this->assertFalse($manager->would_answer(
            \core_ai\aiactions\summarise_text::class,
            [\core_ai\aiactions\summarise_text::class],
        ));
    }

    public function test_the_switch_hands_the_site_back_to_moodle(): void {
        // Off, the plugin has to be as absent as uninstalling it would make it,
        // because that is what somebody turning it off is checking.
        $this->add_target('Ahead', 'Answered by the first provider');
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);
        set_config(managed_policy::SWITCH, 0, 'local_airouter');

        $response = $this->ask();

        $this->assertSame('Answered by the first provider', $response->get_response_data()['generatedcontent']);
    }

    public function test_the_switch_forgets_nothing(): void {
        // Turning it off is not the same as taking the actions out, or a site would
        // have to set the whole arrangement up again to try running without it.
        $this->add_target('Ahead', 'Answered by the first provider');
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);

        set_config(managed_policy::SWITCH, 0, 'local_airouter');
        $this->assertSame([generate_text::class], managed_policy::managed_actions());

        set_config(managed_policy::SWITCH, 1, 'local_airouter');
        $this->assertSame(
            'Answered through the router',
            $this->ask()->get_response_data()['generatedcontent'],
        );
    }

    public function test_the_boundary_check_is_quiet_while_the_switch_is_off(): void {
        // With nothing being routed, nothing can be failing to be routed, and an
        // error about it would be about a state the site has deliberately left.
        managed_policy::set_managed_actions([generate_text::class]);
        set_config(managed_policy::SWITCH, 0, 'local_airouter');

        $result = (new check\managedboundary())->get_result();

        $this->assertSame(\core\check\result::NA, $result->get_status());
        $this->assertSame(
            get_string('check:managedboundary:off', 'local_airouter'),
            $result->get_summary(),
        );
    }

    public function test_a_change_made_elsewhere_takes_effect_on_the_next_request(): void {
        global $DB;

        // P-4. A task runner can be processing a queue for hours. Settings read
        // through get_config() are kept inside the process, and another process
        // saving a change deletes the shared copy without reaching this one, so the
        // runner would go on routing by the settings it read when it started.
        $this->add_target('Ahead', 'Answered by the first provider');
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);
        set_config(managed_policy::SWITCH, 1, 'local_airouter');

        $this->assertSame(
            'Answered through the router',
            $this->ask()->get_response_data()['generatedcontent'],
        );

        // Read the setting the way the rest of Moodle does, so that this process holds
        // a copy of it. The difficulty below only exists once it does, and a request
        // no longer reads any setting through get_config(), so nothing else here would
        // have put one there.
        $this->assertEquals(1, get_config('local_airouter', managed_policy::SWITCH));

        // An administrator switching the router off in another process, which leaves
        // the table changed and this process's caches untouched.
        $DB->set_field(
            'config_plugins',
            'value',
            0,
            ['plugin' => 'local_airouter', 'name' => managed_policy::SWITCH],
        );

        // The cache still says the old thing, which is the whole difficulty.
        $this->assertEquals(1, get_config('local_airouter', managed_policy::SWITCH));

        $this->assertSame(
            'Answered by the first provider',
            $this->ask()->get_response_data()['generatedcontent'],
        );
    }

    public function test_the_rules_apply_to_an_action_not_placed_under_the_router(): void {
        // What the rule tester has to answer. The screen asked for a stored instance,
        // found none on a site that routes without one, and reported every matched
        // rule as unable to carry the request. Asking find_router() instead was no
        // better: that also wants the action to be one the site has placed under the
        // router, and the rules apply to a request whether or not it is.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([]);

        $this->assertNull((new order_inspector())->get_primary_router());

        $candidates = target_resolver::for_site()->get_candidates(
            new generate_text(
                contextid: \context_system::instance()->id,
                userid: get_admin()->id,
                prompttext: 'Hello',
            ),
        );

        $this->assertNotSame([], $candidates);
        $this->assertSame((int) $target->id, (int) $candidates[0]->target->id);
    }

    public function test_the_screens_reach_whichever_router_the_site_has(): void {
        // A page cannot be unit tested, so the decision the pages were getting wrong
        // lives where it can be.
        $this->add_rule((int) $this->add_target('Routed', 'Answered')->id);

        $this->assertInstanceOf(adapter_provider::class, target_resolver::for_site()->get_router());

        $this->manager->create_provider_instance(
            classname: provider::INSTANCE_CLASS,
            name: 'Router',
            enabled: true,
            config: [],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        provider::get_instance_ids(true);

        $this->assertNotInstanceOf(adapter_provider::class, target_resolver::for_site()->get_router());
    }

    public function test_an_unusable_target_says_which_one_and_why(): void {
        // An instance id on its own sends an administrator to the database to find
        // out what they just configured.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);
        $resolver = new target_resolver(adapter_provider::create());

        $usable = $resolver->describe_unusable((int) $target->id, new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        ));
        $gone = $resolver->describe_unusable(99999, new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        ));

        $this->assertNull($usable);
        $this->assertSame(get_string('ruletest:reason:missing', 'local_airouter'), $gone);
    }

    public function test_an_action_the_target_offers_but_has_switched_off(): void {
        // Two different reasons an administrator has to tell apart: the provider
        // offers this action and the site turned it off here, or the provider does
        // not do it at all. The second is the case a site meets when a rule with no
        // conditions names a provider that does text and not pictures.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        $resolver = new target_resolver(adapter_provider::create());

        $reason = $resolver->describe_unusable((int) $target->id, $this->an_image());

        $this->assertSame(get_string('ruletest:reason:actionoff', 'local_airouter'), $reason);
    }

    public function test_a_target_that_does_not_offer_the_action_says_so(): void {
        $target = $this->manager->create_provider_instance(
            classname: fixture_text_provider::class,
            name: 'Text only',
            enabled: true,
            config: [],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        $this->add_rule((int) $target->id);
        $resolver = new target_resolver(adapter_provider::create());

        $reason = $resolver->describe_unusable((int) $target->id, $this->an_image());

        $this->assertSame(
            get_string(
                'ruletest:reason:noaction',
                'local_airouter',
                \core_ai\aiactions\generate_image::get_name(),
            ),
            $reason,
        );
    }

    /**
     * An image request, which not every provider can carry.
     *
     * @return \core_ai\aiactions\generate_image The action.
     */
    protected function an_image(): \core_ai\aiactions\generate_image {
        return new \core_ai\aiactions\generate_image(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'A cat',
            quality: 'hd',
            aspectratio: 'square',
            numimages: 1,
            style: 'natural',
        );
    }

    public function test_the_adapter_is_built_fresh_for_each_request(): void {
        // The manager in the container is shared for the length of the request and
        // may be reused. Nothing about one request may survive into the next.
        $target = $this->add_target('Routed', 'Answered through the router');
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);

        /** @var routing_manager $manager */
        $manager = $this->manager;
        $first = $manager->find_router(generate_text::class);

        managed_policy::set_managed_actions([]);
        $second = $manager->find_router(generate_text::class);

        $this->assertInstanceOf(adapter_provider::class, $first);
        $this->assertNull($second);
    }
}
