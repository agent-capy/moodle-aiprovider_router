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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Takes a key from the person bringing it.
 *
 * The field is a password field that can be unmasked while it is being typed, because a
 * key is long, opaque and pasted, and a mistake in one is invisible afterwards: once it
 * is stored, nothing shows it again.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $targets = $this->_customdata['targets'] ?? [];

        $mform->addElement(
            'select',
            'targetid',
            get_string('keys:target', 'local_airouter'),
            ['' => get_string('choosedots')] + $targets,
        );
        $mform->setType('targetid', PARAM_INT);
        $mform->addHelpButton('targetid', 'keys:target', 'local_airouter');

        $mform->addElement('passwordunmask', 'secret', get_string('keys:secret', 'local_airouter'));
        $mform->setType('secret', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('secret', 'keys:secret', 'local_airouter');

        $this->add_action_buttons(false, get_string('keys:save', 'local_airouter'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if ((int) ($data['targetid'] ?? 0) <= 0) {
            $errors['targetid'] = get_string('key:error:notarget', 'local_airouter');
        }
        if (trim((string) ($data['secret'] ?? '')) === '') {
            $errors['secret'] = get_string('key:error:empty', 'local_airouter');
        }

        return $errors;
    }
}
