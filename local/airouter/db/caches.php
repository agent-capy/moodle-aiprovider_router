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

/**
 * Cache definitions for local_airouter.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Whether each person may bring their own key. Asked on every request that would use
    // one, and answering it means looking across the site's role assignments, which is
    // not a query to repeat per request.
    //
    // The routing rules themselves are deliberately not cached: they are few, and a
    // stale rule is a request sent to the wrong provider. This is the other case. The
    // lifetime is short because it is what a change in who holds which role waits for;
    // a change to the policy itself empties this outright instead.
    'eligibility' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 20,
        'ttl' => 300,
    ],

    // What has been spent, for the conditions and caps that route on it. Asked on every
    // request a budget applies to, and answering it means summing the history, which is
    // not a query to repeat per request either.
    //
    // The lifetime is what a limit is accurate to. Requests arriving while a figure
    // is held see the spending as it was when it was measured, so a burst can carry a
    // site past its limit by whatever it can spend in that time. A minute is short
    // enough for that to stay small and long enough to be worth having; making it
    // shorter buys accuracy nobody can rely on anyway, because the request being
    // weighed has not been paid for yet.
    //
    // Not simple data: a figure that is not known is stored as null, and a store that
    // took the entry apart would hand back something else.
    'budget' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 20,
        'ttl' => 60,
    ],

    // The settled part of each budget's period: the days the daily task has folded into
    // the summary. A budget's figure is that and what has not been summarised yet, and
    // only the second changes between runs of the daily task, so the first is worked out
    // once -- by the daily task itself, where it can -- and kept. Kept under the
    // generation of the record it was read in, which everything that rewrites the
    // summary moves, so an entry is never found once it is out of date; the lifetime
    // only clears away entries nobody will ask for again.
    //
    // A site that has APCu, or another memory store, can map this definition to it.
    'budgethistory' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 50,
        'ttl' => 2 * DAYSECS,
    ],
];
