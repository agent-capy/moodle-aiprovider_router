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

use local_airouter\record\ledger;

/**
 * The only place a brought key is written, read back or destroyed.
 *
 * Plaintext keys exist in exactly two places in this plugin: the form a person types one
 * into, and the moment one is handed to a provider. Everything in between goes through
 * here, so that there is one answer to where keys are encrypted, one answer to what is
 * kept beside them and one place to look when asking what happens to them.
 *
 * Beside each key is its wallet: what its spending is counted against. A provider bills
 * an account, and the key is only the way in. Rotating a key within one account does not
 * start a new bill, while a key for another account does, and nothing in a key says
 * which, so the owner is asked when they replace one and the answer is kept as which
 * wallet the key belongs to. The one case nobody need be asked about is a key entered
 * again: a wallet keeps a keyed hash of every key it has held, so a key can be known as
 * one of the wallet's after the key itself has been replaced or removed, without any
 * key being kept. A key the wallets know goes back to its wallet whatever was answered.
 *
 * Every change to a key and its wallets is one transaction, and a key that is held is
 * written column by column: the object a screen read may be older than the row, and
 * what it did not mean to change it must not change.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_repository {
    /** @var string The table holding wallets. */
    public const WALLET_TABLE = 'local_airouter_wallet';

    /** @var string The table holding the hash of every key a wallet has held. */
    public const WALLET_KEY_TABLE = 'local_airouter_walletkey';

    /** @var string The setting holding the secret the key hashes are keyed with. */
    public const SECRET_SETTING = 'walletsecret';

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
     * Store a key for a subject and target.
     *
     * Where a key is held already, it is replaced within its wallet, which is what
     * rotating a key means; whether it should instead move to a new wallet is a
     * question for replace(), which asks. Where none is held, the key opens a wallet
     * of its own -- unless it is a key one of this subject's wallets here has held,
     * in which case it goes back to that wallet, or the owner has chosen an earlier
     * wallet to go on with.
     *
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course the key belongs to.
     * @param int $targetid The delegation target it is for.
     * @param string $secret The key as the owner typed it.
     * @param int|null $continue An earlier wallet of theirs to go on with, or null to
     *                           let the key decide. Only for a key not held already,
     *                           and not heeded for a key the wallets know.
     * @return key The stored key.
     */
    public function save(string $scope, int $scopeid, int $targetid, string $secret, ?int $continue = null): key {
        $secret = trim($secret);
        $transaction = $this->db->start_delegated_transaction();
        try {
            $record = $this->find($scope, $scopeid, $targetid);
            if ($record !== null) {
                if ($continue !== null) {
                    throw new \coding_exception('A key that is held has its wallet already; replace() decides whether it keeps it');
                }
                $this->write($record, $secret, $record->get_wallet());
            } else {
                $record = new key();
                $record->set('scope', $scope);
                $record->set('scopeid', $scopeid);
                $record->set('targetid', $targetid);
                // A key the wallets know is the same account, whatever else was chosen.
                $wallet = $this->find_wallet_of($scope, $scopeid, $targetid, $secret);
                if ($wallet === null && $continue !== null) {
                    $wallet = $this->get_released_wallet($continue, $scope, $scopeid, $targetid);
                }
                $walletid = $wallet === null
                    ? $this->open_wallet($scope, $scopeid, $targetid, $secret)
                    : (int) $wallet->id;
                $this->write($record, $secret, $walletid);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $record;
    }

    /**
     * Replace a key that is held, as its owner has said it should be.
     *
     * A key one of this subject's wallets here has held is that wallet's, and is not
     * asked about: it goes back to the wallet, its limit stays, and whatever was
     * answered is not heeded. Otherwise the owner has to have said whether the new
     * key is for the same account, and, where the key carries a limit, whether the
     * limit stays.
     *
     * @param key $record The key being replaced.
     * @param string $secret The new key as the owner typed it.
     * @param bool|null $sameaccount Whether the new key is for the same account as the
     *                               old one. Null only where the key is known.
     * @param bool|null $keepcap Whether the limit stays. Null where there is no limit,
     *                           or where the key is known.
     * @return key The stored key, which is the object given, updated.
     */
    public function replace(key $record, string $secret, ?bool $sameaccount, ?bool $keepcap = null): key {
        $secret = trim($secret);
        $scope = (string) $record->get('scope');
        $scopeid = (int) $record->get('scopeid');
        $targetid = (int) $record->get('targetid');
        $current = $record->get_wallet();
        $transaction = $this->db->start_delegated_transaction();
        try {
            $known = $this->is_same_secret($record, $secret)
                ? $current
                : $this->find_wallet_of($scope, $scopeid, $targetid, $secret)?->id;
            if ($known !== null) {
                $walletid = (int) $known;
                $keepcap ??= true;
            } else {
                if ($sameaccount === null) {
                    throw new \coding_exception('Replacing a key with another has to say whether it is for the same account');
                }
                if ($keepcap === null && $record->has_cap()) {
                    throw new \coding_exception('Replacing a key that carries a limit has to say whether the limit stays');
                }
                $walletid = $sameaccount ? $current : 0;
            }
            if ($walletid !== $current) {
                // Another account, or an earlier one: what the current wallet holds
                // is not this key's to count.
                $this->release_wallet($current, $record);
                if ($walletid === 0) {
                    $walletid = $this->open_wallet($scope, $scopeid, $targetid, $secret);
                }
            }
            $this->write($record, $secret, $walletid, $keepcap === false);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $record;
    }

    /**
     * Write a key, and let its wallet know it holds it.
     *
     * A key that is held is written column by column, and only the columns this is
     * about: the object was read some time ago, and a limit set on the key since
     * must not be put back to what it was.
     *
     * @param key $record The key, with its subject and target set.
     * @param string $secret The key as the owner typed it, trimmed.
     * @param int $walletid The wallet the key belongs to.
     * @param bool $dropcap Whether the limit goes with the old key.
     */
    protected function write(key $record, string $secret, int $walletid, bool $dropcap = false): void {
        global $USER;

        $hint = key::hint_of($secret);
        $values = [
            'walletid' => $walletid,
            'secret' => \core\encryption::encrypt($secret),
            'hint' => $hint,
            // A replaced key has not been tested, whatever the one before it managed.
            'timeverified' => 0,
            'verifystatus' => null,
        ];
        if ($dropcap) {
            $values['capamount'] = null;
        }
        foreach ($values as $property => $value) {
            $record->set($property, $value);
        }
        if ((int) $record->get('id') > 0) {
            $this->db->update_record(key::TABLE, (object) ($values + [
                'id' => (int) $record->get('id'),
                'usermodified' => (int) $USER->id,
                'timemodified' => time(),
            ]));
        } else {
            $record->create();
        }

        // The wallet holds this key now, and remembers it among the keys it has held.
        $this->db->update_record(self::WALLET_TABLE, (object) [
            'id' => $walletid,
            'hint' => $hint,
            'timereleased' => 0,
        ]);
        $this->remember($walletid, $this->hash_secret($secret));
    }

    /**
     * Record the limit an owner has put on their own key.
     *
     * Kept apart from save(), so that changing a limit does not mean typing the key in
     * again. Whether a limit survives the key being replaced is asked at that moment,
     * by replace().
     *
     * Written as the limit's columns alone, and only while the key is still in the
     * wallet it was read in. The key object came from a screen, and the key may have
     * been replaced since it was read: writing the object back would put the old
     * secret and the old wallet back with it, and a limit meant for one account
     * would land on another. A key that has moved is refused, and the owner is told.
     *
     * @param key $record The key, as it was read.
     * @param float|null $amount The limit, or null for none.
     * @param string $period One of the ledger's periods.
     * @param int $days How many days a rolling period counts.
     * @return bool True when the limit was recorded, false when the key had changed.
     */
    public function set_cap(key $record, ?float $amount, string $period, int $days): bool {
        global $USER;

        $amount = $amount !== null && $amount > 0 ? $amount : null;
        $period = $period === ledger::PERIOD_ROLLING ? ledger::PERIOD_ROLLING : ledger::PERIOD_MONTH;
        $days = max(1, $days);
        $this->db->execute(
            'UPDATE {' . key::TABLE . '}
                SET capamount = :amount, capperiod = :period, capdays = :days,
                    usermodified = :usermodified, timemodified = :timemodified
              WHERE id = :id AND walletid = :walletid',
            [
                'amount' => $amount,
                'period' => $period,
                'days' => $days,
                'usermodified' => (int) $USER->id,
                'timemodified' => time(),
                'id' => (int) $record->get('id'),
                'walletid' => $record->get_wallet(),
            ],
        );
        // Whether the statement reached the row: it did exactly when the key is still
        // in that wallet. A key moved in the instant since reads as refused, which
        // errs on the side of asking the owner to look again.
        $applied = $this->db->record_exists(key::TABLE, [
            'id' => (int) $record->get('id'),
            'walletid' => $record->get_wallet(),
        ]);
        if ($applied) {
            $record->set('capamount', $amount);
            $record->set('capperiod', $period);
            $record->set('capdays', $days);
        }

        return $applied;
    }

    /**
     * Whether a key somebody has typed is the one held.
     *
     * Compared in the clear rather than by hash, so that it holds for a key stored
     * before wallets kept hashes. A key that cannot be decrypted is not the same as
     * anything: nothing can be said about it.
     *
     * @param key $record The key held.
     * @param string $secret The key as typed.
     * @return bool True when they are the same.
     */
    public function is_same_secret(key $record, string $secret): bool {
        $plain = $this->decrypt($record);

        return $plain !== null && hash_equals($plain, trim($secret));
    }

    /**
     * The wallets this subject had at this target and no longer holds a key for.
     *
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course.
     * @param int $targetid The delegation target.
     * @return \stdClass[] Wallet rows, the most recently released first.
     */
    public function get_previous_wallets(string $scope, int $scopeid, int $targetid): array {
        return array_values($this->db->get_records_select(
            self::WALLET_TABLE,
            'scope = :scope AND scopeid = :scopeid AND targetid = :targetid AND timereleased > 0',
            ['scope' => $scope, 'scopeid' => $scopeid, 'targetid' => $targetid],
            'timereleased DESC, id DESC',
        ));
    }

    /**
     * Whether a key is one that a wallet of this subject's at this target has held.
     *
     * True exactly when save() or replace() would put the key back in that wallet
     * without asking: the wallet may be the one held now, or one released since.
     *
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course.
     * @param int $targetid The delegation target.
     * @param string $secret The key as typed.
     * @return bool True when the key is known here.
     */
    public function is_known_secret(string $scope, int $scopeid, int $targetid, string $secret): bool {
        return $this->find_wallet_of($scope, $scopeid, $targetid, trim($secret)) !== null;
    }

    /**
     * The wallet of this subject's at this target that has held this very key, if any.
     *
     * Held or released: a key renewed within a wallet is still that wallet's. A key
     * is only ever put into one wallet of a subject's at a target, since a known key
     * goes back to its wallet before anything else is considered; the newest is taken
     * all the same, so that the answer is one wallet whatever the rows hold.
     *
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course.
     * @param int $targetid The delegation target.
     * @param string $secret The key as typed, trimmed.
     * @return \stdClass|null The wallet row, or null when no wallet held this key.
     */
    protected function find_wallet_of(string $scope, int $scopeid, int $targetid, string $secret): ?\stdClass {
        $wallets = $this->db->get_records_sql(
            'SELECT w.*
               FROM {' . self::WALLET_TABLE . '} w
               JOIN {' . self::WALLET_KEY_TABLE . '} k ON k.walletid = w.id
              WHERE w.scope = :scope AND w.scopeid = :scopeid AND w.targetid = :targetid AND k.keyhash = :hash
           ORDER BY w.timereleased DESC, w.id DESC',
            [
                'scope' => $scope,
                'scopeid' => $scopeid,
                'targetid' => $targetid,
                'hash' => $this->hash_secret($secret),
            ],
        );

        return $wallets ? reset($wallets) : null;
    }

    /**
     * One released wallet of this subject's, by id.
     *
     * The subject is part of the lookup, as it is for a key: a wallet id from
     * somewhere else does not resolve here.
     *
     * @param int $walletid The wallet.
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course.
     * @param int $targetid The delegation target.
     * @return \stdClass The wallet row.
     */
    protected function get_released_wallet(int $walletid, string $scope, int $scopeid, int $targetid): \stdClass {
        foreach ($this->get_previous_wallets($scope, $scopeid, $targetid) as $wallet) {
            if ((int) $wallet->id === $walletid) {
                return $wallet;
            }
        }

        throw new \moodle_exception('invalidrecord', 'error', '', self::WALLET_TABLE);
    }

    /**
     * Open a wallet for a key.
     *
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course.
     * @param int $targetid The delegation target.
     * @param string $secret The key as typed, trimmed.
     * @return int The wallet id.
     */
    protected function open_wallet(string $scope, int $scopeid, int $targetid, string $secret): int {
        return $this->db->insert_record(self::WALLET_TABLE, (object) [
            'scope' => $scope,
            'scopeid' => $scopeid,
            'targetid' => $targetid,
            'hint' => key::hint_of($secret),
            'timecreated' => time(),
            'timereleased' => 0,
        ]);
    }

    /**
     * Let go of a wallet, keeping it so that it can be gone on with.
     *
     * The key it held is remembered on the way out. A wallet made for a key that was
     * registered before wallets kept hashes has no hash of it yet, and this is the
     * last moment the key is at hand to make one from; for any other key this
     * changes nothing.
     *
     * @param int $walletid The wallet.
     * @param key $record The key that held it, still readable.
     */
    protected function release_wallet(int $walletid, key $record): void {
        $plain = $this->decrypt($record);
        if ($plain !== null) {
            $this->remember($walletid, $this->hash_secret($plain));
        }
        $this->db->set_field(self::WALLET_TABLE, 'timereleased', time(), ['id' => $walletid]);
    }

    /**
     * Note that a wallet has held a key.
     *
     * @param int $walletid The wallet.
     * @param string $hash The key's hash.
     */
    protected function remember(int $walletid, string $hash): void {
        if ($this->db->record_exists(self::WALLET_KEY_TABLE, ['walletid' => $walletid, 'keyhash' => $hash])) {
            return;
        }
        $this->db->insert_record(self::WALLET_KEY_TABLE, (object) [
            'walletid' => $walletid,
            'keyhash' => $hash,
            'timecreated' => time(),
        ]);
    }

    /**
     * A keyed hash of a key, which is what a wallet remembers of it.
     *
     * Keyed with a secret of the site's, so that the hash says nothing on its own and
     * cannot be checked against a guess without it; and one way, so that the key
     * cannot be had back from it whatever else is had.
     *
     * @param string $secret The key.
     * @return string The hash, in hexadecimal.
     */
    public function hash_secret(string $secret): string {
        return hash_hmac('sha256', $secret, $this->hash_key());
    }

    /**
     * The secret the hashes are keyed with.
     *
     * Made at install time. Made here only for a site that somehow has none, and then
     * kept, since a hash is only good for comparing with hashes made under the same
     * secret.
     *
     * @return string The secret.
     */
    protected function hash_key(): string {
        $secret = (string) get_config('local_airouter', self::SECRET_SETTING);
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            set_config(self::SECRET_SETTING, $secret, 'local_airouter');
        }

        return $secret;
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
     * Its wallet stays, released, so that the same key registered again can go on
     * with it, and so that the owner can choose to go on with it under another key.
     * What was said about the key's limit goes with the key: it named the key.
     *
     * @param int $id The key id.
     */
    public function delete(int $id): void {
        $transaction = $this->db->start_delegated_transaction();
        try {
            $record = $this->get($id);
            if ($record !== null) {
                $this->release_wallet($record->get_wallet(), $record);
                $this->db->delete_records(key::TABLE, ['id' => $id]);
                $this->forget_notices([$id]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Remove every key one person registered for themselves, and the wallets they had.
     *
     * Their course keys are left alone. A course key belongs to the course rather than
     * to the teacher who happened to enter it, and removing it would stop the AI for
     * everybody in that course because one person left.
     *
     * @param int $userid The user.
     * @return int How many keys were removed.
     */
    public function delete_for_user(int $userid): int {
        return $this->delete_all(key::SCOPE_USER, $userid);
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
     * Remove every key registered for a course, and the wallets it had.
     *
     * @param int $courseid The course.
     */
    public function delete_for_course(int $courseid): void {
        $this->delete_all(key::SCOPE_COURSE, $courseid);
    }

    /**
     * Remove everything held for one subject: keys, wallets, and what was said about
     * the keys' limits.
     *
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course.
     * @return int How many keys were removed.
     */
    protected function delete_all(string $scope, int $scopeid): int {
        $conditions = ['scope' => $scope, 'scopeid' => $scopeid];
        $transaction = $this->db->start_delegated_transaction();
        try {
            $ids = $this->db->get_fieldset_select(
                key::TABLE,
                'id',
                'scope = :scope AND scopeid = :scopeid',
                $conditions,
            );
            $this->db->delete_records(key::TABLE, $conditions);
            $this->db->delete_records_select(
                self::WALLET_KEY_TABLE,
                'walletid IN (SELECT id FROM {' . self::WALLET_TABLE . '} WHERE scope = :scope AND scopeid = :scopeid)',
                $conditions,
            );
            $this->db->delete_records(self::WALLET_TABLE, $conditions);
            $this->forget_notices($ids);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return count($ids);
    }

    /**
     * Remove what was said about the limits on some keys.
     *
     * @param int[] $ids The keys.
     */
    protected function forget_notices(array $ids): void {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }
        [$insql, $params] = $this->db->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $params['kind'] = budget_notifier::KIND_KEY;
        $this->db->delete_records_select(budget_notifier::TABLE, "kind = :kind AND subjectid {$insql}", $params);
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
