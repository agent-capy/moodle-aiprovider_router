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

/**
 * Tests for the router provider instance itself.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hook_listener::class)]
final class provider_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The instance list is memoised for the request, so each test starts clean.
        provider::get_instance_ids(true);
    }

    /**
     * Create a router instance on the site.
     *
     * @param array $config The instance configuration.
     * @return provider The created instance.
     */
    protected function create_router(array $config = []): provider {
        $instance = \core\di::get(\core_ai\manager::class)->create_provider_instance(
            classname: provider::INSTANCE_CLASS,
            name: 'router ' . uniqid(),
            enabled: true,
            config: $config,
        );
        provider::get_instance_ids(true);

        return $instance;
    }

    /**
     * Test that a router with nowhere to delegate reports itself as unconfigured.
     */
    public function test_router_without_target_is_not_configured(): void {
        $router = $this->create_router();

        // Otherwise core would hand it every request only for the router to fail them.
        $this->assertFalse($router->is_provider_configured());
        $this->assertNull($router->get_default_target_id());
    }

    /**
     * Test that a router with a target reports itself as configured.
     */
    public function test_router_with_target_is_configured(): void {
        $router = $this->create_router(['defaulttarget' => 42]);

        $this->assertTrue($router->is_provider_configured());
        $this->assertEquals(42, $router->get_default_target_id());
    }

    /**
     * Test that the mode defaults to full routing and only accepts known values.
     */
    public function test_mode_defaults_to_full_routing(): void {
        $this->assertEquals(provider::MODE_FULL, $this->create_router()->get_mode());
        $this->assertEquals(
            provider::MODE_COEXIST,
            $this->create_router(['mode' => provider::MODE_COEXIST])->get_mode(),
        );
        // An unrecognised value must not silently disable full router mode.
        $this->assertEquals(
            provider::MODE_FULL,
            $this->create_router(['mode' => 'nonsense'])->get_mode(),
        );
    }

    /**
     * Test that a second instance stands down instead of competing with the first.
     */
    public function test_second_instance_takes_itself_out_of_the_running(): void {
        $first = $this->create_router(['defaulttarget' => 42]);
        $second = $this->create_router(['defaulttarget' => 42]);

        // The form refuses a second instance, but CLI and upgrade scripts do not go
        // through the form, so the rest of the plugin must not assume there is one.
        $this->assertTrue($first->is_primary_instance());
        $this->assertFalse($second->is_primary_instance());
        $this->assertTrue($first->is_provider_configured());
        $this->assertFalse($second->is_provider_configured());
    }

    /**
     * Test that the first router may be created but a second is refused.
     */
    public function test_form_refuses_a_second_instance(): void {
        $this->assertTrue(hook_listener::validate_single_instance(['name' => 'first']));

        $this->create_router();
        $errors = hook_listener::validate_single_instance(['name' => 'second']);

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('name', $errors);
    }

    /**
     * Test that editing an existing instance is never blocked.
     */
    public function test_editing_an_existing_instance_is_allowed(): void {
        $router = $this->create_router();

        // A site that already has two routers still has to be able to edit them,
        // otherwise the delete guidance would be unreachable.
        $this->assertTrue(
            hook_listener::validate_single_instance(['id' => $router->id, 'name' => 'edited']),
        );
    }
}
