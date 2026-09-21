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

use local_airouter\key;
use local_airouter\key_repository;
use core\check\result;

/**
 * Checks that the keys people have brought can still be read.
 *
 * Keys are encrypted with the site key, which lives in a file under the data directory
 * rather than in the database. A site restored from a database backup without that file
 * has every brought key intact and unreadable, and nothing says so until somebody tries
 * to use one and is told their request failed. This is where it gets said instead.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byokkeys extends base {
    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/local/airouter/byok.php'),
            get_string('byok:heading', 'local_airouter'),
        );
    }

    #[\Override]
    protected function check_router(): result {
        global $DB;

        $total = $DB->count_records(key::TABLE);
        if ($total === 0) {
            // Nothing has been brought, so there is nothing that could be unreadable.
            return new result(result::NA, get_string('check:byokkeys:nokeys', 'local_airouter'));
        }

        $unreadable = (new key_repository($DB))->count_unreadable();
        if ($unreadable === 0) {
            return new result(
                result::OK,
                get_string('check:byokkeys:ok', 'local_airouter', $total),
            );
        }

        // Not a warning. Every request these keys were meant to pay for is failing, and
        // the only fix is a file the administrator may still have a copy of somewhere.
        return new result(
            result::ERROR,
            get_string('check:byokkeys:unreadable', 'local_airouter', [
                'unreadable' => $unreadable,
                'total' => $total,
            ]),
            get_string('check:byokkeys:unreadable_details', 'local_airouter'),
        );
    }
}
