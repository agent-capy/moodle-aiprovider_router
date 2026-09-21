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

namespace local_airouter\check;

use core\check\result;

/**
 * Checks that the site has only one router instance.
 *
 * The settings form refuses a second one, but nothing stops CLI code, an upgrade step or
 * another plugin from creating instances directly, so the situation is reported rather
 * than assumed away. Removing the surplus is left to core's own delete button.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class singleinstance extends base {
    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']),
            get_string('check:singleinstance:manage', 'local_airouter'),
        );
    }

    #[\Override]
    protected function check_router(): result {
        $routers = $this->inspector->get_routers();
        if (count($routers) < 2) {
            return new result(result::OK, get_string('check:singleinstance:ok', 'local_airouter'));
        }

        $primaryid = (int) $this->inspector->get_primary_router()->id;
        $rows = [];
        foreach ($routers as $id => $instance) {
            $rows[] = get_string(
                $id === $primaryid ? 'order:instance:keep' : 'order:instance:remove',
                'local_airouter',
                ['id' => $id, 'name' => s($instance->name)],
            );
        }

        return new result(
            result::ERROR,
            get_string('check:singleinstance:duplicates', 'local_airouter', count($routers)),
            \html_writer::alist($rows),
        );
    }
}
