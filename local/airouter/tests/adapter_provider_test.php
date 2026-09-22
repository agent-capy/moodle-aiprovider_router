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
