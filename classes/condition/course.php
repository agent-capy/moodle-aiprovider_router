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

namespace aiprovider_router\condition;

use aiprovider_router\evaluation_context;

/**
 * Restricts a rule to requests raised in one of the chosen courses.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course extends set_base {
    #[\Override]
    protected static function get_config_key(): string {
        return 'courseids';
    }

    #[\Override]
    protected function get_actual(evaluation_context $context): array {
        $courseid = $context->get_courseid();

        // A request from outside any course meets no course condition. Site wide
        // placements do raise them, and letting those fall into a course rule would
        // route them on evidence that is not there.
        return $courseid === null ? [] : [$courseid];
    }
    #[\Override]
    public static function add_to_form(\MoodleQuickForm $mform): void {
        // Core's course selector searches as you type. A site can have more courses than
        // it is reasonable to put in a list, which is why this one condition does not use
        // the plain multiple choice element the others share.
        $mform->addElement(
            'course',
            self::get_type(),
            self::get_label(),
            ['multiple' => true, 'includefrontpage' => false],
        );
        $mform->addHelpButton(self::get_type(), 'condition:' . self::get_type(), 'aiprovider_router');
    }

    #[\Override]
    protected static function get_labels(array $values): array {
        global $DB;

        if (!$values) {
            return [];
        }
        $labels = [];
        $records = $DB->get_records_list('course', 'id', $values, '', 'id, shortname');
        foreach ($records as $record) {
            $labels[(int) $record->id] = format_string($record->shortname);
        }

        return $labels;
    }
}
