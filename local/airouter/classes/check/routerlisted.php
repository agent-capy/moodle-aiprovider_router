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
 * Checks that the router appears in the site's provider order at all.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class routerlisted extends base {
    #[\Override]
    protected function check_router(): result {
        if ($this->inspector->is_router_listed()) {
            return new result(result::OK, get_string('check:routerlisted:ok', 'local_airouter'));
        }

        return new result(
            result::ERROR,
            get_string('check:routerlisted:missing', 'local_airouter'),
            get_string('check:routerlisted:missing_details', 'local_airouter'),
        );
    }
}
