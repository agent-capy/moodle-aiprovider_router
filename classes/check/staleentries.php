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

namespace aiprovider_router\check;

use core\check\result;

/**
 * Checks the provider order for ids of instances that no longer exist.
 *
 * Deleting an instance can leave its id behind, and core's move up and move down both
 * work on positions within that list, so the leftovers make reordering behave in ways
 * the admin did not ask for.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class staleentries extends base {
    #[\Override]
    protected function check_router(): result {
        $stale = $this->inspector->get_stale_entries();
        if (!$stale) {
            return new result(result::OK, get_string('check:staleentries:ok', 'aiprovider_router'));
        }

        return new result(
            result::WARNING,
            get_string('check:staleentries:found', 'aiprovider_router', count($stale)),
            get_string('check:staleentries:found_details', 'aiprovider_router', implode(', ', $stale)),
        );
    }
}
