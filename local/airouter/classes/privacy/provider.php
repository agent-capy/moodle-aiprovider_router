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

namespace local_airouter\privacy;

use local_airouter\budget_notifier;
use local_airouter\key;
use local_airouter\key_repository;
use local_airouter\usage_aggregator;
use local_airouter\usage_logger;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_airouter.
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
 * @package    local_airouter
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
            usage_aggregator::TABLE,
            [
                'userid' => 'privacy:metadata:daily:userid',
                'courseid' => 'privacy:metadata:daily:courseid',
                'actionname' => 'privacy:metadata:daily:actionname',
                'targetname' => 'privacy:metadata:daily:targetname',
                'model' => 'privacy:metadata:daily:model',
                'requests' => 'privacy:metadata:daily:requests',
                'prompttokens' => 'privacy:metadata:daily:prompttokens',
                'completiontokens' => 'privacy:metadata:daily:completiontokens',
                'cost' => 'privacy:metadata:daily:cost',
                'daystart' => 'privacy:metadata:daily:daystart',
            ],
            'privacy:metadata:daily',
        );

        $collection->add_database_table(
            key::TABLE,
            [
                'scope' => 'privacy:metadata:key:scope',
                'scopeid' => 'privacy:metadata:key:scopeid',
                'targetid' => 'privacy:metadata:key:targetid',
                'secret' => 'privacy:metadata:key:secret',
                'hint' => 'privacy:metadata:key:hint',
                'capamount' => 'privacy:metadata:key:capamount',
                'usermodified' => 'privacy:metadata:key:usermodified',
                'timecreated' => 'privacy:metadata:key:timecreated',
            ],
            'privacy:metadata:key',
        );

        $collection->add_database_table(
            budget_notifier::TABLE,
            [
                'kind' => 'privacy:metadata:notice:kind',
                'subjectid' => 'privacy:metadata:notice:subjectid',
                'metric' => 'privacy:metadata:notice:metric',
                'limitamount' => 'privacy:metadata:notice:limitamount',
                'threshold' => 'privacy:metadata:notice:threshold',
                'timenotified' => 'privacy:metadata:notice:timenotified',
            ],
            'privacy:metadata:notice',
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

        // The summaries carry no context of their own: a day of somebody's usage is not
        // an event in a course, it is a fact about them. Their own user context is where
        // it belongs, and it is the context a deletion request reaches it through.
        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
              WHERE ctx.contextlevel = :level AND ctx.instanceid = :userid
                AND EXISTS (SELECT 1 FROM {' . usage_aggregator::TABLE . '} d WHERE d.userid = :duserid)',
            ['level' => CONTEXT_USER, 'userid' => $userid, 'duserid' => $userid],
        );

        // Being told that a limit on one's own spending has been reached is a fact
        // about that person, kept until the limit eases off. Like the summaries it has
        // no context of its own, so it lives in theirs.
        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
               JOIN {' . budget_notifier::TABLE . '} n
                 ON n.subjectid = ctx.instanceid AND n.kind = :kind
              WHERE ctx.contextlevel = :level AND ctx.instanceid = :userid',
            ['kind' => budget_notifier::KIND_USER, 'level' => CONTEXT_USER, 'userid' => $userid],
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

        // Asked of every context, including a user's. A request made outside any course
        // is recorded against the asker's own user context -- which is what the media
        // web services produce when no context is given -- and looking only for keys
        // and summaries there missed anybody whose usage had not been summarised yet.
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {' . usage_logger::TABLE . '} WHERE contextid = :contextid',
            ['contextid' => $context->id],
        );

        if ($context instanceof \context_user) {
            $userlist->add_from_sql(
                'scopeid',
                'SELECT scopeid FROM {' . key::TABLE . '} WHERE scope = :scope AND scopeid = :userid',
                ['scope' => key::SCOPE_USER, 'userid' => $context->instanceid],
            );
            $userlist->add_from_sql(
                'userid',
                'SELECT DISTINCT userid FROM {' . usage_aggregator::TABLE . '} WHERE userid = :userid',
                ['userid' => $context->instanceid],
            );
            $userlist->add_from_sql(
                'subjectid',
                'SELECT subjectid FROM {' . budget_notifier::TABLE . '}
                  WHERE kind = :kind AND subjectid = :userid',
                ['kind' => budget_notifier::KIND_USER, 'userid' => $context->instanceid],
            );

            return;
        }

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
        self::export_summaries($contextlist, (int) $userid);
        self::export_notices($contextlist, (int) $userid);

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
                [get_string('privacy:path:keys', 'local_airouter')],
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
            $DB->delete_records(usage_aggregator::TABLE, ['userid' => (int) $context->instanceid]);
            self::forget_notices((int) $context->instanceid, self::key_ids_of((int) $context->instanceid));
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

        self::delete_summaries($contextlist->get_contexts(), $userid);
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
            self::delete_summaries([$context], (int) $userid);
            self::forget_keys([$context], (int) $userid);
        }
    }

    /**
     * Remove one person from the summaries.
     *
     * This is what makes it affordable for the summaries to name anybody. Taking one
     * person out of an anonymous total would mean recomputing it from detail rows that
     * have long been purged; taking their own rows out costs one delete and leaves
     * everybody else's figures exactly as they were.
     *
     * @param \context[] $contexts The contexts being acted on.
     * @param int $userid The user.
     */
    protected static function delete_summaries(array $contexts, int $userid): void {
        global $DB;

        foreach ($contexts as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                $DB->delete_records(usage_aggregator::TABLE, ['userid' => $userid]);

                return;
            }
        }
    }

    /**
     * Export the summarised days of one person's usage.
     *
     * Exported without a course, even though the rows have one. The summary says how
     * much was used and when, and the detail export beside it already says where each
     * request was made; repeating the course here would only make the same fact look
     * like two.
     *
     * @param approved_contextlist $contextlist The contexts approved for export.
     * @param int $userid The user.
     */
    protected static function export_summaries(approved_contextlist $contextlist, int $userid): void {
        global $DB;

        $usercontext = null;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                $usercontext = $context;
                break;
            }
        }
        if ($usercontext === null) {
            return;
        }

        $records = $DB->get_records(usage_aggregator::TABLE, ['userid' => $userid], 'daystart ASC');
        if (!$records) {
            return;
        }

        $days = [];
        foreach ($records as $record) {
            $days[] = (object) [
                'day' => transform::datetime($record->daystart),
                'action' => $record->actionname,
                'delegatedto' => $record->targetname,
                'model' => $record->model,
                'requests' => $record->requests,
                'prompttokens' => $record->prompttokens,
                'completiontokens' => $record->completiontokens,
                'cost' => $record->cost,
                'currency' => $record->currency,
                'paidwith' => $record->keysource,
            ];
        }

        writer::with_context($usercontext)->export_data(
            [get_string('privacy:path:summaries', 'local_airouter')],
            (object) ['days' => $days],
        );
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
                // Anything said about a limit on one of these keys goes with them.
                self::forget_notices($userid, self::key_ids_of($userid));
                $repository->delete_for_user($userid);
            } else if ($context instanceof \context_course) {
                // Not a deletion. The key belongs to the course, and only the record of
                // who entered it is this person's to have removed -- and only in the
                // course that was approved. The same person may have entered a key in
                // another course, and that course was not part of this request.
                $repository->forget_registrar($userid, (int) $context->instanceid);
            }
        }
    }

    /**
     * The keys somebody holds for themselves.
     *
     * Read before they are deleted, so that what was said about their limits can be
     * removed with them rather than left pointing at a row that has gone.
     *
     * @param int $userid The user.
     * @return int[] The key ids.
     */
    protected static function key_ids_of(int $userid): array {
        global $DB;

        return $DB->get_fieldset_select(
            key::TABLE,
            'id',
            'scope = :scope AND scopeid = :userid',
            ['scope' => key::SCOPE_USER, 'userid' => $userid],
        );
    }

    /**
     * Remove what has been said to somebody about a limit being reached.
     *
     * These rows exist so that a limit is announced once per crossing rather than once
     * per day. A person's own budget names them outright, and a limit somebody put on
     * their own key names the key, so both go when they do.
     *
     * @param int $userid The user.
     * @param int[] $keyids Keys of theirs whose notices should go too.
     */
    protected static function forget_notices(int $userid, array $keyids = []): void {
        global $DB;

        $DB->delete_records(budget_notifier::TABLE, [
            'kind' => budget_notifier::KIND_USER,
            'subjectid' => $userid,
        ]);
        if (!$keyids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($keyids, SQL_PARAMS_NAMED);
        $params['kind'] = budget_notifier::KIND_KEY;
        $DB->delete_records_select(
            budget_notifier::TABLE,
            "kind = :kind AND subjectid {$insql}",
            $params,
        );
    }

    /**
     * Export what has been said to somebody about their own spending.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @param int $userid The user.
     */
    protected static function export_notices(approved_contextlist $contextlist, int $userid): void {
        global $DB;

        $usercontext = null;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                $usercontext = $context;
                break;
            }
        }
        if ($usercontext === null) {
            return;
        }

        $notices = $DB->get_records(budget_notifier::TABLE, [
            'kind' => budget_notifier::KIND_USER,
            'subjectid' => $userid,
        ], 'timenotified ASC');
        if (!$notices) {
            return;
        }

        writer::with_context($usercontext)->export_data(
            [get_string('privacy:path:notices', 'local_airouter')],
            (object) ['notices' => array_values(array_map(
                static fn($notice) => (object) [
                    'metric' => $notice->metric,
                    'limitamount' => $notice->limitamount,
                    'threshold' => $notice->threshold,
                    'timenotified' => transform::datetime((int) $notice->timenotified),
                ],
                $notices,
            ))],
        );
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
                [get_string('privacy:path:log', 'local_airouter')],
                (object) ['requests' => $requests],
            );
        }
    }
}
