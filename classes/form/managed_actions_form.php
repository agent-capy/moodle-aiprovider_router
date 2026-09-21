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

namespace aiprovider_router\form;

use aiprovider_router\managed_policy;
use aiprovider_router\provider;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Chooses which actions Moodle must bring to the router.
 *
 * One checkbox per action the router declares. An action the router does not declare
 * is not offered, because placing it here would refuse every request for it and give
 * nothing back.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class managed_actions_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'static',
            'intro',
            '',
            get_string('managed:intro', 'aiprovider_router'),
        );

        $managed = managed_policy::managed_actions();
        foreach (provider::get_action_list() as $action) {
            $class = ltrim($action, '\\');
            $field = self::field_name($class);

            $mform->addElement('advcheckbox', $field, $action::get_name(), $action::get_description());
            $mform->setType($field, PARAM_BOOL);
            $mform->setDefault($field, in_array($class, $managed, true) ? 1 : 0);
        }

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    /**
     * The form field standing for an action.
     *
     * A class name cannot be a form field name, so the basename is used. Two actions
     * sharing a basename would collide, and core resolves processors by basename too,
     * so a site where that happened would have larger problems than this form.
     *
     * @param string $actionclass The action class.
     * @return string The field name.
     */
    public static function field_name(string $actionclass): string {
        return 'managed_' . $actionclass::get_basename();
    }
}
