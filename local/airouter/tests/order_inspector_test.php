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
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture_action.php');
require_once(__DIR__ . '/fixtures/fixture_text_provider.php');
require_once(__DIR__ . '/fixtures/fixture_other_provider.php');
require_once(__DIR__ . '/fixtures/fixture_unconfigured_provider.php');

/**
 * Tests for the provider order inspector.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(order_inspector::class)]
final class order_inspector_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
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
    protected function router(int $id, string $mode = provider::MODE_FULL): provider {
        return new \aiprovider_router\provider(
            enabled: true,
            name: "Router {$id}",
            config: json_encode(['mode' => $mode, 'defaulttarget' => 99]),
            id: $id,
        );
    }

    /**
     * A non-router instance.
     *
     * @param int $id The instance id.
     * @param string $class The fixture provider class to build.
     * @param bool $enabled Whether the instance is enabled.
     * @param bool $actionenabled Whether its action is enabled.
     * @return ai_provider The instance.
     */
    protected function other(
        int $id,
        string $class = fixture_text_provider::class,
        bool $enabled = true,
        bool $actionenabled = true,
    ): ai_provider {
        $actionconfig = $actionenabled
            ? ''
            : json_encode([generate_text::class => ['enabled' => false, 'settings' => []]]);

        return new $class(
            enabled: $enabled,
            name: "Provider {$id}",
            config: '{}',
            actionconfig: $actionconfig,
            id: $id,
        );
    }

    public function test_a_site_without_a_router_reports_nothing_to_fix(): void {
        $inspector = $this->inspector([9 => $this->other(9)], ',9');

        $this->assertNull($inspector->get_primary_router());
        $this->assertNull($inspector->get_router_position());
        $this->assertFalse($inspector->is_router_listed());
        $this->assertSame([], $inspector->get_routers());
    }

    public function test_the_router_is_found_at_the_front(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',5,9');

        $this->assertSame(0, $inspector->get_router_position());
        $this->assertTrue($inspector->is_router_first());
        $this->assertTrue($inspector->is_router_listed());
    }

    public function test_the_router_is_found_behind_another_provider(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');

        $this->assertSame(1, $inspector->get_router_position());
        $this->assertFalse($inspector->is_router_first());
    }

    public function test_a_router_missing_from_the_order_is_tried_last(): void {
        // Core appends instances it cannot find in the order, so the router is reached
        // only after every other provider has refused the request.
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9');

        $this->assertFalse($inspector->is_router_listed());
        $this->assertSame(1, $inspector->get_router_position());
    }

    public function test_entries_for_deleted_instances_are_reported(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,77,5');

        $this->assertSame(['77'], $inspector->get_stale_entries());
    }

    public function test_the_empty_first_entry_is_not_treated_as_a_leftover(): void {
        $inspector = $this->inspector([5 => $this->router(5)], ',5');

        $this->assertSame([], $inspector->get_stale_entries());
    }

    public function test_promoting_keeps_the_empty_entry_and_the_other_providers_order(): void {
        $inspector = $this->inspector(
            [5 => $this->router(5), 9 => $this->other(9), 3 => $this->other(3)],
            ',9,3,5',
        );

        $this->assertSame(',5,9,3', $inspector->build_promoted_order());
    }

    public function test_promoting_gives_the_router_an_empty_entry_to_hide_behind(): void {
        // Index 0 is the position core mishandles when a provider is enabled or disabled,
        // so promoting must not leave the router sitting on it.
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], '9,5');

        $this->assertSame(',5,9', $inspector->build_promoted_order());
    }

    public function test_promoting_adds_a_router_that_was_missing_from_the_order(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9');

        $this->assertSame(',5,9', $inspector->build_promoted_order());
    }

    public function test_promoting_leaves_leftover_entries_alone(): void {
        // Removing them is a separate decision the administrator confirms separately.
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,77,5');

        $this->assertSame(',5,9,77', $inspector->build_promoted_order());
    }

    public function test_cleaning_drops_leftovers_and_keeps_the_empty_entry(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,77,5');

        $this->assertSame(',9,5', $inspector->build_cleaned_order());
    }

    public function test_cleaning_changes_nothing_when_there_is_nothing_to_clean(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');

        $this->assertSame(',9,5', $inspector->build_cleaned_order());
    }

    public function test_a_provider_in_front_handling_the_same_action_is_reported(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');

        $intercepting = $inspector->get_intercepting_instances();
        $this->assertCount(1, $intercepting);
        $this->assertSame(9, (int) $intercepting[0]->id);
    }

    public function test_a_provider_behind_the_router_is_not_reported(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',5,9');

        $this->assertSame([], $inspector->get_intercepting_instances());
    }

    public function test_a_provider_in_front_handling_other_actions_is_not_reported(): void {
        $instances = [5 => $this->router(5), 9 => $this->other(9, fixture_other_provider::class)];

        $this->assertSame([], $this->inspector($instances, ',9,5')->get_intercepting_instances());
    }

    public function test_a_disabled_or_unconfigured_provider_in_front_is_not_reported(): void {
        $instances = [
            5 => $this->router(5),
            8 => $this->other(8, enabled: false),
            9 => $this->other(9, class: fixture_unconfigured_provider::class),
        ];

        $this->assertSame([], $this->inspector($instances, ',8,9,5')->get_intercepting_instances());
    }

    public function test_a_provider_with_the_action_switched_off_is_not_reported(): void {
        $instances = [5 => $this->router(5), 9 => $this->other(9, actionenabled: false)];

        $this->assertSame([], $this->inspector($instances, ',9,5')->get_intercepting_instances());
    }

    public function test_the_lowest_numbered_router_is_the_one_that_counts(): void {
        $instances = [7 => $this->router(7), 4 => $this->router(4)];
        $inspector = $this->inspector($instances, ',7,4');

        $this->assertSame([4, 7], array_keys($inspector->get_routers()));
        $this->assertSame(4, (int) $inspector->get_primary_router()->id);
    }
}
