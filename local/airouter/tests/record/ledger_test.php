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

namespace local_airouter\record;

use local_airouter\key;
use local_airouter\key_repository;
use local_airouter\price;
use local_airouter\price_book;
use local_airouter\rule;

/**
 * Tests for the ledger every limit is measured against.
 *
 * The questions that matter: whose money is counted, that a fact is counted once
 * whether or not it has been summarised, that money is one figure per provider and
 * never added across them, and that not knowing is told apart from knowing zero.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ledger::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(spend::class)]
final class ledger_test extends \advanced_testcase {
    /** @var ledger The ledger under test, reading the database every time. */
    private ledger $ledger;

    /** @var \local_airouter_generator The plugin's generator. */
    private $generator;

    /** @var int A fixed moment. */
    private int $now;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        self::setTimezone('UTC', 'UTC');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $this->ledger = new ledger($DB, false);
        $this->now = make_timestamp(2026, 9, 20, 10, 0, 0);
        $this->rate('aiprovider_mock', 'USD');
    }

    /**
     * Enter a rate for a provider, which is what gives it a currency.
     *
     * @param string $provider The provider component.
     * @param string $currency What it bills in.
     */
    private function rate(string $provider, string $currency): void {
        $rate = new price();
        $rate->set('provider', $provider);
        $rate->set('currency', $currency);
        $rate->set('promptrate', 1.0);
        $rate->create();
    }

    /**
     * A finished request with one call, as the records hold it.
     *
     * @param float|null $cost What the call cost, or null when no rate covered it.
     * @param array $fields Anything else: userid, courseid, keysource, keyid, walletid, targetid, targetprovider,
     *                      currency, ended.
     * @return \stdClass The request.
     */
    private function spend(?float $cost, array $fields = []): \stdClass {
        $ended = $fields['ended'] ?? $this->now - MINSECS;
        $keysource = $fields['keysource'] ?? rule::KEYSOURCE_SITE;
        $targetid = $fields['targetid'] ?? 1;
        $request = $this->generator->create_request([
            'userid' => $fields['userid'] ?? 5,
            'courseid' => $fields['courseid'] ?? null,
            'keysource' => $keysource,
            'answeredby' => $targetid,
            'timestarted' => $ended - 5,
            'timeended' => $ended,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id,
            'targetid' => $targetid,
            'targetprovider' => $fields['targetprovider'] ?? 'aiprovider_mock',
            'keysource' => $keysource,
            'keyid' => $fields['keyid'] ?? null,
            'walletid' => $fields['walletid'] ?? 0,
            'cost' => $cost,
            'currency' => $cost === null ? null : ($fields['currency'] ?? 'USD'),
            'timestarted' => $ended - 4,
            'timeended' => $ended,
        ]);

        return $request;
    }

    /**
     * The whole of the last week, as a period ending now.
     *
     * @return int[] From and to.
     */
    private function week(): array {
        return ledger::get_window(ledger::PERIOD_ROLLING, 7, $this->now);
    }

    public function test_a_site_budget_leaves_out_what_people_paid_for_themselves(): void {
        $this->spend(1.0);
        $this->spend(500.0, ['keysource' => rule::KEYSOURCE_USER]);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        // Somebody else spent that money, and the site's budget is the site's.
        $this->assertEqualsWithDelta(1.0, $spend->get_amount('aiprovider_mock'), 0.000001);
        $this->assertSame(1, $spend->requests);
    }

    public function test_spending_is_counted_once_whether_or_not_it_has_been_summarised(): void {
        global $DB;
        $this->spend(2.0, ['ended' => $this->now - 2 * DAYSECS]);
        $this->spend(3.0);
        [$from, $to] = $this->week();

        $before = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);
        (new summariser($DB))->run($this->now);
        $after = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertEqualsWithDelta(5.0, $before->get_amount('aiprovider_mock'), 0.000001);
        $this->assertEquals($before, $after, 'Applying moves nothing.');
        // The older day is summarised, as a row for the request and a row for the priced
        // call; today is not.
        $this->assertSame(2, $DB->count_records(summariser::TABLE));
        $this->assertSame(1, $DB->count_records(summariser::TABLE, ['currency' => 'USD']));
    }

    public function test_spending_survives_the_detail_being_purged(): void {
        global $DB;
        $this->spend(2.0, ['ended' => $this->now - 3 * DAYSECS]);
        set_config('logretentiondays', 1, 'local_airouter');
        [$from, $to] = $this->week();

        (new summariser($DB))->run($this->now);

        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE), 'The detail has gone.');
        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);
        $this->assertEqualsWithDelta(2.0, $spend->get_amount('aiprovider_mock'), 0.000001);
        $this->assertTrue($spend->is_known(ledger::METRIC_COST, 'aiprovider_mock'));
    }

    public function test_a_course_or_person_budget_counts_only_that_course_or_person(): void {
        $this->spend(1.0, ['courseid' => 7, 'userid' => 5]);
        $this->spend(10.0, ['courseid' => 9, 'userid' => 6]);
        [$from, $to] = $this->week();

        $this->assertEqualsWithDelta(
            1.0,
            $this->ledger->measure(ledger::SCOPE_COURSE, 7, $from, $to)->get_amount('aiprovider_mock'),
            0.000001,
        );
        $this->assertEqualsWithDelta(
            10.0,
            $this->ledger->measure(ledger::SCOPE_USER, 6, $from, $to)->get_amount('aiprovider_mock'),
            0.000001,
        );
        $this->assertSame(0, $this->ledger->measure(ledger::SCOPE_COURSE, 8, $from, $to)->requests);
    }

    public function test_a_period_nobody_priced_is_not_a_period_that_cost_nothing(): void {
        $this->spend(null);
        $this->spend(null);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertFalse($spend->is_known(ledger::METRIC_COST, 'aiprovider_mock'));
        $this->assertNull($spend->has_reached(1.0, ledger::METRIC_COST, 'aiprovider_mock'));
        // A count of requests is counted rather than worked out, so it is always known.
        $this->assertTrue($spend->is_known(ledger::METRIC_REQUESTS));
        $this->assertTrue($spend->has_reached(2.0, ledger::METRIC_REQUESTS));
    }

    public function test_a_provider_that_costs_nothing_is_a_known_zero(): void {
        $this->spend(0.0);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertTrue($spend->is_known(ledger::METRIC_COST, 'aiprovider_mock'));
        $this->assertFalse($spend->has_reached(1.0, ledger::METRIC_COST, 'aiprovider_mock'));
    }

    public function test_a_period_with_nothing_in_it_is_known_to_be_nothing(): void {
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertTrue($spend->is_known(ledger::METRIC_COST, 'aiprovider_mock'));
        $this->assertFalse($spend->has_reached(1.0, ledger::METRIC_COST, 'aiprovider_mock'));
        $this->assertSame([], $spend->providers);
    }

    public function test_a_partly_priced_period_says_so_and_still_weighs_what_it_knows(): void {
        $this->spend(8.0);
        $this->spend(null);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        // Not complete, and said so; but eight is known to have been spent, and a
        // limit of eight has been reached whatever the other call cost.
        $this->assertFalse($spend->is_complete('aiprovider_mock'));
        $this->assertSame(0.5, $spend->get_coverage('aiprovider_mock'));
        $this->assertTrue($spend->has_reached(8.0, ledger::METRIC_COST, 'aiprovider_mock'));
    }

    public function test_money_is_one_figure_per_provider_and_never_added_across_them(): void {
        $this->rate('aiprovider_sakuraaiengine', 'JPY');
        $this->spend(9.0);
        $this->spend(900.0, ['targetid' => 2, 'targetprovider' => 'aiprovider_sakuraaiengine', 'currency' => 'JPY']);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertSame(['aiprovider_mock', 'aiprovider_sakuraaiengine'], array_keys($spend->providers));
        $this->assertTrue($spend->has_reached(9.0, ledger::METRIC_COST, 'aiprovider_mock'));
        $this->assertFalse($spend->has_reached(10.0, ledger::METRIC_COST, 'aiprovider_mock'));
        $this->assertSame('JPY', $spend->get_currency('aiprovider_sakuraaiengine'));
        $this->assertTrue($spend->has_reached(900.0, ledger::METRIC_COST, 'aiprovider_sakuraaiengine'));
        // Requests are counted over everything, whoever answered.
        $this->assertSame(2, $spend->requests);
    }

    public function test_money_in_a_currency_the_providers_rates_are_not_in_is_not_weighed(): void {
        // Recorded in dollars, but the provider's rates say yen, so a limit on it is
        // in yen. Nothing here converts, and weighing 1000 dollars against a limit of
        // 100 yen compares two different things while looking like a comparison.
        $this->rate('aiprovider_sakuraaiengine', 'JPY');
        $this->spend(1000.0, ['targetprovider' => 'aiprovider_sakuraaiengine', 'currency' => 'USD']);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertFalse($spend->is_known(ledger::METRIC_COST, 'aiprovider_sakuraaiengine'));
        $this->assertNull($spend->has_reached(100.0, ledger::METRIC_COST, 'aiprovider_sakuraaiengine'));
        $this->assertSame('USD', $spend->get_currency('aiprovider_sakuraaiengine'), 'Shown as recorded, not weighed.');
    }

    public function test_a_provider_with_no_rates_has_no_currency_for_a_limit_to_be_in(): void {
        $this->spend(1.0, ['targetprovider' => 'aiprovider_unrated']);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertNull($spend->has_reached(1.0, ledger::METRIC_COST, 'aiprovider_unrated'));
    }

    public function test_reaching_the_limit_counts_as_reaching_it(): void {
        $this->spend(10.0);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertTrue($spend->has_reached(10.0, ledger::METRIC_COST, 'aiprovider_mock'));
        $this->assertSame(0.0, $spend->get_remaining(10.0, ledger::METRIC_COST, 'aiprovider_mock'));
    }

    public function test_a_rolling_period_counts_today_and_the_days_before_it(): void {
        [$from, $to] = ledger::get_window(ledger::PERIOD_ROLLING, 7, $this->now);

        $this->assertSame(summariser::add_days(summariser::day_of($this->now), -6), $from);
        $this->assertSame($this->now, $to);
        [$today] = ledger::get_window(ledger::PERIOD_ROLLING, 1, $this->now);
        $this->assertSame(summariser::day_of($this->now), $today, 'A period of one day is today.');
    }

    public function test_a_calendar_month_starts_on_the_first(): void {
        [$from] = ledger::get_window(ledger::PERIOD_MONTH, 30, $this->now);

        $this->assertSame(make_timestamp(2026, 9, 1, 0, 0, 0), $from);
        $this->spend(1.0, ['ended' => $from - HOURSECS]);
        $this->spend(2.0, ['ended' => $from + HOURSECS]);
        $this->assertEqualsWithDelta(
            2.0,
            $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $this->now)->get_amount('aiprovider_mock'),
            0.000001,
            'The day before the first is last month.',
        );
    }

    /**
     * Something spent on a key, charged to the key's wallet.
     *
     * @param float $cost What it cost.
     * @param key $key The key.
     * @param array $fields Anything else, as for spend().
     */
    private function spend_on(float $cost, key $key, array $fields = []): void {
        $this->spend($cost, $fields + [
            'keysource' => (string) $key->get('scope'),
            'keyid' => (int) $key->get('id'),
            'walletid' => $key->get_wallet(),
            'targetid' => (int) $key->get('targetid'),
        ]);
    }

    public function test_a_keys_spending_follows_its_wallet_through_a_renewal_within_the_account(): void {
        global $DB;
        $repository = new key_repository($DB);
        $first = $repository->save(key::SCOPE_USER, 5, 3, 'sk-first');
        $wallet = $first->get_wallet();
        $this->spend_on(4.0, $first);
        // Renewed at the provider halfway through the period: the same account, as
        // its owner said, so the same wallet and the limit goes on counting.
        $second = $repository->replace($first, 'sk-second', true);
        $this->spend_on(5.0, $second);
        // Somebody else's key at the same target, and this person's at another.
        $this->spend(100.0, ['keysource' => rule::KEYSOURCE_USER, 'userid' => 6, 'targetid' => 3, 'walletid' => 98]);
        $this->spend(100.0, ['keysource' => rule::KEYSOURCE_USER, 'targetid' => 4, 'walletid' => 99]);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure_key($second, $from, $to);

        $this->assertSame($wallet, $second->get_wallet());
        $this->assertEqualsWithDelta(9.0, $spend->get_amount('aiprovider_mock'), 0.000001);
        $this->assertSame('aiprovider_mock', $spend->sole_provider());
        $this->assertTrue($spend->has_reached(9.0, ledger::METRIC_COST, $spend->sole_provider()));
    }

    public function test_a_key_for_another_account_starts_counting_from_nothing(): void {
        global $DB;
        $repository = new key_repository($DB);
        $first = $repository->save(key::SCOPE_USER, 5, 3, 'sk-first');
        $wallet = $first->get_wallet();
        $this->spend_on(4.0, $first);
        // Another account, as its owner said. What the old key spent is not this
        // key's to count, though it is the same person at the same target.
        $second = $repository->replace($first, 'sk-second', false);
        $this->spend_on(5.0, $second);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure_key($second, $from, $to);

        $this->assertNotSame($wallet, $second->get_wallet());
        $this->assertEqualsWithDelta(5.0, $spend->get_amount('aiprovider_mock'), 0.000001);
    }

    public function test_a_keys_spending_is_read_from_the_summary_once_its_day_is_over(): void {
        global $DB;
        $repository = new key_repository($DB);
        $key = $repository->save(key::SCOPE_USER, 5, 3, 'sk-mine');
        $this->spend_on(4.0, $key, ['ended' => $this->now - DAYSECS]);
        $this->spend(100.0, [
            'keysource' => rule::KEYSOURCE_USER, 'targetid' => 3, 'walletid' => 98, 'ended' => $this->now - DAYSECS,
        ]);
        (new summariser($DB))->run($this->now);
        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE, ['applied' => 0]), 'Everything is in the summary.');
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure_key($key, $from, $to);

        $this->assertEqualsWithDelta(4.0, $spend->get_amount('aiprovider_mock'), 0.000001);
    }

    public function test_a_key_without_a_wallet_cannot_be_measured(): void {
        // Every stored key has one. Measuring by wallet zero would count what the
        // site paid for, and measuring nothing would let the key spend without limit.
        $key = new key(0, (object) [
            'id' => 1, 'scope' => key::SCOPE_USER, 'scopeid' => 5, 'targetid' => 3, 'walletid' => 0,
            'secret' => 'x', 'hint' => '', 'timeverified' => 0, 'verifystatus' => null,
            'capamount' => null, 'capperiod' => ledger::PERIOD_MONTH, 'capdays' => 30,
            'usermodified' => 0, 'timecreated' => 0, 'timemodified' => 0,
        ]);
        [$from, $to] = $this->week();

        $this->expectException(\coding_exception::class);
        $this->ledger->measure_key($key, $from, $to);
    }

    public function test_a_course_key_is_measured_by_its_wallet_whoever_in_the_course_spent_it(): void {
        global $DB;
        $key = (new key_repository($DB))->save(key::SCOPE_COURSE, 7, 3, 'sk-course');
        $this->spend_on(4.0, $key, ['courseid' => 7, 'userid' => 5]);
        $this->spend_on(6.0, $key, ['courseid' => 7, 'userid' => 6]);
        $this->spend(100.0, ['keysource' => rule::KEYSOURCE_COURSE, 'courseid' => 8, 'targetid' => 3, 'walletid' => 98]);
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure_key($key, $from, $to);
        $this->assertEqualsWithDelta(10.0, $spend->get_amount('aiprovider_mock'), 0.000001);
    }

    public function test_what_each_course_spent_is_found_without_asking_about_every_course(): void {
        $this->spend(1.0, ['courseid' => 7]);
        $this->spend(2.0, ['courseid' => 7]);
        $this->spend(5.0, ['courseid' => 9]);
        $this->spend(50.0);
        [$from, $to] = $this->week();

        $each = $this->ledger->measure_each(ledger::SCOPE_COURSE, $from, $to);

        $this->assertSame([7, 9], array_keys($each));
        $this->assertEqualsWithDelta(3.0, $each[7]->get_amount('aiprovider_mock'), 0.000001);
        $this->assertSame(2, $each[7]->requests);
    }

    public function test_a_period_the_site_cannot_account_for_the_start_of_is_a_floor(): void {
        global $DB;
        $this->spend(9.0);
        // The purge discarded everything before three days ago, and wrote that down.
        set_config(summariser::HISTORY_SETTING, $this->now - 3 * DAYSECS, 'local_airouter');
        [$from, $to] = $this->week();

        $spend = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertTrue($spend->is_partial());
        $this->assertSame($this->now - 3 * DAYSECS, $spend->get_covered_from());
        $this->assertEqualsWithDelta(9.0, $spend->get_amount('aiprovider_mock'), 0.000001, 'A floor, not unknown.');
        $this->assertTrue($spend->is_known(ledger::METRIC_COST, 'aiprovider_mock'));

        [$from, $to] = ledger::get_window(ledger::PERIOD_ROLLING, 1, $this->now);
        $today = $this->ledger->measure(ledger::SCOPE_SITE, 0, $from, $to);
        $this->assertFalse($today->is_partial(), 'A period inside what the site still holds.');
        $this->assertSame($from, $today->get_covered_from());

        // And the held figure says the same.
        $cached = new ledger($DB, true);
        $this->assertTrue($cached->get_spend(ledger::SCOPE_SITE, 0, ledger::PERIOD_ROLLING, 7, $this->now)->is_partial());
        $this->assertTrue($cached->get_spend(ledger::SCOPE_SITE, 0, ledger::PERIOD_ROLLING, 7, $this->now + 1)->is_partial());
    }

    public function test_a_budget_for_no_course_is_a_coding_error(): void {
        [$from, $to] = $this->week();

        $this->expectException(\coding_exception::class);
        $this->ledger->measure(ledger::SCOPE_COURSE, 0, $from, $to);
    }

    public function test_a_held_figure_is_reused_while_it_lives_and_a_new_period_is_measured_again(): void {
        global $DB;
        $cached = new ledger($DB);
        $this->spend(1.0);

        $first = $cached->get_spend(ledger::SCOPE_SITE, 0, ledger::PERIOD_ROLLING, 7, $this->now);
        $this->spend(1.0);
        $held = $cached->get_spend(ledger::SCOPE_SITE, 0, ledger::PERIOD_ROLLING, 7, $this->now);
        $fresh = $cached->get_spend(ledger::SCOPE_SITE, 0, ledger::PERIOD_ROLLING, 7, $this->now + DAYSECS);

        $this->assertEqualsWithDelta(1.0, $first->get_amount('aiprovider_mock'), 0.000001);
        $this->assertEqualsWithDelta(1.0, $held->get_amount('aiprovider_mock'), 0.000001, 'Held: a limit to within a minute.');
        $this->assertEqualsWithDelta(2.0, $fresh->get_amount('aiprovider_mock'), 0.000001, 'A new window is measured again.');
    }

    public function test_the_furthest_limit_decides_how_far_back_the_site_must_remember(): void {
        global $DB;
        $this->assertSame(0, ledger::longest_reach_days($DB));

        $key = (new key_repository($DB))->save(key::SCOPE_USER, 5, 3, 'sk-key');
        (new key_repository($DB))->set_cap($key, 10.0, ledger::PERIOD_ROLLING, 45);

        $this->assertSame(45, ledger::longest_reach_days($DB));
        $this->assertSame(31, ledger::reach_of(ledger::PERIOD_MONTH, 0), 'A month is counted as the most it can be.');
    }

    /**
     * Run something on a second database connection, as another process would.
     *
     * @param \Closure $operation What to run; it is given the other connection.
     * @return mixed What it returned.
     */
    private function separately(\Closure $operation): mixed {
        global $DB, $CFG;
        $original = $DB;
        $other = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $other->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
        try {
            $DB = $other;

            return $operation($other);
        } finally {
            $DB = $original;
            $other->dispose();
        }
    }

    public function test_a_budget_keeps_its_spending_when_the_summariser_commits_between_the_reads(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->spend(9.0, ['ended' => $this->now - DAYSECS]);
        $passes = 0;
        $ledger = new ledger($DB, true, null, function (string $point) use (&$passes): void {
            if ($point === 'summarised' && $passes++ === 0) {
                $this->separately(fn($db) => (new summariser($db))->run($this->now));
            }
        });

        $measured = $ledger->get_spend(ledger::SCOPE_SITE, 0, ledger::PERIOD_ROLLING, 7, $this->now);
        $held = $ledger->get_spend(ledger::SCOPE_SITE, 0, ledger::PERIOD_ROLLING, 7, $this->now + 1);

        // Nine dollars spent is nine dollars spent, whichever table it sat in while
        // it was being read, and what the cache holds is the same figure.
        $this->assertEqualsWithDelta(9.0, $measured->get_amount('aiprovider_mock'), 0.000001);
        $this->assertTrue($measured->has_reached(8.0, ledger::METRIC_COST, 'aiprovider_mock'));
        $this->assertSame(1, $measured->requests);
        $this->assertEqualsWithDelta(9.0, $held->get_amount('aiprovider_mock'), 0.000001);
    }

    public function test_a_budget_is_read_in_one_currency_when_a_correction_commits_between_the_reads(): void {
        global $DB;
        // R8-06. A correction rewrites the summary and the detail together, and moves
        // the generation with them; a reader that saw the summary before and the
        // detail after reads again rather than adding dollars to yen.
        $this->preventResetByRollback();
        $this->spend(1.0, ['ended' => $this->now - DAYSECS]);
        (new summariser($DB))->run($this->now);
        $this->spend(1.0);
        $passes = 0;
        $ledger = new ledger($DB, true, null, function (string $point) use (&$passes): void {
            if ($point === 'summarised' && $passes++ === 0) {
                $this->separately(fn($db) => (new price_book($db))->recost_provider('aiprovider_mock', 'JPY'));
            }
        });

        $measured = $ledger->get_spend(ledger::SCOPE_SITE, 0, ledger::PERIOD_ROLLING, 7, $this->now);
        $held = (new ledger($DB, true))->get_spend(ledger::SCOPE_SITE, 0, ledger::PERIOD_ROLLING, 7, $this->now + 1);

        // Both facts priced again in yen, from their tokens: one figure, comparable.
        $expected = 2 * (new price_book($DB))->find('aiprovider_mock', null, $this->now)->cost(10, 5, 0);
        foreach ([$measured, $held] as $spend) {
            $this->assertTrue($ledger->was_consistent());
            $this->assertTrue($spend->is_known(ledger::METRIC_COST, 'aiprovider_mock'));
            $this->assertSame('JPY', $spend->get_currency('aiprovider_mock'));
            $this->assertEqualsWithDelta($expected, $spend->get_amount('aiprovider_mock'), 0.0000001);
            $this->assertSame(2, $spend->calls);
        }
    }

    public function test_what_each_course_spent_survives_the_summariser_committing_between_the_reads(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->spend(3.0, ['courseid' => 7, 'ended' => $this->now - DAYSECS]);
        [$from, $to] = $this->week();
        $passes = 0;
        $ledger = new ledger($DB, false, null, function (string $point) use (&$passes): void {
            if ($point === 'summarised' && $passes++ === 0) {
                $this->separately(fn($db) => (new summariser($db))->run($this->now));
            }
        });

        $each = $ledger->measure_each(ledger::SCOPE_COURSE, $from, $to);

        $this->assertEqualsWithDelta(3.0, $each[7]->get_amount('aiprovider_mock'), 0.000001);
    }
}
