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

use local_airouter\record\ledger;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Takes the limit somebody wants to put on the key they brought.
 *
 * Its own form, rather than a field on the one that takes the key, for two reasons.
 * Changing a limit would otherwise mean typing the key in again, which nobody has to
 * hand; and a key is registered once while a limit is the kind of thing that gets
 * adjusted.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_cap_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'keyid');
        $mform->setType('keyid', PARAM_INT);
        // The wallet of the key the limit is being set on, so that a limit meant for
        // one account does not land on a key that has moved to another meanwhile.
        $mform->addElement('hidden', 'walletid');
        $mform->setType('walletid', PARAM_INT);
        $mform->addElement('hidden', 'action', 'cap');
        $mform->setType('action', PARAM_ALPHA);
        if (isset($this->_customdata['courseid'])) {
            $mform->addElement('hidden', 'courseid');
            $mform->setType('courseid', PARAM_INT);
        }

        $group = [
            $mform->createElement('text', 'capamount', '', ['size' => 10]),
            $mform->createElement(
                'static',
                'capcurrency',
                '',
                (string) ($this->_customdata['currency'] ?? ''),
            ),
            $mform->createElement('select', 'capperiod', '', [
                ledger::PERIOD_MONTH => get_string('keys:cap:month', 'local_airouter'),
                ledger::PERIOD_ROLLING => get_string('keys:cap:rolling', 'local_airouter'),
            ]),
            $mform->createElement('text', 'capdays', '', ['size' => 4]),
            $mform->createElement('static', 'capunit', '', get_string('keys:cap:days', 'local_airouter')),
        ];
        $mform->addGroup($group, 'capgroup', get_string('keys:cap', 'local_airouter'), ' ', false);
        $mform->setType('capamount', PARAM_RAW_TRIMMED);
        $mform->setType('capdays', PARAM_INT);
        $mform->setDefault('capperiod', ledger::PERIOD_MONTH);
        $mform->setDefault('capdays', 30);
        $mform->hideIf('capdays', 'capperiod', 'eq', ledger::PERIOD_MONTH);
        $mform->hideIf('capunit', 'capperiod', 'eq', ledger::PERIOD_MONTH);
        $mform->addHelpButton('capgroup', 'keys:cap', 'local_airouter');

        $this->add_action_buttons(true, get_string('keys:cap:save', 'local_airouter'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $amount = trim((string) ($data['capamount'] ?? ''));
        if ($amount !== '' && (!is_numeric($amount) || (float) $amount <= 0)) {
            // Empty is how somebody says they want no limit. Anything else has to be a
            // number, because a limit that did not save would read as no limit and the
            // only sign of it would be the bill.
            $errors['capgroup'] = get_string('keys:cap:error', 'local_airouter');
        }

        return $errors;
    }

    /**
     * The limit as the database holds it.
     *
     * @param \stdClass $data The submitted data.
     * @return float|null The limit, or null when there is to be none.
     */
    public static function read_amount(\stdClass $data): ?float {
        $amount = trim((string) ($data->capamount ?? ''));

        return $amount === '' || !is_numeric($amount) || (float) $amount <= 0 ? null : (float) $amount;
    }
}
