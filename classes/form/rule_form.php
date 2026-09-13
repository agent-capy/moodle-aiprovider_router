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

use aiprovider_router\condition\registry;
use aiprovider_router\rule;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Creates and edits one routing rule.
 *
 * A fixed form with one row per condition type, rather than a builder that can nest
 * conditions arbitrarily. A rule an administrator can read at a glance is worth more
 * than one that can express anything, and every condition left blank is simply one the
 * rule does not use.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('rule:name', 'aiprovider_router'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('rule:error:noname', 'aiprovider_router'), 'required', null, 'client');

        $targets = $this->_customdata['targets'] ?? [];
        $mform->addElement(
            'select',
            'targetid',
            get_string('rule:target', 'aiprovider_router'),
            ['' => get_string('choosedots')] + $targets,
        );
        $mform->setType('targetid', PARAM_INT);
        $mform->addHelpButton('targetid', 'rule:target', 'aiprovider_router');

        $mform->addElement('advcheckbox', 'enabled', get_string('rule:enabled', 'aiprovider_router'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('header', 'conditions', get_string('rule:conditions', 'aiprovider_router'));
        $mform->setExpanded('conditions');
        // Said once, above the lot: leaving every one of these blank is allowed, and
        // means the rule takes every request that reaches it.
        $mform->addElement(
            'static',
            'conditionsintro',
            '',
            get_string('rule:conditions_intro', 'aiprovider_router'),
        );
        foreach (registry::get_types() as $type) {
            $class = '\\aiprovider_router\\condition\\' . $type;
            $class::add_to_form($mform);
        }

        $mform->addElement('header', 'window', get_string('rule:window', 'aiprovider_router'));
        $mform->addElement(
            'date_time_selector',
            'timestart',
            get_string('rule:timestart', 'aiprovider_router'),
            ['optional' => true],
        );
        $mform->addElement(
            'date_time_selector',
            'timeend',
            get_string('rule:timeend', 'aiprovider_router'),
            ['optional' => true],
        );
        $mform->addHelpButton('timestart', 'rule:window', 'aiprovider_router');

        $this->add_action_buttons();
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        // The persistent validates the same things when it is saved. Repeating them here
        // is what puts the message next to the field the administrator has to change.
        $candidate = new rule();
        $candidate->set('name', (string) ($data['name'] ?? ''));
        $candidate->set('targetid', (int) ($data['targetid'] ?? 0));
        $candidate->set('timestart', (int) ($data['timestart'] ?? 0));
        $candidate->set('timeend', (int) ($data['timeend'] ?? 0));

        // A valid rule validates to true rather than to an empty list of problems.
        $problems = $candidate->validate();
        if (is_array($problems)) {
            foreach ($problems as $property => $message) {
                $errors[$property] = (string) $message;
            }
        }

        return $errors;
    }

    /**
     * The condition set the administrator filled in.
     *
     * Conditions of a type this version does not know are carried over untouched. A rule
     * written on a newer version would otherwise lose part of itself the first time
     * somebody opened it here, which is a quiet way to widen a rule.
     *
     * @param \stdClass $data The submitted data.
     * @param array[] $existing The rule's stored conditions, keyed by type.
     * @return array[] Configuration keyed by condition type, holding only the conditions used.
     */
    public static function read_conditions(\stdClass $data, array $existing = []): array {
        $conditions = [];
        foreach ($existing as $type => $config) {
            if (!registry::is_known((string) $type)) {
                $conditions[$type] = $config;
            }
        }
        foreach (registry::get_types() as $type) {
            $class = '\\aiprovider_router\\condition\\' . $type;
            $config = $class::read_from_form($data);
            if ($config !== null) {
                $conditions[$type] = $config;
            }
        }

        return $conditions;
    }

    /**
     * Turn a stored condition set back into form values.
     *
     * @param array[] $conditions Stored configuration keyed by condition type.
     * @return array Values keyed by form element name.
     */
    public static function conditions_to_form_data(array $conditions): array {
        $data = [];
        foreach ($conditions as $type => $config) {
            if (!registry::is_known((string) $type)) {
                // A condition from a newer version has no control to show it in. It is
                // carried through the save by read_conditions() rather than displayed.
                continue;
            }
            $class = '\\aiprovider_router\\condition\\' . $type;
            $data += $class::to_form_data($config);
        }

        return $data;
    }
}
