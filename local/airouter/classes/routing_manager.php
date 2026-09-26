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

namespace local_airouter;

use core_ai\aiactions\base as action_base;
use core_ai\aiactions\responses\response_base;
use local_airouter\record\request_state;
use local_airouter\record\usage_recorder;

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
 * @package    local_airouter
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
        // Everything this request is decided by is read once, here. A change saved
        // while it is being processed applies to the next one, not to half of this
        // one, and a process that has been running for hours still sees the change.
        $policy = request_policy::start($this->db);

        // One switch above everything else. Off, this object does what core's own
        // manager does, which is what a site turning the router off is asking for.
        if (!$policy->is_switched_on() || !$policy->is_managed($action)) {
            return parent::process_action($action);
        }

        $router = $this->router_for_dispatch($action::class, $policy);
        if ($router === null) {
            // The site says this action goes through the router and the router cannot
            // take it. Answering it with another provider would be the one thing the
            // site asked not to happen, so it is refused here, before any AI is called.
            //
            // Core's record is not written for this: writing it needs a provider that
            // really exists to attribute it to, and inventing one to make the row
            // appear would be a lie in the site's own audit trail. What is left here
            // is the one case where nothing can run at all: an action this release of
            // the router has no processor for. There is no code that could be asked to
            // explain itself.
            //
            // The plugin's own record is written, though: the site kept this action
            // inside the router, and a request it turned away at the door is still a
            // request somebody made. One row, declined, with no attempt.
            $recorder = new usage_recorder($this->db);
            $recorder->end_request(
                $recorder->begin_request(new evaluation_context($action)),
                request_state::DECLINED,
                'router_unavailable',
                503,
                null,
                null,
                rule::KEYSOURCE_SITE,
                0,
            );

            return response_factory::failure(
                $action,
                503,
                'router_unavailable',
                get_string('error:routerunavailable', 'local_airouter'),
            );
        }

        // Every provider instance, read once for this request and handed to whatever
        // chooses the router's target, so that the choice is made from one reading.
        $router->carry_request_instances($this->get_provider_instances());
        $dispatch = new single_router_dispatch($this->db, $router);

        return $dispatch->process_action($action);
    }

    /**
     * The router, when it can answer this action.
     *
     * Availability is asked here and not folded into the managed list, because the two
     * answer different questions. Whether the site manages an action is a decision the
     * site made; whether the router can carry it today is a fact about right now. A
     * managed action the router cannot answer is refused, not quietly let out.
     *
     * The status check asks the same question through this method rather than
     * repeating the conditions, so that what the administrator is shown and what
     * actually happens cannot drift apart.
     *
     * @param string $actionclass The action class being requested.
     * @param request_policy|null $policy The settings this request began with, when
     *                                    the answer is going to carry it out.
     * @return provider|null The router, or null if it cannot answer.
     */
    public function find_router(string $actionclass, ?request_policy $policy = null): ?provider {
        return $this->adapter_for(ltrim($actionclass, '\\'), null, $policy);
    }

    /**
     * The router to hand this request to, which may be one that will refuse it.
     *
     * Being unable to answer and being unconfigured are different, and only the first
     * is a reason to stop here. The router knows why it cannot carry a request whether
     * or not the rules are finished: no target, no rule matched, a budget spent, a key
     * it could not read. Letting it say so puts the refusal through core's own
     * processing, so the request is recorded and the person is shown a reason, where
     * stopping short of it left the site with neither.
     *
     * @param string $actionclass The action class being requested.
     * @param request_policy|null $policy The settings this request began with.
     * @return provider|null The router, or null when it has nothing that could even
     *                       explain itself for this action.
     */
    protected function router_for_dispatch(string $actionclass, ?request_policy $policy = null): ?provider {
        // The settings go with the request all the way to the object that carries it
        // out. Finding the router one way and building it another is how a request
        // came to be judged by this morning's settings and this minute's switch.
        $adapter = adapter_provider::create(policy: $policy);
        $carried = array_map(
            static fn(string $action): string => ltrim($action, '\\'),
            $adapter->get_action_list(),
        );

        return in_array(ltrim($actionclass, '\\'), $carried, true) ? $adapter : null;
    }

    /**
     * The router built from the site's own settings, when it can answer this action.
     *
     * The router has no row in ai_providers. The policy, the rules and the budgets live
     * in this plugin's configuration, and the object core needs while it runs an
     * action is made from them for the length of the request.
     *
     * @param string $actionclass The action class being requested, already normalised.
     * @param string[]|null $managedactions A policy to judge by instead of the saved one.
     * @param request_policy|null $policy The settings this request began with.
     * @return provider|null The adapter, or null when it cannot answer.
     */
    protected function adapter_for(
        string $actionclass,
        ?array $managedactions = null,
        ?request_policy $policy = null,
    ): ?provider {
        $adapter = adapter_provider::create($managedactions, $policy);
        if (!$adapter->is_provider_configured()) {
            return null;
        }

        $carried = array_map(
            static fn(string $action): string => ltrim($action, '\\'),
            $adapter->get_action_list(),
        );
        if (!in_array($actionclass, $carried, true)) {
            return null;
        }

        return ($adapter->actionconfig[$actionclass]['enabled'] ?? false) ? $adapter : null;
    }

    /**
     * Whether the router would answer this action if the site saved this policy.
     *
     * The management screen warns before an action is placed under a router that
     * cannot answer it, because that stops the action working everywhere. Asking
     * find_router() gives the wrong answer there: what the router answers is the saved
     * policy, so an action being added for the first time is always one it does not
     * answer yet -- which is precisely what the save being asked about would change.
     * Every first choice looked like a mistake, and a warning shown for correct
     * settings is one that stops being read.
     *
     * Nothing is written to decide this. Requests keep asking find_router(), which
     * judges by what is saved, so the policy an administrator is still considering
     * cannot let a request through.
     *
     * @param string $actionclass The action class the site is considering.
     * @param string[] $managedactions The policy as it would be saved.
     * @return bool True when a request for it would reach the router.
     */
    public function would_answer(string $actionclass, array $managedactions): bool {
        return $this->adapter_for(ltrim($actionclass, '\\'), $managedactions) !== null;
    }
}
