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

use local_airouter\key;
use local_airouter\key_repository;
use local_airouter\record\attempt_state;
use local_airouter\record\request_state;
use local_airouter\record\usage_recorder;
use local_airouter\target_settings;

/**
 * Makes recorded requests and attempts for tests, without a provider being asked.
 *
 * The recorder writes what happened as it happens, which is right for the product
 * and slow for a test that needs a month of history. This writes the rows directly,
 * in the shape the recorder would have left them.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_airouter_generator extends component_generator_base {
    /**
     * A finished request.
     *
     * @param array $record Column values. Anything not given gets a sensible default:
     *                      a succeeded generate_text request by the admin, in the system
     *                      context, ended now.
     * @return stdClass The row as written.
     */
    public function create_request(array $record = []): stdClass {
        global $DB;

        $now = time();
        $row = (object) ($record + [
            'correlation' => \core\uuid::generate(),
            'userid' => 2,
            'contextid' => context_system::instance()->id,
            'courseid' => null,
            'actionname' => 'generate_text',
            'placement' => null,
            'ruleid' => null,
            'rulename' => null,
            'keysource' => 'site',
            'state' => request_state::SUCCEEDED,
            'reason' => null,
            'errorcode' => null,
            'answeredby' => null,
            'attempts' => 0,
            'timestarted' => $now,
            'timeended' => $now,
            'applied' => 0,
        ]);
        $row->id = $DB->insert_record(usage_recorder::REQUEST_TABLE, $row);

        return $row;
    }

    /**
     * A finished attempt on a request.
     *
     * @param array $record Column values. requestid is required; the rest defaults to a
     *                      succeeded call on target 1 with known, priced usage.
     * @return stdClass The row as written.
     */
    public function create_attempt(array $record): stdClass {
        global $DB;

        if (empty($record['requestid'])) {
            throw new coding_exception('An attempt needs a requestid.');
        }
        $now = time();
        $seq = $record['seq'] ?? ($DB->count_records(usage_recorder::ATTEMPT_TABLE, ['requestid' => $record['requestid']]) + 1);
        $row = (object) ($record + [
            'seq' => $seq,
            'targetid' => 1,
            'targetname' => 'Target 1',
            'targetprovider' => 'aiprovider_mock',
            'model' => null,
            'keysource' => 'site',
            'keyid' => null,
            'walletid' => 0,
            'state' => attempt_state::SUCCEEDED,
            'errorcode' => null,
            'prompttokens' => 10,
            'completiontokens' => 5,
            'images' => 0,
            'usageknown' => 1,
            'cost' => null,
            'currency' => null,
            'unpriced' => 0,
            'timestarted' => $now,
            'timeended' => $now,
            'applied' => 0,
        ]);
        $row->id = $DB->insert_record(usage_recorder::ATTEMPT_TABLE, $row);
        $DB->set_field(usage_recorder::REQUEST_TABLE, 'attempts', $seq, ['id' => $record['requestid']]);

        return $row;
    }
    /**
     * Say which configuration field a target's key goes in, which lets it take one.
     *
     * @param array $record targetid and keyfield.
     */
    public function create_target_setting(array $record): void {
        global $DB;

        if (empty($record['targetid'])) {
            throw new coding_exception('A target setting needs a targetid.');
        }
        (new target_settings($DB))->set_key_field((int) $record['targetid'], (string) ($record['keyfield'] ?? ''));
    }

    /**
     * Register a key for a person or a course, as the key page would.
     *
     * @param array $record targetid, secret, and userid or courseid.
     * @return key The key.
     */
    public function create_key(array $record): key {
        global $DB;

        [$scope, $scopeid, $targetid] = $this->key_subject($record);
        $key = (new key_repository($DB))->save($scope, $scopeid, $targetid, (string) $record['secret']);
        if ($key === null) {
            throw new coding_exception('A key is registered for that target already; replace it instead.');
        }

        return $key;
    }

    /**
     * Replace the key a person or a course holds, as somebody on another screen would.
     *
     * @param array $record targetid, secret, account ('same' or 'another'), and userid or courseid.
     * @return key The key as replaced.
     */
    public function create_key_replacement(array $record): key {
        global $DB;

        [$scope, $scopeid, $targetid] = $this->key_subject($record);
        $repository = new key_repository($DB);
        $held = $repository->find($scope, $scopeid, $targetid);
        if ($held === null) {
            throw new coding_exception('There is no key there to replace.');
        }
        $replaced = $repository->replace(
            $held,
            (string) $record['secret'],
            ($record['account'] ?? '') === 'same',
            $held->has_cap() ? true : null,
        );
        if ($replaced === null) {
            throw new coding_exception('The key could not be replaced.');
        }

        return $replaced;
    }

    /**
     * Whose key a generator record is about, and for which target.
     *
     * @param array $record targetid, secret, and userid or courseid.
     * @return array The scope, the subject id and the target id.
     */
    protected function key_subject(array $record): array {
        if (empty($record['targetid']) || empty($record['secret'])) {
            throw new coding_exception('A key needs a targetid and a secret.');
        }
        if (!empty($record['courseid'])) {
            return [key::SCOPE_COURSE, (int) $record['courseid'], (int) $record['targetid']];
        }
        if (!empty($record['userid'])) {
            return [key::SCOPE_USER, (int) $record['userid'], (int) $record['targetid']];
        }

        throw new coding_exception('A key needs a userid or a courseid.');
    }
}
