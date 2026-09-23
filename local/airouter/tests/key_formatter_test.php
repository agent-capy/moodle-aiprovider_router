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

        $this->assertStringContainsString('action=replace', $actions);
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

        // Replacing, testing and capping a key all mean going on using it, and none
        // means anything for a key that will not be used again.
        $this->assertStringNotContainsString('action=replace', $actions);
        $this->assertStringNotContainsString('action=test', $actions);
        $this->assertStringNotContainsString('action=cap', $actions);
        // Taking it back does.
        $this->assertStringContainsString('action=delete', $actions);
    }

    public function test_a_limit_is_shown_beside_what_was_spent_against_it(): void {
        global $DB;

        (new key_repository($DB))->set_cap($this->key, 2000.0, ledger::PERIOD_MONTH, 30);
        $this->spend(1000.0, 'USD');
        $this->rate_in('USD');

        $html = key_formatter::cap($this->key, new ledger($DB, false), 'USD');

        $this->assertStringContainsString(format_float(1000.0, 2, true) . ' USD', $html);
        $this->assertStringContainsString('progress-bar', $html);
    }

    public function test_money_spent_in_another_currency_is_not_relabelled_as_this_one(): void {
        global $DB;

        // The rates, and so the limit, are in dollars, and this was recorded in yen.
        // Handing the limit's currency to the figure would print 1000.00 USD for
        // 1000 yen, next to the limit, as though the two could be compared.
        (new key_repository($DB))->set_cap($this->key, 2000.0, ledger::PERIOD_MONTH, 30);
        $this->spend(1000.0, 'JPY');
        $this->rate_in('USD');

        $html = key_formatter::cap($this->key, new ledger($DB, false), 'USD');

        $this->assertStringContainsString(format_float(1000.0, 2, true) . ' JPY', $html);
        $this->assertStringNotContainsString(format_float(1000.0, 2, true) . ' USD', $html);
        // No bar either: a share worked out from two currencies is about nothing.
        $this->assertStringNotContainsString('progress-bar', $html);
    }

    public function test_spending_counted_over_part_of_the_period_is_shown_as_a_floor(): void {
        global $DB;

        (new key_repository($DB))->set_cap($this->key, 2000.0, ledger::PERIOD_ROLLING, 30);
        $this->spend(1000.0, 'USD');
        $this->rate_in('USD');
        // The site discarded everything before five days ago.
        set_config(\local_airouter\record\summariser::HISTORY_SETTING, time() - 5 * DAYSECS, 'local_airouter');

        $html = key_formatter::cap($this->key, new ledger($DB, false), 'USD');

        $this->assertStringContainsString('at least ' . format_float(1000.0, 2, true) . ' USD', $html);
        $this->assertStringContainsString('progress-bar', $html, 'Still measured, still a bar.');
    }

    public function test_a_limit_the_summaries_do_not_cover_says_so(): void {
        global $DB;

        (new key_repository($DB))->set_cap($this->key, 2000.0, ledger::PERIOD_ROLLING, 30);
        // Shorter than the limit, which the screen would refuse: set another way.
        set_config(retention_policy::SUMMARY_SETTING, 7, 'local_airouter');

        $html = key_formatter::cap($this->key, null, 'USD');

        $this->assertStringContainsString('Looks back 30 days', $html);
        $this->assertStringContainsString('7', $html);
    }

    public function test_a_key_is_not_stopped_by_money_counted_in_another_currency(): void {
        global $DB;

        (new key_repository($DB))->set_cap($this->key, 500.0, ledger::PERIOD_MONTH, 30);
        $this->spend(1000.0, 'JPY');
        $this->rate_in('USD');

        // 1000 yen is not 1000 dollars, and it is not over a limit of 500 dollars
        // either. The direction for somebody's own key is to keep using it.
        $this->assertFalse($this->key->is_spent(new ledger($DB, false), time()));
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
     * Record something spent against the key under test, as the request and attempt records hold it.
     *
     * @param float $cost What it cost.
     * @param string $currency What that is in.
     */
    protected function spend(float $cost, string $currency): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        // A minute ago. The period a limit is measured over ends at the moment it
        // is asked about, and that moment is not counted.
        $when = time() - MINSECS;
        $request = $generator->create_request([
            'userid' => 7,
            'keysource' => 'user',
            'answeredby' => 3,
            'timestarted' => $when,
            'timeended' => $when,
        ]);
        $generator->create_attempt([
            'requestid' => $request->id,
            'targetid' => 3,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'keysource' => 'user',
            'keyid' => (int) $this->key->get('id'),
            'walletid' => $this->key->get_wallet(),
            'prompttokens' => 100,
            'completiontokens' => 50,
            'cost' => $cost,
            'currency' => $currency,
            'timestarted' => $when,
            'timeended' => $when,
        ]);
    }

    public function test_the_table_carries_the_same_answer_through(): void {
        $table = key_formatter::table(
            [3 => $this->key],
            [3 => 'Target one'],
            new \moodle_url('/local/airouter/keys.php'),
            null,
            [3 => 'JPY'],
            false,
        );

        $this->assertStringNotContainsString('action=test', $table->data[0][4]);
        $this->assertStringContainsString('action=delete', $table->data[0][4]);
    }
}
