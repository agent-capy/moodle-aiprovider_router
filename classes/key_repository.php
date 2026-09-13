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

namespace aiprovider_router;

/**
 * The only place a brought key is written, read back or destroyed.
 *
 * Plaintext keys exist in exactly two places in this plugin: the form a person types one
 * into, and the moment one is handed to a provider. Everything in between goes through
 * here, so that there is one answer to where keys are encrypted, one answer to what is
 * kept beside them and one place to look when asking what happens to them.
 *
 * @package    aiprovider_router
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
                'aiprovider_router: could not decrypt key ' . (int) $key->get('id'),
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
     * @param key $key The key that was tested.
     * @param string $status One of the key verification results.
     * @param int|null $when The time of the test, or null for now.
     */
    public function record_verification(key $key, string $status, ?int $when = null): void {
        $key->set('verifystatus', $status);
        $key->set('timeverified', $when ?? time());
        $key->save();
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
     */
    public function forget_registrar(int $userid): void {
        $this->db->set_field_select(
            key::TABLE,
            'usermodified',
            0,
            'usermodified = :userid AND scope = :scope',
            ['userid' => $userid, 'scope' => key::SCOPE_COURSE],
        );
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
