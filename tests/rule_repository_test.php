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

/**
 * Tests for reading and writing routing rules.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rule_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rule::class)]
final class rule_repository_test extends \advanced_testcase {
    /** @var rule_repository The repository under test. */
    protected rule_repository $repository;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->repository = new rule_repository($DB);
    }

    /**
     * Save a rule with the given name and target.
     *
     * @param string $name The rule name.
     * @param int $targetid The delegation target.
     * @param array[] $conditions Configuration keyed by condition type.
     * @return rule The saved rule.
     */
    protected function add(string $name, int $targetid = 7, array $conditions = []): rule {
        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);

        return $this->repository->save($rule, $conditions);
    }

    /**
     * The names of every rule, in the order they would be evaluated.
     *
     * @return string[] The names.
     */
    protected function names(): array {
        return array_values(array_map(
            static fn(rule $rule): string => $rule->get('name'),
            $this->repository->get_all(),
        ));
    }

    public function test_new_rules_go_to_the_bottom(): void {
        $this->add('first');
        $this->add('second');
        $this->add('third');

        $this->assertSame(['first', 'second', 'third'], $this->names());
        $this->assertSame([0, 1, 2], array_values(array_map(
            static fn(rule $rule): int => (int) $rule->get('sortorder'),
            $this->repository->get_all(),
        )));
    }

    public function test_conditions_survive_a_round_trip(): void {
        $rule = $this->add('with conditions', 7, [
            'role' => ['roleids' => [3, 4]],
            'promptlength' => ['operator' => 'gte', 'characters' => 2000],
        ]);

        $conditions = $this->repository->get_conditions((int) $rule->get('id'));

        $this->assertSame(['promptlength', 'role'], array_keys($conditions));
        $this->assertSame([3, 4], $conditions['role']['roleids']);
        $this->assertSame(2000, $conditions['promptlength']['characters']);
    }

    public function test_saving_replaces_the_whole_condition_set(): void {
        $rule = $this->add('narrowing', 7, [
            'role' => ['roleids' => [3]],
            'course' => ['courseids' => [11]],
        ]);

        $this->repository->save($rule, ['course' => ['courseids' => [12]]]);

        $conditions = $this->repository->get_conditions((int) $rule->get('id'));
        $this->assertSame(['course'], array_keys($conditions));
        $this->assertSame([12], $conditions['course']['courseids']);
    }

    public function test_a_rule_holds_one_condition_of_each_type(): void {
        global $DB;
        $rule = $this->add('once only', 7, ['role' => ['roleids' => [3]]]);

        // The database, not only the form, has to refuse a second condition of a type.
        // Two conditions of one type have no defined meaning: the model is that all
        // conditions must be met, and that any of the values inside one will do.
        $this->expectException(\dml_exception::class);
        $DB->insert_record(rule_repository::CONDITION_TABLE, (object) [
            'ruleid' => $rule->get('id'),
            'type' => 'role',
            'configdata' => json_encode(['roleids' => [4]]),
        ]);
    }

    public function test_damaged_configuration_narrows_the_rule(): void {
        global $DB;
        $rule = $this->add('damaged', 7, ['role' => ['roleids' => [3]]]);
        $DB->set_field(rule_repository::CONDITION_TABLE, 'configdata', 'not json at all', [
            'ruleid' => $rule->get('id'),
        ]);

        $conditions = $this->repository->get_conditions((int) $rule->get('id'));

        // The condition is still there, with nothing in it. Every condition type treats
        // an empty configuration as not met, so the rule stops matching rather than
        // matching everything.
        $this->assertSame(['role' => []], $conditions);
    }

    public function test_deleting_a_rule_takes_its_conditions_with_it(): void {
        global $DB;
        $rule = $this->add('doomed', 7, ['role' => ['roleids' => [3]]]);
        $ruleid = (int) $rule->get('id');

        $this->repository->delete($ruleid);

        $this->assertNull($this->repository->get($ruleid));
        $this->assertSame(0, $DB->count_records(rule_repository::CONDITION_TABLE, ['ruleid' => $ruleid]));
    }

    public function test_deleting_closes_the_gap_in_the_order(): void {
        $this->add('first');
        $second = $this->add('second');
        $this->add('third');

        $this->repository->delete((int) $second->get('id'));

        $this->assertSame([0, 1], array_values(array_map(
            static fn(rule $rule): int => (int) $rule->get('sortorder'),
            $this->repository->get_all(),
        )));
    }

    public function test_moving_a_rule_up_swaps_it_with_its_neighbour(): void {
        $this->add('first');
        $second = $this->add('second');
        $this->add('third');

        $this->assertTrue($this->repository->move((int) $second->get('id'), -1));

        $this->assertSame(['second', 'first', 'third'], $this->names());
    }

    public function test_moving_a_rule_down_swaps_it_with_its_neighbour(): void {
        $this->add('first');
        $second = $this->add('second');
        $this->add('third');

        $this->assertTrue($this->repository->move((int) $second->get('id'), 1));

        $this->assertSame(['first', 'third', 'second'], $this->names());
    }

    public function test_moving_past_either_end_does_nothing(): void {
        $first = $this->add('first');
        $last = $this->add('last');

        $this->assertFalse($this->repository->move((int) $first->get('id'), -1));
        $this->assertFalse($this->repository->move((int) $last->get('id'), 1));

        $this->assertSame(['first', 'last'], $this->names());
    }

    public function test_moving_works_even_when_the_order_has_gaps(): void {
        global $DB;
        $first = $this->add('first');
        $second = $this->add('second');
        // A gap is what makes core's own provider order reordering appear to do nothing,
        // so the rules are moved by position and then renumbered rather than by value.
        $DB->set_field(rule::TABLE, 'sortorder', 40, ['id' => $first->get('id')]);
        $DB->set_field(rule::TABLE, 'sortorder', 90, ['id' => $second->get('id')]);

        $this->assertTrue($this->repository->move((int) $second->get('id'), -1));

        $this->assertSame(['second', 'first'], $this->names());
        $this->assertSame([0, 1], array_values(array_map(
            static fn(rule $rule): int => (int) $rule->get('sortorder'),
            $this->repository->get_all(),
        )));
    }

    public function test_only_enabled_rules_inside_their_window_are_evaluated(): void {
        $now = 1000;
        $enabled = $this->add('enabled');
        $disabled = $this->add('disabled');
        $this->repository->set_enabled((int) $disabled->get('id'), false);

        $future = new rule();
        $future->set('name', 'not yet');
        $future->set('targetid', 7);
        $future->set('timestart', $now + 100);
        $this->repository->save($future);

        $past = new rule();
        $past->set('name', 'over');
        $past->set('targetid', 7);
        $past->set('timeend', $now - 100);
        $this->repository->save($past);

        $active = $this->repository->get_active($now);

        $this->assertSame([(int) $enabled->get('id')], array_keys($active));
    }

    public function test_a_window_that_has_just_opened_counts_as_open(): void {
        $rule = new rule();
        $rule->set('name', 'starts now');
        $rule->set('targetid', 7);
        $rule->set('timestart', 1000);
        $rule->set('timeend', 2000);
        $saved = $this->repository->save($rule);

        $this->assertTrue($saved->is_active(1000));
        $this->assertTrue($saved->is_active(1999));
        // The end is the moment the rule stops applying, not the last moment it applies.
        $this->assertFalse($saved->is_active(2000));
    }

    public function test_a_copy_arrives_disabled_and_last(): void {
        $original = $this->add('original', 9, ['role' => ['roleids' => [3]]]);
        $this->add('other');

        $copy = $this->repository->duplicate((int) $original->get('id'), 'original (copy)');

        $this->assertNotNull($copy);
        $this->assertSame(['original', 'other', 'original (copy)'], $this->names());
        $this->assertFalse((bool) $copy->get('enabled'));
        $this->assertSame(9, (int) $copy->get('targetid'));
        $this->assertSame(
            ['role' => ['roleids' => [3]]],
            $this->repository->get_conditions((int) $copy->get('id')),
        );
    }

    public function test_a_rule_needs_a_name_and_a_target(): void {
        $rule = new rule();
        $rule->set('name', '  ');
        $rule->set('targetid', 0);

        $errors = $rule->validate();

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('targetid', $errors);
    }

    public function test_a_window_cannot_end_before_it_starts(): void {
        $rule = new rule();
        $rule->set('name', 'backwards');
        $rule->set('targetid', 7);
        $rule->set('timestart', 2000);
        $rule->set('timeend', 1000);

        $errors = $rule->validate();

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('timeend', $errors);
    }

    public function test_a_rule_may_point_at_an_instance_that_is_gone(): void {
        // Refusing to save would leave the administrator unable to edit the rule back
        // into shape, and core has to stay free to delete a provider instance.
        $rule = $this->add('orphaned', 999999);

        $this->assertSame(999999, (int) $rule->get('targetid'));
    }

    public function test_conditions_of_several_rules_are_read_together(): void {
        $first = $this->add('first', 7, ['role' => ['roleids' => [3]]]);
        $second = $this->add('second', 7, ['course' => ['courseids' => [5]]]);
        $this->add('third');

        $conditions = $this->repository->get_conditions_for(array_keys($this->repository->get_all()));

        $this->assertSame([3], $conditions[(int) $first->get('id')]['role']['roleids']);
        $this->assertSame([5], $conditions[(int) $second->get('id')]['course']['courseids']);
        // A rule with no conditions matches everything, and has nothing to read back.
        $this->assertCount(2, $conditions);
    }
}
