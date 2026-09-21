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

namespace local_airouter;

/**
 * Tests for what happens to a course's key when the course is deleted.
 *
 * What a course spent is history and stays. Its key is not history: it is a secret this
 * site can still decrypt, for an account somebody is still paying for, and once the
 * course is gone nothing can use it, no screen can reach it -- the key screen is a page
 * in a course -- and Moodle's privacy tools cannot find it, because they find a course
 * key through the course context.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(hook_listener::class)]
final class course_deletion_test extends \advanced_testcase {
    /** @var key_repository The key store. */
    protected key_repository $keys;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->keys = new key_repository($DB);
    }

    public function test_the_key_a_course_paid_with_goes_when_the_course_does(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->keys->save(key::SCOPE_COURSE, (int) $course->id, 3, 'the-course-key-aaaa');

        // Moodle's own deletion, not the privacy tools: this is the way a course is
        // actually removed, and until now it was the way a key was actually kept.
        delete_course($course, false);

        $this->assertNull($this->keys->find(key::SCOPE_COURSE, (int) $course->id, 3));
    }

    public function test_nobody_elses_key_is_taken_with_it(): void {
        $going = $this->getDataGenerator()->create_course();
        $staying = $this->getDataGenerator()->create_course();
        $this->keys->save(key::SCOPE_COURSE, (int) $going->id, 3, 'the-doomed-key-aaaa');
        $this->keys->save(key::SCOPE_COURSE, (int) $staying->id, 3, 'the-surviving-key-b');
        // A person whose id happens to match the course being deleted.
        $this->keys->save(key::SCOPE_USER, (int) $going->id, 3, 'somebodys-own-key-c');

        delete_course($going, false);

        $this->assertNotNull($this->keys->find(key::SCOPE_COURSE, (int) $staying->id, 3));
        $this->assertNotNull($this->keys->find(key::SCOPE_USER, (int) $going->id, 3));
    }

    public function test_what_the_course_spent_is_history_and_stays(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record(usage_logger::TABLE, (object) [
            'timecreated' => time(),
            'userid' => 5,
            'courseid' => (int) $course->id,
            'contextid' => \context_course::instance((int) $course->id)->id,
            'actionname' => 'generate_text',
            'targetid' => 3,
            'targetname' => 'Target',
            'targetprovider' => 'aiprovider_openai',
            'success' => 1,
            'attempts' => 1,
            'keysource' => usage_logger::KEY_SITE,
        ]);

        delete_course($course, false);

        // Removing a course does not unspend the money it spent.
        $this->assertSame(1, $DB->count_records(usage_logger::TABLE, ['courseid' => (int) $course->id]));
    }
}
