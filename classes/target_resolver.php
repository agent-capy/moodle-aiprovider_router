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
 * WP2 resolves the configured default target only. WP3 puts the rule engine in front
 * of this class; the candidate list it returns keeps the same shape, so the fallback
 * chain in the processors does not change when rules arrive.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class target_resolver {
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
        $targetid = $this->router->get_default_target_id();
        if ($targetid === null) {
            return [];
        }

        $candidates = [];
        foreach ($this->get_instances() as $instance) {
            if ((int) $instance->id === $targetid && $this->is_usable($instance, $action)) {
                $candidates[] = $instance;
            }
        }

        return $candidates;
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
}
