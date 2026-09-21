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

use local_airouter\provider;
use core\check\result;

/**
 * Checks that the router is the first provider core will try.
 *
 * What "first" is worth depends on the operating mode. In router only mode the whole
 * design rests on it, so anything else is an error. In coexist mode the admin may well
 * have put another provider in front on purpose, so this only reports what it sees.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class routerfirst extends base {
    #[\Override]
    protected function depends_on_provider_order(): bool {
        return true;
    }

    #[\Override]
    protected function check_router(): result {
        if ($this->inspector->is_router_first()) {
            return new result(result::OK, get_string('check:routerfirst:ok', 'local_airouter'));
        }

        $router = $this->inspector->get_primary_router();
        $position = $this->inspector->get_router_position();
        $details = get_string('check:routerfirst:details', 'local_airouter', [
            'position' => $position === null ? '-' : $position + 1,
            'total' => count($this->inspector->get_sorted_instances()),
        ]);

        if ($router->get_mode() === provider::MODE_COEXIST) {
            return new result(
                result::INFO,
                get_string('check:routerfirst:coexist', 'local_airouter'),
                $details,
            );
        }

        return new result(
            result::ERROR,
            get_string('check:routerfirst:notfirst', 'local_airouter'),
            $details,
        );
    }
}
