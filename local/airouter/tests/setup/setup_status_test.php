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

namespace local_airouter\setup;

use core_ai\aiactions\generate_text;
use core_ai\manager;
use core_ai\provider as ai_provider;
use local_airouter\condition\budget;
use local_airouter\eligibility_policy;
use local_airouter\managed_policy;
use local_airouter\provider;
use local_airouter\record\attempt_state;
use local_airouter\record\ledger;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\target_settings;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/mock/provider.php');
require_once(__DIR__ . '/../fixtures/fixture_unconfigured_provider.php');

/**
 * Tests for the setup page's steps.
 *
 * What these hold is the independent review's point about purposes: a step one
 * purpose needs is not a step every site must finish. A site that routes by rule
 * alone is not told to choose a default target, budgets in requests are not told to
 * enter rates, and a course key is not held up by the policy on personal keys. And
 * nothing the settings cannot answer is ever called done.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(setup_status::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(setup_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(byok_readiness::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(target_history::class)]
final class setup_status_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A provider instance that answers generate_text.
     *
     * @param string $name Its name.
     * @return ai_provider The instance.
     */
    protected function target(string $name): ai_provider {
        return \core\di::get(manager::class)->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: $name,
            enabled: true,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
    }

    /**
     * A rule delegating to one target.
     *
     * @param int $targetid Where it delegates.
     * @param array $conditions Its conditions.
     * @param string $keysource Who pays.
     */
    protected function rule(int $targetid, array $conditions = [], string $keysource = rule::KEYSOURCE_SITE): void {
        global $DB;

        $rule = new rule();
        $rule->set('name', 'Rule');
        $rule->set('targetid', $targetid);
        $rule->set('keysource', $keysource);
        (new rule_repository($DB))->save($rule, $conditions);
    }

    /**
     * The steps for one purpose, keyed by id.
     *
     * @param string $purpose The purpose.
     * @return setup_step[] The steps.
     */
    protected function steps(string $purpose): array {
        global $DB;

        $status = new setup_status($DB, time());
        $steps = [];
        foreach (array_merge($status->common_steps(), $status->purpose_steps($purpose)) as $step) {
            $steps[$step->id] = $step;
        }

        return $steps;
    }

    public function test_a_new_site_is_told_what_to_do_first(): void {
        $steps = $this->steps(setup_status::PURPOSE_ROUTE);

        $this->assertSame(setup_step::TODO, $steps['providers']->status);
        $this->assertSame(setup_step::TODO, $steps['routed']->status);
    }

    public function test_routing_by_rule_alone_does_not_ask_for_a_default_target(): void {
        $this->rule((int) $this->target('Local')->id);
        set_config('nomatch', provider::NOMATCH_DECLINE, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);

        $steps = $this->steps(setup_status::PURPOSE_ROUTE);

        $this->assertSame(setup_step::DONE, $steps['providers']->status);
        $this->assertSame(setup_step::DONE, $steps['routed']->status);
        $this->assertSame(setup_step::DONE, $steps['targets']->status);
    }

    public function test_seeing_a_request_go_through_is_only_done_once_one_has(): void {
        global $DB;

        $steps = $this->steps(setup_status::PURPOSE_ROUTE);
        $this->assertSame(setup_step::CHECK, $steps['verify']->status);

        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $generator->create_request();

        $steps = $this->steps(setup_status::PURPOSE_ROUTE);
        $this->assertSame(setup_step::DONE, $steps['verify']->status);
    }

    public function test_budgets_in_requests_need_no_rates(): void {
        $this->rule((int) $this->target('Metered')->id, ['budget' => [
            'scope' => ledger::SCOPE_SITE,
            'direction' => budget::DIRECTION_UNDER,
            'amount' => 10,
            'metric' => ledger::METRIC_REQUESTS,
            'period' => ledger::PERIOD_ROLLING,
            'days' => 30,
        ]]);

        $steps = $this->steps(setup_status::PURPOSE_BUDGET);

        $this->assertSame(setup_step::DONE, $steps['budgetrules']->status);
        $this->assertSame(setup_step::NOT_NEEDED, $steps['rates']->status);
    }

    public function test_where_a_request_goes_after_a_budget_is_something_to_look_at(): void {
        $target = $this->target('Cloud');
        set_config('defaulttarget', $target->id, 'local_airouter');
        $this->rule((int) $target->id, ['budget' => [
            'scope' => ledger::SCOPE_SITE,
            'direction' => budget::DIRECTION_UNDER,
            'amount' => 10,
            'metric' => ledger::METRIC_REQUESTS,
            'period' => ledger::PERIOD_ROLLING,
            'days' => 30,
        ]]);
        managed_policy::set_managed_actions([generate_text::class]);

        $step = $this->steps(setup_status::PURPOSE_BUDGET)['afterlimit'];

        // The site's decision, not a fault: shown, never marked done or to do.
        $this->assertSame(setup_step::CHECK, $step->status);
        $this->assertStringContainsString(
            get_string('setup:budget', 'local_airouter', 'Cloud'),
            implode(' ', $step->lines),
        );
    }

    public function test_a_course_key_is_not_held_up_by_the_policy_on_personal_keys(): void {
        $user = $this->steps(setup_status::PURPOSE_BYOK_USER);
        $course = $this->steps(setup_status::PURPOSE_BYOK_COURSE);

        // The policy starts as "nobody".
        $this->assertSame(setup_step::TODO, $user['eligibility']->status);
        $this->assertSame(setup_step::NOT_NEEDED, $course['eligibility']->status);
    }

    public function test_a_provider_whose_own_key_field_is_empty_is_not_ready_for_brought_keys(): void {
        global $DB;

        $empty = \core\di::get(manager::class)->create_provider_instance(
            classname: \local_airouter\fixture_unconfigured_provider::class,
            name: 'Empty key',
            enabled: true,
            config: [],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        (new target_settings($DB))->set_key_field((int) $empty->id, 'apikey');

        $rows = (new byok_readiness($DB, time()))->rows();

        $this->assertFalse(byok_readiness::is_ready($rows[(int) $empty->id]));
        $this->assertSame(
            get_string('byokready:siteempty', 'local_airouter'),
            byok_readiness::describe($rows[(int) $empty->id]),
        );
        $this->assertSame(setup_step::TODO, $this->steps(setup_status::PURPOSE_BYOK_USER)['keyfield']->status);
    }

    public function test_a_provider_taking_no_key_is_told_apart_from_one_nobody_has_answered_for(): void {
        global $DB;

        $answered = $this->target('Takes no key');
        $unanswered = $this->target('Not answered');
        (new target_settings($DB))->set_key_field((int) $answered->id, target_settings::NO_KEY);

        $rows = (new byok_readiness($DB, time()))->rows();

        $this->assertSame(
            get_string('byokready:takesnokey', 'local_airouter'),
            byok_readiness::describe($rows[(int) $answered->id]),
        );
        $this->assertSame(
            get_string('byokready:nofield', 'local_airouter'),
            byok_readiness::describe($rows[(int) $unanswered->id]),
        );
    }

    public function test_registered_keys_are_counted_by_scope_and_are_never_called_done(): void {
        global $DB;

        $target = $this->target('Own key');
        (new target_settings($DB))->set_key_field((int) $target->id, 'apikey');
        (new eligibility_policy())->save(eligibility_policy::ACCESS_EVERYBODY, []);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $generator->create_key([
            'userid' => (int) $this->getDataGenerator()->create_user()->id,
            'targetid' => (int) $target->id,
            'secret' => 'sk-one',
        ]);

        $rows = (new byok_readiness($DB, time()))->rows();
        $step = $this->steps(setup_status::PURPOSE_BYOK_USER)['registered'];

        $this->assertSame(1, $rows[(int) $target->id]->userkeys);
        $this->assertSame(0, $rows[(int) $target->id]->coursekeys);
        $this->assertSame(setup_step::CHECK, $step->status);
    }

    public function test_the_delivery_record_says_when_a_target_last_answered_and_failed(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $now = time();
        $request = $generator->create_request(['timestarted' => $now - HOURSECS]);
        $generator->create_attempt(['requestid' => $request->id, 'targetid' => 7, 'timeended' => $now - HOURSECS]);
        $generator->create_attempt([
            'requestid' => $request->id,
            'targetid' => 7,
            'state' => attempt_state::FAILED,
            'timeended' => $now - 60,
        ]);
        // Outside the period: not counted.
        $old = $generator->create_request(['timestarted' => $now - 60 * DAYSECS]);
        $generator->create_attempt(['requestid' => $old->id, 'targetid' => 8, 'timeended' => $now - 60 * DAYSECS]);

        $last = (new target_history($DB))->get_last($now);

        $this->assertSame($now - HOURSECS, $last[7]->lastsucceeded);
        $this->assertSame($now - 60, $last[7]->lastfailed);
        $this->assertArrayNotHasKey(8, $last);
        // A target nothing was sent to is not known, never fine.
        $this->assertSame(
            get_string('setup:delivery:none', 'local_airouter', target_history::DAYS),
            target_history::describe($last[9] ?? null),
        );
    }

    public function test_every_step_has_a_title_and_a_status_in_words(): void {
        foreach (setup_status::purposes() as $purpose) {
            foreach ($this->steps($purpose) as $step) {
                $this->assertNotSame('', $step->get_title());
                $this->assertNotSame('', $step->get_status_label());
            }
        }
    }
}
