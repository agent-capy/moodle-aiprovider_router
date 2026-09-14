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

use aiprovider_router\eligibility\registry;
use aiprovider_router\eligibility_policy;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Says who may bring their own key.
 *
 * The three answers are spelled out rather than inferred from an empty condition list.
 * A site that names no condition means "nobody yet", not "everybody", and a form where
 * those two look the same is a form that gives the site's AI away by accident.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok_policy_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $options = [];
        foreach (eligibility_policy::get_access_options() as $access) {
            $options[$access] = get_string('eligibility:access:' . $access, 'aiprovider_router');
        }
        $mform->addElement('select', 'access', get_string('eligibility:access', 'aiprovider_router'), $options);
        $mform->setDefault('access', eligibility_policy::ACCESS_NOBODY);
        $mform->addHelpButton('access', 'eligibility:access', 'aiprovider_router');

        $matches = [];
        foreach (eligibility_policy::get_match_options() as $match) {
            $matches[$match] = get_string('eligibility:match:' . $match, 'aiprovider_router');
        }
        $mform->addElement('select', 'match', get_string('eligibility:match', 'aiprovider_router'), $matches);
        $mform->setDefault('match', eligibility_policy::MATCH_ANY);
        $mform->addHelpButton('match', 'eligibility:match', 'aiprovider_router');
        $mform->hideIf('match', 'access', 'neq', eligibility_policy::ACCESS_CONDITIONS);

        foreach (registry::get_types() as $type) {
            $class = 'aiprovider_router\\eligibility\\' . $type;
            $class::add_to_form($mform);
            foreach ($class::get_form_elements() as $element) {
                $mform->hideIf($element, 'access', 'neq', eligibility_policy::ACCESS_CONDITIONS);
            }
        }

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    /**
     * The conditions as the administrator has just described them.
     *
     * @param \stdClass $data The submitted data.
     * @return array Configuration keyed by condition type.
     */
    public static function read_conditions(\stdClass $data): array {
        $conditions = [];
        foreach (registry::get_types() as $type) {
            $class = 'aiprovider_router\\eligibility\\' . $type;
            $config = $class::read_from_form($data);
            if ($config !== null) {
                $conditions[$type] = $config;
            }
        }

        return $conditions;
    }

    /**
     * Turn a stored policy back into form values.
     *
     * @param eligibility_policy $policy The policy.
     * @return array Values keyed by element name.
     */
    public static function to_form_data(eligibility_policy $policy): array {
        $values = ['access' => $policy->get_access(), 'match' => $policy->get_match()];
        $stored = $policy->get_stored_conditions();
        foreach (registry::get_types() as $type) {
            $class = 'aiprovider_router\\eligibility\\' . $type;
            $values += $class::to_form_data(is_array($stored[$type] ?? null) ? $stored[$type] : []);
        }

        return $values;
    }
}
