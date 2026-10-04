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

use local_airouter\managed_policy;
use local_airouter\provider;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\target_resolver;

/**
 * Reads what the router's settings offer each action, without sending anything.
 *
 * The screens that explain the router to an administrator ask this, and it answers
 * from the same judgement the router makes when a request arrives: whether a target
 * can carry an action is the resolver's question, asked of the resolver. What it adds
 * is the part a request never needs, which is looking at every path at once.
 *
 * Nothing here is written, and no provider is called. It cannot say that a request
 * will succeed: budgets, brought keys and permissions are decided per request, and
 * whether a provider answers is only known by asking it.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class route_inspector {
    /** @var target_resolver|null The resolver whose judgement is used. */
    protected ?target_resolver $resolver = null;

    /** @var rule[]|null The rules in force, keyed by id. */
    protected ?array $rules = null;

    /** @var array|null Each rule's conditions, keyed by rule id and then type. */
    protected ?array $conditions = null;

    /** @var string[]|null Provider instance names, keyed by id. */
    protected ?array $names = null;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int|null $now The moment to judge rule windows at, or null for now.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var int|null The moment to judge rule windows at. */
        protected readonly ?int $now = null,
    ) {
    }

    /**
     * What the settings offer one action.
     *
     * @param string $actionclass The action, as a class name.
     * @return route_inspection The answer.
     */
    public function inspect(string $actionclass): route_inspection {
        $actionclass = ltrim($actionclass, '\\');
        $resolver = $this->get_resolver();
        $router = $resolver->get_router();

        $defaultid = $router->get_default_target_id();
        $defaultproblem = $defaultid === null
            ? null
            : $resolver->get_target_problem($defaultid, $actionclass);
        $declines = $router->get_nomatch_behaviour() === provider::NOMATCH_DECLINE;

        $rules = [];
        foreach ($this->get_rules() as $id => $rule) {
            $conditions = $this->get_conditions()[$id] ?? [];
            if (!self::applies_to($conditions, $actionclass)) {
                continue;
            }
            $targetid = (int) $rule->get('targetid');
            $rules[] = new route_rule(
                $id,
                (string) $rule->get('name'),
                (string) $rule->get('keysource'),
                $rule->is_byok(),
                $targetid,
                $this->get_names()[$targetid] ?? null,
                $resolver->get_target_problem($targetid, $actionclass, $rule->is_byok()),
                isset($conditions['budget']),
            );
        }

        $usable = array_filter($rules, static fn(route_rule $rule): bool => $rule->problem === null);
        $defaultusable = $defaultid !== null && $defaultproblem === null;
        $state = match (true) {
            !$declines && $defaultusable => route_inspection::STATE_READY,
            $usable !== [] => route_inspection::STATE_CONDITIONAL,
            $declines && $defaultusable => route_inspection::STATE_POLICY_REFUSED,
            default => route_inspection::STATE_ACTION_NEEDED,
        };

        return new route_inspection(
            $actionclass,
            $this->applied($actionclass),
            $state,
            $defaultid,
            $defaultid === null ? null : ($this->get_names()[$defaultid] ?? null),
            $defaultproblem,
            $declines,
            $rules,
            $this->core_first($actionclass),
        );
    }

    /**
     * What the settings offer every action routed through the router.
     *
     * @return route_inspection[] The answers, keyed by action class name.
     */
    public function inspect_managed(): array {
        $inspections = [];
        foreach (managed_policy::managed_actions() as $actionclass) {
            $inspections[$actionclass] = $this->inspect($actionclass);
        }

        return $inspections;
    }

    /**
     * Whether a request for the action goes through the router.
     *
     * The order of the questions is the order routing_manager asks them in.
     *
     * @param string $actionclass The action, as a class name without a leading separator.
     * @return string One of the route_inspection APPLIED_ constants.
     */
    protected function applied(string $actionclass): string {
        if (!managed_policy::is_switched_on()) {
            return route_inspection::APPLIED_SWITCHED_OFF;
        }
        if (!managed_policy::is_managed($actionclass)) {
            return route_inspection::APPLIED_NOT_ROUTED;
        }
        $carried = array_map(static fn(string $action): string => ltrim($action, '\\'), provider::get_action_list());

        return in_array($actionclass, $carried, true)
            ? route_inspection::APPLIED_ROUTED
            : route_inspection::APPLIED_NO_PROCESSOR;
    }

    /**
     * The provider Moodle would try first if it handled the action itself.
     *
     * Read from core's own list of providers able to carry the action, in the order
     * core would try them. The router has no row of its own, so it is never in it.
     *
     * @param string $actionclass The action, as a class name without a leading separator.
     * @return string|null The provider instance's name, or null when there is none.
     */
    protected function core_first(string $actionclass): ?string {
        if (!class_exists($actionclass)) {
            return null;
        }
        $providers = \core\di::get(\core_ai\manager::class)->get_providers_for_actions([$actionclass], true);
        $list = $providers[$actionclass] ?? [];
        $first = reset($list);

        return $first === false ? null : (string) $first->name;
    }

    /**
     * Whether a rule's action condition, if it has one, includes the action.
     *
     * Every other condition depends on the request, so a rule that passes this is one
     * that some request for the action could be claimed by.
     *
     * @param array $conditions The rule's conditions, keyed by type.
     * @param string $actionclass The action, as a class name without a leading separator.
     * @return bool True when the rule can apply.
     */
    protected static function applies_to(array $conditions, string $actionclass): bool {
        $actions = $conditions['action']['actions'] ?? [];
        if (!is_array($actions) || $actions === []) {
            return true;
        }

        return in_array($actionclass, array_map(static fn($action): string => ltrim((string) $action, '\\'), $actions), true);
    }

    /**
     * The resolver whose judgement this follows, with the provider list read once.
     *
     * @return target_resolver The resolver.
     */
    protected function get_resolver(): target_resolver {
        return $this->resolver ??= target_resolver::for_site_with_instances();
    }

    /**
     * The rules in force now: switched on and inside their window.
     *
     * @return rule[] The rules, keyed by id, in the order they are considered.
     */
    protected function get_rules(): array {
        return $this->rules ??= (new rule_repository($this->db))->get_active($this->now ?? time());
    }

    /**
     * The conditions of the rules in force.
     *
     * @return array Conditions keyed by rule id and then by type.
     */
    protected function get_conditions(): array {
        return $this->conditions ??= (new rule_repository($this->db))->get_conditions_for(array_keys($this->get_rules()));
    }

    /**
     * The names of the provider instances.
     *
     * @return string[] Names keyed by instance id.
     */
    protected function get_names(): array {
        return $this->names ??= target_resolver::get_delegation_targets();
    }
}
