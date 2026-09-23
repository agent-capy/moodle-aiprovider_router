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
use local_airouter\target_resolver;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Records what a provider charges for a model, from a given date.
 *
 * Rates are entered the way providers publish them, per million tokens, so that an
 * administrator can copy a published price list without doing arithmetic first.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class price_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement(
            'select',
            'provider',
            get_string('price:provider', 'local_airouter'),
            ['' => get_string('choosedots')] + target_resolver::get_provider_options(),
        );
        $mform->setType('provider', PARAM_COMPONENT);
        $mform->addHelpButton('provider', 'price:provider', 'local_airouter');

        $mform->addElement('text', 'model', get_string('price:model', 'local_airouter'), ['size' => 40]);
        $mform->setType('model', PARAM_TEXT);
        $mform->addHelpButton('model', 'price:model', 'local_airouter');

        // The provider's currency, not the site's: there is no site currency. Typed
        // rather than chosen from a list, since the codes are short and a list of every
        // currency in the world would hide the two or three a site uses.
        $mform->addElement('text', 'currency', get_string('price:currency', 'local_airouter'), ['size' => 8]);
        $mform->setType('currency', PARAM_ALPHA);
        $mform->addHelpButton('currency', 'price:currency', 'local_airouter');

        foreach (['promptrate', 'completionrate'] as $field) {
            $mform->addElement(
                'text',
                $field,
                get_string('price:' . $field, 'local_airouter'),
                ['size' => 12],
            );
            $mform->setType($field, PARAM_RAW_TRIMMED);
        }
        $mform->addHelpButton('promptrate', 'price:tokenrate', 'local_airouter');

        $mform->addElement(
            'text',
            'imagerate',
            get_string('price:imagerate', 'local_airouter'),
            ['size' => 12],
        );
        $mform->setType('imagerate', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('imagerate', 'price:imagerate', 'local_airouter');

        $mform->addElement('date_time_selector', 'timefrom', get_string('price:timefrom', 'local_airouter'));
        $mform->addHelpButton('timefrom', 'price:timefrom', 'local_airouter');

        $this->add_action_buttons();
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['provider'] ?? '')) === '') {
            $errors['provider'] = get_string('price:error:noprovider', 'local_airouter');
        }
        $currency = price::normalise_currency((string) ($data['currency'] ?? ''));
        if ($currency === '' || strlen($currency) > price::CURRENCY_LENGTH) {
            $errors['currency'] = get_string('price:error:nocurrency', 'local_airouter');
        }

        $given = 0;
        foreach (['promptrate', 'completionrate', 'imagerate'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            if (!is_numeric($value) || (float) $value < 0) {
                $errors[$field] = get_string('price:error:negative', 'local_airouter');
                continue;
            }
            $given++;
        }
        if ($given === 0 && !$errors) {
            // A rate that says nothing costs anything would silently record every
            // request as free, which is a claim, not an absence of one.
            $errors['promptrate'] = get_string('price:error:norate', 'local_airouter');
        }

        return $errors;
    }

    /**
     * Turn a submitted rate into what the database stores.
     *
     * @param mixed $value The submitted value.
     * @return float|null The rate, or null when the field was left empty.
     */
    public static function read_rate($value): ?float {
        $value = trim((string) $value);

        return $value === '' ? null : (float) $value;
    }
}
