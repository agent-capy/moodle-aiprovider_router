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

namespace local_airouter;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/dml/mariadb_native_moodle_database.php');
require_once(__DIR__ . '/fault_database.php');

/**
 * The MariaDB driver, failing where a test says.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fault_mariadb_database extends \mariadb_native_moodle_database {
    use fault_database;

    /**
     * Whether the connection is in a transaction, as the database server sees it.
     *
     * @return bool True when it is.
     */
    public function in_transaction_physically(): bool {
        return (bool) $this->get_field_sql('SELECT @@in_transaction');
    }
}
