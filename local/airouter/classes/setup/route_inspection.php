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

/**
 * What the settings say about one action, read without sending anything.
 *
 * Two separate answers, and neither is a promise that a request will succeed. The
 * first is whether a request for the action goes through the router at all. The
 * second is what the router's settings offer it: which targets the default and the
 * rules name, whether those can carry the action, and where a request goes when no
 * rule claims it. Budgets, brought keys, permissions and whether a provider really
 * answers are decided per request, and are not part of either.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class route_inspection {
    /** @var string Requests go through the router. */
    public const APPLIED_ROUTED = 'routed';

    /** @var string The action is not routed through the router; Moodle handles it. */
    public const APPLIED_NOT_ROUTED = 'notrouted';

    /** @var string Routing is switched off; Moodle handles every action. */
    public const APPLIED_SWITCHED_OFF = 'switchedoff';

    /** @var string The action is routed, but this release of the router cannot carry it. */
    public const APPLIED_NO_PROCESSOR = 'noprocessor';

    /** @var string Requests no rule claims go to a default target that can carry them. */
    public const STATE_READY = 'ready';

    /** @var string Only requests some rule claims can be carried. */
    public const STATE_CONDITIONAL = 'conditional';

    /** @var string Every request is refused because the policy declines what no rule claims. */
    public const STATE_POLICY_REFUSED = 'policyrefused';

    /** @var string Nothing the settings name can carry the action. */
    public const STATE_ACTION_NEEDED = 'actionneeded';

    /**
     * Constructor.
     *
     * @param string $actionclass The action, as a class name without a leading separator.
     * @param string $applied One of the APPLIED_ constants.
     * @param string $state One of the STATE_ constants.
     * @param int|null $defaulttargetid The default delegation target, or null when none is set.
     * @param string|null $defaulttargetname Its name, or null when it is gone or not set.
     * @param string|null $defaultproblem Why it cannot carry the action, or null when it can.
     * @param bool $declines Whether requests no rule claims are refused.
     * @param route_rule[] $rules The rules in force that apply to this action, in order.
     * @param string|null $corefirst The provider Moodle would try first if it handled this
     *                               action itself, or null when it has none to offer.
     */
    public function __construct(
        /** @var string The action, as a class name without a leading separator. */
        public readonly string $actionclass,
        /** @var string One of the APPLIED_ constants. */
        public readonly string $applied,
        /** @var string One of the STATE_ constants. */
        public readonly string $state,
        /** @var int|null The default delegation target, or null when none is set. */
        public readonly ?int $defaulttargetid,
        /** @var string|null The default target's name, or null when it is gone or not set. */
        public readonly ?string $defaulttargetname,
        /** @var string|null Why the default target cannot carry the action, or null when it can. */
        public readonly ?string $defaultproblem,
        /** @var bool Whether requests no rule claims are refused. */
        public readonly bool $declines,
        /** @var route_rule[] The rules in force that apply to this action, in order. */
        public readonly array $rules,
        /** @var string|null The provider Moodle would try first, or null when there is none. */
        public readonly ?string $corefirst,
    ) {
    }

    /**
     * Whether the default target is in use for this action.
     *
     * The same condition answers two questions: whether a request no rule claims has
     * somewhere to go, and whether a site-paid rule whose target fails falls back to
     * the default target. The router uses the default target for both or for neither.
     *
     * @return bool True when the default target can carry the action and is used.
     */
    public function has_default_route(): bool {
        return !$this->declines && $this->defaulttargetid !== null && $this->defaultproblem === null;
    }

    /**
     * The rules that can carry the action, as far as the settings say.
     *
     * @return route_rule[] The rules.
     */
    public function usable_rules(): array {
        return array_values(array_filter($this->rules, static fn(route_rule $rule): bool => $rule->problem === null));
    }

    /**
     * The rules that name a target that cannot carry the action.
     *
     * @return route_rule[] The rules.
     */
    public function broken_rules(): array {
        return array_values(array_filter($this->rules, static fn(route_rule $rule): bool => $rule->problem !== null));
    }

    /**
     * Whether every rule that can carry the action is paid for with a brought key.
     *
     * @return bool True when only people or courses holding a key are served by rules.
     */
    public function served_only_by_brought_keys(): bool {
        $usable = $this->usable_rules();
        if ($usable === []) {
            return false;
        }
        foreach ($usable as $rule) {
            if (!$rule->brought) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a rule with a budget condition applies to this action.
     *
     * @return bool True when one does.
     */
    public function has_budget_rule(): bool {
        foreach ($this->rules as $rule) {
            if ($rule->hasbudget) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a default target is set but cannot carry the action.
     *
     * @return bool True when one is set and cannot be used.
     */
    public function default_is_broken(): bool {
        return $this->defaulttargetid !== null && $this->defaultproblem !== null;
    }
}
