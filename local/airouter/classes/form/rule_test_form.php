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

namespace local_airouter\form;

use local_airouter\provider;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Describes a request so that the rules can be tried against it.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_test_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $actions = [];
        foreach (provider::get_action_list() as $class) {
            $actions[ltrim($class, '\\')] = $class::get_name();
        }
        $mform->addElement('select', 'actionclass', get_string('ruletest:action', 'local_airouter'), $actions);

        $mform->addElement(
            'course',
            'courseid',
            get_string('ruletest:course', 'local_airouter'),
            ['multiple' => false, 'includefrontpage' => false],
        );
        $mform->addHelpButton('courseid', 'ruletest:course', 'local_airouter');

        $mform->addElement('text', 'username', get_string('ruletest:user', 'local_airouter'), ['size' => 40]);
        $mform->setType('username', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('username', 'ruletest:user', 'local_airouter');

        $placements = ['' => get_string('ruletest:placement:none', 'local_airouter')];
        foreach (\core_component::get_plugin_list('aiplacement') as $name => $unused) {
            $placements['aiplacement_' . $name] = get_string('pluginname', 'aiplacement_' . $name);
        }
        $mform->addElement(
            'select',
            'placement',
            get_string('ruletest:placement', 'local_airouter'),
            $placements,
        );
        $mform->addHelpButton('placement', 'ruletest:placement', 'local_airouter');

        $mform->addElement('textarea', 'prompt', get_string('ruletest:prompt', 'local_airouter'), [
            'rows' => 5,
            'cols' => 60,
        ]);
        $mform->setType('prompt', PARAM_RAW);
        $mform->addHelpButton('prompt', 'ruletest:prompt', 'local_airouter');

        $this->add_action_buttons(false, get_string('ruletest:run', 'local_airouter'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $username = trim((string) ($data['username'] ?? ''));
        if ($username !== '' && !self::find_user($username)) {
            $errors['username'] = get_string('ruletest:error:nosuchuser', 'local_airouter');
        }

        return $errors;
    }

    /**
     * Look a user up by username or email.
     *
     * @param string $identifier What the administrator typed.
     * @return \stdClass|null The user, or null when there is no such account.
     */
    public static function find_user(string $identifier): ?\stdClass {
        global $DB;

        $user = $DB->get_record('user', ['username' => $identifier, 'deleted' => 0]);
        if (!$user) {
            $user = $DB->get_record('user', ['email' => $identifier, 'deleted' => 0]);
        }

        return $user ?: null;
    }
}
