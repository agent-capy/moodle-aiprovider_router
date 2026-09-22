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

namespace local_airouter\task;

use local_airouter\record\summariser;

/**
 * Adds the day's finished requests and attempts into the summary, closes what was
 * left open, and purges what has been counted and is past keeping.
 *
 * Allowed to fail: everything it does is applied once or not at all, so a run that
 * stops half way costs nothing but a later catch-up. Nothing is purged that has not
 * been counted.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summarise_records extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:summariserecords', 'local_airouter');
    }

    #[\Override]
    public function execute(): void {
        global $DB;

        $result = (new summariser($DB))->run(time());
        if ($result === false) {
            mtrace(get_string('task:summariserecords:locked', 'local_airouter'));

            return;
        }
        mtrace(get_string('task:summariserecords:done', 'local_airouter', (object) $result));
    }
}
