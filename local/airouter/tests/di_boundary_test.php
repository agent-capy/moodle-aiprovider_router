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
use local_airouter\check\managedboundary;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');

/**
 * How far the boundary reaches, and what it does about where it does not.
 *
 * Every entry point in core asks the container for a manager, which is what lets one
 * definition decide what happens to every request. It is not a lock. Another plugin
 * can define the same thing, and whoever is last wins; code can build a manager for
 * itself and never ask. Neither can be prevented from here, so what matters is that
 * the site is told rather than left to find out from a bill.
 *
 * These say where the line is. A test that pretends the line is somewhere else would
 * be worse than none.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(managedboundary::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hook_listener::class)]
final class di_boundary_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A provider instance, appended to the site order as instances are.
     *
     * @param string $name Its name, which is also what it answers with.
     * @return \core_ai\provider The instance.
     */
    protected function add_provider(string $name): \core_ai\provider {
        return \core\di::get(manager::class)->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: $name,
            enabled: true,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS, 'content' => $name],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
    }

    /**
     * A site that routes: a target behind another provider, and generate_text managed.
     *
     * The other provider is created first, so it is the one core would reach without
     * the router. Otherwise a request that never reached the router would look like
     * one that did.
     *
     * @return \core_ai\provider The target the router delegates to.
     */
    protected function routing_site(): \core_ai\provider {
        global $DB;

        $this->add_provider('Answered by the first provider');
        $target = \core\di::get(manager::class)->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: 'Routed',
            enabled: true,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS, 'content' => 'Answered through the router'],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );

        $rule = new rule();
        $rule->set('name', 'Everything');
        $rule->set('targetid', (int) $target->id);
        (new rule_repository($DB))->save($rule);
        managed_policy::set_managed_actions([generate_text::class]);

        return $target;
    }

    /**
     * The action a placement would bring.
     *
     * @return generate_text The action.
     */
    protected function action(): generate_text {
        return new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        );
    }

    public function test_the_definition_survives_the_container_being_rebuilt(): void {
        // The manager comes from the definition this plugin registers, not from
        // anything a test has put there: building the container again produces it.
        \core\di::reset_container();

        $this->assertInstanceOf(routing_manager::class, \core\di::get(manager::class));
    }

    public function test_a_plugin_that_takes_the_manager_is_named(): void {
        global $DB;

        // Knowing that something displaced the router is not enough to do anything
        // about it. The check says what, so there is somewhere to start.
        $this->routing_site();
        $replacement = new class ($DB) extends manager {
        };
        \core\di::set(manager::class, $replacement);

        $result = (new managedboundary())->get_result();

        $this->assertSame(\core\check\result::ERROR, $result->get_status());
        $this->assertStringContainsString($replacement::class, $result->get_details());
    }

    public function test_a_manager_built_directly_does_not_route(): void {
        global $DB;

        // The limit of the arrangement, written down. Code that builds its own
        // manager never asks the container, so nothing here can reach it. A site
        // adding such code is choosing to leave the boundary, and the manual says
        // so; what must not happen is this being discovered as a surprise.
        $this->routing_site();

        $response = (new manager($DB))->process_action($this->action());

        $this->assertTrue($response->get_success());
        $this->assertSame(
            'Answered by the first provider',
            $response->get_response_data()['generatedcontent'],
        );
    }

    public function test_the_boundary_holds_for_everything_that_does_ask(): void {
        // The other side of the same coin: a request through the container reaches
        // the router even though a provider ahead of it in the site order could have
        // answered, which is what the site asked for.
        $this->routing_site();

        $response = \core\di::get(manager::class)->process_action($this->action());

        $this->assertSame(
            'Answered through the router',
            $response->get_response_data()['generatedcontent'],
        );
    }
}
