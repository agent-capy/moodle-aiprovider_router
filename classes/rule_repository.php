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
 * Reads and writes routing rules.
 *
 * Every write to the rule tables goes through this class. Keeping one door means the
 * priority order can be renumbered on the way out, and it is also what would make a
 * cache safe to add later, if the rule set ever turns out to be worth caching. It is
 * not cached today: rules are read once per AI request, which is once per deliberate
 * action by a user, and a stale cache would be a worse problem than two queries.
 *
 * Conditions are stored as one row each rather than as a blob on the rule, so that the
 * database can hold a rule to at most one condition of any given type. That restriction
 * is the "all conditions must be met, any value within a condition will do" model
 * written down in a form that cannot drift.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_repository {
    /** @var string The table holding conditions. */
    public const CONDITION_TABLE = 'aiprovider_router_condition';

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to work against.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
    ) {
    }

    /**
     * Every rule, in the order they are evaluated.
     *
     * The id breaks ties so that two rules sharing a priority still have a defined
     * order, rather than one that depends on how the database feels about it.
     *
     * @return rule[] The rules, keyed by id.
     */
    public function get_all(): array {
        $rules = [];
        foreach ($this->db->get_records(rule::TABLE, null, 'sortorder ASC, id ASC') as $record) {
            $rules[(int) $record->id] = new rule(0, $record);
        }

        return $rules;
    }

    /**
     * The rules worth evaluating at a given moment, in order.
     *
     * Rules outside their window are dropped here rather than during evaluation, so
     * that no request can end up having matched a rule it may not use.
     *
     * @param int $now The time to test the rule windows against.
     * @return rule[] The rules, keyed by id.
     */
    public function get_active(int $now): array {
        return array_filter($this->get_all(), static fn(rule $rule): bool => $rule->is_active($now));
    }

    /**
     * One rule.
     *
     * @param int $id The rule id.
     * @return rule|null The rule, or null when there is no such rule.
     */
    public function get(int $id): ?rule {
        $record = $this->db->get_record(rule::TABLE, ['id' => $id]);

        return $record ? new rule(0, $record) : null;
    }

    /**
     * The conditions of one rule.
     *
     * @param int $ruleid The rule id.
     * @return array[] Decoded configuration, keyed by condition type.
     */
    public function get_conditions(int $ruleid): array {
        return $this->get_conditions_for([$ruleid])[$ruleid] ?? [];
    }

    /**
     * The conditions of several rules, read in one query.
     *
     * Configuration that cannot be decoded is returned as an empty array rather than
     * discarded. Every condition type treats an empty configuration as not met, so a
     * damaged row narrows the rule it belongs to instead of widening it.
     *
     * @param int[] $ruleids The rule ids.
     * @return array[] Decoded configuration keyed by rule id, then by condition type.
     */
    public function get_conditions_for(array $ruleids): array {
        $conditions = [];
        if (!$ruleids) {
            return $conditions;
        }
        [$insql, $params] = $this->db->get_in_or_equal(array_map('intval', $ruleids), SQL_PARAMS_NAMED);
        $records = $this->db->get_records_select(self::CONDITION_TABLE, "ruleid {$insql}", $params, 'type ASC');
        foreach ($records as $record) {
            $decoded = json_decode($record->configdata, true);
            $conditions[(int) $record->ruleid][$record->type] = is_array($decoded) ? $decoded : [];
        }

        return $conditions;
    }

    /**
     * Create or update a rule together with the whole of its condition set.
     *
     * Conditions are replaced rather than merged. A condition that has been removed from
     * the form is a condition the administrator no longer wants, and leaving it behind
     * would silently keep a rule narrower than the screen says it is.
     *
     * @param rule $rule The rule to save. Saved as a new rule when it has no id.
     * @param array[] $conditions Configuration arrays keyed by condition type.
     * @return rule The saved rule.
     */
    public function save(rule $rule, array $conditions = []): rule {
        $transaction = $this->db->start_delegated_transaction();

        if (!$rule->get('id')) {
            // New rules go to the bottom. Anything else would change which rule wins for
            // requests the administrator has not thought about yet.
            $rule->set('sortorder', $this->next_sortorder());
            $rule->create();
        } else {
            $rule->update();
        }

        $this->replace_conditions((int) $rule->get('id'), $conditions);
        $this->renumber();
        $transaction->allow_commit();

        return $this->get((int) $rule->get('id'));
    }

    /**
     * Delete a rule and everything that belongs to it.
     *
     * The id is not reused afterwards, because the monitor log refers to rules by id.
     *
     * @param int $id The rule id.
     */
    public function delete(int $id): void {
        $transaction = $this->db->start_delegated_transaction();
        $this->db->delete_records(self::CONDITION_TABLE, ['ruleid' => $id]);
        $this->db->delete_records(rule::TABLE, ['id' => $id]);
        $this->renumber();
        $transaction->allow_commit();
    }

    /**
     * Move a rule one place up or down the priority order.
     *
     * The two rules swap places and the whole list is then renumbered, so that moving a
     * rule cannot depend on the positions being contiguous in the first place. Core's
     * own provider order is reordered by position without that guarantee, which is how
     * a leftover entry there can make "move up" appear to do nothing.
     *
     * @param int $id The rule to move.
     * @param int $direction -1 to move towards the front, 1 to move towards the back.
     * @return bool True if the rule moved, false if it was already at the end it was
     *              being moved towards.
     */
    public function move(int $id, int $direction): bool {
        $ordered = array_keys($this->get_all());
        $position = array_search($id, $ordered, true);
        if ($position === false) {
            return false;
        }
        $target = $position + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= count($ordered)) {
            return false;
        }

        $transaction = $this->db->start_delegated_transaction();
        [$ordered[$position], $ordered[$target]] = [$ordered[$target], $ordered[$position]];
        $this->apply_order($ordered);
        $transaction->allow_commit();

        return true;
    }

    /**
     * Turn a rule on or off without losing it.
     *
     * @param int $id The rule id.
     * @param bool $enabled The state to set.
     */
    public function set_enabled(int $id, bool $enabled): void {
        $rule = $this->get($id);
        if ($rule === null) {
            return;
        }
        $rule->set('enabled', $enabled);
        $rule->update();
    }

    /**
     * Copy a rule, conditions and all.
     *
     * Copying is what the first version offers in place of import and export. Targets
     * are referred to by instance id, which does not survive being carried to another
     * site, so a copy stays within the site where the ids mean something.
     *
     * @param int $id The rule to copy.
     * @param string $name The name for the copy.
     * @return rule|null The new rule, or null when the original is gone.
     */
    public function duplicate(int $id, string $name): ?rule {
        $original = $this->get($id);
        if ($original === null) {
            return null;
        }

        $copy = new rule();
        $copy->set('name', $name);
        // A copy arrives disabled. It is a starting point for editing, and enabling it
        // straight away would change routing before anyone has looked at it.
        $copy->set('enabled', false);
        $copy->set('targetid', $original->get('targetid'));
        $copy->set('timestart', $original->get('timestart'));
        $copy->set('timeend', $original->get('timeend'));

        return $this->save($copy, $this->get_conditions($id));
    }

    /**
     * Replace the condition set of a rule.
     *
     * @param int $ruleid The rule id.
     * @param array[] $conditions Configuration arrays keyed by condition type.
     */
    protected function replace_conditions(int $ruleid, array $conditions): void {
        $this->db->delete_records(self::CONDITION_TABLE, ['ruleid' => $ruleid]);
        foreach ($conditions as $type => $configdata) {
            $this->db->insert_record(self::CONDITION_TABLE, (object) [
                'ruleid' => $ruleid,
                'type' => (string) $type,
                'configdata' => json_encode($configdata),
            ]);
        }
    }

    /**
     * Close the gaps in the priority order.
     *
     * @return void
     */
    protected function renumber(): void {
        $this->apply_order(array_keys($this->get_all()));
    }

    /**
     * Write a priority order out as a contiguous run starting at zero.
     *
     * @param int[] $ordered Rule ids, first to be evaluated first.
     */
    protected function apply_order(array $ordered): void {
        foreach (array_values($ordered) as $position => $ruleid) {
            $this->db->set_field(rule::TABLE, 'sortorder', $position, ['id' => $ruleid]);
        }
    }

    /**
     * The position a new rule takes.
     *
     * @return int One past the last rule, or zero when there are none.
     */
    protected function next_sortorder(): int {
        $max = $this->db->get_field_sql('SELECT MAX(sortorder) FROM {' . rule::TABLE . '}');

        return $max === null || $max === false ? 0 : (int) $max + 1;
    }
}
