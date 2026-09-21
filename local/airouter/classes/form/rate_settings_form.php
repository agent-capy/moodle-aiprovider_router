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

use local_airouter\price;
use local_airouter\price_book;
use local_airouter\token_estimator;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Sets the currency and the ratios the token estimate is worked out from.
 *
 * Neither of these changes where a request goes. Rules compare character counts, and
 * the estimate is shown as a guide when a threshold is being chosen, so getting these
 * wrong costs a misleading figure on a screen. The form says so, because a setting that
 * looks like it might reroute traffic will not be touched.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_settings_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'static',
            'intro',
            '',
            get_string('rates:settings_intro', 'local_airouter'),
        );

        $mform->addElement('text', 'currency', get_string('rates:currency', 'local_airouter'), ['size' => 8]);
        $mform->setType('currency', PARAM_ALPHA);
        $mform->setDefault('currency', price_book::DEFAULT_CURRENCY);
        $mform->addHelpButton('currency', 'rates:currency', 'local_airouter');

        $mform->addElement(
            'text',
            'tokenratiocjk',
            get_string('rates:tokenratiocjk', 'local_airouter'),
            ['size' => 8],
        );
        $mform->setType('tokenratiocjk', PARAM_FLOAT);
        $mform->setDefault('tokenratiocjk', token_estimator::DEFAULT_CJK_RATIO);
        $mform->addHelpButton('tokenratiocjk', 'rates:tokenratio', 'local_airouter');

        $mform->addElement(
            'text',
            'tokenratioother',
            get_string('rates:tokenratioother', 'local_airouter'),
            ['size' => 8],
        );
        $mform->setType('tokenratioother', PARAM_FLOAT);
        $mform->setDefault('tokenratioother', token_estimator::DEFAULT_OTHER_RATIO);
        $mform->addHelpButton('tokenratioother', 'rates:tokenratio', 'local_airouter');

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        foreach (['tokenratiocjk', 'tokenratioother'] as $field) {
            if ((float) ($data[$field] ?? 0) <= 0) {
                // Zero would divide by nothing and a negative would produce a negative
                // estimate, so neither is worth accepting even for a figure nothing
                // routes on.
                $errors[$field] = get_string('rates:error:ratio', 'local_airouter');
            }
        }
        if (trim((string) ($data['currency'] ?? '')) === '') {
            $errors['currency'] = get_string('rates:error:currency', 'local_airouter');
        }

        return $errors;
    }
}
