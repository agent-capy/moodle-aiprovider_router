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

use aiprovider_router\condition\registry;

/**
 * Walks the rules in priority order and reports which of them a request matches.
 *
 * Matches are produced one at a time. The caller stops at the first one it can actually
 * use, which is not always the first one that matched: a rule whose target has been
 * deleted or switched off is a rule that cannot be honoured, and stopping there would
 * strand the request rather than letting the next rule have it.
 *
 * A condition this version does not recognise counts as not met. Reading it as "no
 * restriction" would turn a rule written on a newer version into one that matches
 * everything, which is the failure worth avoiding.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_evaluator {
    /** @var string The condition that spends rather than describes. */
    protected const BUDGET = 'budget';

    /** @var rule[] Rules the last evaluation turned away on their budget alone. */
    protected array $budgetblocked = [];

    /**
     * Constructor.
     *
     * @param rule_repository $repository Where the rules are read from.
     */
    public function __construct(
        /** @var rule_repository The repository. */
        protected readonly rule_repository $repository,
    ) {
    }

    /**
     * The rules this request matches, in priority order, worked out as they are asked for.
     *
     * @param evaluation_context $context The request being routed.
     * @param int|null $now The moment to test rule windows against, or null for the current time.
     * @return \Generator<int, rule> The matching rules, keyed by rule id.
     */
    public function matches(evaluation_context $context, ?int $now = null): \Generator {
        $now ??= $this->now();
        $rules = $this->repository->get_active($now);
        $conditions = $this->repository->get_conditions_for(array_keys($rules));
        $this->budgetblocked = [];

        foreach ($rules as $id => $rule) {
            $unmet = $this->unmet_conditions($conditions[$id] ?? [], $context);
            if (!$unmet) {
                yield $id => $rule;

                continue;
            }
            if ($unmet === [self::BUDGET]) {
                // Everything else about this rule fitted the request. Worth remembering,
                // because "this rule is not for you" and "this rule is for you and the
                // money has run out" are the same absence of a match and mean opposite
                // things to a site that set a budget.
                $this->budgetblocked[$id] = $rule;
            }
        }
    }

    /**
     * Rules the last evaluation turned away on their budget and nothing else.
     *
     * Only meaningful once matches() has been read to the end, which is what the
     * resolver does whenever it finds nothing it can use.
     *
     * @return rule[] The rules, keyed by id, in priority order.
     */
    public function get_budget_blocked(): array {
        return $this->budgetblocked;
    }

    /**
     * What every rule did with this request, whether or not it matched.
     *
     * This is what the rule tester shows. It is deliberately the same evaluation the
     * router itself does, so that the answer on the screen is the answer a real request
     * would get rather than a second implementation that agrees most of the time.
     *
     * @param evaluation_context $context The request being routed.
     * @param int|null $now The moment to test rule windows against, or null for the current time.
     * @return array[] One entry per rule, in priority order, keyed by rule id, each
     *                 holding the rule, whether its window is open, the conditions it
     *                 failed and whether it matched.
     */
    public function trace(evaluation_context $context, ?int $now = null): array {
        $now ??= $this->now();
        $rules = $this->repository->get_all();
        $conditions = $this->repository->get_conditions_for(array_keys($rules));

        $trace = [];
        foreach ($rules as $id => $rule) {
            $active = $rule->is_active($now);
            $unmet = $active ? $this->unmet_conditions($conditions[$id] ?? [], $context) : [];
            $trace[$id] = [
                'rule' => $rule,
                'active' => $active,
                'unmet' => $unmet,
                'matched' => $active && !$unmet,
            ];
        }

        return $trace;
    }

    /**
     * Which of a rule's conditions this request fails.
     *
     * @param array[] $conditions Stored configuration keyed by condition type.
     * @param evaluation_context $context The request being routed.
     * @return string[] The types that were not met. Empty means the rule matches.
     */
    protected function unmet_conditions(array $conditions, evaluation_context $context): array {
        $unmet = [];
        foreach ($conditions as $type => $config) {
            $condition = registry::make((string) $type, $config);
            if ($condition === null || !$condition->is_met($context)) {
                $unmet[] = (string) $type;
            }
        }

        return $unmet;
    }

    /**
     * The current time, taken from the clock core provides so that tests can move it.
     *
     * @return int The timestamp.
     */
    protected function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
