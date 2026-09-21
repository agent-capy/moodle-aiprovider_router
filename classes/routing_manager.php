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
use core_ai\aiactions\responses\response_base;
use core_ai\provider as ai_provider;

/**
 * The manager Moodle asks, for sites that have placed actions under the router.
 *
 * Every entry point in core that starts an AI request asks the dependency injection
 * container for a manager. Registering this class there is what lets the site decide
 * which requests the router answers, instead of hoping the router is first in the
 * provider order: an order is a preference, and a preference is not a policy. A
 * provider placed above the router answers before the router is ever asked, so the
 * rules, the budgets and the choice of who pays never run.
 *
 * Nothing else changes. Actions the site has not placed under the router are handled
 * by core exactly as before, and every other thing a manager does -- listing
 * providers, creating them, the settings screens -- is inherited untouched, so the
 * administration pages show the same site they showed before.
 *
 * This object is shared for the length of a request and may be reused. It holds no
 * state about the person asking or the request being made.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class routing_manager extends \core_ai\manager {
    /**
     * Process an action.
     *
     * @param action_base $action The action to process.
     * @return response_base The result.
     */
    #[\Override]
    public function process_action(action_base $action): response_base {
        if (!managed_policy::is_managed($action)) {
            return parent::process_action($action);
        }

        $router = $this->find_router($action::class);
        if ($router === null) {
            // The site says this action goes through the router and the router cannot
            // take it. Answering it with another provider would be the one thing the
            // site asked not to happen, so it is refused here, before any AI is called.
            //
            // Core's record is not written for this: writing it needs a provider that
            // really exists to attribute it to, and inventing one to make the row
            // appear would be a lie in the site's own audit trail.
            return response_factory::failure(
                $action,
                503,
                'router_unavailable',
                get_string('error:routerunavailable', 'aiprovider_router'),
            );
        }

        $dispatch = new single_router_dispatch($this->db, $router);

        return $dispatch->process_action($action);
    }

    /**
     * The router instance that can answer this action, if there is one.
     *
     * Availability is asked here and not folded into the managed list, because the two
     * answer different questions. Whether the site manages an action is a decision the
     * site made; whether the router can carry it today is a fact about right now. A
     * managed action whose router is missing is refused, not quietly let out.
     *
     * The status check asks the same question through this method rather than
     * repeating the conditions, so that what the administrator is shown and what
     * actually happens cannot drift apart.
     *
     * @param string $actionclass The action class being requested.
     * @return ai_provider|null The instance, or null if none can answer.
     */
    public function find_router(string $actionclass): ?ai_provider {
        $actionclass = ltrim($actionclass, '\\');
        $instances = $this->get_provider_instances(['provider' => ltrim(provider::class, '\\')]);

        foreach ($instances as $instance) {
            if (!$instance->enabled || !$instance->is_provider_configured()) {
                continue;
            }
            $carried = array_map(
                static fn(string $action): string => ltrim($action, '\\'),
                $instance->get_action_list(),
            );
            if (!in_array($actionclass, $carried, true)) {
                continue;
            }
            if (!$this->is_action_enabled($instance->provider, $actionclass, $instance->id)) {
                continue;
            }

            return $instance;
        }

        return null;
    }
}
