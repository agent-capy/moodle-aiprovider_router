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
 * The only place a brought key is written, read back or destroyed.
 *
 * Plaintext keys exist in exactly two places in this plugin: the form a person types one
 * into, and the moment one is handed to a provider. Everything in between goes through
 * here, so that there is one answer to where keys are encrypted, one answer to what is
 * kept beside them and one place to look when asking what happens to them.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_repository {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to work on.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
    ) {
    }

    /**
     * Store a key, replacing whatever was there for the same subject and target.
     *
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course the key belongs to.
     * @param int $targetid The delegation target it is for.
     * @param string $secret The key as the owner typed it.
     * @return key The stored key.
     */
    public function save(string $scope, int $scopeid, int $targetid, string $secret): key {
        $secret = trim($secret);
        $record = $this->find($scope, $scopeid, $targetid) ?? new key();
        $record->set('scope', $scope);
        $record->set('scopeid', $scopeid);
        $record->set('targetid', $targetid);
        $record->set('secret', \core\encryption::encrypt($secret));
        $record->set('hint', key::hint_of($secret));
        // A replaced key has not been tested, whatever the one before it managed.
        $record->set('timeverified', 0);
        $record->set('verifystatus', null);
        $record->save();

        return $record;
    }

    /**
     * Record the limit an owner has put on their own key.
     *
     * Kept apart from save(), so that changing a limit does not mean typing the key in
     * again, and so that replacing a key does not quietly clear the limit. Replacing a
     * key is not a new month: the provider goes on billing the same account.
     *
     * @param key $record The key.
     * @param float|null $amount The limit, or null for none.
     * @param string $period One of the ledger's periods.
     * @param int $days How many days a rolling period counts.
     * @return key The saved key.
     */
    public function set_cap(key $record, ?float $amount, string $period, int $days): key {
        $record->set('capamount', $amount !== null && $amount > 0 ? $amount : null);
        $record->set('capperiod', $period === spend_ledger::PERIOD_ROLLING
            ? spend_ledger::PERIOD_ROLLING
            : spend_ledger::PERIOD_MONTH);
        $record->set('capdays', max(1, $days));
        $record->save();

        return $record;
    }

    /**
     * The key for one subject and target, if there is one.
     *
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course.
     * @param int $targetid The delegation target.
     * @return key|null The key, or null when none is registered.
     */
    public function find(string $scope, int $scopeid, int $targetid): ?key {
        $record = $this->db->get_record(key::TABLE, [
            'scope' => $scope,
            'scopeid' => $scopeid,
            'targetid' => $targetid,
        ]);

        return $record ? new key(0, $record) : null;
    }

    /**
     * One key by its id.
     *
     * @param int $id The key id.
     * @return key|null The key, or null when it has gone.
     */
    public function get(int $id): ?key {
        $record = $this->db->get_record(key::TABLE, ['id' => $id]);

        return $record ? new key(0, $record) : null;
    }

    /**
     * One key by its id, but only if it belongs to the subject asking for it.
     *
     * Screens act on a key id taken from the request, so the subject is part of the
     * lookup rather than checked afterwards: a key id from somewhere else simply does not
     * resolve here.
     *
     * @param int $id The key id.
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course.
     * @return key|null The key, or null when it is not theirs.
     */
    public function get_for(int $id, string $scope, int $scopeid): ?key {
        if ($id <= 0) {
            return null;
        }
        $record = $this->db->get_record(key::TABLE, [
            'id' => $id,
            'scope' => $scope,
            'scopeid' => $scopeid,
        ]);

        return $record ? new key(0, $record) : null;
    }

    /**
     * Every key registered by or for one subject.
     *
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course.
     * @return key[] The keys, keyed by target id.
     */
    public function get_all(string $scope, int $scopeid): array {
        $keys = [];
        $records = $this->db->get_records(key::TABLE, ['scope' => $scope, 'scopeid' => $scopeid], 'targetid ASC');
        foreach ($records as $record) {
            $keys[(int) $record->targetid] = new key(0, $record);
        }

        return $keys;
    }

    /**
     * Read a key back, for the one moment it is needed.
     *
     * Returning null means the row is there and its contents cannot be recovered, which
     * is a fault in the site rather than an absent key, and the two lead to opposite
     * behaviour: an absent key means a rule does not match and the next one is tried,
     * while a key that cannot be decrypted stops the request and says so. Callers hold
     * the row already, so null is unambiguous here.
     *
     * @param key $key The key to read.
     * @return string|null The key, or null when it cannot be decrypted.
     */
    public function reveal(key $key): ?string {
        $plain = $this->decrypt($key);
        if ($plain === null) {
            // The message can carry details of the site's encryption setup, so nothing
            // beyond the fact of it is said anywhere a user could see.
            debugging(
                'local_airouter: could not decrypt key ' . (int) $key->get('id'),
                DEBUG_NORMAL,
            );
        }

        return $plain;
    }

    /**
     * Decrypt a key without saying anything about it.
     *
     * Counting how many keys a site can no longer read means trying to read all of them,
     * and a site in that state would otherwise fill its log with one line per key every
     * time the site status report was opened.
     *
     * @param key $key The key to read.
     * @return string|null The key, or null when it cannot be decrypted.
     */
    protected function decrypt(key $key): ?string {
        $secret = (string) $key->get('secret');
        if ($secret === '') {
            return null;
        }
        try {
            $plain = \core\encryption::decrypt($secret);
        } catch (\Throwable $e) {
            return null;
        }

        return $plain === '' ? null : $plain;
    }

    /**
     * Record what happened when the key was last tested.
     *
     * Written as a statement of its own rather than by saving the key, because of what
     * happens in between. Testing a key puts a request to somebody else's server, and
     * the object being held while that happens was read before it started. Moodle's
     * persistent saves every column it holds, so saving it afterwards would write back
     * the secret and the spending limit as they were when the test began -- undoing a
     * key somebody rotated, or a limit somebody lowered, in the meantime. The window is
     * as long as the provider takes to answer, which is the longest window in the
     * plugin.
     *
     * The stored secret is part of the condition, so a verdict is never attached to a
     * key other than the one it was about. A key replaced during the test simply has no
     * verdict recorded, which is correct: nobody has tested it.
     *
     * @param key $key The key that was tested.
     * @param string $status One of the key verification results.
     * @param int|null $when The time of the test, or null for now.
     */
    public function record_verification(key $key, string $status, ?int $when = null): void {
        $this->db->execute(
            'UPDATE {' . key::TABLE . '}
                SET verifystatus = :status, timeverified = :when
              WHERE id = :id AND secret = :secret',
            [
                'status' => $status,
                'when' => $when ?? time(),
                'id' => (int) $key->get('id'),
                'secret' => (string) $key->get('secret'),
            ],
        );
    }

    /**
     * Remove one key.
     *
     * @param int $id The key id.
     */
    public function delete(int $id): void {
        $this->db->delete_records(key::TABLE, ['id' => $id]);
    }

    /**
     * Remove every key one person registered for themselves.
     *
     * Their course keys are left alone. A course key belongs to the course rather than
     * to the teacher who happened to enter it, and removing it would stop the AI for
     * everybody in that course because one person left.
     *
     * @param int $userid The user.
     * @return int How many keys were removed.
     */
    public function delete_for_user(int $userid): int {
        $conditions = ['scope' => key::SCOPE_USER, 'scopeid' => $userid];
        $count = $this->db->count_records(key::TABLE, $conditions);
        $this->db->delete_records(key::TABLE, $conditions);

        return $count;
    }

    /**
     * Forget who registered a course key, keeping the key itself.
     *
     * This is what a deletion request does to a course key: the only personal data in
     * the row is who entered it, so that is what goes.
     *
     * @param int $userid The user to forget.
     * @param int|null $courseid Only the key of this course, or null for all of theirs.
     *                           A deletion request approves particular contexts, and a
     *                           course that was not among them is not part of it.
     */
    public function forget_registrar(int $userid, ?int $courseid = null): void {
        $where = 'usermodified = :userid AND scope = :scope';
        $params = ['userid' => $userid, 'scope' => key::SCOPE_COURSE];
        if ($courseid !== null) {
            $where .= ' AND scopeid = :courseid';
            $params['courseid'] = $courseid;
        }

        $this->db->set_field_select(key::TABLE, 'usermodified', 0, $where, $params);
    }

    /**
     * Remove every key registered for a course.
     *
     * @param int $courseid The course.
     */
    public function delete_for_course(int $courseid): void {
        $this->db->delete_records(key::TABLE, ['scope' => key::SCOPE_COURSE, 'scopeid' => $courseid]);
    }

    /**
     * How many keys the site holds that cannot be decrypted.
     *
     * Reported by the Check API. A site that has restored its database without the
     * encryption key file has every brought key in this state, and nothing else says so
     * until somebody tries to use one.
     *
     * @return int The number of unreadable keys.
     */
    public function count_unreadable(): int {
        $unreadable = 0;
        $recordset = $this->db->get_recordset(key::TABLE);
        foreach ($recordset as $record) {
            if ($this->decrypt(new key(0, $record)) === null) {
                $unreadable++;
            }
        }
        $recordset->close();

        return $unreadable;
    }
}
