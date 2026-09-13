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

    public function test_emptying_a_course_takes_its_key(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->keys->save(key::SCOPE_COURSE, (int) $course->id, 3, 'sk-course-efgh');

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));

        $this->assertSame(0, $DB->count_records(key::TABLE));
    }
}
