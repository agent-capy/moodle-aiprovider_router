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

use aiprovider_router\target_settings;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Confirms which configuration field of each delegation target holds its key.
 *
 * Offered as a choice between the fields the instance actually has, rather than as free
 * text. A field name typed with a typo would be accepted, stored, and then quietly
 * substitute nothing: the request would go out charged to the site while the person who
 * brought a key believed they were paying for it.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok_targets_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $targets = $this->_customdata['targets'] ?? [];

        foreach ($targets as $targetid => $target) {
            $options = ['' => get_string('byok:nokey', 'aiprovider_router')];
            foreach (array_keys($target->config ?? []) as $name) {
                $options[$name] = $name;
            }

            $field = 'keyfield_' . (int) $targetid;
            $mode = 'byokmode_' . (int) $targetid;
            $group = [
                $mform->createElement('select', $field, '', $options),
                $mform->createElement('select', $mode, '', self::get_mode_options()),
            ];
            $mform->addGroup($group, 'target_' . (int) $targetid, format_string($target->name), ' ', false);
            $mform->setType($field, PARAM_ALPHANUMEXT);
            $mform->setType($mode, PARAM_ALPHA);
        }

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        foreach (array_keys($this->_customdata['targets'] ?? []) as $targetid) {
            $field = (string) ($data['keyfield_' . (int) $targetid] ?? '');
            $mode = (string) ($data['byokmode_' . (int) $targetid] ?? '');
            if ($mode === target_settings::MODE_ONLY && $field === target_settings::NO_KEY) {
                // Reachable by nobody: the site's own key is refused and a brought key
                // has nowhere to go. Said here rather than resolved quietly later.
                $errors['target_' . (int) $targetid] = get_string(
                    'byok:error:onlywithoutfield',
                    'aiprovider_router',
                );
            }
        }

        return $errors;
    }

    /**
     * What a site can allow at one provider.
     *
     * @return string[] Labels keyed by mode.
     */
    public static function get_mode_options(): array {
        $options = [];
        foreach (target_settings::get_modes() as $mode) {
            $options[$mode] = get_string('byok:mode:' . $mode, 'aiprovider_router');
        }

        return $options;
    }
}
