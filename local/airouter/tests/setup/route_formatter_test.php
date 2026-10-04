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
use local_airouter\rule;
use local_airouter\target_resolver;

/**
 * Tests for the words used about what the settings offer.
 *
 * Built from inspections made by hand, so that each sentence can be tied to the one
 * fact it is there to state. The three that matter most are the ones an
 * administrator is least likely to work out alone: where a site-paid request goes
 * when its target fails, where a request a budget turns away goes, and what happens
 * to an action that stops being routed.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(route_formatter::class)]
final class route_formatter_test extends \basic_testcase {
    /**
     * An inspection of generate_text.
     *
     * @param array $overrides Values to change from a routed action with a usable default target.
     * @return route_inspection The inspection.
     */
    protected static function inspection(array $overrides = []): route_inspection {
        $values = $overrides + [
            'applied' => route_inspection::APPLIED_ROUTED,
            'state' => route_inspection::STATE_READY,
            'defaulttargetid' => 7,
            'defaulttargetname' => 'Cloud',
            'defaultproblem' => null,
            'declines' => false,
            'rules' => [],
            'corefirst' => 'First in order',
        ];

        return new route_inspection(
            ltrim(generate_text::class, '\\'),
            $values['applied'],
            $values['state'],
            $values['defaulttargetid'],
            $values['defaulttargetname'],
            $values['defaultproblem'],
            $values['declines'],
            $values['rules'],
            $values['corefirst'],
        );
    }

    /**
     * A rule as the inspector reports it.
     *
     * @param array $overrides Values to change from a usable site-paid rule.
     * @return route_rule The rule.
     */
    protected static function rule(array $overrides = []): route_rule {
        $values = $overrides + [
            'name' => 'Teachers',
            'keysource' => rule::KEYSOURCE_SITE,
            'targetname' => 'Local',
            'problem' => null,
            'hasbudget' => false,
        ];

        return new route_rule(
            1,
            $values['name'],
            $values['keysource'],
            $values['keysource'] !== rule::KEYSOURCE_SITE,
            3,
            $values['targetname'],
            $values['problem'],
            $values['hasbudget'],
        );
    }

    public function test_a_site_paid_rule_falls_back_to_the_default_target(): void {
        $text = implode(' ', route_formatter::explain(self::inspection(['rules' => [self::rule()]])));

        $this->assertStringContainsString(
            get_string('setup:fallback:default', 'local_airouter', 'Cloud'),
            $text,
        );
    }

    public function test_declining_unclaimed_requests_also_stops_the_fallback(): void {
        $text = implode(' ', route_formatter::explain(self::inspection([
            'state' => route_inspection::STATE_CONDITIONAL,
            'declines' => true,
            'rules' => [self::rule()],
        ])));

        $this->assertStringContainsString(get_string('setup:fallback:decline', 'local_airouter'), $text);
        $this->assertStringContainsString(get_string('setup:unmatched:decline', 'local_airouter'), $text);
    }

    public function test_a_budget_does_not_stop_spending_while_unclaimed_requests_go_to_the_default(): void {
        $text = implode(' ', route_formatter::explain(self::inspection([
            'rules' => [self::rule(['hasbudget' => true])],
        ])));

        $this->assertStringContainsString(get_string('setup:budget', 'local_airouter', 'Cloud'), $text);
    }

    public function test_a_brought_key_rule_falls_back_only_to_the_payers_keys(): void {
        $text = implode(' ', route_formatter::explain(self::inspection([
            'state' => route_inspection::STATE_CONDITIONAL,
            'declines' => true,
            'rules' => [self::rule(['keysource' => rule::KEYSOURCE_USER])],
        ])));

        $this->assertStringContainsString(get_string('setup:fallback:brought', 'local_airouter'), $text);
        $this->assertStringContainsString(get_string('setup:state:conditional_brought', 'local_airouter'), $text);
        $this->assertStringNotContainsString(get_string('setup:fallback:decline', 'local_airouter'), $text);
    }

    public function test_a_broken_rule_is_named_with_its_reason_and_escaped(): void {
        $text = implode(' ', route_formatter::explain(self::inspection([
            'rules' => [self::rule(['name' => '<i>Gone</i>', 'targetname' => null, 'problem' => target_resolver::PROBLEM_MISSING])],
        ])));

        $this->assertStringContainsString('&lt;i&gt;Gone&lt;/i&gt;', $text);
        $this->assertStringNotContainsString('<i>Gone</i>', $text);
        $this->assertStringContainsString(get_string('setup:missingtarget', 'local_airouter', 3), $text);
        $this->assertStringContainsString(get_string('ruletest:reason:missing', 'local_airouter'), $text);
    }

    public function test_the_best_case_still_says_a_target_is_available_and_nothing_more(): void {
        $label = route_formatter::state_label(self::inspection());

        $this->assertSame(get_string('setup:state:ready', 'local_airouter'), $label);
    }

    public function test_a_routed_action_says_what_stopping_would_hand_back(): void {
        $html = route_formatter::describe(self::inspection());

        $this->assertStringContainsString(get_string('setup:applied:routed', 'local_airouter'), $html);
        $this->assertStringContainsString(
            get_string('setup:wouldtake', 'local_airouter', get_string('setup:core:first', 'local_airouter', 'First in order')),
            $html,
        );
    }

    public function test_an_action_not_routed_says_who_answers_now_and_what_routing_would_give(): void {
        $html = route_formatter::describe(self::inspection(['applied' => route_inspection::APPLIED_NOT_ROUTED]));

        $this->assertStringContainsString(get_string('setup:core:first', 'local_airouter', 'First in order'), $html);
        $this->assertStringContainsString(substr(get_string('setup:ifrouted', 'local_airouter', ''), 0, 10), $html);
    }

    public function test_a_provider_name_is_escaped(): void {
        $html = route_formatter::describe(self::inspection([
            'defaulttargetname' => '<script>x</script>',
            'corefirst' => '<b>First</b>',
        ]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>First</b>', $html);
    }
}
