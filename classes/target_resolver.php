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
 * A rule can also say that somebody other than the site pays. Holding a key is then part
 * of what the rule requires: where there is none the rule simply does not apply and the
 * next one is asked, which is how "their own key if they have one, the site's otherwise"
 * is written as two rules. A key that is there and cannot be read is the opposite case
 * and stops the request, because carrying on would charge the site for a request somebody
 * asked to pay for themselves.
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

    /** @var string Whose key the last resolution asked for. */
    protected string $keysource = rule::KEYSOURCE_SITE;

    /** @var int[]|null The targets the site's own key may not be used at, once read. */
    protected ?array $byokonly = null;

    /** @var key|null A key that is registered and cannot be decrypted. */
    protected ?key $unreadable = null;

    /** @var bool Whether a budget, and nothing else, is why there is nowhere to go. */
    protected bool $budgetspent = false;

    /** @var rule_evaluator|null The evaluator, kept so its findings can be read back. */
    protected ?rule_evaluator $evaluator = null;

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
     * @return candidate[] Usable targets. Empty when nothing can handle the action.
     */
    public function get_candidates(action_base $action): array {
        $this->matchedrule = null;
        $this->declined = false;
        $this->keysource = rule::KEYSOURCE_SITE;
        $this->unreadable = null;
        $this->budgetspent = false;
        $this->byokonly = null;
        $instances = $this->get_instances_by_id();

        $this->evaluated = $this->get_evaluation_context($action);
        foreach ($this->get_evaluator()->matches($this->evaluated) as $rule) {
            $target = $instances[(int) $rule->get('targetid')] ?? null;
            if ($target === null || !$this->is_usable($target, $action, $rule->is_byok())) {
                continue;
            }
            if (!$rule->is_byok()) {
                $this->matchedrule = $rule;

                return $this->with_fallback(new candidate($target), $instances, $action);
            }

            $candidates = $this->with_brought_key($rule, $target, $instances, $action);
            if ($candidates === null) {
                // Nobody has a key here. An ordinary state, and the rule does not apply.
                continue;
            }
            $this->matchedrule = $rule;
            $this->keysource = (string) $rule->get('keysource');

            return $candidates;
        }

        return $this->get_unmatched_candidates($instances, $action);
    }

    /**
     * Whose key the last resolution asked for.
     *
     * @return string One of the rule key sources.
     */
    public function get_keysource(): string {
        return $this->keysource;
    }

    /**
     * The key that stopped the last resolution by being unreadable, if one did.
     *
     * A registered key that cannot be decrypted is a fault in the site, not an absent
     * key, and the two lead to opposite behaviour. Reporting it separately is what keeps
     * them apart at the one point where they otherwise look alike: no candidates.
     *
     * @return key|null The key, or null when nothing of the sort happened.
     */
    public function get_unreadable_key(): ?key {
        return $this->unreadable;
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
     * Whether the last request had nowhere to go because a budget had been spent.
     *
     * A budget is written as a condition, so a request that has run past one simply
     * stops matching the rule that carried it. That makes it indistinguishable from a
     * request no rule was ever about, which will not do: the first is the site's
     * spending limit doing its work and has to be the end of the matter, while the
     * second is a request the router was never asked to handle and may perfectly well
     * be somebody else's. They are separated here, at the only point that can tell.
     *
     * @return bool True when a rule fitted in every respect but its budget.
     */
    public function was_budget_spent(): bool {
        return $this->budgetspent;
    }

    /**
     * Where a request goes when no rule claimed it.
     *
     * @param ai_provider[] $instances Every provider instance, keyed by id.
     * @param action_base $action The action to be delegated.
     * @return candidate[] The candidates, which may be none.
     */
    protected function get_unmatched_candidates(array $instances, action_base $action): array {
        if ($this->router->get_nomatch_behaviour() === provider::NOMATCH_DECLINE) {
            $this->declined = true;
            $this->budgetspent = (bool) $this->get_evaluator()->get_budget_blocked();

            return [];
        }

        $target = $this->get_default_target($instances, $action);
        if ($target !== null) {
            // A budget that sends the request to the default target instead is the
            // administrator's arrangement working, not a refusal.
            return [new candidate($target)];
        }
        $this->budgetspent = (bool) $this->get_evaluator()->get_budget_blocked();

        return [];
    }

    /**
     * The candidates for a rule that asks somebody other than the site to pay.
     *
     * @param rule $rule The matching rule.
     * @param ai_provider $target The instance it names.
     * @param ai_provider[] $instances Every provider instance, keyed by id.
     * @param action_base $action The action to be delegated.
     * @return candidate[]|null The candidates, or null when there is no key here at all,
     *                          which means the rule does not apply.
     */
    protected function with_brought_key(
        rule $rule,
        ai_provider $target,
        array $instances,
        action_base $action,
    ): ?array {
        $scope = (string) $rule->get('keysource');
        $scopeid = $this->get_scopeid($scope);
        if ($scopeid <= 0) {
            // A course key wanted by a request made outside any course, or a user key
            // with nobody to own it.
            return null;
        }
        if ($scope === rule::KEYSOURCE_USER && !$this->get_policy()->is_eligible($scopeid)) {
            // Asked again here, not only when the key was registered, so that a policy
            // the administrator tightens stops being obeyed on the next request rather
            // than when somebody remembers to go and delete the keys.
            return null;
        }

        $injection = $this->get_injector()->for_subject($target, $scope, $scopeid);
        if ($injection->status === key_status::UNREADABLE) {
            $this->unreadable = $injection->key;
            $this->matchedrule = $rule;
            $this->keysource = $scope;

            return [];
        }
        if (!$injection->is_usable()) {
            return null;
        }
        if ($this->is_spent($injection->key)) {
            // The owner said how much they were willing to spend here and it has been
            // spent. Treated exactly as a key that was never registered: the rule does
            // not apply and the next one is tried. It is not a fault, and stopping the
            // request would punish somebody for setting themselves a limit.
            return null;
        }

        return $this->with_key_fallback($injection, $scope, $scopeid, $instances, $action);
    }

    /**
     * Who is paying, as a subject the key store can be asked about.
     *
     * @param string $scope One of the rule key sources.
     * @return int The user or course id, or zero when there is none here.
     */
    protected function get_scopeid(string $scope): int {
        if ($scope === rule::KEYSOURCE_COURSE) {
            // Resolved exactly as the rules resolve it, so that a request from a module
            // or a block counts as being in the course it belongs to.
            return (int) ($this->evaluated?->get_courseid() ?? 0);
        }

        return (int) ($this->evaluated?->get_userid() ?? 0);
    }

    /**
     * A target carrying a brought key, followed by the others that subject has a key for.
     *
     * The fallback chain is confined to instances the same subject holds a key for. A
     * request somebody asked to pay for themselves must not quietly become a request the
     * site pays for because the first provider was busy.
     *
     * Every one of those keys is decrypted here, including the ones that will not be
     * needed if the first target answers. They all belong to the subject whose key is
     * already being read, and there are as many of them as the site has delegation
     * targets, so the alternative is complexity bought for very little.
     *
     * @param key_injection $first The instance the rule named, carrying its key.
     * @param string $scope One of the rule key sources.
     * @param int $scopeid The user or course paying.
     * @param ai_provider[] $instances Every provider instance, keyed by id.
     * @param action_base $action The action to be delegated.
     * @return candidate[] The candidates, in the order they should be tried.
     */
    protected function with_key_fallback(
        key_injection $first,
        string $scope,
        int $scopeid,
        array $instances,
        action_base $action,
    ): array {
        $candidates = [new candidate($first->target, $scope, $first->key)];
        $named = (int) $first->key->get('targetid');

        // Nothing orders these, so they are tried in a stable order rather than an
        // arbitrary one. An administrator who wants a particular order writes rules.
        foreach ($this->get_keys($scope, $scopeid) as $targetid => $key) {
            $instance = $instances[(int) $targetid] ?? null;
            if ((int) $targetid === $named || $instance === null || !$this->is_usable($instance, $action, true)) {
                continue;
            }
            $injection = $this->get_injector()->inject($instance, $key);
            if ($injection->is_usable() && !$this->is_spent($injection->key)) {
                $candidates[] = new candidate($injection->target, $scope, $key);
            }
        }

        return $candidates;
    }

    /**
     * Every key one subject holds.
     *
     * @param string $scope One of the rule key sources.
     * @param int $scopeid The user or course.
     * @return key[] The keys, keyed by target id.
     */
    protected function get_keys(string $scope, int $scopeid): array {
        global $DB;

        return (new key_repository($DB))->get_all($scope, $scopeid);
    }

    /**
     * How a key is put into the instance it belongs to.
     *
     * @return key_injector The injector.
     */
    protected function get_injector(): key_injector {
        global $DB;

        return new key_injector($DB);
    }

    /**
     * Who the site allows to bring a key.
     *
     * @return eligibility_policy The policy.
     */
    protected function get_policy(): eligibility_policy {
        return new eligibility_policy();
    }

    /**
     * A matched target, followed by the default target when it may serve as a backstop.
     *
     * The default target stands behind a rule only where the administrator has said
     * unclaimed requests may go to it. Where they have chosen to decline those, sending
     * a failed request there anyway would spend money at a provider they deliberately
     * kept out of the picture.
     *
     * @param candidate $target The instance the matching rule named.
     * @param ai_provider[] $instances Every provider instance, keyed by id.
     * @param action_base $action The action to be delegated.
     * @return candidate[] The candidates, in the order they should be tried.
     */
    protected function with_fallback(candidate $target, array $instances, action_base $action): array {
        if ($this->router->get_nomatch_behaviour() === provider::NOMATCH_DECLINE) {
            return [$target];
        }
        $fallback = $this->get_default_target($instances, $action);
        if ($fallback === null || (int) $fallback->id === (int) $target->target->id) {
            return [$target];
        }

        return [$target, new candidate($fallback)];
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

        // One per resolver: what it noticed while walking the rules is read back after
        // the walk, so a fresh instance each time would have nothing to report.
        return $this->evaluator ??= new rule_evaluator(new rule_repository($DB));
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
     * @param bool $brought Whether the request would be paid for with a brought key.
     * @return bool True if the instance may be used.
     */
    protected function is_usable(ai_provider $instance, action_base $action, bool $brought = false): bool {
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
        if (empty($actionconfig['enabled'])) {
            return false;
        }

        // A provider the site has set aside for brought keys only. Turned away here
        // rather than at the provider, so that a request the site would have paid for
        // moves on to the next rule instead of spending a round trip finding out.
        return $brought || !in_array((int) $instance->id, $this->get_byok_only(), true);
    }

    /**
     * Whether a key has spent what its owner allowed it to.
     *
     * ⚠ Not the same kind of answer as a budget condition's. A budget guards the
     * site's money and refuses to route when it cannot be measured; this guards
     * somebody's own money, and a site with no rates entered must not silently stop
     * every key it holds. Unknown spending leaves the key in play.
     *
     * @param key|null $brought The key the request would carry.
     * @return bool True when the key should be passed over.
     */
    protected function is_spent(?key $brought): bool {
        global $DB;

        if ($brought === null || !$brought->has_cap()) {
            return false;
        }

        return $brought->is_spent(new spend_ledger($DB), time());
    }

    /**
     * The targets the site's own key may not be used at.
     *
     * Read once per resolution. Every candidate is weighed against it, and the answer
     * cannot change in the middle of choosing one.
     *
     * @return int[] The target ids.
     */
    protected function get_byok_only(): array {
        global $DB;

        $this->byokonly ??= (new target_settings($DB))->get_byok_only_ids();

        return $this->byokonly;
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
