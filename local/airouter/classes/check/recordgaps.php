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

namespace local_airouter\check;

use local_airouter\record\attempt_state;
use local_airouter\record\request_state;
use local_airouter\record\summariser;
use local_airouter\record\usage_recorder;
use core\check\result;

/**
 * Says whether the record of requests and attempts has holes in it.
 *
 * Three kinds, none of which a report can see from its totals alone: writes that
 * failed and were let go of so that the request could go on; attempts given up as
 * lost because the process did not live to record how they came back; and attempts
 * or requests still open long past any call this plugin makes, which the next run
 * will give up on. A site comparing a bill with a report needs to be told these are
 * there rather than find them.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recordgaps extends base {
    /** @var int How far back lost attempts are counted. */
    public const LOOKBACK = 30 * DAYSECS;

    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/local/airouter/usage.php'),
            get_string('usage:heading', 'local_airouter'),
        );
    }


    #[\Override]
    protected function check_router(): result {
        global $DB;

        $now = time();
        $failed = usage_recorder::get_failure_count();
        $lost = $DB->count_records_select(
            usage_recorder::ATTEMPT_TABLE,
            'state = :lost AND timeended >= :since',
            ['lost' => attempt_state::LOST, 'since' => $now - self::LOOKBACK],
        );
        // A request given up on is a gap of its own, whether or not it got as far as
        // asking anybody: what it would have recorded is not there either way.
        $lostrequests = $DB->count_records_select(
            usage_recorder::REQUEST_TABLE,
            'reason = :lost AND timeended >= :since',
            ['lost' => summariser::REASON_LOST, 'since' => $now - self::LOOKBACK],
        );
        $stale = $DB->count_records_select(
            usage_recorder::ATTEMPT_TABLE,
            'state = :started AND timestarted < :cutoff',
            ['started' => attempt_state::STARTED, 'cutoff' => $now - summariser::STALE_AFTER],
        ) + $DB->count_records_select(
            usage_recorder::REQUEST_TABLE,
            'state = :open AND timestarted < :cutoff',
            ['open' => request_state::OPEN, 'cutoff' => $now - summariser::STALE_AFTER],
        );

        if ($failed === 0 && $lost === 0 && $lostrequests === 0 && $stale === 0) {
            return new result(result::OK, get_string('check:recordgaps:ok', 'local_airouter'));
        }

        return new result(
            result::WARNING,
            get_string('check:recordgaps:gaps', 'local_airouter'),
            get_string('check:recordgaps:gaps_details', 'local_airouter', (object) [
                'failed' => $failed,
                'lost' => $lost,
                'lostrequests' => $lostrequests,
                'stale' => $stale,
            ]),
        );
    }
}
