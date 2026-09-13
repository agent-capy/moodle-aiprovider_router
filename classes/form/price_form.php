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

use aiprovider_router\price;
use aiprovider_router\price_book;
use aiprovider_router\target_resolver;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Records what a provider charges for a model, from a given date.
 *
 * Rates are entered the way providers publish them, per million tokens, so that an
 * administrator can copy a published price list without doing arithmetic first.
 *
 * @package    aiprovider_router
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
            get_string('price:provider', 'aiprovider_router'),
            ['' => get_string('choosedots')] + self::get_provider_options(),
        );
        $mform->setType('provider', PARAM_COMPONENT);
        $mform->addHelpButton('provider', 'price:provider', 'aiprovider_router');

        $mform->addElement('text', 'model', get_string('price:model', 'aiprovider_router'), ['size' => 40]);
        $mform->setType('model', PARAM_TEXT);
        $mform->addHelpButton('model', 'price:model', 'aiprovider_router');

        $currency = price_book::get_currency();
        foreach (['promptrate', 'completionrate'] as $field) {
            $mform->addElement(
                'text',
                $field,
                get_string('price:' . $field, 'aiprovider_router', $currency),
                ['size' => 12],
            );
            $mform->setType($field, PARAM_RAW_TRIMMED);
        }
        $mform->addHelpButton('promptrate', 'price:tokenrate', 'aiprovider_router');

        $mform->addElement(
            'text',
            'imagerate',
            get_string('price:imagerate', 'aiprovider_router', $currency),
            ['size' => 12],
        );
        $mform->setType('imagerate', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('imagerate', 'price:imagerate', 'aiprovider_router');

        $mform->addElement('date_time_selector', 'timefrom', get_string('price:timefrom', 'aiprovider_router'));
        $mform->addHelpButton('timefrom', 'price:timefrom', 'aiprovider_router');

        $this->add_action_buttons();
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['provider'] ?? '')) === '') {
            $errors['provider'] = get_string('price:error:noprovider', 'aiprovider_router');
        }

        $given = 0;
        foreach (['promptrate', 'completionrate', 'imagerate'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            if (!is_numeric($value) || (float) $value < 0) {
                $errors[$field] = get_string('price:error:negative', 'aiprovider_router');
                continue;
            }
            $given++;
        }
        if ($given === 0 && !$errors) {
            // A rate that says nothing costs anything would silently record every
            // request as free, which is a claim, not an absence of one.
            $errors['promptrate'] = get_string('price:error:norate', 'aiprovider_router');
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

    /**
     * The providers a rate can be entered for.
     *
     * Rates belong to a provider plugin rather than to an instance, so the list is of
     * the plugins behind the instances this site can delegate to.
     *
     * @return string[] Plugin names keyed by component.
     */
    protected static function get_provider_options(): array {
        $options = [];
        foreach (\core\di::get(\core_ai\manager::class)->get_provider_instances() as $instance) {
            if ($instance instanceof \aiprovider_router\provider) {
                continue;
            }
            $component = $instance->get_name();
            $options[$component] = get_string_manager()->string_exists('pluginname', $component)
                ? get_string('pluginname', $component)
                : $component;
        }
        ksort($options);

        return $options;
    }
}
