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
 * Tests for the store that holds keys people have brought.
 *
 * Two things are being guarded here. One is that the key is never sitting in the database
 * as somebody typed it. The other is the line between a key that is absent and a key that
 * cannot be read: those lead to opposite behaviour, and telling them apart is the whole
 * of the difference between a rule quietly moving on and a request being stopped.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(key::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(key_repository::class)]
final class key_repository_test extends \advanced_testcase {
    /** @var key_repository The store under test. */
    protected key_repository $repository;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->repository = new key_repository($DB);
    }

    public function test_a_key_is_stored_encrypted_and_read_back(): void {
        global $DB;

        $saved = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-secret-value-1234');

        $stored = $DB->get_field(key::TABLE, 'secret', ['id' => $saved->get('id')]);
        $this->assertStringNotContainsString('sk-secret-value-1234', $stored);
        $this->assertSame('sk-secret-value-1234', $this->repository->reveal($saved));
    }

    public function test_a_key_too_short_to_hint_at_gets_no_hint(): void {
        // The hint is the tail of something longer. Where the key is no longer than
        // the hint, the tail is the whole key, and the promise that the plaintext is
        // never kept or shown again would be broken by the thing meant to keep it.
        $this->assertSame('', key::hint_of('abcd'));
        $this->assertSame('', key::hint_of('ab'));
        $this->assertSame('bcde', key::hint_of('abcde'));
    }

    public function test_the_hint_is_the_end_of_the_key_not_the_start(): void {
        $saved = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-proj-abcdefgh');

        // The beginning of a provider key is usually a fixed prefix, which identifies
        // nothing and gives away what kind of key it is.
        $this->assertSame('efgh', $saved->get('hint'));
        $this->assertStringNotContainsString('sk-', $saved->get('hint'));
    }

    public function test_registering_again_replaces_the_key_and_forgets_the_last_test(): void {
        global $DB;

        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'first-key-aaaa');
        $this->repository->record_verification($first, key::VERIFY_OK, 1000);

        $second = $this->repository->save(key::SCOPE_USER, 7, 3, 'second-key-bbbb');

        $this->assertSame((int) $first->get('id'), (int) $second->get('id'));
        $this->assertSame(1, $DB->count_records(key::TABLE));
        $this->assertSame('second-key-bbbb', $this->repository->reveal($second));
        // Whatever the old key managed says nothing about the new one.
        $this->assertSame(0, (int) $second->get('timeverified'));
        $this->assertNull($second->get('verifystatus'));
    }

    public function test_a_verdict_never_writes_back_the_key_it_was_read_with(): void {
        global $DB;

        // What happens in between is the point. Testing a key puts a request to
        // somebody else's server, and while it is out another administrator can rotate
        // the key or lower what it may spend. The object being held was read before any
        // of that, and saving it whole afterwards would put all of it back.
        $tested = $this->repository->save(key::SCOPE_COURSE, 42, 3, 'the-old-key-aaaa');
        $this->repository->set_cap($tested, 100.0, spend_ledger::PERIOD_MONTH, 30);

        $meanwhile = $this->repository->save(key::SCOPE_COURSE, 42, 3, 'the-new-key-bbbb');
        $this->repository->set_cap($meanwhile, 10.0, spend_ledger::PERIOD_MONTH, 30);

        $this->repository->record_verification($tested, key::VERIFY_OK, 1000);

        $now = $this->repository->find(key::SCOPE_COURSE, 42, 3);
        $this->assertSame('the-new-key-bbbb', $this->repository->reveal($now));
        $this->assertSame(10.0, $now->get_cap_amount());
        // And the verdict itself is not attached to a key it was never about.
        $this->assertNull($now->get('verifystatus'));
        $this->assertSame(1, $DB->count_records(key::TABLE));
    }

    public function test_a_verdict_is_recorded_when_the_key_is_still_the_one_tested(): void {
        $key = $this->repository->save(key::SCOPE_USER, 7, 3, 'a-steady-key-cccc');

        $this->repository->record_verification($key, key::VERIFY_REJECTED, 1000);

        $now = $this->repository->find(key::SCOPE_USER, 7, 3);
        $this->assertSame(key::VERIFY_REJECTED, $now->get('verifystatus'));
        $this->assertSame(1000, (int) $now->get('timeverified'));
    }

    public function test_a_key_starts_with_no_limit_on_it(): void {
        $saved = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-a-key-abcd');

        $this->assertFalse($saved->has_cap());
        $this->assertSame(0.0, $saved->get_cap_amount());
        $this->assertSame(spend_ledger::PERIOD_MONTH, $saved->get_cap_period());
    }

    public function test_an_owner_can_limit_their_own_key_and_lift_it_again(): void {
        $saved = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-a-key-abcd');

        $this->repository->set_cap($saved, 25.0, spend_ledger::PERIOD_ROLLING, 7);
        $reloaded = $this->repository->find(key::SCOPE_USER, 7, 3);
        $this->assertTrue($reloaded->has_cap());
        $this->assertSame(25.0, $reloaded->get_cap_amount());
        $this->assertSame(spend_ledger::PERIOD_ROLLING, $reloaded->get_cap_period());
        $this->assertSame(7, $reloaded->get_cap_days());

        // Emptying the amount is how somebody says they want no limit, which is not
        // the same as a limit of nothing.
        $this->repository->set_cap($reloaded, null, spend_ledger::PERIOD_MONTH, 30);
        $this->assertFalse($this->repository->find(key::SCOPE_USER, 7, 3)->has_cap());
    }

    public function test_replacing_a_key_keeps_the_limit_its_owner_set(): void {
        $saved = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-the-first-aaaa');
        $this->repository->set_cap($saved, 25.0, spend_ledger::PERIOD_MONTH, 30);

        $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-the-second-bbbb');

        // Replacing a key is not a new month. The provider carries on billing the same
        // account, and the ledger counts the same spending, so clearing the limit here
        // would quietly undo it.
        $reloaded = $this->repository->find(key::SCOPE_USER, 7, 3);
        $this->assertSame(25.0, $reloaded->get_cap_amount());
        $this->assertSame('sk-the-second-bbbb', $this->repository->reveal($reloaded));
    }

    public function test_the_same_target_can_hold_a_key_for_each_subject(): void {
        $this->repository->save(key::SCOPE_USER, 7, 3, 'user-seven-key');
        $this->repository->save(key::SCOPE_USER, 8, 3, 'user-eight-key');
        $this->repository->save(key::SCOPE_COURSE, 7, 3, 'course-seven-key');

        $this->assertSame('user-seven-key', $this->repository->reveal(
            $this->repository->find(key::SCOPE_USER, 7, 3),
        ));
        $this->assertSame('course-seven-key', $this->repository->reveal(
            $this->repository->find(key::SCOPE_COURSE, 7, 3),
        ));
    }

    public function test_no_key_registered_is_simply_nothing(): void {
        $this->assertNull($this->repository->find(key::SCOPE_USER, 7, 3));
    }

    public function test_a_key_that_cannot_be_decrypted_is_not_the_same_as_no_key(): void {
        global $DB;

        $saved = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-secret-value-1234');
        // What a site restored without its encryption key file looks like.
        $DB->set_field(key::TABLE, 'secret', 'nonsense', ['id' => $saved->get('id')]);
        $broken = $this->repository->get((int) $saved->get('id'));

        // The row is still there, and the rule engine will be told the key is unusable
        // rather than absent, which is what stops the request instead of falling through.
        $this->assertNotNull($broken);
        $this->assertNull($this->repository->reveal($broken));
        $this->assertDebuggingCalled();
    }

    public function test_counting_unreadable_keys_does_not_fill_the_log(): void {
        global $DB;

        $good = $this->repository->save(key::SCOPE_USER, 7, 3, 'good-key-aaaa');
        $bad = $this->repository->save(key::SCOPE_USER, 8, 3, 'bad-key-bbbb');
        $DB->set_field(key::TABLE, 'secret', 'nonsense', ['id' => $bad->get('id')]);

        $unreadable = $this->repository->count_unreadable();

        $this->assertSame(1, $unreadable);
        $this->assertNotNull($this->repository->reveal($good));
        $this->assertDebuggingNotCalled();
    }

    public function test_removing_a_persons_keys_leaves_the_course_keys_they_entered(): void {
        global $DB, $USER;

        $this->setAdminUser();
        $this->repository->save(key::SCOPE_USER, (int) $USER->id, 3, 'my-own-key-aaaa');
        $coursekey = $this->repository->save(key::SCOPE_COURSE, 42, 3, 'the-course-key-bbbb');

        $removed = $this->repository->delete_for_user((int) $USER->id);

        $this->assertSame(1, $removed);
        // The course key belongs to the course. Removing it because the teacher who
        // entered it has gone would stop the AI for everybody enrolled.
        $this->assertTrue($DB->record_exists(key::TABLE, ['id' => $coursekey->get('id')]));
    }

    public function test_forgetting_who_entered_a_course_key_keeps_the_key(): void {
        global $DB, $USER;

        $this->setAdminUser();
        $userid = (int) $USER->id;
        $coursekey = $this->repository->save(key::SCOPE_COURSE, 42, 3, 'the-course-key-bbbb');
        $ownkey = $this->repository->save(key::SCOPE_USER, $userid, 4, 'my-own-key-aaaa');

        $this->repository->forget_registrar($userid);

        $this->assertSame(0, (int) $DB->get_field(key::TABLE, 'usermodified', ['id' => $coursekey->get('id')]));
        $this->assertSame('the-course-key-bbbb', $this->repository->reveal(
            $this->repository->get((int) $coursekey->get('id')),
        ));
        // Their own key is untouched by this: it is deleted outright instead.
        $this->assertSame($userid, (int) $DB->get_field(key::TABLE, 'usermodified', ['id' => $ownkey->get('id')]));
    }

    public function test_a_course_being_emptied_takes_its_key_with_it(): void {
        global $DB;

        $this->repository->save(key::SCOPE_COURSE, 42, 3, 'the-course-key-bbbb');
        $this->repository->save(key::SCOPE_COURSE, 43, 3, 'another-course-key-cccc');

        $this->repository->delete_for_course(42);

        $this->assertSame(1, $DB->count_records(key::TABLE));
        $this->assertNotNull($this->repository->find(key::SCOPE_COURSE, 43, 3));
    }

    public function test_a_key_has_to_say_whose_it_is(): void {
        $record = new key();
        $record->set('scope', 'somebody');
        $record->set('scopeid', 7);
        $record->set('targetid', 3);
        $record->set('secret', 'encrypted');

        $errors = $record->validate();

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('scope', $errors);
    }

    public function test_an_empty_key_is_refused(): void {
        $record = new key();
        $record->set('scope', key::SCOPE_USER);
        $record->set('scopeid', 7);
        $record->set('targetid', 3);
        $record->set('secret', '   ');

        $errors = $record->validate();

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('secret', $errors);
    }
}
