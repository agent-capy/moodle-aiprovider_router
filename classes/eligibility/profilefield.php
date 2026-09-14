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

namespace aiprovider_router\eligibility;

/**
 * A custom profile field holds one of the listed values.
 *
 * For sites where the answer to "who may bring their own key" is not a role but something
 * the institution already records: a staff category, a department, a flag set when
 * somebody has signed an agreement about paying for their own use.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profilefield extends base {
    #[\Override]
    public function is_met(int $userid): bool {
        global $DB;

        $field = trim((string) ($this->config['field'] ?? ''));
        $values = array_filter(array_map('trim', (array) ($this->config['values'] ?? [])), 'strlen');
        if ($field === '' || !$values || $userid <= 0) {
            return false;
        }

        $held = $DB->get_field_sql(
            'SELECT d.data
               FROM {user_info_data} d
               JOIN {user_info_field} f ON f.id = d.fieldid
              WHERE d.userid = :userid AND f.shortname = :field',
            ['userid' => $userid, 'field' => $field],
        );
        if ($held === false) {
            return false;
        }

        // Compared without regard to case, because the value is usually typed by a person
        // into a text field rather than chosen from a list.
        return in_array(\core_text::strtolower(trim((string) $held)), array_map(
            fn($value) => \core_text::strtolower($value),
            $values,
        ), true);
    }

    #[\Override]
    public static function add_to_form(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'select',
            'eligibilityfield',
            self::get_label(),
            ['' => get_string('choosedots')] + self::get_field_options(),
        );
        $mform->setType('eligibilityfield', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('eligibilityfield', 'eligibility:profilefield', 'aiprovider_router');

        $mform->addElement(
            'text',
            'eligibilityvalues',
            get_string('eligibility:profilefield:values', 'aiprovider_router'),
            ['size' => 40],
        );
        $mform->setType('eligibilityvalues', PARAM_TEXT);
    }

    #[\Override]
    public static function get_form_elements(): array {
        return ['eligibilityfield', 'eligibilityvalues'];
    }

    #[\Override]
    public static function read_from_form(\stdClass $data): ?array {
        $field = trim((string) ($data->eligibilityfield ?? ''));
        $values = array_values(array_filter(
            array_map('trim', explode(',', (string) ($data->eligibilityvalues ?? ''))),
            'strlen',
        ));
        if ($field === '' || !$values) {
            return null;
        }

        return ['field' => $field, 'values' => $values];
    }

    #[\Override]
    public static function to_form_data(array $config): array {
        return [
            'eligibilityfield' => (string) ($config['field'] ?? ''),
            'eligibilityvalues' => implode(', ', (array) ($config['values'] ?? [])),
        ];
    }

    #[\Override]
    public function get_description(): string {
        return get_string('eligibility:profilefield:described', 'aiprovider_router', (object) [
            'field' => s((string) ($this->config['field'] ?? '')),
            'values' => s(implode(', ', (array) ($this->config['values'] ?? []))),
        ]);
    }

    /**
     * The custom profile fields a policy may name.
     *
     * @return string[] Field names keyed by short name.
     */
    public static function get_field_options(): array {
        global $DB;

        $options = [];
        foreach ($DB->get_records('user_info_field', null, 'name ASC', 'id, shortname, name') as $field) {
            $options[$field->shortname] = format_string($field->name);
        }

        return $options;
    }
}
