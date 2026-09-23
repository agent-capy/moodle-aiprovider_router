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
 * Tests for what somebody is offered against a key of theirs.
 *
 * The one that matters is removal. A key is a secret its owner handed over, and a site
 * that later narrows who may bring one must not thereby leave somebody holding a key
 * they can no longer take back.
 *
 * @package    local_airouter
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
        $actions = key_formatter::actions(new \moodle_url('/local/airouter/keys.php'), $this->key);

        $this->assertStringContainsString('action=test', $actions);
        $this->assertStringContainsString('action=cap', $actions);
        $this->assertStringContainsString('action=delete', $actions);
    }

    public function test_somebody_who_may_not_can_still_take_their_key_back(): void {
        $actions = key_formatter::actions(
            new \moodle_url('/local/airouter/keys.php'),
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

    public function test_a_limit_is_shown_beside_what_was_spent_against_it(): void {
        global $DB;

        (new key_repository($DB))->set_cap($this->key, 2000.0, spend_ledger::PERIOD_MONTH, 30);
        $this->spend(1000.0, 'USD');

        $html = key_formatter::cap($this->key, new spend_ledger($DB, null, false), 'USD');

        $this->assertStringContainsString(format_float(1000.0, 2, true) . ' USD', $html);
        $this->assertStringContainsString('progress-bar', $html);
    }

    public function test_money_spent_in_another_currency_is_not_relabelled_as_this_one(): void {
        global $DB;

        // The rates, and so the limit, are in dollars, and this was recorded in yen.
        // Handing the limit's currency to the figure would print 1000.00 USD for
        // 1000 yen, next to the limit, as though the two could be compared.
        (new key_repository($DB))->set_cap($this->key, 2000.0, spend_ledger::PERIOD_MONTH, 30);
        $this->spend(1000.0, 'JPY');
        $this->rate_in('USD');

        $html = key_formatter::cap($this->key, new spend_ledger($DB, null, false), 'USD');

        $this->assertStringContainsString(format_float(1000.0, 2, true) . ' JPY', $html);
        $this->assertStringNotContainsString(format_float(1000.0, 2, true) . ' USD', $html);
        // No bar either: a share worked out from two currencies is about nothing.
        $this->assertStringNotContainsString('progress-bar', $html);
    }

    public function test_a_key_is_not_stopped_by_money_counted_in_another_currency(): void {
        global $DB;

        (new key_repository($DB))->set_cap($this->key, 500.0, spend_ledger::PERIOD_MONTH, 30);
        $this->spend(1000.0, 'JPY');
        $this->rate_in('USD');

        // 1000 yen is not 1000 dollars, and it is not over a limit of 500 dollars
        // either. The direction for somebody's own key is to keep using it.
        $this->assertFalse($this->key->is_spent(new spend_ledger($DB, null, false), time()));
    }

    /**
     * Enter a rate, so that the limits on this site are read as figures in its currency.
     *
     * @param string $currency What the rate is in.
     */
    protected function rate_in(string $currency): void {
        $rate = new price();
        $rate->set('provider', 'aiprovider_openai');
        $rate->set('currency', $currency);
        $rate->set('promptrate', 1.0);
        $rate->create();
    }

    /**
     * Record something spent against the key under test.
     *
     * @param float $cost What it cost.
     * @param string $currency What that is in.
     */
    protected function spend(float $cost, string $currency): void {
        global $DB;

        $DB->insert_record(usage_logger::TABLE, (object) [
            // A minute ago. The period a limit is measured over ends at the moment it
            // is asked about, and that moment is not counted.
            'timecreated' => time() - MINSECS,
            'userid' => 7,
            'contextid' => 0,
            'courseid' => null,
            'actionname' => 'generate_text',
            'targetid' => 3,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'currency' => $currency,
            'success' => 1,
            'attempts' => 1,
            'prompttokens' => 100,
            'completiontokens' => 50,
            'cost' => $cost,
            'keysource' => 'user',
            'keyid' => (int) $this->key->get('id'),
        ]);
    }

    public function test_the_table_carries_the_same_answer_through(): void {
        $table = key_formatter::table(
            [3 => $this->key],
            [3 => 'Target one'],
            new \moodle_url('/local/airouter/keys.php'),
            null,
            'JPY',
            false,
        );

        $this->assertStringNotContainsString('action=test', $table->data[0][4]);
        $this->assertStringContainsString('action=delete', $table->data[0][4]);
    }
}
