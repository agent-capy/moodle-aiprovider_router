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

namespace aiprovider_router\condition;

use aiprovider_router\evaluation_context;
use aiprovider_router\rule;
use aiprovider_router\spend_ledger;
use aiprovider_router\token_estimator;
use aiprovider_router\usage_logger;
use core_ai\aiactions\generate_text;

/**
 * Tests for the condition that routes by how much has been spent.
 *
 * The direction that matters most here is the one nothing forces: a budget that cannot
 * be measured must satisfy this condition neither way round. Costs come from the site's
 * own rate table, so a site that has entered no rates spends nothing however much it
 * uses, and the wrong direction would give that site an unlimited budget while looking
 * perfectly configured.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(budget::class)]
final class budget_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Write one detail row.
     *
     * @param array $fields What to record, over the defaults.
     * @param int|null $time When the request happened. An hour ago by default.
     */
    protected function log(array $fields = [], ?int $time = null): void {
        global $DB;

        $DB->insert_record(usage_logger::TABLE, (object) ($fields + [
            'timecreated' => $time ?? time() - HOURSECS,
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
        $this->forget();
    }

    /**
     * Empty the short lived cache the condition reads through.
     *
     * Necessary here and nowhere near a real site: figures written a moment ago would
     * otherwise be weighed against a total measured before they existed.
     */
    protected function forget(): void {
        \core_cache\helper::purge_by_definition('aiprovider_router', spend_ledger::CACHE_AREA);
    }

    /**
     * An evaluation context over a generate_text action.
     *
     * @param \context $context Where the action was raised.
     * @param int $userid Who raised it.
     * @return evaluation_context The context.
     */
    protected function context(\context $context, int $userid = 5): evaluation_context {
        return new evaluation_context(
            new generate_text(contextid: $context->id, userid: $userid, prompttext: 'Hello'),
            new token_estimator(),
        );
    }

    /**
     * A budget condition over the site.
     *
     * @param string $direction Which side of the limit is wanted.
     * @param float $amount The limit.
     * @return budget The condition.
     */
    protected function sitebudget(string $direction, float $amount): budget {
        return new budget([
            'scope' => spend_ledger::SCOPE_SITE,
            'direction' => $direction,
            'amount' => $amount,
            'period' => spend_ledger::PERIOD_ROLLING,
            'days' => 30,
        ]);
    }

    public function test_there_is_room_until_the_limit_is_reached(): void {
        $context = $this->context(\context_system::instance());
        $this->log(['cost' => 4.0]);

        $this->assertTrue($this->sitebudget(budget::DIRECTION_UNDER, 10.0)->is_met($context));
        $this->assertFalse($this->sitebudget(budget::DIRECTION_OVER, 10.0)->is_met($context));

        $this->log(['cost' => 6.0]);

        // Ten spent against a limit of ten is a limit reached.
        $this->assertFalse($this->sitebudget(budget::DIRECTION_UNDER, 10.0)->is_met($context));
        $this->assertTrue($this->sitebudget(budget::DIRECTION_OVER, 10.0)->is_met($context));
    }

    public function test_spending_that_cannot_be_worked_out_satisfies_neither_direction(): void {
        $context = $this->context(\context_system::instance());
        // A site that has entered no rates. Every request is recorded, none is priced.
        $this->log(['cost' => null]);
        $this->log(['cost' => null]);

        $this->assertFalse($this->sitebudget(budget::DIRECTION_UNDER, 10.0)->is_met($context));
        $this->assertFalse($this->sitebudget(budget::DIRECTION_OVER, 10.0)->is_met($context));
    }

    public function test_money_people_brought_is_not_the_sites_budget(): void {
        $context = $this->context(\context_system::instance());
        $this->log(['cost' => 1.0]);
        $this->log(['cost' => 500.0, 'keysource' => rule::KEYSOURCE_USER]);

        // The site is nowhere near its limit. Somebody else spent that money.
        $this->assertTrue($this->sitebudget(budget::DIRECTION_UNDER, 10.0)->is_met($context));
    }

    public function test_a_course_budget_is_not_met_outside_a_course(): void {
        $condition = new budget([
            'scope' => spend_ledger::SCOPE_COURSE,
            'direction' => budget::DIRECTION_UNDER,
            'amount' => 10.0,
            'period' => spend_ledger::PERIOD_ROLLING,
            'days' => 30,
        ]);
        $over = new budget([
            'scope' => spend_ledger::SCOPE_COURSE,
            'direction' => budget::DIRECTION_OVER,
            'amount' => 10.0,
            'period' => spend_ledger::PERIOD_ROLLING,
            'days' => 30,
        ]);
        $context = $this->context(\context_system::instance());

        // There is no budget here to be inside or past, so neither way round is met.
        $this->assertFalse($condition->is_met($context));
        $this->assertFalse($over->is_met($context));
    }

    public function test_a_course_budget_counts_that_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $this->log(['courseid' => (int) $course->id, 'cost' => 20.0]);

        $condition = new budget([
            'scope' => spend_ledger::SCOPE_COURSE,
            'direction' => budget::DIRECTION_OVER,
            'amount' => 10.0,
            'period' => spend_ledger::PERIOD_ROLLING,
            'days' => 30,
        ]);

        $this->assertTrue($condition->is_met($this->context(\context_course::instance($course->id))));
        $this->assertFalse($condition->is_met($this->context(\context_course::instance($other->id))));
    }

    public function test_a_person_budget_counts_that_person(): void {
        $this->log(['userid' => 5, 'cost' => 20.0]);
        $condition = new budget([
            'scope' => spend_ledger::SCOPE_USER,
            'direction' => budget::DIRECTION_OVER,
            'amount' => 10.0,
            'period' => spend_ledger::PERIOD_ROLLING,
            'days' => 30,
        ]);

        $this->assertTrue($condition->is_met($this->context(\context_system::instance(), 5)));
        $this->assertFalse($condition->is_met($this->context(\context_system::instance(), 6)));
    }

    public function test_a_calendar_month_starts_again_at_the_first(): void {
        global $DB;
        $ledger = new spend_ledger($DB, null, false);
        [$from] = $ledger->get_window(spend_ledger::PERIOD_MONTH, 0, time());

        // One request inside this month and one the day before it began.
        $this->log(['cost' => 1.0], max($from, time() - HOURSECS));
        $this->log(['cost' => 500.0], $from - DAYSECS);

        $condition = new budget([
            'scope' => spend_ledger::SCOPE_SITE,
            'direction' => budget::DIRECTION_UNDER,
            'amount' => 10.0,
            'period' => spend_ledger::PERIOD_MONTH,
            'days' => 30,
        ]);

        $this->assertTrue($condition->is_met($this->context(\context_system::instance())));
    }

    public function test_an_unfinished_condition_narrows_the_rule(): void {
        $context = $this->context(\context_system::instance());

        $this->assertFalse((new budget([]))->is_met($context));
        $this->assertFalse($this->sitebudget(budget::DIRECTION_UNDER, 0.0)->is_met($context));
        $this->assertFalse((new budget([
            'scope' => 'something else',
            'direction' => budget::DIRECTION_UNDER,
            'amount' => 10.0,
        ]))->is_met($context));
    }

    public function test_a_budget_typed_wrongly_is_reported_rather_than_dropped(): void {
        // Nothing filled in at all is how a rule says it has no budget condition.
        $this->assertSame([], budget::validate_form(['budgetscope' => '', 'budgetamount' => '']));

        // An amount without a subject, and a subject without a usable amount, are both
        // things somebody meant. Losing them silently would widen the rule.
        $this->assertArrayHasKey('budgetgroup', budget::validate_form([
            'budgetscope' => '',
            'budgetamount' => '100',
        ]));
        $this->assertArrayHasKey('budgetgroup', budget::validate_form([
            'budgetscope' => spend_ledger::SCOPE_SITE,
            'budgetamount' => 'ten pounds',
        ]));
        $this->assertArrayHasKey('budgetgroup', budget::validate_form([
            'budgetscope' => spend_ledger::SCOPE_SITE,
            'budgetamount' => '0',
        ]));
        $this->assertSame([], budget::validate_form([
            'budgetscope' => spend_ledger::SCOPE_SITE,
            'budgetamount' => '100.5',
        ]));
    }

    public function test_a_budget_survives_the_trip_through_the_form(): void {
        $config = budget::read_from_form((object) [
            'budgetscope' => spend_ledger::SCOPE_USER,
            'budgetdirection' => budget::DIRECTION_OVER,
            'budgetamount' => '250',
            'budgetperiod' => spend_ledger::PERIOD_MONTH,
            'budgetdays' => 30,
        ]);

        $this->assertSame(spend_ledger::SCOPE_USER, $config['scope']);
        $this->assertSame(budget::DIRECTION_OVER, $config['direction']);
        $this->assertSame(250.0, $config['amount']);
        $this->assertSame(spend_ledger::PERIOD_MONTH, $config['period']);

        $form = budget::to_form_data($config);
        $this->assertSame(spend_ledger::SCOPE_USER, $form['budgetscope']);
        $this->assertSame(budget::DIRECTION_OVER, $form['budgetdirection']);

        // Nothing chosen is not a budget of nothing.
        $this->assertNull(budget::read_from_form((object) ['budgetscope' => '', 'budgetamount' => '']));
    }

    public function test_a_budget_reads_as_a_sentence(): void {
        $description = $this->sitebudget(budget::DIRECTION_UNDER, 10000.0)->get_description();

        $this->assertStringContainsString('10000.00', $description);
        $this->assertStringContainsString('30', $description);
        $this->assertStringNotContainsString('{$a', $description);
    }

    /**
     * A budget condition over the site, counted in requests.
     *
     * @param string $direction Which side of the limit is wanted.
     * @param float $amount How many requests.
     * @return budget The condition.
     */
    protected function siterequests(string $direction, float $amount): budget {
        return new budget([
            'scope' => spend_ledger::SCOPE_SITE,
            'direction' => $direction,
            'metric' => spend_ledger::METRIC_REQUESTS,
            'amount' => $amount,
            'period' => spend_ledger::PERIOD_ROLLING,
            'days' => 30,
        ]);
    }

    public function test_a_budget_in_requests_works_on_a_site_that_prices_nothing(): void {
        $context = $this->context(\context_system::instance());
        // The case this measure exists for: models somebody runs themselves, where
        // there is no bill to estimate and so no rate to enter.
        $this->log(['cost' => null]);
        $this->log(['cost' => null]);

        // The same two requests satisfy a budget in money neither way round.
        $this->assertFalse($this->sitebudget(budget::DIRECTION_UNDER, 10.0)->is_met($context));
        $this->assertFalse($this->sitebudget(budget::DIRECTION_OVER, 10.0)->is_met($context));

        // Counted instead, they are two requests, and that is not in doubt.
        $this->assertTrue($this->siterequests(budget::DIRECTION_UNDER, 5.0)->is_met($context));
        $this->assertFalse($this->siterequests(budget::DIRECTION_OVER, 5.0)->is_met($context));

        $this->log(['cost' => null]);
        $this->log(['cost' => null]);
        $this->log(['cost' => null]);

        // Five made against a limit of five is a limit reached, as with money.
        $this->assertFalse($this->siterequests(budget::DIRECTION_UNDER, 5.0)->is_met($context));
        $this->assertTrue($this->siterequests(budget::DIRECTION_OVER, 5.0)->is_met($context));
    }

    public function test_requests_people_paid_for_themselves_are_not_the_sites_count(): void {
        $context = $this->context(\context_system::instance());
        $this->log(['cost' => null]);
        for ($i = 0; $i < 20; $i++) {
            $this->log(['cost' => null, 'keysource' => rule::KEYSOURCE_USER]);
        }

        // Somebody else's key, somebody else's allowance. One request is the site's.
        $this->assertTrue($this->siterequests(budget::DIRECTION_UNDER, 5.0)->is_met($context));
    }

    public function test_a_budget_counted_in_requests_has_to_be_whole(): void {
        $whole = [
            'budgetscope' => spend_ledger::SCOPE_SITE,
            'budgetmetric' => spend_ledger::METRIC_REQUESTS,
            'budgetamount' => '3000',
        ];
        $this->assertSame([], budget::validate_form($whole));

        // Half a request is not something anybody can make. In money it is ordinary.
        $this->assertArrayHasKey('budgetgroup', budget::validate_form(
            ['budgetamount' => '100.5'] + $whole,
        ));
        $this->assertSame([], budget::validate_form([
            'budgetscope' => spend_ledger::SCOPE_SITE,
            'budgetmetric' => spend_ledger::METRIC_COST,
            'budgetamount' => '100.5',
        ]));
    }

    public function test_a_request_budget_survives_the_trip_through_the_form(): void {
        $config = budget::read_from_form((object) [
            'budgetscope' => spend_ledger::SCOPE_COURSE,
            'budgetdirection' => budget::DIRECTION_OVER,
            'budgetmetric' => spend_ledger::METRIC_REQUESTS,
            'budgetamount' => '3000',
            'budgetperiod' => spend_ledger::PERIOD_MONTH,
            'budgetdays' => 30,
        ]);

        $this->assertSame(spend_ledger::METRIC_REQUESTS, $config['metric']);
        $this->assertSame(3000.0, $config['amount']);
        $this->assertSame(spend_ledger::METRIC_REQUESTS, budget::to_form_data($config)['budgetmetric']);
    }

    public function test_a_budget_written_before_this_setting_existed_is_about_money(): void {
        // Stored configuration from an earlier version carries no metric at all, and
        // every one of those was an amount of money.
        $this->assertSame(spend_ledger::METRIC_COST, (new budget([
            'scope' => spend_ledger::SCOPE_SITE,
            'direction' => budget::DIRECTION_UNDER,
            'amount' => 10.0,
        ]))->get_metric());
        $this->assertSame(spend_ledger::METRIC_COST, (new budget([
            'metric' => 'something else',
        ]))->get_metric());
        $this->assertSame(spend_ledger::METRIC_COST, budget::to_form_data([])['budgetmetric']);
    }

    public function test_a_request_budget_reads_as_a_sentence_of_its_own(): void {
        $money = $this->sitebudget(budget::DIRECTION_UNDER, 3000.0)->get_description();
        $requests = $this->siterequests(budget::DIRECTION_UNDER, 3000.0)->get_description();

        $this->assertStringNotContainsString('{$a', $requests);
        // The same number, said to be two different things. A description that could
        // not tell them apart would be the whole feature going unsaid on the screen
        // where somebody checks their rules.
        $this->assertNotSame($money, $requests);

        // Pinned in full, because a Behat scenario asserts on this wording and Behat
        // runs only in CI. Getting it wrong here is six jobs and several minutes away
        // from being found out; getting it wrong in a way this test can see is not.
        $this->assertSame('The site has made fewer than 3,000 requests in the last 30 days', $requests);
        $this->assertSame('The site has spent under 3000.00 USD in the last 30 days', $money);
    }

    public function test_the_registry_knows_the_type(): void {
        $this->assertTrue(registry::is_known('budget'));
        $this->assertInstanceOf(budget::class, registry::make('budget', []));
        // Last in the form, because it says least about what a rule is for.
        $types = registry::get_types();
        $this->assertSame('budget', end($types));
    }
}
