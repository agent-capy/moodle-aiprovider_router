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

use aiprovider_router\key;
use aiprovider_router\key_repository;
use aiprovider_router\usage_logger;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for aiprovider_router.
 *
 * Two kinds of personal data are held. The first is the history of AI requests: core's
 * own record cannot say where a request was delegated, so this plugin keeps its own, and
 * that history names the user who made each request. The prompt itself is never stored —
 * its length is used to route the request and then forgotten — and what the AI answered
 * is core's record to keep, not this plugin's. The daily totals kept for longer hold no
 * user at all, so nothing in them has to be found or removed when a user goes.
 *
 * The second is keys people have brought. A key registered by a person for themselves is
 * theirs and goes when they do. A key registered for a course belongs to the course: the
 * only personal data in that row is who entered it, so a deletion request clears that and
 * leaves the key, rather than stopping the AI for everybody in the course because one
 * teacher left.
 *
 * Keys are never exported. They are held encrypted, an export is a plain file handed to
 * the person who asked for it, and somebody who wants to know their own key can read it
 * where they issued it.
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

        $collection->add_database_table(
            key::TABLE,
            [
                'scope' => 'privacy:metadata:key:scope',
                'scopeid' => 'privacy:metadata:key:scopeid',
                'targetid' => 'privacy:metadata:key:targetid',
                'secret' => 'privacy:metadata:key:secret',
                'hint' => 'privacy:metadata:key:hint',
                'usermodified' => 'privacy:metadata:key:usermodified',
                'timecreated' => 'privacy:metadata:key:timecreated',
            ],
            'privacy:metadata:key',
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

        // A person's own keys are theirs, and belong in their user context.
        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
               JOIN {' . key::TABLE . '} k ON k.scopeid = ctx.instanceid AND k.scope = :scope
              WHERE ctx.contextlevel = :level AND ctx.instanceid = :userid',
            ['scope' => key::SCOPE_USER, 'level' => CONTEXT_USER, 'userid' => $userid],
        );

        // A course key they entered leaves their name on a course.
        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
               JOIN {' . key::TABLE . '} k ON k.scopeid = ctx.instanceid AND k.scope = :scope
              WHERE ctx.contextlevel = :level AND k.usermodified = :userid',
            ['scope' => key::SCOPE_COURSE, 'level' => CONTEXT_COURSE, 'userid' => $userid],
        );

        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_user) {
            $userlist->add_from_sql(
                'scopeid',
                'SELECT scopeid FROM {' . key::TABLE . '} WHERE scope = :scope AND scopeid = :userid',
                ['scope' => key::SCOPE_USER, 'userid' => $context->instanceid],
            );

            return;
        }

        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {' . usage_logger::TABLE . '} WHERE contextid = :contextid',
            ['contextid' => $context->id],
        );

        if ($context instanceof \context_course) {
            $userlist->add_from_sql(
                'usermodified',
                'SELECT usermodified FROM {' . key::TABLE . '}
                  WHERE scope = :scope AND scopeid = :courseid AND usermodified > 0',
                ['scope' => key::SCOPE_COURSE, 'courseid' => $context->instanceid],
            );
        }
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        self::export_requests($contextlist, $userid);

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === (int) $userid) {
                $keys = $DB->get_records(key::TABLE, ['scope' => key::SCOPE_USER, 'scopeid' => $userid]);
            } else if ($context instanceof \context_course) {
                $keys = $DB->get_records(key::TABLE, [
                    'scope' => key::SCOPE_COURSE,
                    'scopeid' => $context->instanceid,
                    'usermodified' => $userid,
                ]);
            } else {
                continue;
            }
            if (!$keys) {
                continue;
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:path:keys', 'aiprovider_router')],
                (object) ['keys' => array_values(array_map(self::describe_key(...), $keys))],
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        $DB->delete_records(usage_logger::TABLE, ['contextid' => $context->id]);

        $repository = new key_repository($DB);
        if ($context instanceof \context_user) {
            $repository->delete_for_user((int) $context->instanceid);
        } else if ($context instanceof \context_course) {
            // The course itself is being cleared, so the key it held goes with it.
            $repository->delete_for_course((int) $context->instanceid);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        [$insql, $params] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $DB->delete_records_select(usage_logger::TABLE, "userid = :userid AND contextid {$insql}", $params);

        self::forget_keys($contextlist->get_contexts(), $userid);
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (!$userlist->count()) {
            return;
        }
        $context = $userlist->get_context();
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;
        $DB->delete_records_select(usage_logger::TABLE, "contextid = :contextid AND userid {$insql}", $params);

        foreach ($userlist->get_userids() as $userid) {
            self::forget_keys([$context], (int) $userid);
        }
    }

    /**
     * Remove or anonymise the keys one user has in the given contexts.
     *
     * @param \context[] $contexts The contexts being acted on.
     * @param int $userid The user.
     */
    protected static function forget_keys(array $contexts, int $userid): void {
        global $DB;

        $repository = new key_repository($DB);
        foreach ($contexts as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                $repository->delete_for_user($userid);
            } else if ($context instanceof \context_course) {
                // Not a deletion. The key belongs to the course, and only the record of
                // who entered it is this person's to have removed.
                $repository->forget_registrar($userid);
            }
        }
    }

    /**
     * What may be said about a key.
     *
     * Everything except the key. What is exported is enough for somebody to know which
     * of their keys Moodle is holding and whether it last worked.
     *
     * @param \stdClass $record The stored key.
     * @return \stdClass The description.
     */
    protected static function describe_key(\stdClass $record): \stdClass {
        return (object) [
            'target' => $record->targetid,
            'endsWith' => $record->hint,
            'timecreated' => transform::datetime($record->timecreated),
            'timemodified' => transform::datetime($record->timemodified),
            'lasttested' => $record->timeverified ? transform::datetime($record->timeverified) : null,
            'lastresult' => $record->verifystatus,
        ];
    }

    /**
     * Export the history of what this user asked the router to do.
     *
     * @param approved_contextlist $contextlist The contexts approved for export.
     * @param int $userid The user.
     */
    protected static function export_requests(approved_contextlist $contextlist, int $userid): void {
        global $DB;

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
                'timecreated' => transform::datetime($record->timecreated),
                'action' => $record->actionname,
                'delegatedto' => $record->targetname,
                'model' => $record->model,
                'prompttokens' => $record->prompttokens,
                'completiontokens' => $record->completiontokens,
                'cost' => $record->cost,
                'currency' => $record->currency,
                'success' => transform::yesno($record->success),
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
}
