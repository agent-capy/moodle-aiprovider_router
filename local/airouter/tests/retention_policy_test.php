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

use local_airouter\condition\budget;
use local_airouter\record\ledger;

/**
 * Tests for how long the site keeps its record, and what that has to be enough for.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(retention_policy::class)]
final class retention_policy_test extends \advanced_testcase {
    /** @var retention_policy The policy under test. */
    private retention_policy $policy;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->policy = new retention_policy($DB);
    }

    #[\Override]
    protected function tearDown(): void {
        global $CFG;
        unset($CFG->forced_plugin_settings['local_airouter']);
        parent::tearDown();
    }

    /**
     * Give the site a rule carrying a budget.
     *
     * @param string $period How the period is counted.
     * @param int $days How many days a rolling period covers.
     * @param bool $enabled Whether the rule is switched on.
     * @return int The rule id.
     */
    private function budget_rule(string $period, int $days = 30, bool $enabled = true): int {
        global $DB;

        $record = new rule();
        $record->set('name', 'While there is money left');
        $record->set('targetid', 3);
        $record->set('enabled', $enabled);

        return (int) (new rule_repository($DB))->save($record, ['budget' => self::budget_config($period, $days)])->get('id');
    }

    /**
     * A budget condition as stored.
     *
     * @param string $period How the period is counted.
     * @param int $days How many days a rolling period covers.
     * @return array The configuration.
     */
    private static function budget_config(string $period, int $days): array {
        return [
            'scope' => ledger::SCOPE_SITE,
            'direction' => budget::DIRECTION_UNDER,
            'metric' => ledger::METRIC_REQUESTS,
            'amount' => 100.0,
            'period' => $period,
            'days' => $days,
        ];
    }

    /**
     * Whether another connection could take the lock the retention is changed under, right now.
     *
     * @return bool True when it was free.
     */
    private function limits_lock_is_free(): bool {
        global $DB, $CFG;
        $original = $DB;
        $other = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $other->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
        try {
            // The lock factory binds to the connection that is $DB when it is made.
            $DB = $other;
            $lock = \core\lock\lock_config::get_lock_factory('local_airouter')->get_lock(retention_policy::LOCK, 0);
            if ($lock === false) {
                return false;
            }
            $lock->release();

            return true;
        } finally {
            $DB = $original;
            $other->dispose();
        }
    }

    public function test_negative_days_are_refused_before_anything_else_is_asked(): void {
        $problems = $this->policy->problems(-1, -1);

        $this->assertArrayHasKey(retention_policy::DETAIL_SETTING, $problems);
        $this->assertArrayHasKey(retention_policy::SUMMARY_SETTING, $problems);
    }

    public function test_summaries_are_kept_at_least_as_long_as_the_detail(): void {
        $this->assertArrayHasKey(retention_policy::SUMMARY_SETTING, $this->policy->problems(30, 10));
        $this->assertSame([], $this->policy->problems(30, 30));
        $this->assertSame([], $this->policy->problems(30, 0), 'For ever covers anything.');
        $this->assertSame([], $this->policy->problems(0, 30), 'Detail kept for ever asks nothing of the summaries.');
    }

    public function test_summaries_are_kept_at_least_as_long_as_the_furthest_limit_looks_back(): void {
        $this->budget_rule(ledger::PERIOD_ROLLING, 30);

        $this->assertArrayHasKey(retention_policy::SUMMARY_SETTING, $this->policy->problems(1, 29));
        $this->assertSame([], $this->policy->problems(1, 30));
        $this->assertSame([], $this->policy->problems(1, 0));
    }

    public function test_a_limit_on_a_key_counts_as_far_as_a_budget_does(): void {
        global $DB;
        $repository = new key_repository($DB);
        $key = $repository->save(key::SCOPE_USER, 7, 3, 'sk-their-own-key');
        $repository->set_cap($key, 10.0, ledger::PERIOD_MONTH, 30);

        // A calendar month is counted as thirty one days, the most one can be.
        $this->assertSame(31, $this->policy->longest_reach_days());
        $this->assertArrayHasKey(retention_policy::SUMMARY_SETTING, $this->policy->problems(1, 30));
        $this->assertSame([], $this->policy->problems(1, 31));
    }

    public function test_saving_records_both_figures_or_neither(): void {
        set_config(retention_policy::DETAIL_SETTING, 5, 'local_airouter');
        set_config(retention_policy::SUMMARY_SETTING, 50, 'local_airouter');
        $this->budget_rule(ledger::PERIOD_ROLLING, 30);

        $refused = $this->policy->save(2, 10);

        $this->assertArrayHasKey(retention_policy::SUMMARY_SETTING, $refused);
        $this->assertSame(5, $this->policy->get_detail_days(), 'Nothing saved: not even the figure that was fine.');
        $this->assertSame(50, $this->policy->get_summary_days());

        $this->assertSame([], $this->policy->save(2, 40));
        $this->assertSame(2, $this->policy->get_detail_days());
        $this->assertSame(40, $this->policy->get_summary_days());
    }

    public function test_a_limit_is_measured_against_what_is_kept_now(): void {
        set_config(retention_policy::SUMMARY_SETTING, 10, 'local_airouter');

        $short = $this->policy->shortfall_of(ledger::PERIOD_ROLLING, 30);
        $this->assertSame(30, $short->reach);
        $this->assertSame(10, $short->kept);
        $this->assertNull($this->policy->shortfall_of(ledger::PERIOD_ROLLING, 10));
        $this->assertNull($this->policy->shortfall(), 'No limit on the site, so nothing falls short.');
    }

    public function test_a_setting_fixed_in_config_php_cannot_be_refused_but_is_seen(): void {
        global $CFG;
        $this->budget_rule(ledger::PERIOD_ROLLING, 30);
        $this->assertSame([], $this->policy->save(1, 60));
        // Fixed beyond the reach of any screen, and shorter than the budget.
        $CFG->forced_plugin_settings['local_airouter'][retention_policy::SUMMARY_SETTING] = 2;

        $this->assertTrue(retention_policy::is_forced(retention_policy::SUMMARY_SETTING));
        $this->assertFalse(retention_policy::is_forced(retention_policy::DETAIL_SETTING));
        $this->assertSame(2, $this->policy->get_summary_days(), 'What the purge will read.');
        $short = $this->policy->shortfall();
        $this->assertSame(30, $short->reach);
        $this->assertSame(2, $short->kept);
    }

    public function test_a_forced_null_is_not_a_forced_setting(): void {
        global $CFG;
        $CFG->forced_plugin_settings['local_airouter'][retention_policy::SUMMARY_SETTING] = null;

        $this->assertFalse(retention_policy::is_forced(retention_policy::SUMMARY_SETTING));
    }

    public function test_a_rule_and_the_screen_refuse_the_same_thing(): void {
        // The same rule from both sides: a retention too short for a budget, and a
        // budget too long for the retention, are one comparison in one place.
        set_config(retention_policy::SUMMARY_SETTING, 10, 'local_airouter');

        $this->assertNotNull(budget::retention_problem(ledger::PERIOD_ROLLING, 30));
        $this->assertNull(budget::retention_problem(ledger::PERIOD_ROLLING, 10));
        $this->budget_rule(ledger::PERIOD_ROLLING, 10);
        $this->assertArrayHasKey(retention_policy::SUMMARY_SETTING, $this->policy->problems(1, 9));
    }

    public function test_the_retention_and_a_budget_are_not_decided_on_beside_each_other(): void {
        // C33. Each side checks the other before it writes. Checked apart, a retention
        // being shortened and a budget being switched on each passed its check before
        // the other was written, and the site kept a budget measured over history it
        // was throwing away. Both now decide under one lock.
        global $DB;
        $ruleid = $this->budget_rule(ledger::PERIOD_ROLLING, 90, false);
        $free = [];
        $policy = new class ($DB) extends retention_policy {
            /** @var \Closure|null What to do once, while deciding. */
            public ?\Closure $pause = null;

            #[\Override]
            public function longest_reach_days(): int {
                if ($this->pause !== null) {
                    $pause = $this->pause;
                    $this->pause = null;
                    $pause();
                }

                return parent::longest_reach_days();
            }
        };
        $policy->pause = function () use (&$free): void {
            $free['retention'] = $this->limits_lock_is_free();
        };
        $repository = new class ($DB) extends rule_repository {
            /** @var \Closure|null What to do once, while deciding. */
            public ?\Closure $pause = null;

            #[\Override]
            public function why_not_enabled(int $id): ?string {
                if ($this->pause !== null) {
                    $pause = $this->pause;
                    $this->pause = null;
                    $pause();
                }

                return parent::why_not_enabled($id);
            }
        };
        $repository->pause = function () use (&$free): void {
            $free['budget'] = $this->limits_lock_is_free();
        };

        $this->assertSame([], $policy->save(30, 100));
        $this->assertNull($repository->set_enabled($ruleid, true));

        $this->assertSame(['retention' => false, 'budget' => false], $free, 'Neither decided with the lock free.');
        $this->assertTrue($this->limits_lock_is_free(), 'And both let it go.');
    }

    public function test_a_budget_is_weighed_against_the_retention_as_stored_not_as_first_read(): void {
        // C33. get_config() keeps a copy for the rest of a request, so a request that
        // read the retention before another shortened it would weigh a budget against
        // the old figure, even under the lock. The comparison reads what is stored.
        // The copy is stood in for here: in the test process it is not kept.
        global $DB;
        set_config(retention_policy::SUMMARY_SETTING, 30, 'local_airouter');
        $stale = new class ($DB) extends retention_policy {
            #[\Override]
            public function get_summary_days(): int {
                // What a copy read before the retention was shortened says.
                return 0;
            }
        };

        $short = $stale->shortfall_of(ledger::PERIOD_ROLLING, 90);

        $this->assertNotNull($short);
        $this->assertSame(30, $short->kept);
    }

    public function test_a_rule_saved_switched_on_with_a_budget_past_the_retention_is_not_saved(): void {
        // C33. The form asks first; the repository asks again under the lock, so that
        // a retention shortened in between is not saved round.
        global $DB;
        // The refusal rolls its transaction back; on PostgreSQL the test's own
        // transaction would then refuse to commit.
        $this->preventResetByRollback();
        set_config(retention_policy::SUMMARY_SETTING, 30, 'local_airouter');

        try {
            $this->budget_rule(ledger::PERIOD_ROLLING, 90);
            $this->fail('A budget reaching past the retention should have been refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('condition:budget:error:retention', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(rule::TABLE));

        // Switched off, it sets no limit yet, and is kept.
        $this->budget_rule(ledger::PERIOD_ROLLING, 90, false);
        $this->assertSame(1, $DB->count_records(rule::TABLE));
    }

    public function test_a_retention_that_cannot_take_the_lock_saves_nothing(): void {
        global $DB, $CFG;
        set_config(retention_policy::SUMMARY_SETTING, 100, 'local_airouter');
        $original = $DB;
        $other = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $other->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
        $held = false;
        try {
            $DB = $other;
            $held = \core\lock\lock_config::get_lock_factory('local_airouter')->get_lock(retention_policy::LOCK, 0);
            $DB = $original;
            $this->assertNotFalse($held);
            $impatient = new class ($DB) extends retention_policy {
                /** @var int Not waiting, so that the test does not. */
                public const LOCK_TIMEOUT = 0;
            };
            try {
                $impatient->save(10, 20);
                $this->fail('A busy lock should have been said.');
            } catch (\moodle_exception $e) {
                $this->assertSame('limits:error:busy', $e->errorcode);
            }
        } finally {
            if ($held !== false) {
                $held->release();
            }
            $DB = $original;
            $other->dispose();
        }

        $this->assertSame(100, (int) get_config('local_airouter', retention_policy::SUMMARY_SETTING));
    }
}
