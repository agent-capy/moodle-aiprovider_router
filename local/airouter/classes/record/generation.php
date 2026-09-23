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

namespace local_airouter\record;

/**
 * A number that changes whenever the record is changed underneath a reader.
 *
 * A reader answers from two or three statements, and a database gives each statement
 * its own view, so a change committed between them would be in none of them and in
 * all of them at once. Every operation that rewrites finished facts wholesale --
 * the summariser folding the detail into the summary, a correction pricing a
 * provider's record again -- moves this number inside its own transaction, and a
 * reader compares it before and after: the same number, the same record.
 *
 * Read straight from the database each time, not through get_config(), because the
 * point is to see what another process has just committed.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generation {
    /** @var string The setting the number is kept in. */
    public const SETTING = 'recordgeneration';

    /**
     * The number now.
     *
     * @param \moodle_database $db The database to read, which a test may make a second connection.
     * @return int The number, zero until anything has moved it.
     */
    public static function get(\moodle_database $db): int {
        $value = $db->get_field('config_plugins', 'value', ['plugin' => 'local_airouter', 'name' => self::SETTING]);

        return $value === false || $value === null ? 0 : (int) $value;
    }

    /**
     * Note that the record has changed.
     *
     * Called inside the transaction that changes it, so that the number and the
     * change become visible together. Written with set_config() so that the row
     * exists and the config caches are told.
     */
    public static function bump(): void {
        global $DB;

        set_config(self::SETTING, self::get($DB) + 1, 'local_airouter');
    }
}
