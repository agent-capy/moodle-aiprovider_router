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

use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;

/**
 * Tests for walking the rules in priority order.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rule_evaluator::class)]
final class rule_evaluator_test extends \advanced_testcase {
    /** @var rule_repository Where the rules live. */
    protected rule_repository $repository;

    /** @var rule_evaluator The evaluator under test. */
    protected rule_evaluator $evaluator;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->repository = new rule_repository($DB);
        $this->evaluator = new rule_evaluator($this->repository);
    }

    /**
     * Save a rule.
     *
     * @param string $name The rule name.
     * @param int $targetid Where it delegates.
     * @param array[] $conditions Configuration keyed by condition type.
     * @return rule The saved rule.
     */
    protected function add(string $name, int $targetid, array $conditions = []): rule {
        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);

        return $this->repository->save($rule, $conditions);
    }

    /**
     * A request from a system context.
     *
     * @param string $prompt What was asked.
     * @return evaluation_context The context.
     */
    protected function request(string $prompt = 'Hello'): evaluation_context {
        return new evaluation_context(
            new generate_text(contextid: \context_system::instance()->id, userid: 0, prompttext: $prompt),
            new token_estimator(cjkratio: 1.0, otherratio: 4.0),
        );
    }

    /**
     * The names of the rules a request matches, in order.
     *
     * @param evaluation_context $context The request.
     * @param int|null $now The moment to evaluate at.
     * @return string[] The rule names.
     */
    protected function matched(evaluation_context $context, ?int $now = null): array {
        $names = [];
        foreach ($this->evaluator->matches($context, $now ?? time()) as $rule) {
            $names[] = $rule->get('name');
        }

        return $names;
    }

    public function test_a_rule_with_no_conditions_matches_everything(): void {
        $this->add('catch all', 7);

        $this->assertSame(['catch all'], $this->matched($this->request()));
    }

    public function test_every_condition_has_to_be_met(): void {
        $this->add('both', 7, [
            'action' => ['actions' => [generate_text::class]],
            'promptlength' => ['operator' => 'gte', 'characters' => 1000],
        ]);

        // The action matches and the length does not, so the rule does not.
        $this->assertSame([], $this->matched($this->request()));
    }

    public function test_any_value_within_a_condition_will_do(): void {
        $this->add('either action', 7, [
            'action' => ['actions' => [summarise_text::class, generate_text::class]],
        ]);

        $this->assertSame(['either action'], $this->matched($this->request()));
    }

    public function test_matches_come_back_in_priority_order(): void {
        $this->add('first', 7);
        $this->add('second', 8);
        $third = $this->add('third', 9);
        $this->repository->move((int) $third->get('id'), -1);

        $this->assertSame(['first', 'third', 'second'], $this->matched($this->request()));
    }

    public function test_rules_are_only_evaluated_until_the_caller_stops_asking(): void {
        $this->add('first', 7);
        $this->add('second', 8);

        // The caller takes the first rule it can use, which is not always the first that
        // matched, so matches are produced one at a time rather than all at once.
        $generator = $this->evaluator->matches($this->request(), time());
        $first = $generator->current();

        $this->assertSame('first', $first->get('name'));
        $this->assertTrue($generator->valid());
    }

    public function test_a_disabled_rule_is_not_evaluated(): void {
        $disabled = $this->add('disabled', 7);
        $this->repository->set_enabled((int) $disabled->get('id'), false);
        $this->add('enabled', 8);

        $this->assertSame(['enabled'], $this->matched($this->request()));
    }

    public function test_a_rule_outside_its_window_is_not_evaluated(): void {
        $rule = new rule();
        $rule->set('name', 'expired');
        $rule->set('targetid', 7);
        $rule->set('timeend', 1000);
        $this->repository->save($rule);

        // Not "matched but expired". There is no such state to explain to anyone.
        $this->assertSame([], $this->matched($this->request(), 2000));
        $this->assertSame(['expired'], $this->matched($this->request(), 500));
    }

    public function test_a_condition_this_version_does_not_know_is_not_met(): void {
        $this->add('from the future', 7, ['futurecondition' => ['limit' => 100]]);

        // A rule written on a newer version stops matching rather than matching every
        // request on the site.
        $this->assertSame([], $this->matched($this->request()));
    }

    public function test_the_trace_says_why_each_rule_did_not_match(): void {
        $this->add('wrong action', 7, ['action' => ['actions' => [summarise_text::class]]]);
        $this->add('too short', 8, ['promptlength' => ['operator' => 'gte', 'characters' => 1000]]);
        $this->add('matches', 9);

        $trace = array_values($this->evaluator->trace($this->request(), time()));

        $this->assertSame(['action'], $trace[0]['unmet']);
        $this->assertFalse($trace[0]['matched']);
        $this->assertSame(['promptlength'], $trace[1]['unmet']);
        $this->assertTrue($trace[2]['matched']);
    }

    public function test_the_trace_shows_a_rule_whose_window_is_shut(): void {
        $rule = new rule();
        $rule->set('name', 'expired');
        $rule->set('targetid', 7);
        $rule->set('timeend', 1000);
        $this->repository->save($rule);

        $trace = array_values($this->evaluator->trace($this->request(), 2000));

        $this->assertFalse($trace[0]['active']);
        $this->assertFalse($trace[0]['matched']);
        // Its conditions were never looked at, so none of them are reported as failing.
        $this->assertSame([], $trace[0]['unmet']);
    }

    public function test_the_trace_covers_disabled_rules_too(): void {
        $disabled = $this->add('disabled', 7);
        $this->repository->set_enabled((int) $disabled->get('id'), false);

        // The rule tester has to show a rule that is switched off, because "why did my
        // rule not fire" is very often answered by that.
        $this->assertCount(1, $this->evaluator->trace($this->request(), time()));
    }

    /**
     * A budget of one request over the whole site, in the direction given.
     *
     * Counted in requests rather than money so that the figure is always known: a site
     * with no rates entered has spent nothing however much it has used.
     *
     * @param string $direction Which side of the limit the rule wants.
     * @return array The condition configuration.
     */
    protected function onerequest(string $direction = condition\budget::DIRECTION_UNDER): array {
        return [
            'scope' => spend_ledger::SCOPE_SITE,
            'direction' => $direction,
            'amount' => 1,
            'metric' => spend_ledger::METRIC_REQUESTS,
            'period' => spend_ledger::PERIOD_ROLLING,
            'days' => 30,
        ];
    }

    /**
     * Record one request already made.
     */
    protected function spend_one(): void {
        global $DB;

        $DB->insert_record(usage_logger::TABLE, (object) [
            'timecreated' => time() - HOURSECS,
            'userid' => 5,
            'contextid' => \context_system::instance()->id,
            'actionname' => 'generate_text',
            'targetid' => 1,
            'targetname' => 'Target',
            'targetprovider' => 'aiprovider_openai',
            'success' => 1,
            'attempts' => 1,
            'keysource' => usage_logger::KEY_SITE,
        ]);
        \core_cache\helper::purge_by_definition('local_airouter', spend_ledger::CACHE_AREA);
    }

    public function test_a_rule_stopped_only_by_its_budget_is_remembered_as_such(): void {
        // The difference the router has to be able to see: this rule was about this
        // request in every other respect, and the money is what stopped it.
        $rule = $this->add('metered', 7, ['budget' => $this->onerequest()]);
        $this->spend_one();

        $this->assertSame([], $this->matched($this->request()));
        $this->assertSame([(int) $rule->get('id')], array_keys($this->evaluator->get_budget_blocked()));
    }

    public function test_a_rule_that_was_never_about_this_request_is_not_a_spent_budget(): void {
        // Wrong action as well as no money left. Reporting this as a budget having run
        // out would stop a request the rule had nothing to do with.
        $this->add('metered elsewhere', 7, [
            'action' => ['actions' => [summarise_text::class]],
            'budget' => $this->onerequest(),
        ]);
        $this->spend_one();

        $this->assertSame([], $this->matched($this->request()));
        $this->assertSame([], $this->evaluator->get_budget_blocked());
    }

    public function test_a_budget_with_room_left_leaves_nothing_to_report(): void {
        $this->add('metered', 7, ['budget' => $this->onerequest()]);

        $this->assertSame(['metered'], $this->matched($this->request()));
        $this->assertSame([], $this->evaluator->get_budget_blocked());
    }

    public function test_a_limit_that_has_not_been_reached_yet_is_not_a_limit_that_has_run_out(): void {
        // A rule that asks to be used once the site has passed a limit. Before it is
        // passed the rule does not apply, which is the ordinary state of the first day
        // of the month -- not a refusal, and nothing for the router to stop on. Reading
        // it as one stopped every request a coexisting site made.
        $this->add('over the line', 7, ['budget' => $this->onerequest(condition\budget::DIRECTION_OVER)]);

        $this->assertSame([], $this->matched($this->request()));
        $this->assertSame([], $this->evaluator->get_budget_blocked());
    }

    public function test_a_limit_that_has_been_reached_still_stops_the_request(): void {
        // The other half of the test above: the same rule, once the limit is passed,
        // matches outright. What must not happen is the failure being read as a refusal.
        $this->add('over the line', 7, ['budget' => $this->onerequest(condition\budget::DIRECTION_OVER)]);
        $this->spend_one();

        $this->assertSame(['over the line'], $this->matched($this->request()));
        $this->assertSame([], $this->evaluator->get_budget_blocked());
    }

    public function test_a_course_budget_asked_about_no_course_is_not_a_budget_running_out(): void {
        // There is no course here, so there is no budget here to be inside or past.
        // The rule does not apply; nobody has run out of anything.
        $this->add('course metered', 7, [
            'budget' => ['scope' => spend_ledger::SCOPE_COURSE] + $this->onerequest(),
        ]);
        $this->spend_one();

        $this->assertSame([], $this->matched($this->request()));
        $this->assertSame([], $this->evaluator->get_budget_blocked());
    }

    public function test_an_unfinished_budget_is_not_a_budget_running_out(): void {
        // A condition somebody started and did not finish narrows the rule. It says
        // nothing at all about money, so it must not end the request.
        $this->add('half written', 7, ['budget' => ['amount' => 0] + $this->onerequest()]);

        $this->assertSame([], $this->matched($this->request()));
        $this->assertSame([], $this->evaluator->get_budget_blocked());
    }

    public function test_what_the_last_walk_found_replaces_what_the_one_before_found(): void {
        $rule = $this->add('metered', 7, ['budget' => $this->onerequest()]);
        $this->spend_one();
        $this->matched($this->request());
        $this->assertNotEmpty($this->evaluator->get_budget_blocked());

        // An evaluator is reused for the life of a request, and a verdict left over
        // from the walk before would stop a request nothing had decided anything about.
        $this->repository->delete((int) $rule->get('id'));
        $this->matched($this->request());

        $this->assertSame([], $this->evaluator->get_budget_blocked());
    }
}
