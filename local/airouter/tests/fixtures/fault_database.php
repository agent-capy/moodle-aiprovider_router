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

/**
 * A database driver that fails where a test says, with a real SQL error where it can.
 *
 * Mixed into a subclass of the driver the site uses, on a connection of its own. The
 * failures are armed by point and happen the given number of times:
 *
 *   lock                the SQL asking for the record lock
 *   begin               beginning a transaction
 *   request             the update that closes a request
 *   commit_before       committing, before the COMMIT is sent
 *   commit_after        committing, after the COMMIT has been answered
 *   rollback            core's own rollback
 *   rollback_statement  a ROLLBACK statement sent through the DML layer
 *
 * A real SQL error, rather than an exception thrown in PHP, makes the driver's own
 * handling of one run: on PostgreSQL that is the savepoint it keeps inside a transaction.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait fault_database {
    /** @var int[] Failures still to happen, by point, with how many times. */
    public array $faults = [];

    /** @var string[] The failures that happened, in order. */
    public array $happened = [];

    /**
     * Whether a failure is due here, counting it off if so.
     *
     * @param string $point The point.
     * @return bool True when it fails here.
     */
    private function fails_at(string $point): bool {
        if (empty($this->faults[$point])) {
            return false;
        }
        $this->faults[$point]--;
        $this->happened[] = $point;

        return true;
    }

    /**
     * Make the database report an error.
     */
    private function fail_for_real(): void {
        $this->get_field_sql('SELECT nothing FROM {local_airouter_no_such_table}');
    }

    /**
     * Begin a transaction, or fail before the database is asked.
     */
    #[\Override]
    protected function begin_transaction() {
        if ($this->fails_at('begin')) {
            $this->fail_for_real();
        }
        parent::begin_transaction();
    }

    /**
     * Commit, or fail before the COMMIT is sent or after it has been answered.
     */
    #[\Override]
    protected function commit_transaction() {
        if ($this->fails_at('commit_before')) {
            throw new \dml_write_exception('Injected: the COMMIT was not sent');
        }
        parent::commit_transaction();
        if ($this->fails_at('commit_after')) {
            throw new \dml_write_exception('Injected: the COMMIT was answered and the answer was lost');
        }
    }

    /**
     * Roll back, or fail before the database is asked.
     */
    #[\Override]
    protected function rollback_transaction() {
        if ($this->fails_at('rollback')) {
            $this->fail_for_real();
        }
        parent::rollback_transaction();
    }

    /**
     * Start a query, or fail first at the queries a test named.
     *
     * @param string $sql The query.
     * @param array|null $params Its parameters.
     * @param int $type Its kind, one of the SQL_QUERY_ constants.
     * @param mixed $extrainfo Driver specific extra information.
     */
    #[\Override]
    protected function query_start($sql, ?array $params, $type, $extrainfo = null) {
        if (preg_match('/GET_LOCK|pg_try_advisory_lock/i', $sql) && $this->fails_at('lock')) {
            $this->fail_for_real();
        }
        if (preg_match('/UPDATE\s+\S*local_airouter_request\b/i', $sql) && $this->fails_at('request')) {
            $this->fail_for_real();
        }
        if (trim($sql) === 'ROLLBACK' && $this->fails_at('rollback_statement')) {
            $this->fail_for_real();
        }
        parent::query_start($sql, $params, $type, $extrainfo);
    }
}
