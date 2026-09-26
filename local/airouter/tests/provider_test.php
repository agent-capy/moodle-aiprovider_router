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
 * Tests for the router object core runs an action against.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(adapter_provider::class)]
final class provider_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_a_router_with_nowhere_to_delegate_is_not_configured(): void {
        // Otherwise it would take every request placed under it only to fail them.
        $this->assertFalse(adapter_provider::create()->is_provider_configured());
    }

    public function test_a_default_target_is_somewhere_to_delegate(): void {
        set_config('defaulttarget', 7, 'local_airouter');

        $router = adapter_provider::create();

        $this->assertTrue($router->is_provider_configured());
        $this->assertSame(7, $router->get_default_target_id());
    }

    public function test_rules_are_somewhere_to_delegate_too(): void {
        // A site that routes everything by rule and declines the rest has no use for a
        // default target.
        set_config('rulecount', 1, 'local_airouter');

        $this->assertTrue(adapter_provider::create()->is_provider_configured());
    }

    public function test_what_no_rule_claimed_goes_to_the_default_target_unless_the_site_declines_it(): void {
        $this->assertSame(provider::NOMATCH_DELEGATE, adapter_provider::create()->get_nomatch_behaviour());

        set_config('nomatch', provider::NOMATCH_DECLINE, 'local_airouter');

        $this->assertSame(provider::NOMATCH_DECLINE, adapter_provider::create()->get_nomatch_behaviour());
    }

    public function test_the_router_is_not_stored_anywhere(): void {
        // It is built for one request from the plugin's settings: no row, no id.
        $this->assertNull(adapter_provider::create()->id);
    }
}
