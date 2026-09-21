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

namespace local_airouter\eligibility;

/**
 * A custom profile field holds one of the listed values.
 *
 * For sites where the answer to "who may bring their own key" is not a role but something
 * the institution already records: a staff category, a department, a flag set when
 * somebody has signed an agreement about paying for their own use.
 *
 * @package    local_airouter
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
        $mform->addHelpButton('eligibilityfield', 'eligibility:profilefield', 'local_airouter');

        $mform->addElement(
            'text',
            'eligibilityvalues',
            get_string('eligibility:profilefield:values', 'local_airouter'),
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
        return get_string('eligibility:profilefield:described', 'local_airouter', (object) [
            'field' => s((string) ($this->config['field'] ?? '')),
            'values' => s(implode(', ', (array) ($this->config['values'] ?? []))),
        ]);
    }

    /**
     * The custom profile fields a policy may name.
     *
     * A field somebody can fill in about themselves is marked as such, because a
     * policy resting on one is a policy people admit themselves to. That is
     * sometimes exactly what is wanted -- a box saying "I understand what this
     * costs" is a self declaration on purpose -- so the choice is not taken away.
     * It is only said out loud, at the moment it is being made.
     *
     * @return string[] Field names keyed by short name.
     */
    public static function get_field_options(): array {
        global $DB;

        $selfset = self::get_self_settable_fields();
        $options = [];
        foreach ($DB->get_records('user_info_field', null, 'name ASC', 'id, shortname, name') as $field) {
            $name = format_string($field->name);
            if (isset($selfset[$field->shortname])) {
                $name = get_string('eligibility:profilefield:selfset', 'local_airouter', $name);
            }
            $options[$field->shortname] = $name;
        }

        return $options;
    }

    /**
     * Profile fields the person they describe can put a value into.
     *
     * Two separate routes, and the second is easy to miss:
     *
     * - An unlocked field that the person can see is editable on their own profile,
     *   which is what profile_field_base::is_editable() and edit_field_set_locked()
     *   between them decide.
     * - A field marked for the signup form is filled in by whoever is registering.
     *   profile_signup_fields() calls edit_field(), which does not call
     *   edit_field_set_locked(), so a locked field is still typed in freely there.
     *   This only matters where the site lets people register themselves.
     *
     * @return string[] Why each one, keyed by short name. Empty where none.
     */
    public static function get_self_settable_fields(): array {
        global $CFG, $DB;

        $selfregistration = !empty($CFG->registerauth);
        $reasons = [];
        $fields = $DB->get_records('user_info_field', null, 'name ASC', 'id, shortname, name, visible, locked, signup');
        foreach ($fields as $field) {
            if ((int) $field->visible !== 0 && (int) $field->locked === 0) {
                $reasons[$field->shortname] = 'ownprofile';
            } else if ($selfregistration && (int) $field->signup === 1) {
                $reasons[$field->shortname] = 'signup';
            }
        }

        return $reasons;
    }

    /**
     * Whether this condition rests on a field its subject can fill in.
     *
     * @return bool True when the person decides their own answer.
     */
    public function is_self_declared(): bool {
        $field = trim((string) ($this->config['field'] ?? ''));

        return $field !== '' && isset(self::get_self_settable_fields()[$field]);
    }
}
