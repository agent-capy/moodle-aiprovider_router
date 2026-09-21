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
 * The same figures as the dashboard, asked about people rather than about the site.
 *
 * A site that has to account for what its AI cost is eventually asked who spent it. The
 * dashboard cannot answer that, deliberately: it adds everybody together, because
 * knowing that the site spent a certain amount last month is a different question from
 * knowing who spent it, and most of the time only the first one needs asking.
 *
 * This answers the second, and is guarded by a capability of its own rather than by
 * being part of the ordinary monitor. It reads the same two tables across the same seam
 * as everything else here: the summaries as far as the scheduled task has reached, the
 * detail beyond it.
 *
 * Costs are what the site's own rate table says, including for requests somebody paid
 * for with a key they brought. That figure is an estimate of what their provider would
 * have charged, not what it did. Anything shown from here has to say so.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_report extends usage_report {
    /** @var int How many requests one person's detail listing shows at most. */
    public const MAX_REQUESTS = 500;

    /**
     * Everybody who used the AI in the period, with what they used, busiest first.
     *
     * One row per person, carrying the site's spending and their own separately. Added
     * together those two would be nobody's expenditure, and telling them apart is most
     * of the point of this screen.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass[] Rows of userid, requests, tokens and cost per payer.
     */
    public function get_people(int $from, int $to): array {
        $people = [];
        foreach ($this->get_breakdown(self::BY_USER, $from, $to) as $row) {
            $userid = $row->userid === null ? 0 : (int) $row->userid;
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
     * One person's usage, day by day, for as far back as anything is kept.
     *
     * @param int $userid The person.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass[] Rows, most recent day first.
     */
    public function get_days(int $userid, int $from, int $to): array {
        $fields = ['daystart', 'actionname', 'targetname', 'model', 'keysource'];
        $boundary = $this->get_boundary();
        $rows = [];

        if ($from < $boundary) {
            $this->collect($rows, $fields, $this->summarised(
                $fields,
                'daystart >= :from AND daystart < :to AND userid = :userid',
                ['from' => $from, 'to' => min($to, $boundary), 'userid' => $userid],
                null,
            ));
        }
        if ($to > $boundary) {
            // The detail has no daystart, so the day is worked out on the way out rather
            // than grouped on in the database, which has no portable way to do it.
            foreach ($this->days_from_detail($userid, max($from, $boundary), $to) as $row) {
                $this->collect($rows, $fields, [$row]);
            }
        }

        usort($rows, fn($a, $b) => [$b->daystart, $a->actionname] <=> [$a->daystart, $b->actionname]);

        return $rows;
    }

    /**
     * One person's individual requests, as far back as the detail still goes.
     *
     * This is the only thing here that cannot outlive the detail retention period.
     * Everything else falls back on the summaries, which say how much was used on a day
     * but not at what time; a listing of separate requests has nowhere to fall back to.
     *
     * @param int $userid The person.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass[] Rows, most recent first.
     */
    public function get_requests(int $userid, int $from, int $to): array {
        return array_values($this->db->get_records_select(
            usage_logger::TABLE,
            'userid = :userid AND timecreated >= :from AND timecreated < :to',
            ['userid' => $userid, 'from' => $from, 'to' => $to],
            'timecreated DESC',
            'id, timecreated, courseid, actionname, placement, targetname, model,
             prompttokens, completiontokens, cost, currency, keysource, success, reason',
            0,
            self::MAX_REQUESTS,
        ));
    }

    /**
     * Everybody who has registered a key, and every course one has been registered for.
     *
     * Nothing about the key itself is here, not even the hint. The hint exists so that
     * an owner can tell their own keys apart; an administrator asking who brings keys
     * does not need to know which one, and showing it would spread a fragment of
     * somebody's credential across a screen that has no use for it.
     *
     * @return \stdClass[] Rows of scope, scopeid, targetid, and when it was last tested.
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
     * What each brought key has been used for, so the holder list can say so.
     *
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass[] Rows of requests and cost, keyed by key id.
     */
    public function get_key_usage(int $from, int $to): array {
        return $this->db->get_records_sql(
            'SELECT keyid,
                    SUM(counted) AS requests,
                    SUM(cost) AS cost
               FROM {' . usage_logger::TABLE . '}
              WHERE keyid IS NOT NULL AND timecreated >= :from AND timecreated < :to
           GROUP BY keyid',
            ['from' => $from, 'to' => $to],
        );
    }

    /**
     * The names of the people a set of rows refers to, read in one query.
     *
     * An account that has been deleted leaves figures behind and no name. That is the
     * point of keeping the figures, and the caller says so rather than inventing one.
     *
     * @param int[] $userids The people.
     * @return string[] Names keyed by id.
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

    /**
     * One person's detail rows, gathered into days.
     *
     * @param int $userid The person.
     * @param int $from The start of the period.
     * @param int $to The end of the period.
     * @return \stdClass[] Rows shaped like the summary rows.
     */
    protected function days_from_detail(int $userid, int $from, int $to): array {
        $rows = [];
        $recordset = $this->db->get_recordset_select(
            usage_logger::TABLE,
            'userid = :userid AND timecreated >= :from AND timecreated < :to',
            ['userid' => $userid, 'from' => $from, 'to' => $to],
            'timecreated ASC',
        );
        foreach ($recordset as $record) {
            $day = $this->aggregator->day_of((int) $record->timecreated);
            $key = implode('|', [$day, $record->actionname, $record->targetname, $record->model,
                $record->keysource]);
            $rows[$key] ??= (object) [
                'daystart' => $day,
                'actionname' => $record->actionname,
                'targetname' => $record->targetname,
                'model' => $record->model,
                'keysource' => $record->keysource,
                'requests' => 0,
                'failures' => 0,
                'calls' => 0,
                'prompttokens' => 0,
                'completiontokens' => 0,
                'cost' => null,
                'costedcalls' => 0,
            ];
            $row = $rows[$key];
            // One row is one call, and only the row the request was counted on is a
            // request. Counting every row as one showed somebody two requests for the
            // one thing they asked for, until the day was summarised and it became
            // one again.
            $counted = (int) $record->counted === 1;
            $row->requests += $counted ? 1 : 0;
            $row->failures += $counted && !$record->success ? 1 : 0;
            $row->calls++;
            $row->prompttokens += (int) $record->prompttokens;
            $row->completiontokens += (int) $record->completiontokens;
            if ($record->cost !== null) {
                $row->cost = (float) ($row->cost ?? 0) + (float) $record->cost;
                $row->costedcalls++;
            }
        }
        $recordset->close();

        return array_values($rows);
    }
}
