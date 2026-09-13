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

namespace aiprovider_router\privacy;

use aiprovider_router\usage_logger;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for aiprovider_router.
 *
 * The router keeps its own history of the AI requests it handled, because core's record
 * cannot say where a request was delegated. That history names the user who made each
 * request, so it is personal data and is reported, exported and deleted here.
 *
 * The prompt itself is never stored. Its length is used to route the request and then
 * forgotten, and what the AI answered is core's record to keep, not this plugin's.
 *
 * The daily totals the monitor keeps for longer hold no user at all, so nothing in them
 * has to be found or removed when a user goes.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            usage_logger::TABLE,
            [
                'userid' => 'privacy:metadata:log:userid',
                'contextid' => 'privacy:metadata:log:contextid',
                'courseid' => 'privacy:metadata:log:courseid',
                'actionname' => 'privacy:metadata:log:actionname',
                'targetname' => 'privacy:metadata:log:targetname',
                'model' => 'privacy:metadata:log:model',
                'prompttokens' => 'privacy:metadata:log:prompttokens',
                'completiontokens' => 'privacy:metadata:log:completiontokens',
                'cost' => 'privacy:metadata:log:cost',
                'timecreated' => 'privacy:metadata:log:timecreated',
            ],
            'privacy:metadata:log',
        );

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            'SELECT DISTINCT contextid FROM {' . usage_logger::TABLE . '} WHERE userid = :userid',
            ['userid' => $userid],
        );

        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {' . usage_logger::TABLE . '} WHERE contextid = :contextid',
            ['contextid' => $userlist->get_context()->id],
        );
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        [$insql, $params] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $params['userid'] = $userid;

        $records = $DB->get_records_select(
            usage_logger::TABLE,
            "userid = :userid AND contextid {$insql}",
            $params,
            'timecreated ASC',
        );

        $bycontext = [];
        foreach ($records as $record) {
            $bycontext[(int) $record->contextid][] = (object) [
                'timecreated' => \core_privacy\local\request\transform::datetime($record->timecreated),
                'action' => $record->actionname,
                'delegatedto' => $record->targetname,
                'model' => $record->model,
                'prompttokens' => $record->prompttokens,
                'completiontokens' => $record->completiontokens,
                'cost' => $record->cost,
                'currency' => $record->currency,
                'success' => \core_privacy\local\request\transform::yesno($record->success),
            ];
        }

        foreach ($bycontext as $contextid => $requests) {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            writer::with_context($context)->export_data(
                [get_string('privacy:path:log', 'aiprovider_router')],
                (object) ['requests' => $requests],
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        $DB->delete_records(usage_logger::TABLE, ['contextid' => $context->id]);
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $params['userid'] = $contextlist->get_user()->id;

        $DB->delete_records_select(usage_logger::TABLE, "userid = :userid AND contextid {$insql}", $params);
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (!$userlist->count()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params['contextid'] = $userlist->get_context()->id;

        $DB->delete_records_select(usage_logger::TABLE, "contextid = :contextid AND userid {$insql}", $params);
    }
}
