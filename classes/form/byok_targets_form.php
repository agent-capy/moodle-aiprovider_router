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

            $element = 'keyfield_' . (int) $targetid;
            $mform->addElement('select', $element, format_string($target->name), $options);
            $mform->setType($element, PARAM_ALPHANUMEXT);
        }

        $this->add_action_buttons(false, get_string('savechanges'));
    }
}
