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

use local_airouter\record\ledger;

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
        $this->assertSame($first->get_wallet(), $second->get_wallet(), 'Registered again is renewed, not moved.');
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
    public function test_a_new_key_opens_a_wallet_that_remembers_it_without_holding_it(): void {
        global $DB;

        $saved = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');

        $wallet = $DB->get_record(key_repository::WALLET_TABLE, ['id' => $saved->get_wallet()], '*', MUST_EXIST);
        $this->assertSame(key::SCOPE_USER, $wallet->scope);
        $this->assertSame(7, (int) $wallet->scopeid);
        $this->assertSame(3, (int) $wallet->targetid);
        $this->assertSame('aaaa', $wallet->hint);
        $this->assertSame(0, (int) $wallet->timereleased);
        // A hash, keyed and one way: nothing kept is the key or can become it.
        $held = $DB->get_records(key_repository::WALLET_KEY_TABLE, ['walletid' => $wallet->id]);
        $this->assertCount(1, $held);
        $hash = reset($held)->keyhash;
        $this->assertSame(64, strlen($hash));
        $this->assertStringNotContainsString('sk-first-key-aaaa', json_encode([$wallet, $held]));
        $this->assertNotSame(hash('sha256', 'sk-first-key-aaaa'), $hash, 'Keyed, so not the plain hash.');
    }

    public function test_replacing_within_the_same_account_keeps_the_wallet_and_the_limit(): void {
        global $DB;
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->set_cap($first, 25.0, ledger::PERIOD_MONTH, 30);

        $second = $this->repository->replace($first, 'sk-second-key-bbbb', true, true);

        $this->assertSame((int) $first->get('id'), (int) $second->get('id'));
        $this->assertSame($first->get_wallet(), $second->get_wallet());
        $this->assertSame(25.0, $second->get_cap_amount());
        $this->assertSame('sk-second-key-bbbb', $this->repository->reveal($second));
        $wallet = $DB->get_record(key_repository::WALLET_TABLE, ['id' => $second->get_wallet()]);
        $this->assertSame('bbbb', $wallet->hint, 'The wallet follows the key it holds now.');
        $this->assertSame(0, (int) $wallet->timereleased);
    }

    public function test_replacing_with_a_key_for_another_account_opens_a_new_wallet_and_releases_the_old(): void {
        global $DB;
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $before = $first->get_wallet();

        $second = $this->repository->replace($first, 'sk-second-key-bbbb', false);

        $this->assertSame((int) $first->get('id'), (int) $second->get('id'), 'The same key row, moved.');
        $this->assertNotSame($before, $second->get_wallet());
        $old = $DB->get_record(key_repository::WALLET_TABLE, ['id' => $before]);
        $this->assertGreaterThan(0, (int) $old->timereleased);
        $this->assertSame('aaaa', $old->hint, 'The old wallet remembers the key it last held.');
        $new = $DB->get_record(key_repository::WALLET_TABLE, ['id' => $second->get_wallet()]);
        $this->assertSame(0, (int) $new->timereleased);
        $this->assertSame('bbbb', $new->hint);
    }

    public function test_the_limit_stays_or_goes_as_the_owner_says(): void {
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->set_cap($first, 25.0, ledger::PERIOD_MONTH, 30);

        $dropped = $this->repository->replace($first, 'sk-second-key-bbbb', true, false);
        $this->assertFalse($dropped->has_cap());

        $this->repository->set_cap($dropped, 30.0, ledger::PERIOD_ROLLING, 7);
        $kept = $this->repository->replace($dropped, 'sk-third-key-cccc', false, true);
        $this->assertSame(30.0, $kept->get_cap_amount());
        $this->assertSame(ledger::PERIOD_ROLLING, $kept->get_cap_period());
    }

    public function test_the_same_key_entered_again_is_the_same_account_whatever_was_answered(): void {
        global $DB;
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->set_cap($first, 25.0, ledger::PERIOD_MONTH, 30);

        // Nothing answered, because nothing is asked; and "another account" would be
        // wrong, since the same key opens the same account.
        $unasked = $this->repository->replace($first, 'sk-first-key-aaaa', null);
        $this->assertSame($first->get_wallet(), $unasked->get_wallet());
        $this->assertSame(25.0, $unasked->get_cap_amount());
        $contradicted = $this->repository->replace($first, ' sk-first-key-aaaa ', false);
        $this->assertSame($first->get_wallet(), $contradicted->get_wallet());
        $this->assertSame(1, $DB->count_records(key_repository::WALLET_TABLE));
    }

    public function test_replacing_with_another_key_has_to_say_whose_account_it_is(): void {
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');

        $this->expectException(\coding_exception::class);
        $this->repository->replace($first, 'sk-second-key-bbbb', null);
    }

    public function test_replacing_a_key_that_carries_a_limit_has_to_say_whether_the_limit_stays(): void {
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->set_cap($first, 25.0, ledger::PERIOD_MONTH, 30);

        $this->expectException(\coding_exception::class);
        $this->repository->replace($first, 'sk-second-key-bbbb', true, null);
    }

    public function test_removing_a_key_keeps_its_wallet_released_and_forgets_what_was_said_about_it(): void {
        global $DB;
        $saved = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $DB->insert_record(budget_notifier::TABLE, (object) [
            'kind' => budget_notifier::KIND_KEY,
            'subjectid' => (int) $saved->get('id'),
            'metric' => ledger::METRIC_COST,
            'limitamount' => 20.0,
            'threshold' => 100,
            'timenotified' => time(),
        ]);

        $this->repository->delete((int) $saved->get('id'));

        $this->assertSame(0, $DB->count_records(key::TABLE));
        $wallet = $DB->get_record(key_repository::WALLET_TABLE, ['id' => $saved->get_wallet()], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $wallet->timereleased);
        $this->assertSame(0, $DB->count_records(budget_notifier::TABLE));
    }

    public function test_the_same_key_registered_again_after_being_removed_goes_on_with_its_wallet(): void {
        global $DB;
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->delete((int) $first->get('id'));
        $this->assertTrue($this->repository->is_known_secret(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa'));
        $this->assertFalse($this->repository->is_known_secret(key::SCOPE_USER, 7, 3, 'sk-second-key-bbbb'));
        $this->assertFalse(
            $this->repository->is_known_secret(key::SCOPE_USER, 8, 3, 'sk-first-key-aaaa'),
            'Not for somebody else.',
        );

        $again = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');

        $this->assertNotSame((int) $first->get('id'), (int) $again->get('id'));
        $this->assertSame($first->get_wallet(), $again->get_wallet());
        $this->assertSame(0, (int) $DB->get_field(key_repository::WALLET_TABLE, 'timereleased', ['id' => $again->get_wallet()]));
        $this->assertSame([], $this->repository->get_previous_wallets(key::SCOPE_USER, 7, 3), 'Held again, so not previous.');
    }

    public function test_a_key_is_known_to_the_wallet_that_holds_it_and_to_nobody_else(): void {
        $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');

        $this->assertTrue($this->repository->is_known_secret(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa'));
        $this->assertFalse($this->repository->is_known_secret(key::SCOPE_USER, 7, 4, 'sk-first-key-aaaa'), 'Another target.');
        $this->assertFalse($this->repository->is_known_secret(key::SCOPE_COURSE, 7, 3, 'sk-first-key-aaaa'), 'Another subject.');
    }

    public function test_another_key_after_one_was_removed_opens_a_wallet_unless_told_which_to_go_on_with(): void {
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->delete((int) $first->get('id'));

        $other = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-second-key-bbbb');
        $this->assertNotSame($first->get_wallet(), $other->get_wallet());

        $this->repository->delete((int) $other->get('id'));
        $told = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-third-key-cccc', $first->get_wallet());
        $this->assertSame($first->get_wallet(), $told->get_wallet());
    }

    public function test_the_same_key_wins_over_a_choice_of_another_wallet(): void {
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->delete((int) $first->get('id'));
        $other = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-second-key-bbbb');
        $this->repository->delete((int) $other->get('id'));

        $again = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa', $other->get_wallet());

        $this->assertSame($first->get_wallet(), $again->get_wallet());
    }

    public function test_going_on_with_a_wallet_that_is_not_theirs_is_refused(): void {
        $theirs = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->delete((int) $theirs->get('id'));

        $this->expectException(\moodle_exception::class);
        $this->repository->save(key::SCOPE_USER, 8, 3, 'sk-somebody-elses', $theirs->get_wallet());
    }

    public function test_going_on_with_a_wallet_that_is_held_is_a_coding_error(): void {
        $held = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');

        $this->expectException(\coding_exception::class);
        $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-second-key-bbbb', $held->get_wallet());
    }

    public function test_previous_wallets_are_listed_newest_first_and_held_ones_not_at_all(): void {
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->delete((int) $first->get('id'));
        $second = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-second-key-bbbb');
        $this->repository->delete((int) $second->get('id'));
        $held = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-third-key-cccc');
        $this->repository->save(key::SCOPE_USER, 8, 3, 'sk-somebody-elses');

        $previous = $this->repository->get_previous_wallets(key::SCOPE_USER, 7, 3);

        $this->assertSame([$second->get_wallet(), $first->get_wallet()], array_map(fn($w) => (int) $w->id, $previous));
        $this->assertSame(['bbbb', 'aaaa'], array_column($previous, 'hint'));
        $this->assertNotContains($held->get_wallet(), array_map(fn($w) => (int) $w->id, $previous));
    }

    public function test_a_wallet_from_before_hashes_were_kept_learns_its_key_when_the_key_goes(): void {
        global $DB;
        $saved = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        // As the upgrade leaves a wallet: it did not decrypt the key to hash it.
        $DB->delete_records(key_repository::WALLET_KEY_TABLE, ['walletid' => $saved->get_wallet()]);
        $this->assertFalse($this->repository->is_known_secret(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa'));

        $this->repository->delete((int) $saved->get('id'));

        $this->assertTrue($this->repository->is_known_secret(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa'));
        $again = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->assertSame($saved->get_wallet(), $again->get_wallet());
    }

    public function test_removing_a_persons_keys_takes_their_wallets_and_leaves_the_course_ones(): void {
        global $DB;
        $mine = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->delete((int) $mine->get('id'));
        $this->repository->save(key::SCOPE_USER, 7, 4, 'sk-second-key-bbbb');
        $course = $this->repository->save(key::SCOPE_COURSE, 42, 3, 'the-course-key-cccc');

        $this->assertSame(1, $this->repository->delete_for_user(7));

        $this->assertSame(0, $DB->count_records(key_repository::WALLET_TABLE, ['scope' => key::SCOPE_USER, 'scopeid' => 7]));
        $this->assertTrue($DB->record_exists(key_repository::WALLET_TABLE, ['id' => $course->get_wallet()]));
    }

    public function test_a_course_being_emptied_takes_its_wallets_with_it(): void {
        global $DB;
        $gone = $this->repository->save(key::SCOPE_COURSE, 42, 3, 'the-course-key-bbbb');
        $this->repository->delete((int) $gone->get('id'));
        $this->repository->save(key::SCOPE_COURSE, 42, 4, 'another-key-cccc');
        $kept = $this->repository->save(key::SCOPE_COURSE, 43, 3, 'a-third-key-dddd');

        $this->repository->delete_for_course(42);

        $this->assertSame(0, $DB->count_records(key_repository::WALLET_TABLE, ['scope' => key::SCOPE_COURSE, 'scopeid' => 42]));
        $this->assertTrue($DB->record_exists(key_repository::WALLET_TABLE, ['id' => $kept->get_wallet()]));
    }
    public function test_a_limit_set_from_a_stale_screen_does_not_put_the_old_key_back(): void {
        // R8-01. The limit screen read the key, and while it was open the key was
        // replaced by one for another account. Saving the object as read would put
        // the old secret and the old wallet back, and the limit would land on the
        // wrong account. The limit is refused instead, and the owner told.
        $stale = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $before = $stale->get_wallet();
        $current = $this->repository->replace($this->repository->get((int) $stale->get('id')), 'sk-second-key-bbbb', false);

        $this->assertFalse($this->repository->set_cap($stale, 8.0, ledger::PERIOD_MONTH, 30));

        $stored = $this->repository->get((int) $stale->get('id'));
        $this->assertSame($current->get_wallet(), $stored->get_wallet());
        $this->assertNotSame($before, $stored->get_wallet());
        $this->assertSame('sk-second-key-bbbb', $this->repository->reveal($stored));
        $this->assertFalse($stored->has_cap());

        // Read again after the replacement, the same limit is recorded.
        $this->assertTrue($this->repository->set_cap($stored, 8.0, ledger::PERIOD_MONTH, 30));
        $this->assertSame(8.0, $this->repository->get((int) $stale->get('id'))->get_cap_amount());
    }

    public function test_a_limit_set_while_the_key_is_renewed_in_its_wallet_still_lands(): void {
        $stale = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->replace($this->repository->get((int) $stale->get('id')), 'sk-second-key-bbbb', true);

        // The same wallet, so the same account: the limit is about it either way.
        $this->assertTrue($this->repository->set_cap($stale, 8.0, ledger::PERIOD_MONTH, 30));
        $stored = $this->repository->get((int) $stale->get('id'));
        $this->assertSame(8.0, $stored->get_cap_amount());
        $this->assertSame('sk-second-key-bbbb', $this->repository->reveal($stored), 'The renewal is not undone.');
    }

    public function test_a_replacement_from_a_stale_screen_does_not_put_an_old_limit_back(): void {
        // The other way round: the replace screen read the key, and the limit was
        // changed on another screen meanwhile. A replacement writes its own columns.
        $stale = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->repository->set_cap($this->repository->get((int) $stale->get('id')), 8.0, ledger::PERIOD_MONTH, 30);

        $this->repository->replace($stale, 'sk-second-key-bbbb', true, true);

        $stored = $this->repository->get((int) $stale->get('id'));
        $this->assertSame(8.0, $stored->get_cap_amount());
        $this->assertSame('sk-second-key-bbbb', $this->repository->reveal($stored));
    }

    public function test_replacing_with_a_key_an_earlier_wallet_held_goes_back_to_that_wallet(): void {
        // R8-02. Whatever was answered: the same key is the same account.
        global $DB;
        foreach ([true, false] as $answer) {
            $DB->delete_records(key::TABLE);
            $DB->delete_records(key_repository::WALLET_KEY_TABLE);
            $DB->delete_records(key_repository::WALLET_TABLE);
            $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
            $wallet = $first->get_wallet();
            $this->repository->replace($first, 'sk-second-key-bbbb', false);
            $this->assertNotSame($wallet, $first->get_wallet());

            $back = $this->repository->replace($first, 'sk-first-key-aaaa', $answer);

            $this->assertSame($wallet, $back->get_wallet(), 'Answered ' . json_encode($answer));
            $this->assertSame(0, (int) $DB->get_field(key_repository::WALLET_TABLE, 'timereleased', ['id' => $wallet]));
            $this->assertSame(2, $DB->count_records(key_repository::WALLET_TABLE), 'No third wallet.');
        }
    }

    public function test_a_wallet_remembers_every_key_it_has_held(): void {
        // R8-03. Renewed within the wallet, then removed: the earlier key is still
        // the wallet's, and the later one too.
        $first = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $wallet = $first->get_wallet();
        $this->repository->replace($first, 'sk-second-key-bbbb', true);
        $this->repository->delete((int) $first->get('id'));

        $earlier = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $this->assertSame($wallet, $earlier->get_wallet());
        $this->repository->delete((int) $earlier->get('id'));
        $later = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-second-key-bbbb');
        $this->assertSame($wallet, $later->get_wallet());
    }

    public function test_a_replacement_that_fails_leaves_the_wallets_as_they_were(): void {
        // R8-04. The old wallet released and a new one opened, and then the key not
        // written: two states that cannot both be true. One transaction, or nothing.
        global $DB;
        $this->preventResetByRollback();
        $key = $this->repository->save(key::SCOPE_USER, 7, 3, 'sk-first-key-aaaa');
        $before = $DB->get_records(key_repository::WALLET_TABLE);
        $broken = new class ($DB) extends key_repository {
            #[\Override]
            protected function write(key $record, string $secret, int $walletid, bool $dropcap = false): void {
                throw new \RuntimeException('the key could not be written');
            }
        };

        try {
            $broken->replace($key, 'sk-second-key-bbbb', false);
            $this->fail('The failure should have surfaced.');
        } catch (\RuntimeException $e) {
            $this->assertSame('the key could not be written', $e->getMessage());
        }

        $this->assertEquals($before, $DB->get_records(key_repository::WALLET_TABLE));
        $this->assertSame('sk-first-key-aaaa', $this->repository->reveal($this->repository->get((int) $key->get('id'))));
    }
}
