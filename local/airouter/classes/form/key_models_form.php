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

use local_airouter\model_catalog;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The models a key's owner asks for, action by action.
 *
 * Offered the way the provider's own settings page offers them: the models it lists,
 * and a name to type where it lets one be typed. Where nothing can be chosen, the
 * site's model is said and nothing else.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_models_form extends \moodleform {
    /** @var string The choice that means a name is typed rather than picked. */
    public const CUSTOM = '__custom';

    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'action', 'models');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'keyid');
        $mform->setType('keyid', PARAM_INT);
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        foreach ($this->_customdata['rows'] ?? [] as $index => $row) {
            $select = 'model_' . $index;
            $site = $row['site'] ?? null;
            if (empty($row['choosable'])) {
                $mform->addElement(
                    'static',
                    $select,
                    $row['label'],
                    $site === null
                        ? get_string('keys:models:unknown:short', 'local_airouter')
                        : get_string('keys:models:fixed', 'local_airouter', s($site)),
                );
                continue;
            }

            $options = ['' => get_string('keys:models:usesite', 'local_airouter', $site)];
            foreach ($row['options'] as $name => $label) {
                $options[$name] = $label === $name ? $name : $label . ' (' . $name . ')';
            }
            $options[self::CUSTOM] = get_string('keys:models:custom', 'local_airouter');
            $mform->addElement('select', $select, $row['label'], $options);
            $mform->setType($select, PARAM_RAW);

            $custom = 'custom_' . $index;
            $mform->addElement('text', $custom, get_string('keys:models:customname', 'local_airouter'), ['size' => 40]);
            $mform->setType($custom, PARAM_TEXT);
            $mform->hideIf($custom, $select, 'neq', self::CUSTOM);
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        foreach ($this->_customdata['rows'] ?? [] as $index => $row) {
            if (empty($row['choosable'])) {
                continue;
            }
            $choice = (string) ($data['model_' . $index] ?? '');
            if ($choice === self::CUSTOM && model_catalog::clean((string) ($data['custom_' . $index] ?? '')) === null) {
                $errors['custom_' . $index] = get_string('keys:models:error:name', 'local_airouter');
            } else if ($choice !== '' && $choice !== self::CUSTOM && !isset($row['options'][$choice])) {
                $errors['model_' . $index] = get_string('keys:models:error:name', 'local_airouter');
            }
        }

        return $errors;
    }

    /**
     * The models chosen, keyed by action class; an empty name means the site's.
     *
     * @param \stdClass $data The submitted data.
     * @param array[] $rows The rows the form was built with.
     * @return string[] Model names keyed by action class.
     */
    public static function read_models(\stdClass $data, array $rows): array {
        $models = [];
        foreach ($rows as $index => $row) {
            if (empty($row['choosable'])) {
                continue;
            }
            $choice = (string) ($data->{'model_' . $index} ?? '');
            $models[$row['action']] = $choice === self::CUSTOM
                ? (string) model_catalog::clean((string) ($data->{'custom_' . $index} ?? ''))
                : $choice;
        }

        return $models;
    }

    /**
     * The form values that show what a key asks for now.
     *
     * @param array[] $rows The rows the form is built with.
     * @param string[] $models What the key asks for, keyed by action class.
     * @return array Values keyed by element name.
     */
    public static function to_form_data(array $rows, array $models): array {
        $values = [];
        foreach ($rows as $index => $row) {
            $chosen = $models[$row['action']] ?? '';
            if ($chosen === '' || isset($row['options'][$chosen])) {
                $values['model_' . $index] = $chosen;
            } else {
                $values['model_' . $index] = self::CUSTOM;
                $values['custom_' . $index] = $chosen;
            }
        }

        return $values;
    }
}
