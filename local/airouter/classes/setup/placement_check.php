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

namespace local_airouter\setup;

/**
 * Whether one person, in one place, would be offered the button for an action.
 *
 * Moodle decides this, not the router: Moodle's own placements show a button when the
 * placement is switched on, the person holds the placement's capability for that
 * action where they are, some provider is set up for the action, and the placement has
 * the action switched on. The same four questions are asked here about the person the
 * administrator chose, rather than about the administrator looking at the page.
 *
 * Only Moodle's own placements are known to work this way. Whether a person has
 * accepted the AI policy is asked later, when they use the button, and is not part of
 * this.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class placement_check {
    /**
     * Why the button would not be shown, or nothing when it would.
     *
     * @param string $placement The placement component, such as aiplacement_editor.
     * @param string $actionclass The action.
     * @param \context $context Where the person would be.
     * @param int $userid The person.
     * @return string[] Reasons, in words; empty when the button would be shown.
     */
    public static function reasons(string $placement, string $actionclass, \context $context, int $userid): array {
        [$type, $name] = \core_component::normalize_component($placement);
        $plugininfo = \core_plugin_manager::resolve_plugininfo_class($type);
        if (!$plugininfo::is_plugin_enabled($name)) {
            return [get_string('ruletest:button:placementoff', 'local_airouter')];
        }

        $capability = "{$type}/{$name}:" . $actionclass::get_basename();
        if (!get_capability_info($capability)) {
            return [get_string('ruletest:button:notoffered', 'local_airouter')];
        }

        $manager = \core\di::get(\core_ai\manager::class);
        $reasons = [];
        if (!has_capability($capability, $context, $userid)) {
            $reasons[] = get_string('ruletest:button:nocapability', 'local_airouter', $capability);
        }
        if (!$manager->is_action_available($actionclass)) {
            $reasons[] = get_string('ruletest:button:noprovider', 'local_airouter');
        }
        if (!$manager->is_action_enabled($placement, $actionclass)) {
            $reasons[] = get_string('ruletest:button:actionoff', 'local_airouter');
        }

        return $reasons;
    }
}
