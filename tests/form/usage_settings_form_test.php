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

namespace aiprovider_router\form;

use aiprovider_router\condition\budget;
use aiprovider_router\rule;
use aiprovider_router\rule_repository;
use aiprovider_router\spend_ledger;

/**
 * Tests for what a site is allowed to throw away.
 *
 * Budgets are worked out from what is still stored. Removing history a budget still
 * reaches back into does not make the figure unknown, which is what this plugin does
 * everywhere else it cannot measure something; it makes it a smaller number. A limit
 * that had been reached is then under the limit again, and the requests it was
 * stopping start going through.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_settings_form::class)]
final class usage_settings_form_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Give the site a rule carrying a budget.
     *
     * @param string $period How the period is counted.
     * @param int $days How many days a rolling period covers.
     */
    protected function budget_rule(string $period, int $days = 30): void {
        global $DB;

        $record = new rule();
        $record->set('name', 'While there is money left');
        $record->set('targetid', 3);
        (new rule_repository($DB))->save($record, ['budget' => [
            'scope' => spend_ledger::SCOPE_SITE,
            'direction' => budget::DIRECTION_UNDER,
            'metric' => spend_ledger::METRIC_COST,
            'amount' => 100.0,
            'period' => $period,
            'days' => $days,
        ]]);
    }

    /**
     * Put a set of retention figures to the form's validation.
     *
     * @param int $detail Days of detail to keep.
     * @param int $summary Days of summary to keep.
     * @return array The errors, keyed by field.
     */
    protected function validate(int $detail, int $summary): array {
        $form = new usage_settings_form(new \moodle_url('/ai/provider/router/usage.php'));

        return $form->validation([
            'logretentiondays' => $detail,
            'summaryretentiondays' => $summary,
            'budgetnotifyshare' => 80,
        ], []);
    }

    public function test_a_site_with_no_budget_may_keep_as_little_as_it_likes(): void {
        $this->assertArrayNotHasKey('summaryretentiondays', $this->validate(1, 2));
    }

    public function test_keeping_less_than_a_rolling_budget_reaches_back_is_refused(): void {
        $this->budget_rule(spend_ledger::PERIOD_ROLLING, 30);

        $errors = $this->validate(1, 2);

        $this->assertArrayHasKey('summaryretentiondays', $errors);
    }

    public function test_keeping_less_than_a_month_is_refused_for_a_monthly_budget(): void {
        // A calendar month is counted as the longest one can be.
        $this->budget_rule(spend_ledger::PERIOD_MONTH);

        $this->assertArrayHasKey('summaryretentiondays', $this->validate(1, 30));
        $this->assertArrayNotHasKey('summaryretentiondays', $this->validate(1, 31));
    }

    public function test_keeping_everything_for_ever_is_always_allowed(): void {
        $this->budget_rule(spend_ledger::PERIOD_MONTH);

        // Zero is not a short period, it is no limit at all.
        $this->assertArrayNotHasKey('summaryretentiondays', $this->validate(1, 0));
    }
}
