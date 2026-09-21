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
 * The contract between this plugin and the connector Moodle sees.
 *
 * The plugin is in two halves for a reason outside its control: Moodle decides what a
 * provider is by the name of the plugin it came from. That makes the join between the
 * halves easy to break silently. Adding an action here and forgetting the matching
 * class over there does not fail until a request for that action is made, and then it
 * fails as a class that does not exist, far from the change that caused it.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class connector_test extends \advanced_testcase {
    public function test_the_connector_is_the_class_this_plugin_names(): void {
        $this->assertTrue(class_exists(provider::INSTANCE_CLASS));
        $this->assertTrue(is_subclass_of(provider::INSTANCE_CLASS, provider::class));

        $reflection = new \ReflectionClass(provider::INSTANCE_CLASS);
        $this->assertFalse($reflection->isAbstract(), 'Moodle has to be able to instantiate it.');
    }

    public function test_the_connector_belongs_to_a_plugin_moodle_treats_as_a_provider(): void {
        // Core tests the plugin name to decide whether a provider's actions can be
        // enabled per instance. A name it does not recognise is handled as a
        // placement instead, silently, and the instance configuration stops counting.
        $this->assertStringStartsWith('aiprovider_', provider::INSTANCE_PLUGIN);
        $this->assertSame(
            provider::INSTANCE_PLUGIN,
            \core\component::get_component_from_classname(provider::INSTANCE_CLASS),
        );
    }

    public function test_every_action_this_plugin_carries_has_a_processor_on_the_other_side(): void {
        // Core builds the processor class name from the provider's own namespace, so
        // each action needs one in the connector. Without this test the omission is
        // found by a person making a request, not by the suite.
        foreach (provider::get_action_list() as $action) {
            $basename = $action::get_basename();
            $connector = 'aiprovider_router\\process_' . $basename;
            $here = __NAMESPACE__ . '\\process_' . $basename;

            $this->assertTrue(class_exists($here), "Missing here: {$here}");
            $this->assertTrue(class_exists($connector), "Missing in the connector: {$connector}");
            $this->assertTrue(is_subclass_of($connector, $here), "{$connector} must extend {$here}");
        }
    }
}
