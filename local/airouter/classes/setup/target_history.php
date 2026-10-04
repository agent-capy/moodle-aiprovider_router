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

namespace local_airouter\setup;

use local_airouter\record\attempt_state;
use local_airouter\record\usage_recorder;

/**
 * When each delegation target last answered, and last failed, through the router.
 *
 * The one thing the settings cannot say is whether a provider answers. The router's
 * own record of attempts can say whether it did, recently, and that is all this
 * reports: a success last week is not a promise for the next request, and a target
 * nothing has been sent to yet is "not known", never "fine".
 *
 * Read through the request table's start time, which is indexed, so that the cost
 * follows the period looked at rather than the size of the whole record.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class target_history {
    /** @var int How far back to look, in days. */
    public const DAYS = 30;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
    ) {
    }

    /**
     * The last success and the last failure of each target, over the period.
     *
     * @param int $now The time to look back from.
     * @return array<int, \stdClass> Keyed by target id: lastsucceeded and lastfailed,
     *                               each a time or 0 when there was none.
     */
    public function get_last(int $now): array {
        [$failedsql, $failedparams] = $this->db->get_in_or_equal(
            [attempt_state::FAILED, attempt_state::THREW, attempt_state::EMPTY],
            SQL_PARAMS_NAMED,
            'failed',
        );
        $sql = "SELECT a.targetid,
                       MAX(CASE WHEN a.state = :succeeded THEN a.timeended ELSE 0 END) AS lastsucceeded,
                       MAX(CASE WHEN a.state {$failedsql} THEN a.timeended ELSE 0 END) AS lastfailed
                  FROM {" . usage_recorder::ATTEMPT_TABLE . "} a
                  JOIN {" . usage_recorder::REQUEST_TABLE . "} r ON r.id = a.requestid
                 WHERE r.timestarted >= :since
              GROUP BY a.targetid";
        $params = ['succeeded' => attempt_state::SUCCEEDED, 'since' => $now - self::DAYS * DAYSECS] + $failedparams;

        $last = [];
        foreach ($this->db->get_records_sql($sql, $params) as $record) {
            $last[(int) $record->targetid] = (object) [
                'lastsucceeded' => (int) $record->lastsucceeded,
                'lastfailed' => (int) $record->lastfailed,
            ];
        }

        return $last;
    }

    /**
     * What the record says about one target, in words.
     *
     * @param \stdClass|null $last The target's entry from get_last(), or null when it has none.
     * @return string The sentence.
     */
    public static function describe(?\stdClass $last): string {
        if ($last === null) {
            return get_string('setup:delivery:none', 'local_airouter', self::DAYS);
        }
        $never = get_string('setup:delivery:never', 'local_airouter');

        return get_string('setup:delivery:last', 'local_airouter', (object) [
            'succeeded' => $last->lastsucceeded > 0 ? userdate($last->lastsucceeded) : $never,
            'failed' => $last->lastfailed > 0 ? userdate($last->lastfailed) : $never,
            'days' => self::DAYS,
        ]);
    }
}
