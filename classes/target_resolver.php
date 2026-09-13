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

use core_ai\aiactions\base as action_base;
use core_ai\provider as ai_provider;

/**
 * Decides which provider instances an action may be delegated to, in order.
 *
 * Rules are asked first, in priority order, and the first rule whose target can
 * actually be used decides where the request goes. A rule that matched but names a
 * target that has been deleted or switched off is passed over rather than obeyed,
 * because there is nothing to obey it with.
 *
 * When nothing matches, what happens is the administrator's choice, defaulting to
 * whichever answer suits the operating mode: delegating to the default target in router
 * only mode, where there is nobody behind the router to take the request, and declining
 * alongside other providers, where declining simply means the next provider is asked.
 * Declining is also a legitimate way to keep AI spending to the cases rules describe.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class target_resolver {
    /** @var rule|null The rule that decided the last resolution. */
    protected ?rule $matchedrule = null;

    /** @var bool Whether the last resolution was a deliberate refusal. */
    protected bool $declined = false;

    /** @var evaluation_context|null What the rules were told about the last request. */
    protected ?evaluation_context $evaluated = null;

    /**
     * Constructor.
     *
     * @param provider $router The router instance doing the delegating.
     */
    public function __construct(
        /** @var provider The router instance. */
        protected readonly provider $router,
    ) {
    }

    /**
     * Candidate targets for an action, in the order they should be tried.
     *
     * @param action_base $action The action to be delegated.
     * @return ai_provider[] Usable targets. Empty when nothing can handle the action.
     */
    public function get_candidates(action_base $action): array {
        $this->matchedrule = null;
        $this->declined = false;
        $instances = $this->get_instances_by_id();

        $this->evaluated = $this->get_evaluation_context($action);
        foreach ($this->get_evaluator()->matches($this->evaluated) as $rule) {
            $target = $instances[(int) $rule->get('targetid')] ?? null;
            if ($target === null || !$this->is_usable($target, $action)) {
                continue;
            }
            $this->matchedrule = $rule;

            return $this->with_fallback($target, $instances, $action);
        }

        return $this->get_unmatched_candidates($instances, $action);
    }

    /**
     * The rule that decided where the last request went.
     *
     * Recorded for the monitor in WP4, which refers to rules by id. Null means no rule
     * decided: either none matched, or the ones that did could not be honoured.
     *
     * @return rule|null The rule, or null.
     */
    public function get_matched_rule(): ?rule {
        return $this->matchedrule;
    }

    /**
     * What the rules were told about the last request.
     *
     * Handed on to the monitor so that the course, the placement and the rest are
     * worked out once for the request rather than again for the history of it.
     *
     * @param action_base $action The action being delegated, for a request that has not
     *                            been resolved yet.
     * @return evaluation_context The context.
     */
    public function get_evaluated_context(action_base $action): evaluation_context {
        return $this->evaluated ??= $this->get_evaluation_context($action);
    }

    /**
     * Whether the router turned the last request down on purpose.
     *
     * A refusal and a misconfiguration both leave no candidates, and they mean opposite
     * things: one is the site working as configured, the other is an administrator who
     * needs to be told. They are reported as different failures for that reason.
     *
     * @return bool True when no rule matched and the router is set to decline.
     */
    public function was_declined(): bool {
        return $this->declined;
    }

    /**
     * Where a request goes when no rule claimed it.
     *
     * @param ai_provider[] $instances Every provider instance, keyed by id.
     * @param action_base $action The action to be delegated.
     * @return ai_provider[] The candidates, which may be none.
     */
    protected function get_unmatched_candidates(array $instances, action_base $action): array {
        if ($this->router->get_nomatch_behaviour() === provider::NOMATCH_DECLINE) {
            $this->declined = true;

            return [];
        }

        $target = $this->get_default_target($instances, $action);

        return $target === null ? [] : [$target];
    }

    /**
     * A matched target, followed by the default target when it may serve as a backstop.
     *
     * The default target stands behind a rule only where the administrator has said
     * unclaimed requests may go to it. Where they have chosen to decline those, sending
     * a failed request there anyway would spend money at a provider they deliberately
     * kept out of the picture.
     *
     * @param ai_provider $target The instance the matching rule named.
     * @param ai_provider[] $instances Every provider instance, keyed by id.
     * @param action_base $action The action to be delegated.
     * @return ai_provider[] The candidates, in the order they should be tried.
     */
    protected function with_fallback(ai_provider $target, array $instances, action_base $action): array {
        if ($this->router->get_nomatch_behaviour() === provider::NOMATCH_DECLINE) {
            return [$target];
        }
        $fallback = $this->get_default_target($instances, $action);
        if ($fallback === null || (int) $fallback->id === (int) $target->id) {
            return [$target];
        }

        return [$target, $fallback];
    }

    /**
     * The configured default target, if it can be used for this action.
     *
     * @param ai_provider[] $instances Every provider instance, keyed by id.
     * @param action_base $action The action to be delegated.
     * @return ai_provider|null The instance, or null when there is none to use.
     */
    protected function get_default_target(array $instances, action_base $action): ?ai_provider {
        $targetid = $this->router->get_default_target_id();
        if ($targetid === null) {
            return null;
        }
        $target = $instances[$targetid] ?? null;

        return $target !== null && $this->is_usable($target, $action) ? $target : null;
    }

    /**
     * The evaluator that reads the rules.
     *
     * @return rule_evaluator The evaluator.
     */
    protected function get_evaluator(): rule_evaluator {
        global $DB;

        return new rule_evaluator(new rule_repository($DB));
    }

    /**
     * What the rules are allowed to know about this request.
     *
     * @param action_base $action The action to be delegated.
     * @return evaluation_context The context.
     */
    protected function get_evaluation_context(action_base $action): evaluation_context {
        return new evaluation_context($action);
    }

    /**
     * The provider instances a rule or a default target may name, as form options.
     *
     * Whether one of them can serve a particular request is decided at the time, since
     * an instance can be switched off or lose an action after a rule names it. What this
     * excludes for good is routers: a router delegating to a router is how a loop starts.
     *
     * @return string[] Instance names keyed by id.
     */
    public static function get_delegation_targets(): array {
        $options = [];
        foreach (\core\di::get(\core_ai\manager::class)->get_provider_instances() as $instance) {
            if ($instance instanceof provider) {
                continue;
            }
            $options[(int) $instance->id] = $instance->name;
        }

        return $options;
    }

    /**
     * Whether an instance can be delegated to for this action.
     *
     * @param ai_provider $instance The candidate instance.
     * @param action_base $action The action to be delegated.
     * @return bool True if the instance may be used.
     */
    protected function is_usable(ai_provider $instance, action_base $action): bool {
        // Never delegate to a router. A router chain would loop, and the single
        // instance restriction only stops routers being created through the UI.
        if ($instance instanceof provider) {
            return false;
        }
        if (!$instance->enabled || !$instance->is_provider_configured()) {
            return false;
        }
        // Excluding targets that do not declare the action is the first of the four
        // guards against an unsupported action reaching a target.
        if (!in_array($action::class, $instance::get_action_list(), true)) {
            return false;
        }
        $actionconfig = $instance->actionconfig[$action::class] ?? [];

        return !empty($actionconfig['enabled']);
    }

    /**
     * All provider instances known to the site.
     *
     * @return ai_provider[] The instances.
     */
    protected function get_instances(): array {
        return \core\di::get(\core_ai\manager::class)->get_provider_instances();
    }

    /**
     * All provider instances known to the site, keyed by id.
     *
     * @return ai_provider[] The instances.
     */
    protected function get_instances_by_id(): array {
        $instances = [];
        foreach ($this->get_instances() as $instance) {
            $instances[(int) $instance->id] = $instance;
        }

        return $instances;
    }
}
