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

namespace local_airouter\check;

use local_airouter\eligibility_policy;
use local_airouter\key;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\price;
use local_airouter\record\ledger;
use local_airouter\record\summariser;
use local_airouter\record\usage_recorder;
use local_airouter\key_repository;
use core\check\result;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/fixture_action.php');
require_once(__DIR__ . '/../fixtures/fixture_text_provider.php');
require_once(__DIR__ . '/../fixtures/fixture_other_provider.php');
require_once(__DIR__ . '/../fixtures/fixture_unconfigured_provider.php');

/**
 * Tests for the status checks the router reports on the site status report.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(byokkeys::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(byokeligibility::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(budgetrates::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(budgethistory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ruletargets::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recordgaps::class)]
final class check_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Every check that stands down on a site that has not set the router up.
     *
     * @return string[] The check class names.
     */
    protected static function all_checks(): array {
        return [
            ruletargets::class,
            byokkeys::class,
            byokeligibility::class,
            budgetrates::class,
            budgethistory::class,
            recordgaps::class,
        ];
    }

    /**
     * Set the router up, and hand the check back to be run.
     *
     * @param base $check The check.
     * @return base The same check.
     */
    protected function with_router(base $check): base {
        set_config('defaulttarget', 99, 'local_airouter');

        return $check;
    }

    public function test_every_check_stands_down_until_the_router_is_set_up(): void {
        foreach (self::all_checks() as $class) {
            $result = (new $class())->get_result();
            $this->assertSame(result::NA, $result->get_status(), $class);
            $this->assertSame(get_string('check:norouter', 'local_airouter'), $result->get_summary(), $class);
        }
    }

    public function test_a_site_that_routes_is_watched(): void {
        // The router is not a provider instance, so there is no instance to ask for; a
        // site that has given it something to delegate to is a site that routes.
        $result = $this->with_router(new recordgaps())->get_result();

        $this->assertNotSame(result::NA, $result->get_status());
    }

    public function test_keys_nobody_has_brought_are_nothing_to_report_on(): void {
        $check = $this->with_router(new byokkeys());

        $this->assertSame(result::NA, $check->get_result()->get_status());
    }

    public function test_keys_that_can_be_read_are_reported_as_such(): void {
        global $DB;
        (new key_repository($DB))->save(key::SCOPE_USER, 7, 3, 'sk-a-key-abcd');

        $result = ($this->with_router(new byokkeys()))->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }

    public function test_keys_that_cannot_be_read_are_an_error_rather_than_a_warning(): void {
        global $DB;
        $repository = new key_repository($DB);
        $repository->save(key::SCOPE_USER, 7, 3, 'sk-a-key-abcd');
        $broken = $repository->save(key::SCOPE_USER, 8, 3, 'sk-another-efgh');
        // A site restored from a database backup without its encryption key file.
        $DB->set_field(key::TABLE, 'secret', 'nonsense', ['id' => $broken->get('id')]);

        $result = ($this->with_router(new byokkeys()))->get_result();

        // Every request those keys were meant to pay for is failing, so this is not a
        // thing to mention in passing.
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('1', $result->get_summary());
    }

    public function test_a_budget_over_history_the_site_discarded_is_reported(): void {
        global $DB;

        // The site ran with a short retention, the purge threw the older part away,
        // and only afterwards was a thirty day budget written. That order is the
        // whole of it: once the budget exists the purge protects what it needs, so
        // the only way to lose the history is to lose it first. The budget then reads
        // a real figure that is smaller than the spending was, and nothing else on
        // the site says so.
        $this->rate();
        $this->request(1.0, time() - 10 * DAYSECS);
        set_config('logretentiondays', 1, 'local_airouter');
        set_config('summaryretentiondays', 2, 'local_airouter');
        (new summariser($DB))->run(time());
        set_config('summaryretentiondays', 60, 'local_airouter');
        $this->budget_rule();

        // Both tables are empty now, which is the shape of a site that has never used
        // its AI at all. It is not one.
        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE));
        $this->assertSame(0, $DB->count_records(summariser::TABLE));

        $result = ($this->with_router(new budgethistory()))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertNotSame('', $result->get_details());
    }

    public function test_a_site_that_has_discarded_nothing_is_not_reported(): void {
        $this->budget_rule();
        $this->request(1.0);

        $result = ($this->with_router(new budgethistory()))->get_result();

        // One day of history and a thirty day budget. Nothing is missing: the site
        // has everything that ever happened, which is all a budget can ask for.
        $this->assertSame(result::OK, $result->get_status());
    }

    public function test_a_budget_inside_what_the_site_still_covers_is_not_reported(): void {
        global $DB;

        // Something was discarded, but long enough ago that no budget reaches it.
        // The short retention it was discarded under is gone too: kept, it would be
        // reported on its own account, as a retention the budget does not fit in.
        $this->rate();
        $this->budget_rule();
        $this->request(1.0, time() - 200 * DAYSECS);
        set_config('logretentiondays', 1, 'local_airouter');
        set_config('summaryretentiondays', 2, 'local_airouter');
        (new summariser($DB))->run(time() - 150 * DAYSECS);
        set_config('summaryretentiondays', 60, 'local_airouter');

        $result = ($this->with_router(new budgethistory()))->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }

    public function test_summaries_kept_shorter_than_a_limit_are_reported_before_anything_is_lost(): void {
        global $CFG;
        // The screen and the rule form both refuse this, so it got here by way of
        // config.php. Nothing has been thrown away yet, and the check says so now,
        // while it is still worth saying.
        $this->rate();
        $this->budget_rule();
        $CFG->forced_plugin_settings['local_airouter'][\local_airouter\retention_policy::SUMMARY_SETTING] = 2;
        try {
            $result = ($this->with_router(new budgethistory()))->get_result();
        } finally {
            unset($CFG->forced_plugin_settings['local_airouter']);
        }

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('2 days', $result->get_summary());
        $this->assertStringContainsString('config.php', $result->get_details());
    }

    public function test_a_limit_on_a_key_is_enough_for_the_history_to_matter(): void {
        global $DB;
        // No rule sets a budget, but somebody has put a limit on their own key, and
        // that limit is measured from the same record.
        $repository = new \local_airouter\key_repository($DB);
        $repository->set_cap($repository->save(\local_airouter\key::SCOPE_USER, 7, 3, 'sk-mine'), 5.0, ledger::PERIOD_ROLLING, 30);
        set_config('summaryretentiondays', 2, 'local_airouter');

        $result = ($this->with_router(new budgethistory()))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
    }

    public function test_without_a_budget_there_is_nothing_to_report(): void {
        $this->request(1.0);

        $result = ($this->with_router(new budgethistory()))->get_result();

        $this->assertSame(result::NA, $result->get_status());
    }

    /**
     * Give the site a rule that routes by budget.
     *
     * @param string $metric What the budget counts.
     */
    protected function budget_rule(string $metric = ledger::METRIC_COST): void {
        global $DB;
        $rule = new rule();
        $rule->set('name', 'While there is money left');
        $rule->set('targetid', 3);
        (new rule_repository($DB))->save($rule, ['budget' => [
            'scope' => ledger::SCOPE_SITE,
            'direction' => 'under',
            'metric' => $metric,
            'provider' => $metric === ledger::METRIC_COST ? 'aiprovider_openai' : '',
            'amount' => 100.0,
            'period' => ledger::PERIOD_ROLLING,
            'days' => 30,
        ]]);
    }

    /**
     * Write one recorded request, as the request and attempt records hold it.
     *
     * @param float|null $cost What it cost, or null when no rate covered it.
     * @param int|null $when When it ended, or null for an hour ago.
     */
    protected function request(?float $cost, ?int $when = null): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $when ??= time() - HOURSECS;
        $request = $generator->create_request([
            'userid' => 5,
            'answeredby' => 1,
            'timestarted' => $when,
            'timeended' => $when,
        ]);
        $generator->create_attempt([
            'requestid' => $request->id,
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'cost' => $cost,
            'currency' => $cost === null ? null : 'USD',
            'timestarted' => $when,
            'timeended' => $when,
        ]);
    }

    /**
     * Enter a rate for the provider the requests above went to, in dollars.
     */
    protected function rate(): void {
        $rate = new price();
        $rate->set('provider', 'aiprovider_openai');
        $rate->set('currency', 'USD');
        $rate->set('promptrate', 1.0);
        $rate->create();
    }

    public function test_rates_are_only_load_bearing_once_a_rule_routes_by_budget(): void {
        $this->request(null);

        $result = ($this->with_router(new budgetrates()))->get_result();

        // Rates are worth having anyway, for the monitor. Nothing here depends on them.
        $this->assertSame(result::NA, $result->get_status());
    }

    public function test_a_budget_rule_with_no_rates_at_all_is_an_error(): void {
        $this->budget_rule();
        $this->request(null);
        $this->request(null);

        $result = ($this->with_router(new budgetrates()))->get_result();

        // The rule is enabled, looks right, and can never match. Nothing else on the
        // site would say so, and it says which provider has no rates.
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('OpenAI', $result->get_summary());
    }

    public function test_a_site_routing_by_request_counts_needs_no_rates(): void {
        $this->budget_rule(ledger::METRIC_REQUESTS);
        $this->request(null);
        $this->request(null);

        $result = ($this->with_router(new budgetrates()))->get_result();

        // Requests are counted, not priced. Telling this site its budgets can never
        // match would send somebody looking for a problem it does not have.
        $this->assertSame(result::NA, $result->get_status());
    }

    public function test_a_mostly_unpriced_site_is_told_its_budgets_understate(): void {
        $this->rate();
        $this->budget_rule();
        $this->request(1.0);
        $this->request(null);
        $this->request(null);
        $this->request(null);

        $result = ($this->with_router(new budgetrates()))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
    }

    public function test_a_priced_site_with_budget_rules_is_fine(): void {
        $this->rate();
        $this->budget_rule();
        $this->request(1.0);
        $this->request(2.0);

        $result = ($this->with_router(new budgetrates()))->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }

    public function test_a_site_that_has_recorded_nothing_is_not_told_off(): void {
        $this->rate();
        $this->budget_rule();

        $result = ($this->with_router(new budgetrates()))->get_result();

        $this->assertSame(result::NA, $result->get_status());
    }

    public function test_a_budget_at_a_provider_with_no_rates_is_an_error_before_any_traffic(): void {
        // The budget names a provider, and its currency is the currency of that
        // provider's rates. With none, the budget has no currency to be in and can
        // never be measured, whatever the rest of the site has priced.
        $this->budget_rule();

        $result = ($this->with_router(new budgetrates()))->get_result();

        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('OpenAI', $result->get_summary());
    }

    public function test_every_check_offers_somewhere_to_go_and_has_a_name(): void {
        foreach (self::all_checks() as $class) {
            $check = $this->with_router(new $class());
            $this->assertNotEmpty($check->get_name(), $class);
            $this->assertNotNull($check->get_action_link(), $class);
        }
    }

    /**
     * A custom profile field with the visibility settings given.
     *
     * @param string $shortname Its short name.
     * @param array $settings visible / locked / signup, over the defaults.
     */
    protected function profile_field(string $shortname, array $settings): void {
        global $DB;

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => $shortname,
            'name' => ucfirst($shortname),
        ]);
        foreach ($settings as $name => $value) {
            $DB->set_field('user_info_field', $name, $value, ['shortname' => $shortname]);
        }
    }

    public function test_a_site_deciding_by_no_conditions_has_no_eligibility_to_report_on(): void {
        $check = $this->with_router(new byokeligibility());

        $this->assertSame(result::NA, $check->get_result()->get_status());
    }

    public function test_a_policy_on_a_field_only_staff_can_change_is_fine(): void {
        $this->profile_field('checked', ['visible' => 1, 'locked' => 1, 'signup' => 0]);
        (new eligibility_policy())->save(eligibility_policy::ACCESS_CONDITIONS, [
            'profilefield' => ['field' => 'checked', 'values' => ['staff']],
        ]);

        $check = $this->with_router(new byokeligibility());

        $this->assertSame(result::OK, $check->get_result()->get_status());
    }

    public function test_a_policy_people_can_admit_themselves_to_is_a_warning(): void {
        $this->profile_field('selfsaid', ['visible' => 1, 'locked' => 0, 'signup' => 0]);
        (new eligibility_policy())->save(eligibility_policy::ACCESS_CONDITIONS, [
            'profilefield' => ['field' => 'selfsaid', 'values' => ['staff']],
        ]);

        $result = ($this->with_router(new byokeligibility()))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('selfsaid', $result->get_details());
    }

    public function test_requiring_every_condition_makes_a_self_declared_field_worth_less(): void {
        // With every condition required, the self declared one narrows the policy
        // rather than opening it: somebody still has to satisfy the others.
        $this->profile_field('selfsaid', ['visible' => 1, 'locked' => 0, 'signup' => 0]);
        (new eligibility_policy())->save(
            eligibility_policy::ACCESS_CONDITIONS,
            ['profilefield' => ['field' => 'selfsaid', 'values' => ['staff']]],
            eligibility_policy::MATCH_ALL,
        );

        $result = ($this->with_router(new byokeligibility()))->get_result();

        $this->assertSame(result::INFO, $result->get_status());
    }


    public function test_calls_recorded_in_another_currency_than_the_rates_are_reported(): void {
        global $DB;
        $this->rate();
        $this->budget_rule();
        $this->request(1.0);
        // One call recorded in yen against dollar rates: a correction that did not
        // reach it, or one that it slipped past.
        $DB->set_field(usage_recorder::ATTEMPT_TABLE, 'currency', 'JPY', ['model' => 'gpt-4o']);

        $result = ($this->with_router(new budgetrates()))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('OpenAI', $result->get_summary());
    }
}
