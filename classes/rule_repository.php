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

use aiprovider_router\condition\budget;
use aiprovider_router\condition\category as category_condition;
use aiprovider_router\condition\course as course_condition;

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
        $this->record_rule_count();
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
        $this->record_rule_count();
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
     * Switching one on is the same act as saving it switched on, so it is held to the
     * same conditions. The list offers it as a single link rather than a form, and a
     * link that quietly put the site into a state the form refuses would be a way
     * round the form: a budget looking back further than the site keeps its summaries
     * measures part of its own period and reads lower than the spending was.
     *
     * Switching one off is never refused. Whatever is wrong with a rule, turning it
     * off is the direction that stops it happening.
     *
     * @param int $id The rule id.
     * @param bool $enabled The state to set.
     * @return string|null Null when done, or why it was refused.
     */
    public function set_enabled(int $id, bool $enabled): ?string {
        $rule = $this->get($id);
        if ($rule === null) {
            return null;
        }
        if ($enabled) {
            $problem = $this->why_not_enabled($id);
            if ($problem !== null) {
                return $problem;
            }
        }
        $rule->set('enabled', $enabled);
        $rule->update();

        return null;
    }

    /**
     * Why this rule cannot be switched on as the site stands, if it cannot.
     *
     * @param int $id The rule id.
     * @return string|null The problem to show somebody, or null when there is none.
     */
    public function why_not_enabled(int $id): ?string {
        $config = $this->get_conditions($id)[budget::get_type()] ?? null;

        return $config === null ? null : budget::stored_retention_problem($config);
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
        $copy->set('keysource', $original->get('keysource'));
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
     * Keep a note of how many rules exist.
     *
     * The provider is asked whether it is configured on every request that reaches the
     * AI subsystem, and a site that routes entirely by rule has no default target for
     * that question to look at. Counting the rules there would mean a query per request,
     * so the count is written here, where rules change, and read from the plugin
     * configuration, which Moodle already has in memory.
     */
    protected function record_rule_count(): void {
        set_config('rulecount', $this->db->count_records(rule::TABLE), 'aiprovider_router');
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
    /**
     * Every budget the rules in force set, with duplicates removed.
     *
     * Two rules asking for the same budget describe one budget: somebody has one limit
     * to think about, and should hear about it once. Read here rather than in the
     * places that use it, so that the daily task and the screens showing how much of a
     * budget is gone cannot come to disagree about what the budgets are.
     *
     * A rule that is switched off, or whose dates have passed, sets no budget. Neither
     * does a rule about one course set a budget on any other course: the rule could
     * never have restricted those courses, so announcing a limit on them describes
     * something that is not happening. The conditions that depend on the request rather
     * than on the course -- who asked, which action, how long the prompt was -- cannot
     * be reflected in a figure for a whole course, so they are not: the figure is what
     * the course spent, which is what the budget condition itself measures.
     *
     * @param int|null $now The moment to test rule windows against, or null for now.
     * @param bool $upcoming Whether to include rules that have not started yet. They
     *                       set no budget today, but what they will measure on their
     *                       first day is the history sitting in the table now, which
     *                       is the one thing that has to survive until then.
     * @return \stdClass[] Rows of scope, metric, amount, period and days, each with the
     *                     courses it is limited to, or null where it is limited to none
     *                     and an empty array where it is limited to no course at all.
     */
    public function get_budgets(?int $now = null, bool $upcoming = false): array {
        $now ??= time();
        $budgets = [];
        $started = $upcoming ? '' : ' AND (r.timestart = 0 OR r.timestart <= :startnow)';
        $params = ['type' => budget::get_type(), 'endnow' => $now];
        if (!$upcoming) {
            $params['startnow'] = $now;
        }
        $records = $this->db->get_records_sql(
            'SELECT c.id, c.ruleid, c.configdata
               FROM {' . self::CONDITION_TABLE . '} c
               JOIN {' . rule::TABLE . '} r ON r.id = c.ruleid
              WHERE c.type = :type AND r.enabled = 1' . $started . '
                AND (r.timeend = 0 OR r.timeend > :endnow)',
            $params,
        );
        if (!$records) {
            return [];
        }

        $courses = $this->courses_by_rule(array_column($records, 'ruleid'));
        foreach ($records as $record) {
            $config = json_decode((string) $record->configdata, true);
            if (!is_array($config)) {
                continue;
            }
            $condition = new budget($config);
            if ($condition->get_scope() === '' || $condition->get_amount() <= 0) {
                continue;
            }
            $found = (object) [
                'scope' => $condition->get_scope(),
                'metric' => $condition->get_metric(),
                'amount' => $condition->get_amount(),
                'period' => $condition->get_period(),
                'days' => $condition->get_days(),
            ];
            $key = implode('|', (array) $found);
            $found->courseids = $courses[(int) $record->ruleid] ?? null;

            if (!isset($budgets[$key])) {
                $budgets[$key] = $found;

                continue;
            }
            // The same budget written twice, once with a course restriction and once
            // without, is a budget without one: the wider rule can reach the courses
            // the narrower one cannot.
            $budgets[$key]->courseids = $budgets[$key]->courseids === null || $found->courseids === null
                ? null
                : array_values(array_unique(array_merge($budgets[$key]->courseids, $found->courseids)));
        }

        return array_values($budgets);
    }

    /**
     * The courses each rule restricts itself to, for the rules that restrict themselves.
     *
     * Two conditions can do the restricting and both have to be read. A rule about a
     * category is about the courses in it, so a budget it sets is not about any course
     * outside -- reading only the course condition announced a limit to courses the
     * rule could never have restricted. Where a rule has both, it applies to the
     * courses that satisfy both, which is what a rule's conditions mean together.
     *
     * The conditions that depend on the request rather than on the course -- who
     * asked, which action, how long the prompt was -- cannot be reflected in a figure
     * for a whole course, so they are not: the figure is what the course spent, which
     * is what the budget condition itself measures.
     *
     * @param int[] $ruleids The rules to look at.
     * @return array<int, int[]> Course ids keyed by rule id, for those rules only. A
     *                           rule that restricts itself to no course at all is
     *                           present with an empty list, which is not the same as
     *                           being absent.
     */
    protected function courses_by_rule(array $ruleids): array {
        if (!$ruleids) {
            return [];
        }
        [$insql, $params] = $this->db->get_in_or_equal(array_unique($ruleids), SQL_PARAMS_NAMED);
        $params['coursetype'] = course_condition::get_type();
        $params['categorytype'] = category_condition::get_type();

        $courses = [];
        $records = $this->db->get_records_select(
            self::CONDITION_TABLE,
            "type IN (:coursetype, :categorytype) AND ruleid {$insql}",
            $params,
        );
        foreach ($records as $record) {
            $config = json_decode((string) $record->configdata, true);
            $ruleid = (int) $record->ruleid;

            $ids = (string) $record->type === category_condition::get_type()
                ? self::courses_in_categories(array_map('intval', (array) ($config['categoryids'] ?? [])))
                : array_map('intval', (array) ($config['courseids'] ?? []));

            // An empty set is kept, and is the whole point of keeping it. A rule
            // pointed at a category that holds no courses is a rule that matches no
            // course, which is not the same as a rule that names no courses at all.
            // Dropping it turned the first into the second, and a budget meant for
            // one category announced itself to every course on the site.
            $courses[$ruleid] = isset($courses[$ruleid])
                ? array_values(array_intersect($courses[$ruleid], $ids))
                : $ids;
        }

        return $courses;
    }

    /**
     * Every course a set of categories holds, including those further down.
     *
     * A category condition is satisfied by a course anywhere beneath the category, so
     * the budget it sets is about all of them.
     *
     * @param int[] $categoryids The categories.
     * @return int[] The course ids.
     */
    protected static function courses_in_categories(array $categoryids): array {
        global $DB;

        if (!$categoryids) {
            return [];
        }

        // Asked of the database rather than of core_course_category::get_courses(),
        // which is written for showing a category to somebody and leaves out what
        // they may not see. A budget is about every course under the category,
        // whoever happens to be looking.
        $courses = [];
        foreach ($categoryids as $categoryid) {
            $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
            if ($path === false) {
                continue;
            }
            $found = $DB->get_fieldset_sql(
                "SELECT c.id
                   FROM {course} c
                   JOIN {course_categories} cc ON cc.id = c.category
                  WHERE cc.id = :categoryid OR " . $DB->sql_like('cc.path', ':path'),
                ['categoryid' => (int) $categoryid, 'path' => $DB->sql_like_escape($path) . '/%'],
            );
            foreach ($found as $courseid) {
                $courses[(int) $courseid] = true;
            }
        }

        return array_keys($courses);
    }
}
