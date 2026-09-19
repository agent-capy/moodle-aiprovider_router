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

use aiprovider_router\rule;
use aiprovider_router\rule_repository;

/**
 * Tests for turning a rule editing form into stored conditions and back.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rule_form::class)]
final class rule_form_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_only_the_conditions_that_were_filled_in_are_stored(): void {
        $data = (object) [
            'course' => [11, 12],
            'role' => [],
            'action' => ['core_ai\\aiactions\\generate_text'],
            'placement' => [],
            'category' => [],
            'promptlengthcharacters' => 0,
            'promptlengthoperator' => 'gte',
        ];

        $conditions = rule_form::read_conditions($data);

        // An empty control means the rule does not care about that condition, which is
        // not the same as a condition present but empty. Only one of those can be stored.
        // The order is the registry's, not the form's, so that a stored rule always
        // reads the same way regardless of how it was entered.
        $this->assertSame(['action', 'course'], array_keys($conditions));
        $this->assertSame([11, 12], $conditions['course']['courseids']);
    }

    public function test_a_prompt_length_of_zero_is_not_a_condition(): void {
        $data = (object) ['promptlengthoperator' => 'lte', 'promptlengthcharacters' => ''];

        $this->assertSame([], rule_form::read_conditions($data));
    }

    public function test_a_prompt_length_condition_keeps_its_operator(): void {
        $data = (object) ['promptlengthoperator' => 'lte', 'promptlengthcharacters' => 500];

        $conditions = rule_form::read_conditions($data);

        $this->assertSame(['operator' => 'lte', 'characters' => 500], $conditions['promptlength']);
    }

    public function test_an_unknown_operator_is_not_stored_as_given(): void {
        $data = (object) ['promptlengthoperator' => 'roughly', 'promptlengthcharacters' => 500];

        $conditions = rule_form::read_conditions($data);

        $this->assertSame('gte', $conditions['promptlength']['operator']);
    }

    public function test_a_condition_this_version_cannot_show_survives_an_edit(): void {
        global $DB;
        $repository = new rule_repository($DB);
        $rule = new rule();
        $rule->set('name', 'written on a newer version');
        $rule->set('targetid', 7);
        $saved = $repository->save($rule, [
            'futurecondition' => ['limit' => 100],
            'course' => ['courseids' => [11]],
        ]);
        $existing = $repository->get_conditions((int) $saved->get('id'));

        $submitted = (object) ['course' => [12]];
        $repository->save($saved, rule_form::read_conditions($submitted, $existing));

        // Losing the condition would widen the rule, and nothing on the screen would have
        // said so. The form carries it through untouched instead.
        $after = $repository->get_conditions((int) $saved->get('id'));
        $this->assertSame(['course' => ['courseids' => [12]], 'futurecondition' => ['limit' => 100]], $after);
    }

    public function test_stored_conditions_come_back_as_form_values(): void {
        $data = rule_form::conditions_to_form_data([
            'course' => ['courseids' => [11, 12]],
            'promptlength' => ['operator' => 'lte', 'characters' => 500],
        ]);

        $this->assertSame([11, 12], $data['course']);
        $this->assertSame('lte', $data['promptlengthoperator']);
        $this->assertSame(500, $data['promptlengthcharacters']);
    }

    public function test_a_condition_this_version_cannot_show_is_left_out_of_the_form(): void {
        $data = rule_form::conditions_to_form_data(['futurecondition' => ['limit' => 100]]);

        $this->assertSame([], $data);
    }

    public function test_a_condition_set_round_trips_through_the_form(): void {
        $original = [
            'course' => ['courseids' => [11]],
            'role' => ['roleids' => [3, 4]],
            'promptlength' => ['operator' => 'gte', 'characters' => 2000],
        ];

        $conditions = rule_form::read_conditions((object) rule_form::conditions_to_form_data($original));

        $this->assertSame($original, $conditions);
    }
    public function test_the_editing_form_can_be_built(): void {
        // Every condition contributes its own controls, and one of them reaching for
        // something that is not there would take the whole page down rather than the
        // condition. The rule list would still look fine, so this is worth asserting.
        $form = new rule_form(null, ['targets' => [7 => 'Somewhere']]);

        $this->assertNotEmpty($form->render());
    }

    public function test_the_rule_tester_form_can_be_built(): void {
        $form = new rule_test_form();

        $this->assertNotEmpty($form->render());
    }
    public function test_a_rule_the_persistent_accepts_passes_the_form(): void {
        $form = new rule_form(null, ['targets' => [7 => 'Somewhere']]);

        $errors = $form->validation(['name' => 'Fine', 'targetid' => 7], []);

        $this->assertSame([], $errors);
    }

    public function test_the_form_reports_what_the_persistent_refuses(): void {
        $form = new rule_form(null, ['targets' => [7 => 'Somewhere']]);

        $errors = $form->validation(['name' => ' ', 'targetid' => 0], []);

        // Beside the field, not on the next page after a failed save.
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('targetid', $errors);
    }

    public function test_the_form_refuses_a_key_source_that_does_not_exist(): void {
        $form = new rule_form(null, ['targets' => [7 => 'Somewhere']]);

        $errors = $form->validation(
            ['name' => 'Strange', 'targetid' => 7, 'keysource' => 'somebodyelse'],
            [],
        );

        $this->assertArrayHasKey('keysource', $errors);
    }

    public function test_a_rule_that_says_nothing_about_keys_is_paid_for_by_the_site(): void {
        $form = new rule_form(null, ['targets' => [7 => 'Somewhere']]);

        // What every rule written before this setting existed meant.
        $this->assertSame([], $form->validation(['name' => 'Fine', 'targetid' => 7], []));
    }

    public function test_the_form_refuses_a_window_that_ends_before_it_starts(): void {
        $form = new rule_form(null, ['targets' => [7 => 'Somewhere']]);

        $errors = $form->validation(
            ['name' => 'Backwards', 'targetid' => 7, 'timestart' => 2000, 'timeend' => 1000],
            [],
        );

        $this->assertArrayHasKey('timeend', $errors);
    }
}
