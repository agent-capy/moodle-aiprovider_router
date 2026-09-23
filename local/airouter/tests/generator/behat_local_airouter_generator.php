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

/**
 * Behat generator for local_airouter.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_airouter_generator extends behat_generator_base {
    #[\Override]
    protected function get_creatable_entities(): array {
        return [
            'target settings' => [
                'singular' => 'target setting',
                'datagenerator' => 'target_setting',
                'required' => ['target', 'keyfield'],
                'switchids' => ['target' => 'targetid'],
            ],
            'keys' => [
                'singular' => 'key',
                'datagenerator' => 'key',
                'required' => ['target', 'secret'],
                'switchids' => ['user' => 'userid', 'course' => 'courseid', 'target' => 'targetid'],
            ],
            'key replacements' => [
                'singular' => 'key replacement',
                'datagenerator' => 'key_replacement',
                'required' => ['target', 'secret', 'account'],
                'switchids' => ['user' => 'userid', 'course' => 'courseid', 'target' => 'targetid'],
            ],
        ];
    }

    /**
     * The id of a provider instance, by the name it was created with.
     *
     * @param string $name The instance name.
     * @return int The id.
     */
    protected function get_target_id(string $name): int {
        global $DB;

        $id = $DB->get_field('ai_providers', 'id', ['name' => $name]);
        if (!$id) {
            throw new Exception('There is no AI provider instance named "' . $name . '"');
        }

        return (int) $id;
    }
}
