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

/**
 * Tests for what somebody is offered against a key of theirs.
 *
 * The one that matters is removal. A key is a secret its owner handed over, and a site
 * that later narrows who may bring one must not thereby leave somebody holding a key
 * they can no longer take back.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(key_formatter::class)]
final class key_formatter_test extends \advanced_testcase {
    /** @var key The key being shown. */
    protected key $key;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->key = (new key_repository($DB))->save(key::SCOPE_USER, 7, 3, 'sk-a-key-wxyz');
    }

    public function test_somebody_who_may_still_bring_one_is_offered_everything(): void {
        $actions = key_formatter::actions(new \moodle_url('/ai/provider/router/keys.php'), $this->key);

        $this->assertStringContainsString('action=test', $actions);
        $this->assertStringContainsString('action=cap', $actions);
        $this->assertStringContainsString('action=delete', $actions);
    }

    public function test_somebody_who_may_not_can_still_take_their_key_back(): void {
        $actions = key_formatter::actions(
            new \moodle_url('/ai/provider/router/keys.php'),
            $this->key,
            false,
        );

        // Testing spends money on the key and capping says how much more it may spend.
        // Neither means anything for a key that will not be used again.
        $this->assertStringNotContainsString('action=test', $actions);
        $this->assertStringNotContainsString('action=cap', $actions);
        // Taking it back does.
        $this->assertStringContainsString('action=delete', $actions);
    }

    public function test_the_table_carries_the_same_answer_through(): void {
        $table = key_formatter::table(
            [3 => $this->key],
            [3 => 'Target one'],
            new \moodle_url('/ai/provider/router/keys.php'),
            null,
            'JPY',
            false,
        );

        $this->assertStringNotContainsString('action=test', $table->data[0][4]);
        $this->assertStringContainsString('action=delete', $table->data[0][4]);
    }
}
