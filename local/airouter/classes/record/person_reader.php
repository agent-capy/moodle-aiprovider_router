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

use local_airouter\key;
use local_airouter\rule;

/**
 * Reads the record by person: who used the AI, how much, and what it cost, and
 * for one person, their days and their individual requests.
 *
 * The same contract as the reader it extends: every finished fact once, from the
 * summary or from the detail. Individual requests are the detail alone, since the
 * summary does not keep them, and so reach back only as far as the purge allows.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class person_reader extends reader {
    /** @var int How many of one person's requests are listed at most. */
    public const MAX_REQUESTS = 500;

    /**
     * Everybody who used the AI in the period, busiest first.
     *
     * What the site paid for somebody and what they paid for themselves are kept
     * apart: a total that added the two would be nobody's expenditure.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass[] One row per person.
     */
    public function get_people(int $from, int $to): array {
        $people = [];
        foreach ($this->get_breakdown(self::BY_USER, $from, $to) as $row) {
            $userid = (int) $row->userid;
            $people[$userid] ??= (object) [
                'userid' => $userid,
                'requests' => 0,
                'prompttokens' => 0,
                'completiontokens' => 0,
                'sitecost' => null,
                'broughtcost' => null,
                'broughtrequests' => 0,
            ];
            $person = $people[$userid];
            $person->requests += (int) $row->requests;
            $person->prompttokens += (int) $row->prompttokens;
            $person->completiontokens += (int) $row->completiontokens;
            if ((string) $row->keysource === rule::KEYSOURCE_SITE) {
                $person->sitecost = self::add($person->sitecost, $row->cost);
            } else {
                $person->broughtcost = self::add($person->broughtcost, $row->cost);
                $person->broughtrequests += (int) $row->requests;
            }
        }
        uasort($people, fn($a, $b) => $b->requests <=> $a->requests);

        return array_values($people);
    }

    /**
     * One person's usage, a row per day, action, target, model and payer, newest first.
     *
     * @param int $userid The person.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass[] The rows.
     */
    public function get_days(int $userid, int $from, int $to): array {
        $fields = ['daystart', 'actionname', 'targetname', 'model', 'keysource'];
        $rows = [];
        $this->collect($rows, $fields, $this->summarised($fields, $from, $to, null, null, $userid));
        $this->collect($rows, $fields, $this->detailed($fields, $from, $to, null, null, $userid));
        usort($rows, fn($a, $b) => [$b->daystart, $a->actionname] <=> [$a->daystart, $b->actionname]);

        return $rows;
    }

    /**
     * One person's individual requests, newest first, each with what it used in all.
     *
     * A request's figures are the figures of every call made for it, answered or
     * not: what a provider charged for a failed call was charged to this person's
     * request. The target and model shown are the ones that answered, or the last
     * ones asked when nothing did.
     *
     * @param int $userid The person.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass[] At most MAX_REQUESTS rows.
     */
    public function get_requests(int $userid, int $from, int $to): array {
        $requests = $this->db->get_records_select(
            usage_recorder::REQUEST_TABLE,
            'userid = :userid AND timestarted >= :from AND timestarted < :to',
            ['userid' => $userid, 'from' => $from, 'to' => $to],
            'timestarted DESC, id DESC',
            'id, timestarted, courseid, actionname, placement, keysource, state, reason, answeredby, attempts',
            0,
            self::MAX_REQUESTS,
        );
        if (!$requests) {
            return [];
        }
        [$insql, $params] = $this->db->get_in_or_equal(array_keys($requests), SQL_PARAMS_NAMED);
        $attempts = $this->db->get_records_select(
            usage_recorder::ATTEMPT_TABLE,
            "requestid $insql",
            $params,
            'requestid ASC, seq ASC',
            'id, requestid, seq, targetid, targetname, model, state, usageknown, prompttokens, completiontokens, cost, currency',
        );

        $rows = [];
        foreach ($requests as $request) {
            $rows[(int) $request->id] = (object) [
                'id' => (int) $request->id,
                'timecreated' => (int) $request->timestarted,
                'courseid' => $request->courseid === null ? null : (int) $request->courseid,
                'actionname' => $request->actionname,
                'placement' => $request->placement,
                'keysource' => $request->keysource,
                'success' => (int) ($request->state === request_state::SUCCEEDED),
                'reason' => $request->reason,
                'attempts' => (int) $request->attempts,
                'targetid' => null,
                'targetname' => null,
                'model' => null,
                'prompttokens' => null,
                'completiontokens' => null,
                'cost' => null,
                'currency' => null,
            ];
        }
        $currencies = [];
        foreach ($attempts as $attempt) {
            $row = $rows[(int) $attempt->requestid];
            // The last one asked, until one answers.
            if ($row->targetid === null || $attempt->state === attempt_state::SUCCEEDED) {
                $row->targetid = (int) $attempt->targetid;
                $row->targetname = $attempt->targetname;
                $row->model = $attempt->model;
            }
            if ((int) $attempt->usageknown === 1) {
                $row->prompttokens = (int) $row->prompttokens + (int) $attempt->prompttokens;
                $row->completiontokens = (int) $row->completiontokens + (int) $attempt->completiontokens;
            }
            if ($attempt->cost !== null) {
                $row->cost = self::add($row->cost, (float) $attempt->cost);
                $currencies[$row->id][$attempt->currency] = true;
            }
        }
        foreach ($currencies as $id => $found) {
            // Money in one currency is a figure; in two it is not, and says so.
            $rows[$id]->currency = count($found) === 1 ? (string) array_key_first($found) : null;
            if (count($found) > 1) {
                $rows[$id]->cost = null;
            }
        }

        return array_values($rows);
    }

    /**
     * Everybody who has registered a key, without anything of the key itself.
     *
     * @return \stdClass[] The keys, by scope, subject and target.
     */
    public function get_key_holders(): array {
        return array_values($this->db->get_records(
            key::TABLE,
            null,
            'scope ASC, scopeid ASC, targetid ASC',
            'id, scope, scopeid, targetid, timecreated, timeverified, verifystatus',
        ));
    }

    /**
     * What each brought key was used for in the period.
     *
     * A request that tried two of somebody's keys is one request at each, because
     * each was asked; the money is what each was charged.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass[] Rows of keyid, requests and cost, keyed by key id.
     */
    public function get_key_usage(int $from, int $to): array {
        return $this->db->get_records_sql(
            'SELECT keyid, COUNT(DISTINCT requestid) AS requests, SUM(cost) AS cost
               FROM {' . usage_recorder::ATTEMPT_TABLE . '}
              WHERE keyid IS NOT NULL AND timeended IS NOT NULL AND timeended >= :from AND timeended < :to
           GROUP BY keyid',
            ['from' => $from, 'to' => $to],
        );
    }

    /**
     * The display names of some users.
     *
     * @param int[] $userids The users.
     * @return string[] Names keyed by user id. Missing for accounts that have gone.
     */
    public static function get_names(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (!$userids) {
            return [];
        }
        $fields = array_merge(['id'], \core_user\fields::get_name_fields());
        $names = [];
        foreach ($DB->get_records_list('user', 'id', $userids, '', implode(', ', $fields)) as $user) {
            $names[(int) $user->id] = fullname($user);
        }

        return $names;
    }
}
