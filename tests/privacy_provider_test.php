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

use aiprovider_router\privacy\provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for what this plugin says, exports and removes about a person.
 *
 * The one thing that must never happen here is a brought key leaving the site. An export
 * is a plain file handed to whoever asked for it, and a key that came out in one would be
 * out for good.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /** @var key_repository The key store. */
    protected key_repository $keys;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->keys = new key_repository($DB);
    }

    /**
     * Write one summary row for somebody.
     *
     * @param int $userid Whose day it is.
     * @param array $fields What to record, over the defaults.
     * @return int The row id.
     */
    protected function summarise(int $userid, array $fields = []): int {
        global $DB;

        return $DB->insert_record(usage_aggregator::TABLE, (object) ($fields + [
            'daystart' => make_timestamp(2026, 9, 1, 0, 0, 0),
            'courseid' => null,
            'userid' => $userid,
            'actionname' => 'generate_text',
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'keysource' => usage_logger::KEY_SITE,
            'currency' => 'USD',
            'requests' => 4,
            'failures' => 0,
            'calls' => 4,
            'prompttokens' => 100,
            'completiontokens' => 50,
            'cost' => 0.5,
            'costedcalls' => 4,
            'timecreated' => time(),
        ]));
    }

    public function test_a_summarised_day_is_exported_to_the_person_it_belongs_to(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->summarise((int) $user->id);
        $context = \context_user::instance($user->id);

        $this->export_context_data_for_user((int) $user->id, $context, 'aiprovider_router');

        $data = writer::with_context($context)->get_data([
            get_string('privacy:path:summaries', 'aiprovider_router'),
        ]);
        $this->assertCount(1, $data->days);
        $this->assertSame(4, (int) $data->days[0]->requests);
    }

    public function test_a_summarised_day_is_found_in_the_persons_own_context(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->summarise((int) $user->id);

        $contexts = provider::get_contexts_for_userid((int) $user->id)->get_contextids();

        $this->assertContainsEquals(\context_user::instance($user->id)->id, $contexts);
    }

    public function test_a_deletion_request_takes_a_person_out_of_the_summaries(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->summarise((int) $user->id);
        $this->summarise((int) $other->id);
        $context = \context_user::instance($user->id);

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'aiprovider_router',
            [$context->id],
        ));

        // This is what makes naming people in the summary affordable: one delete, and
        // nobody else's figures move. Taking somebody out of an anonymous total would
        // mean recomputing it from detail rows that have long been purged.
        $this->assertSame(0, $DB->count_records(usage_aggregator::TABLE, ['userid' => $user->id]));
        $this->assertSame(1, $DB->count_records(usage_aggregator::TABLE, ['userid' => $other->id]));
    }

    public function test_summaries_written_before_anybody_was_named_belong_to_nobody(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        // What an upgrade leaves behind: days counted when the column did not exist.
        $this->summarise(0, ['userid' => null]);
        $this->summarise((int) $user->id);

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'aiprovider_router',
            [\context_user::instance($user->id)->id],
        ));

        // Null is not zero and not anybody: a deletion request cannot claim those rows,
        // and neither can a report attribute them.
        $this->assertSame(1, $DB->count_records_select(usage_aggregator::TABLE, 'userid IS NULL'));
    }

    public function test_a_brought_key_is_never_exported(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->keys->save(key::SCOPE_USER, (int) $user->id, 3, 'sk-do-not-export-wxyz');
        $context = \context_user::instance($user->id);

        $this->export_context_data_for_user((int) $user->id, $context, 'aiprovider_router');

        $data = writer::with_context($context)->get_data([
            get_string('privacy:path:keys', 'aiprovider_router'),
        ]);
        $exported = json_encode($data);
        $this->assertStringNotContainsString('sk-do-not-export-wxyz', $exported);
        // What is exported is enough to know which key this is, and no more.
        $this->assertStringContainsString('wxyz', $exported);
    }

    public function test_a_persons_own_key_is_found_in_their_own_context(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->keys->save(key::SCOPE_USER, (int) $user->id, 3, 'sk-mine-abcd');

        $contexts = provider::get_contexts_for_userid((int) $user->id)->get_contextids();

        $this->assertContainsEquals(\context_user::instance($user->id)->id, $contexts);
    }

    public function test_a_course_key_is_found_against_the_teacher_who_entered_it(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);
        $saved = $this->keys->save(key::SCOPE_COURSE, (int) $course->id, 3, 'sk-course-efgh');
        $DB->set_field(key::TABLE, 'usermodified', $teacher->id, ['id' => $saved->get('id')]);

        $contexts = provider::get_contexts_for_userid((int) $teacher->id)->get_contextids();

        $this->assertContainsEquals(\context_course::instance($course->id)->id, $contexts);
    }

    public function test_a_deletion_request_removes_a_persons_own_key(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->keys->save(key::SCOPE_USER, (int) $user->id, 3, 'sk-mine-abcd');

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'aiprovider_router',
            [\context_user::instance($user->id)->id],
        ));

        $this->assertSame(0, $DB->count_records(key::TABLE));
    }

    public function test_a_deletion_request_leaves_a_course_key_and_clears_the_name_on_it(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);
        $saved = $this->keys->save(key::SCOPE_COURSE, (int) $course->id, 3, 'sk-course-efgh');
        $DB->set_field(key::TABLE, 'usermodified', $teacher->id, ['id' => $saved->get('id')]);

        provider::delete_data_for_user(new approved_contextlist(
            $teacher,
            'aiprovider_router',
            [\context_course::instance($course->id)->id],
        ));

        // The key belongs to the course and stays. Only the record of who entered it is
        // the teacher's to have removed.
        $this->assertSame(1, $DB->count_records(key::TABLE));
        $this->assertSame(0, (int) $DB->get_field(key::TABLE, 'usermodified', ['id' => $saved->get('id')]));
        $this->assertSame('sk-course-efgh', $this->keys->reveal(
            $this->keys->get((int) $saved->get('id')),
        ));
    }

    /**
     * Record one request against a context.
     *
     * @param int $userid Who made it.
     * @param int $contextid Where it was made.
     */
    protected function log(int $userid, int $contextid): void {
        global $DB;

        $DB->insert_record(usage_logger::TABLE, (object) [
            'timecreated' => time(),
            'userid' => $userid,
            'contextid' => $contextid,
            'actionname' => 'generate_text',
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'success' => 1,
            'attempts' => 1,
            'keysource' => usage_logger::KEY_SITE,
        ]);
    }

    /**
     * Record that somebody has been told their own limit was reached.
     *
     * @param int $userid Whose limit.
     */
    protected function notify(int $userid): void {
        global $DB;

        $DB->insert_record(budget_notifier::TABLE, (object) [
            'kind' => budget_notifier::KIND_USER,
            'subjectid' => $userid,
            'metric' => spend_ledger::METRIC_COST,
            'limitamount' => 100.0,
            'threshold' => 100,
            'timenotified' => time(),
        ]);
    }

    public function test_a_request_made_in_somebodys_own_context_still_names_them(): void {
        // A request made outside any course is recorded against the asker's own user
        // context, which is what the media web services produce when no context is
        // given. Looking there only for keys and summaries missed anybody whose usage
        // had not been summarised yet -- which is everybody, until the task first runs.
        $user = $this->getDataGenerator()->create_user();
        $usercontext = \context_user::instance((int) $user->id);
        $this->log((int) $user->id, (int) $usercontext->id);

        $userlist = new userlist($usercontext, 'aiprovider_router');
        provider::get_users_in_context($userlist);

        $this->assertSame([(int) $user->id], $userlist->get_userids());
    }

    public function test_being_told_a_limit_was_reached_is_found_in_the_persons_own_context(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->notify((int) $user->id);

        $contexts = provider::get_contexts_for_userid((int) $user->id)->get_contextids();

        $this->assertContainsEquals(\context_user::instance((int) $user->id)->id, $contexts);
    }

    public function test_a_deletion_request_takes_away_what_was_said_about_their_limit(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $usercontext = \context_user::instance((int) $user->id);
        $this->log((int) $user->id, (int) $usercontext->id);
        $this->notify((int) $user->id);

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'aiprovider_router',
            [(int) $usercontext->id],
        ));

        // Left behind, the row is personal data nothing can find again: the person has
        // no other data, so they no longer appear in any context.
        $this->assertSame(0, $DB->count_records(budget_notifier::TABLE));
        $this->assertCount(0, provider::get_contexts_for_userid((int) $user->id)->get_contextids());
    }

    public function test_a_limit_somebody_put_on_their_own_key_goes_with_the_key(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $saved = $this->keys->save(key::SCOPE_USER, (int) $user->id, 3, 'sk-their-own-ab');
        $DB->insert_record(budget_notifier::TABLE, (object) [
            'kind' => budget_notifier::KIND_KEY,
            'subjectid' => (int) $saved->get('id'),
            'metric' => spend_ledger::METRIC_COST,
            'limitamount' => 20.0,
            'threshold' => 100,
            'timenotified' => time(),
        ]);

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'aiprovider_router',
            [\context_user::instance((int) $user->id)->id],
        ));

        $this->assertSame(0, $DB->count_records(budget_notifier::TABLE));
    }

    public function test_only_the_course_that_was_approved_forgets_who_entered_its_key(): void {
        global $DB;

        $approved = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);
        $first = $this->keys->save(key::SCOPE_COURSE, (int) $approved->id, 3, 'sk-course-one-a');
        $second = $this->keys->save(key::SCOPE_COURSE, (int) $other->id, 3, 'sk-course-two-b');
        $DB->set_field(key::TABLE, 'usermodified', $teacher->id, ['id' => $first->get('id')]);
        $DB->set_field(key::TABLE, 'usermodified', $teacher->id, ['id' => $second->get('id')]);

        provider::delete_data_for_user(new approved_contextlist(
            $teacher,
            'aiprovider_router',
            [\context_course::instance((int) $approved->id)->id],
        ));

        // A deletion request approves particular contexts. The other course was not
        // among them, and its record of who entered its key was not this request's to
        // take away.
        $this->assertSame(0, (int) $DB->get_field(key::TABLE, 'usermodified', ['id' => $first->get('id')]));
        $this->assertSame(
            (int) $teacher->id,
            (int) $DB->get_field(key::TABLE, 'usermodified', ['id' => $second->get('id')]),
        );
    }

    public function test_the_same_holds_when_several_users_are_removed_at_once(): void {
        global $DB;

        $approved = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);
        $elsewhere = $this->keys->save(key::SCOPE_COURSE, (int) $other->id, 3, 'sk-course-two-b');
        $DB->set_field(key::TABLE, 'usermodified', $teacher->id, ['id' => $elsewhere->get('id')]);

        $userlist = new approved_userlist(
            \context_course::instance((int) $approved->id),
            'aiprovider_router',
            [(int) $teacher->id],
        );
        provider::delete_data_for_users($userlist);

        $this->assertSame(
            (int) $teacher->id,
            (int) $DB->get_field(key::TABLE, 'usermodified', ['id' => $elsewhere->get('id')]),
        );
    }

    public function test_emptying_a_course_takes_its_key(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->keys->save(key::SCOPE_COURSE, (int) $course->id, 3, 'sk-course-efgh');

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));

        $this->assertSame(0, $DB->count_records(key::TABLE));
    }
}
