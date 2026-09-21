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

use local_airouter\budget_notifier;

/**
 * Tells people that a budget, or a limit on their own key, has been reached.
 *
 * On its own schedule rather than on the path of a request, deliberately. Sending mail
 * is slow and fails in ways that have nothing to do with AI, and a request that had to
 * wait for a mail server before it could be answered would have inherited all of them.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notify_budgets extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:notifybudgets', 'local_airouter');
    }

    #[\Override]
    public function execute(): void {
        global $DB;

        $result = (new budget_notifier($DB))->run(time());
        mtrace(get_string('task:notifybudgets:done', 'local_airouter', (object) $result));
    }
}
