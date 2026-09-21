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

use local_airouter\usage_aggregator;

/**
 * Summarises finished days of the router's log and purges detail past its retention.
 *
 * Unlike the logging itself, which must never stand in the way of an AI request, this
 * runs on its own and is allowed to fail: cron records the failure and tries again, and
 * nothing is purged that has not been summarised, so a run that stops half way costs
 * nothing but a later catch-up.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summarise_usage extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:summariseusage', 'local_airouter');
    }

    #[\Override]
    public function execute(): void {
        global $DB;

        $result = (new usage_aggregator($DB))->run(time());
        mtrace(get_string('task:summariseusage:done', 'local_airouter', (object) $result));
    }
}
