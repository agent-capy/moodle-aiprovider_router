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

namespace aiprovider_router;

use core_ai\aiactions\generate_image;
use core_ai\aiactions\generate_text;

/**
 * Tests for describing a rule to an administrator, and for the stand in action.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rule_formatter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(action_factory::class)]
final class rule_formatter_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A saved rule.
     *
     * @param string $name The rule name.
     * @param int $targetid Where it delegates.
     * @param array[] $conditions Configuration keyed by condition type.
     * @return rule The rule.
     */
    protected function add(string $name, int $targetid = 7, array $conditions = []): rule {
        global $DB;
        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);

        return (new rule_repository($DB))->save($rule, $conditions);
    }

    public function test_a_rule_with_no_conditions_says_it_takes_everything(): void {
        $output = rule_formatter::conditions([]);

        // Useful at the bottom of the list and alarming at the top, so it is never left
        // for the reader to work out from an empty cell.
        $this->assertStringContainsString(
            get_string('rules:noconditions', 'aiprovider_router'),
            $output,
        );
    }

    public function test_conditions_are_described_by_name_not_by_id(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'BIO101']);

        $output = rule_formatter::conditions(['course' => ['courseids' => [(int) $course->id]]]);

        $this->assertStringContainsString('BIO101', $output);
        $this->assertStringNotContainsString('courseids', $output);
    }

    public function test_a_deleted_item_in_a_condition_is_pointed_out(): void {
        // The condition can no longer be met, so the rule may never fire again. A blank
        // cell would leave an administrator with nothing to go on.
        $output = rule_formatter::conditions(['course' => ['courseids' => [999999]]]);

        $this->assertStringContainsString('999999', $output);
    }

    public function test_a_condition_type_this_version_cannot_read_is_pointed_out(): void {
        $output = rule_formatter::conditions(['budget' => ['limit' => 100]]);

        $this->assertStringContainsString('budget', $output);
    }

    public function test_a_prompt_length_condition_says_that_it_is_an_estimate(): void {
        $output = rule_formatter::conditions([
            'promptlength' => ['operator' => 'gte', 'tokens' => 2000],
        ]);

        $this->assertStringContainsString('2000', $output);
        $this->assertStringContainsString(
            get_string('condition:describe:promptlength:gte', 'aiprovider_router', 2000),
            $output,
        );
    }

    public function test_a_rule_pointing_at_a_deleted_instance_is_flagged(): void {
        $rule = $this->add('orphaned', 999999);

        $output = rule_formatter::target($rule, [7 => 'Somewhere else']);

        $this->assertStringContainsString('999999', $output);
    }

    public function test_a_rule_pointing_at_a_live_instance_names_it(): void {
        $rule = $this->add('fine', 7);

        $this->assertSame('Somewhere', rule_formatter::target($rule, [7 => 'Somewhere']));
    }

    public function test_a_disabled_rule_says_so(): void {
        $rule = $this->add('off');
        $rule->set('enabled', false);

        $output = rule_formatter::name($rule, true);

        $this->assertStringContainsString(get_string('rules:disabled', 'aiprovider_router'), $output);
    }

    public function test_a_rule_nothing_can_reach_says_so(): void {
        $rule = $this->add('below a catch all');

        $output = rule_formatter::name($rule, false);

        $this->assertStringContainsString(get_string('rules:unreachable', 'aiprovider_router'), $output);
    }

    public function test_the_stand_in_action_is_built_for_every_action_the_router_handles(): void {
        foreach (provider::get_action_list() as $class) {
            $action = action_factory::make($class, \context_system::instance()->id, 0, 'Hello');

            $this->assertInstanceOf($class, $action);
            $this->assertSame('Hello', $action->get_configuration('prompttext'));
        }
    }

    public function test_the_stand_in_image_action_is_given_parameters_it_will_not_use(): void {
        $action = action_factory::make(generate_image::class, \context_system::instance()->id, 0, 'A cat');

        // None of them affect routing, which is why the rule tester does not ask for them.
        $this->assertInstanceOf(generate_image::class, $action);
        $this->assertSame(1, $action->get_configuration('numimages'));
    }

    public function test_the_stand_in_action_refuses_a_class_the_router_does_not_handle(): void {
        $this->expectException(\coding_exception::class);

        action_factory::make(self::class, \context_system::instance()->id, 0, 'Hello');
    }

    public function test_a_leading_separator_does_not_confuse_the_stand_in_action(): void {
        $action = action_factory::make('\\' . generate_text::class, \context_system::instance()->id, 0, 'Hi');

        $this->assertInstanceOf(generate_text::class, $action);
    }
}
