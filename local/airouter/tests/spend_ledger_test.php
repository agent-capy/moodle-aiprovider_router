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
 * Tests for the ledger every budget is measured against.
 *
 * Three things here decide whether a limit means anything, and all three fail quietly.
 * Money somebody brought with them must never be counted as the site's, or a site would
 * be stopped by spending that was not its own. A period that nobody priced must not read
 * as a period that cost nothing, or a site with no rates entered would have an unlimited
 * budget. And the seam between the summaries and the detail runs through the middle of
 * every period, so a day counted twice or lost would look entirely plausible.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(spend_ledger::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(spend::class)]
final class spend_ledger_test extends \advanced_testcase {
    /** @var usage_aggregator The aggregator the ledger reads through. */
    protected usage_aggregator $aggregator;

    /** @var spend_ledger The ledger under test, asking the database every time. */
    protected spend_ledger $ledger;

    /** @var int A fixed moment to measure periods from. */
    protected int $now;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        self::setTimezone('UTC', 'UTC');
        $this->aggregator = new usage_aggregator($DB);
        $this->ledger = new spend_ledger($DB, $this->aggregator, false);
        $this->now = make_timestamp(2026, 9, 13, 10, 30, 0);
    }

    /**
     * Midnight a number of days before the fixed moment.
     *
     * @param int $ago How many days back, zero being today.
     * @return int The timestamp.
     */
    protected function day(int $ago): int {
        return $this->aggregator->add_days($this->aggregator->day_of($this->now), -$ago);
    }

    /**
     * Write one detail row.
     *
     * @param int $time When the request happened.
     * @param array $fields What to record, over the defaults.
     * @return int The row id.
     */
    protected function log(int $time, array $fields = []): int {
        global $DB;

        return $DB->insert_record(usage_logger::TABLE, (object) ($fields + [
            'timecreated' => $time,
            'userid' => 5,
            'contextid' => 0,
            'courseid' => null,
            'actionname' => 'generate_text',
            'placement' => 'aiplacement_editor',
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'currency' => 'USD',
            'success' => 1,
            'attempts' => 1,
            'prompttokens' => 100,
            'completiontokens' => 50,
            'cost' => 1.0,
            'keysource' => usage_logger::KEY_SITE,
        ]));
    }

    /**
     * The whole of the last week, as a period.
     *
     * @return int[] The window.
     */
    protected function week(): array {
        return $this->ledger->get_window(spend_ledger::PERIOD_ROLLING, 7, $this->now);
    }

    public function test_a_site_budget_leaves_out_what_people_paid_for_themselves(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => 1.0]);
        $this->log($this->day(0) + HOURSECS, ['cost' => 9.0, 'keysource' => rule::KEYSOURCE_USER]);
        $this->log($this->day(0) + HOURSECS, ['cost' => 90.0, 'keysource' => rule::KEYSOURCE_COURSE]);

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        // The site paid for one of the three. Adding the others in would stop the site
        // on the strength of money it never spent.
        $this->assertSame(1.0, $spend->get_amount());
        $this->assertSame(1, $spend->requests);
    }

    public function test_spending_is_counted_once_across_the_seam(): void {
        // Yesterday has been summarised and today has not, so the two halves of this
        // period come out of different tables.
        $this->log($this->day(1) + HOURSECS, ['cost' => 2.0]);
        $this->aggregator->run($this->now);
        $this->log($this->day(0) + HOURSECS, ['cost' => 3.0]);

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertSame(5.0, $spend->get_amount());
        $this->assertSame(2, $spend->requests);
    }

    public function test_spending_survives_the_detail_being_purged(): void {
        $this->log($this->day(3) + HOURSECS, ['cost' => 2.0]);
        $this->aggregator->run($this->now);
        set_config('logretentiondays', 1, 'local_airouter');
        $this->assertSame(1, $this->aggregator->purge($this->now));

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        // Which is the point of reading the summaries at all: a monthly budget outlives
        // the detail rows on most sites.
        $this->assertSame(2.0, $spend->get_amount());
    }

    public function test_a_course_budget_counts_only_that_course(): void {
        $this->log($this->day(0) + HOURSECS, ['courseid' => 11, 'cost' => 1.0]);
        $this->log($this->day(0) + HOURSECS, ['courseid' => 12, 'cost' => 4.0]);
        $this->log($this->day(0) + HOURSECS, ['courseid' => null, 'cost' => 8.0]);

        [$from, $to] = $this->week();

        $this->assertSame(1.0, $this->ledger->measure(spend_ledger::SCOPE_COURSE, 11, $from, $to)->get_amount());
        $this->assertSame(13.0, $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to)->get_amount());
    }

    public function test_a_person_budget_counts_only_that_person(): void {
        $this->log($this->day(0) + HOURSECS, ['userid' => 5, 'cost' => 1.0]);
        $this->log($this->day(0) + HOURSECS, ['userid' => 6, 'cost' => 4.0]);

        [$from, $to] = $this->week();

        $this->assertSame(1.0, $this->ledger->measure(spend_ledger::SCOPE_USER, 5, $from, $to)->get_amount());
    }

    public function test_a_period_nobody_priced_is_not_a_period_that_cost_nothing(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => null]);

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        // A site that has entered no rates uses the AI and spends nothing, for ever.
        // Reading that as room in the budget would make the limit meaningless.
        $this->assertFalse($spend->is_known());
        $this->assertNull($spend->has_reached(0.5));
        $this->assertSame(1, $spend->requests);
        $this->assertSame(0, $spend->costedcalls);
    }

    public function test_a_count_of_requests_is_known_even_where_the_money_is_not(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => null]);
        $this->log($this->day(0) + HOURSECS, ['cost' => null]);
        $this->log($this->day(0) + HOURSECS, ['cost' => null]);

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        // The same period, read two ways. Nobody can say what it cost; everybody can
        // say how many requests it took.
        $this->assertFalse($spend->is_known(spend_ledger::METRIC_COST));
        $this->assertTrue($spend->is_known(spend_ledger::METRIC_REQUESTS));
        $this->assertSame(3.0, $spend->get_measure(spend_ledger::METRIC_REQUESTS));
        $this->assertTrue($spend->has_reached(3.0, spend_ledger::METRIC_REQUESTS));
        $this->assertFalse($spend->has_reached(4.0, spend_ledger::METRIC_REQUESTS));
        $this->assertSame(1.0, $spend->get_remaining(4.0, spend_ledger::METRIC_REQUESTS));
    }

    public function test_a_provider_that_costs_nothing_is_a_known_zero(): void {
        // A model the site runs itself, entered with a rate of zero. The requests are
        // priced, and priced at nothing, which is a different state from unpriced: a
        // budget can be measured against it and the coverage is complete.
        $this->log($this->day(0) + HOURSECS, ['cost' => 0.0, 'targetprovider' => 'aiprovider_ollama']);
        $this->log($this->day(0) + HOURSECS, ['cost' => 0.0, 'targetprovider' => 'aiprovider_ollama']);

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertTrue($spend->is_known());
        $this->assertTrue($spend->is_complete());
        $this->assertSame(0.0, $spend->get_amount());
        $this->assertFalse($spend->has_reached(0.5));
        // And the requests are counted whether or not anything was paid for them.
        $this->assertSame(2, $spend->requests);
        $this->assertSame(2, $spend->costedcalls);
    }

    public function test_free_requests_do_not_hide_paid_ones(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => 0.0, 'targetprovider' => 'aiprovider_ollama']);
        $this->log($this->day(0) + HOURSECS, ['cost' => 4.0]);

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        // A site running some traffic on its own hardware and some on a paid provider
        // still has a complete figure for what it spent.
        $this->assertSame(4.0, $spend->get_amount());
        $this->assertTrue($spend->is_complete());
    }

    public function test_a_period_with_nothing_in_it_is_known_to_be_nothing(): void {
        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertTrue($spend->is_known());
        $this->assertSame(0.0, $spend->get_amount());
        $this->assertFalse($spend->has_reached(0.5));
    }

    public function test_a_partly_priced_period_says_so(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => 1.0]);
        $this->log($this->day(0) + HOURSECS, ['cost' => null]);

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        // Known, but an understatement, and whoever shows the figure needs to be able
        // to say that.
        $this->assertTrue($spend->is_known());
        $this->assertFalse($spend->is_complete());
        $this->assertSame(0.5, $spend->get_coverage());
    }

    public function test_costs_in_two_currencies_are_not_added_together(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => 1.0, 'currency' => 'USD']);
        $this->log($this->day(0) + HOURSECS, ['cost' => 150.0, 'currency' => 'JPY']);

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        // Nothing here converts between currencies, so their sum is a number in no
        // currency at all. Not knowing is the honest answer.
        $this->assertTrue($spend->mixedcurrency);
        $this->assertFalse($spend->is_known());
        $this->assertNull($spend->has_reached(2.0));
    }

    public function test_a_costed_attempt_still_counts_once_the_day_is_summarised(): void {
        // One request that fell through. The attempt that spent the money answered
        // with nothing, so it is a call and not a request; the row that carries the
        // request reached no target at all and has no cost of its own. Read from the
        // detail, the period is priced and over the limit. It has to still be after
        // the day has been summarised, and it was not: the summary counted the priced
        // rows among the requests, found none, and reported a period holding 1.20
        // that nobody had priced. The budget it was stopping opened again.
        $this->log($this->day(1) + HOURSECS, ['cost' => 1.2, 'counted' => 0, 'success' => 0]);
        $this->log($this->day(1) + 2 * HOURSECS, [
            'cost' => null,
            'success' => 0,
            'targetid' => null,
            'targetname' => null,
            'targetprovider' => null,
            'model' => null,
        ]);

        [$from, $to] = $this->week();
        $before = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);
        $this->assertTrue($before->is_known());
        $this->assertSame(1, $before->requests);
        $this->assertSame(2, $before->get_calls());
        $this->assertTrue($before->has_reached(1.0));

        $this->aggregator->run($this->now);

        $after = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);
        $this->assertTrue($after->is_known());
        $this->assertSame(1, $after->requests);
        $this->assertSame(2, $after->get_calls());
        $this->assertEqualsWithDelta(1.2, $after->get_amount(), 0.000001);
        $this->assertTrue($after->has_reached(1.0));
    }

    public function test_coverage_is_a_share_of_the_calls_rather_than_of_the_requests(): void {
        // The same request twice over: an attempt that was priced and a second one
        // that answered and was not. Counting the priced calls against the request
        // count made this period 100 per cent covered before the day was summarised
        // and said one of one, when one of two calls had a rate.
        $this->log($this->day(1) + HOURSECS, ['cost' => 1.2, 'counted' => 0, 'success' => 0]);
        $this->log($this->day(1) + 2 * HOURSECS, ['cost' => null]);

        [$from, $to] = $this->week();
        $before = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);
        $this->assertFalse($before->is_complete());
        $this->assertSame(0.5, $before->get_coverage());

        $this->aggregator->run($this->now);

        $after = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);
        $this->assertFalse($after->is_complete());
        $this->assertSame(0.5, $after->get_coverage());
    }

    public function test_money_recorded_in_another_currency_is_not_weighed_against_a_limit(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => 1000.0, 'currency' => 'JPY']);
        // The rates are in dollars, so the limits are read as dollars.
        $rate = new price();
        $rate->set('provider', 'aiprovider_openai');
        $rate->set('currency', 'USD');
        $rate->set('promptrate', 1.0);
        $rate->create();

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        // Every limit on this site is a figure in dollars, and this is not a figure in
        // dollars. Weighing 1000 yen against a limit of 100 dollars compares two
        // different things while looking like a comparison.
        $this->assertSame('JPY', $spend->currency);
        $this->assertFalse($spend->is_comparable());
        $this->assertFalse($spend->is_known());
        $this->assertNull($spend->has_reached(100.0));
    }

    public function test_a_budget_that_has_not_started_yet_still_needs_its_history(): void {
        global $DB;

        // A rule written today to start next week looks back over its whole period
        // from the moment it starts, and the days it will need are the days sitting
        // in the table now. Asking only about the rules in force reported no reach at
        // all, and the summaries were thrown away before the budget ever ran.
        $rule = new rule(0, (object) [
            'name' => 'Next week',
            'targetid' => 1,
            'enabled' => 1,
            // Measured from the real clock, because that is what the purge reads.
            'timestart' => time() + WEEKSECS,
            'sortorder' => 0,
        ]);
        $rule->save();
        $DB->insert_record(rule_repository::CONDITION_TABLE, (object) [
            'ruleid' => $rule->get('id'),
            'type' => 'budget',
            'configdata' => json_encode([
                'scope' => spend_ledger::SCOPE_SITE,
                'direction' => 'under',
                'metric' => spend_ledger::METRIC_COST,
                'amount' => 100.0,
                'period' => spend_ledger::PERIOD_ROLLING,
                'days' => 30,
            ]),
        ]);

        $this->assertSame([], (new rule_repository($DB))->get_budgets(time()));
        $this->assertSame(30, spend_ledger::longest_reach_days($DB));
    }

    public function test_a_recorded_amount_is_known_whatever_the_count_beside_it_says(): void {
        global $DB;

        // A summary written by an earlier version, holding a cost and a count of
        // priced rows that was worked out a different way. Deciding whether there is
        // a figure by reading that count is what let a budget through twice, so the
        // amount answers for itself: an amount exists because something was priced.
        $DB->insert_record(usage_aggregator::TABLE, (object) [
            'daystart' => $this->day(1),
            'courseid' => null,
            'userid' => 5,
            'actionname' => 'generate_text',
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'keysource' => usage_logger::KEY_SITE,
            'currency' => 'USD',
            'requests' => 1,
            'failures' => 1,
            'calls' => 1,
            'prompttokens' => 0,
            'completiontokens' => 0,
            'cost' => 1.2,
            'costedcalls' => 0,
            'timecreated' => $this->now,
        ]);
        set_config(usage_aggregator::LAST_SETTING, $this->day(1), 'local_airouter');

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertTrue($spend->is_known());
        $this->assertEqualsWithDelta(1.2, $spend->get_amount(), 0.000001);
        $this->assertTrue($spend->has_reached(1.0));
        // The count still says what it says, and what it says is the coverage.
        $this->assertFalse($spend->is_complete());
    }

    public function test_reaching_the_limit_counts_as_reaching_it(): void {
        $this->log($this->day(0) + HOURSECS, ['cost' => 10.0]);

        [$from, $to] = $this->week();
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertTrue($spend->has_reached(10.0));
        $this->assertSame(0.0, $spend->get_remaining(10.0));
        $this->assertSame(5.0, $spend->get_remaining(15.0));
    }

    public function test_a_rolling_period_counts_today_and_the_days_before_it(): void {
        [$from, $to] = $this->ledger->get_window(spend_ledger::PERIOD_ROLLING, 30, $this->now);

        $this->assertSame($this->day(29), $from);
        $this->assertSame($this->now, $to);
        // A period of one day is today, not today and yesterday.
        $this->assertSame($this->day(0), $this->ledger->get_window(
            spend_ledger::PERIOD_ROLLING,
            1,
            $this->now,
        )[0]);
    }

    public function test_a_calendar_month_starts_on_the_first(): void {
        [$from] = $this->ledger->get_window(spend_ledger::PERIOD_MONTH, 0, $this->now);

        $this->assertSame(make_timestamp(2026, 9, 1, 0, 0, 0), $from);
        // And on the first itself the month is that day alone, not the month before it.
        $firstofmonth = make_timestamp(2026, 9, 1, 9, 0, 0);
        $this->assertSame(
            make_timestamp(2026, 9, 1, 0, 0, 0),
            $this->ledger->get_window(spend_ledger::PERIOD_MONTH, 0, $firstofmonth)[0],
        );
    }

    public function test_a_month_is_counted_from_the_first_and_not_thirty_days_back(): void {
        $this->log(make_timestamp(2026, 8, 31, 12, 0, 0), ['cost' => 100.0]);
        $this->log(make_timestamp(2026, 9, 2, 12, 0, 0), ['cost' => 1.0]);

        [$from, $to] = $this->ledger->get_window(spend_ledger::PERIOD_MONTH, 0, $this->now);
        $spend = $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to);

        $this->assertSame(1.0, $spend->get_amount());
    }

    public function test_a_keys_spending_follows_its_owner_and_its_target(): void {
        global $DB;
        $repository = new key_repository($DB);
        $key = $repository->save(key::SCOPE_USER, 5, 1, 'sk-somebodys-own-key');

        $this->log($this->day(0) + HOURSECS, [
            'userid' => 5,
            'targetid' => 1,
            'cost' => 3.0,
            'keysource' => rule::KEYSOURCE_USER,
            'keyid' => $key->get('id'),
        ]);
        // The same person, on a target they hold a different key for.
        $this->log($this->day(0) + HOURSECS, [
            'userid' => 5,
            'targetid' => 2,
            'cost' => 7.0,
            'keysource' => rule::KEYSOURCE_USER,
        ]);
        // And what the site paid for on their behalf, which is not their spending.
        $this->log($this->day(0) + HOURSECS, ['userid' => 5, 'targetid' => 1, 'cost' => 9.0]);

        [$from, $to] = $this->week();

        $this->assertSame(3.0, $this->ledger->measure_key($key, $from, $to)->get_amount());
    }

    public function test_replacing_a_key_does_not_start_its_spending_again(): void {
        global $DB;
        $repository = new key_repository($DB);
        $first = $repository->save(key::SCOPE_USER, 5, 1, 'sk-the-first-key');
        $this->log($this->day(1) + HOURSECS, [
            'userid' => 5,
            'targetid' => 1,
            'cost' => 4.0,
            'keysource' => rule::KEYSOURCE_USER,
            'keyid' => $first->get('id'),
        ]);

        // Registering again replaces the row rather than adding one, but even a new row
        // would not reset the month: the provider is still billing the same account.
        $second = $repository->save(key::SCOPE_USER, 5, 1, 'sk-the-second-key');

        [$from, $to] = $this->week();

        $this->assertSame(4.0, $this->ledger->measure_key($second, $from, $to)->get_amount());
    }

    public function test_a_course_key_is_measured_by_its_course(): void {
        global $DB;
        $repository = new key_repository($DB);
        $key = $repository->save(key::SCOPE_COURSE, 11, 1, 'sk-the-courses-key');

        $this->log($this->day(0) + HOURSECS, [
            'courseid' => 11,
            'targetid' => 1,
            'cost' => 2.0,
            'keysource' => rule::KEYSOURCE_COURSE,
        ]);
        $this->log($this->day(0) + HOURSECS, [
            'courseid' => 12,
            'targetid' => 1,
            'cost' => 20.0,
            'keysource' => rule::KEYSOURCE_COURSE,
        ]);

        [$from, $to] = $this->week();

        $this->assertSame(2.0, $this->ledger->measure_key($key, $from, $to)->get_amount());
    }

    public function test_a_budget_for_no_course_is_a_coding_error(): void {
        [$from, $to] = $this->week();

        // Whoever is asking has to decide what to do about a request belonging to no
        // course before it reaches the ledger.
        $this->expectException(\coding_exception::class);
        $this->ledger->measure(spend_ledger::SCOPE_COURSE, 0, $from, $to);
    }

    public function test_a_held_figure_is_reused_while_it_lives(): void {
        global $DB;
        $cachingledger = new spend_ledger($DB, $this->aggregator);
        $this->log($this->day(0) + HOURSECS, ['cost' => 1.0]);

        $first = $cachingledger->get_spend(spend_ledger::SCOPE_SITE, 0, spend_ledger::PERIOD_ROLLING, 7, $this->now);
        $this->log($this->day(0) + 2 * HOURSECS, ['cost' => 1.0]);
        $second = $cachingledger->get_spend(spend_ledger::SCOPE_SITE, 0, spend_ledger::PERIOD_ROLLING, 7, $this->now);

        // Documented consequence of caching, not a defect: requests
        // arriving while a figure is held are weighed against the spending as it was
        // when it was measured, so a limit is a limit to within the cache's lifetime.
        $this->assertSame(1.0, $first->get_amount());
        $this->assertSame(1.0, $second->get_amount());
        // Asking the database says otherwise, which is what the reports and the daily
        // task do.
        [$from, $to] = $this->week();
        $this->assertSame(2.0, $this->ledger->measure(spend_ledger::SCOPE_SITE, 0, $from, $to)->get_amount());
    }

    public function test_a_new_period_is_measured_again_rather_than_waiting(): void {
        global $DB;
        $cachingledger = new spend_ledger($DB, $this->aggregator);
        $this->log($this->day(0) + HOURSECS, ['cost' => 1.0]);

        $cachingledger->get_spend(spend_ledger::SCOPE_SITE, 0, spend_ledger::PERIOD_ROLLING, 7, $this->now);
        // A day later the window has rolled, so the held figure is for another period
        // and is not reused.
        $tomorrow = $this->aggregator->add_days($this->now, 1);
        // Before that moment, because the end of a window is the first moment it does
        // not cover.
        $this->log($this->aggregator->day_of($tomorrow) + HOURSECS, ['cost' => 5.0]);
        $spend = $cachingledger->get_spend(spend_ledger::SCOPE_SITE, 0, spend_ledger::PERIOD_ROLLING, 7, $tomorrow);

        $this->assertSame(6.0, $spend->get_amount());
    }
}
