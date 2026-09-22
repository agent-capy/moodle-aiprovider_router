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
        // One switch above everything else. Off, this object does what core's own
        // manager does, which is what a site turning the router off is asking for.
        if (!managed_policy::is_switched_on() || !managed_policy::is_managed($action)) {
            return parent::process_action($action);
        }

        $router = $this->router_for_dispatch($action::class);
        if ($router === null) {
            // The site says this action goes through the router and the router cannot
            // take it. Answering it with another provider would be the one thing the
            // site asked not to happen, so it is refused here, before any AI is called.
            //
            // Core's record is not written for this: writing it needs a provider that
            // really exists to attribute it to, and inventing one to make the row
            // appear would be a lie in the site's own audit trail. What is left here
            // is the case where nothing can run at all -- a stored instance that is
            // switched off, or an action this release of the router has no processor
            // for. There is no code that could be asked to explain itself.
            return response_factory::failure(
                $action,
                503,
                'router_unavailable',
                get_string('error:routerunavailable', 'local_airouter'),
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
        $instances = $this->get_provider_instances(['provider' => provider::INSTANCE_CLASS]);

        if ($instances === []) {
            return $this->adapter_for($actionclass);
        }

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

    /**
     * The router to hand this request to, which may be one that will refuse it.
     *
     * Being unable to answer and being unconfigured are different, and only the first
     * is a reason to stop here. A site with no stored instance has this plugin as its
     * router whether or not the rules are finished, and the router knows why it cannot
     * carry the request: no target, no rule matched, a budget spent, a key it could
     * not read. Letting it say so puts the refusal through core's own processing, so
     * the request is recorded and the person is shown a reason, where stopping short
     * of it left the site with neither.
     *
     * @param string $actionclass The action class being requested.
     * @return ai_provider|null The router, or null when nothing could even explain itself.
     */
    protected function router_for_dispatch(string $actionclass): ?ai_provider {
        $actionclass = ltrim($actionclass, '\\');
        $found = $this->find_router($actionclass);
        if ($found !== null) {
            return $found;
        }

        // A stored instance is the site's router and its own state is the answer:
        // one that is switched off has been switched off on purpose.
        if ($this->get_provider_instances(['provider' => provider::INSTANCE_CLASS]) !== []) {
            return null;
        }

        $adapter = adapter_provider::create();
        $carried = array_map(
            static fn(string $action): string => ltrim($action, '\\'),
            $adapter->get_action_list(),
        );

        return in_array($actionclass, $carried, true) ? $adapter : null;
    }

    /**
     * The router built from the site's own settings, when it can answer this action.
     *
     * A site with no row in ai_providers is not a site without a router. The policy,
     * the rules and the budgets live in this plugin's configuration, and the object
     * core needs while it runs an action can be made from them for the length of the
     * request. This is the arrangement the plugin is moving to; the stored instance
     * above is what it is moving from, and while both exist a stored one wins, so
     * that a site that has configured the router in the old place keeps the settings
     * it can see.
     *
     * @param string $actionclass The action class being requested, already normalised.
     * @param string[]|null $managedactions A policy to judge by instead of the saved one.
     * @return ai_provider|null The adapter, or null when it cannot answer.
     */
    protected function adapter_for(string $actionclass, ?array $managedactions = null): ?ai_provider {
        $adapter = adapter_provider::create($managedactions);
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
     * find_router() gives the wrong answer there: without a stored instance, what the
     * router answers is the saved policy, so an action being added for the first time
     * is always one it does not answer yet -- which is precisely what the save being
     * asked about would change. Every first choice looked like a mistake, and a
     * warning shown for correct settings is one that stops being read.
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
        $actionclass = ltrim($actionclass, '\\');

        // A stored instance carries its own action settings, which this policy does
        // not change, so the honest answer is the one a request would get today.
        if ($this->get_provider_instances(['provider' => provider::INSTANCE_CLASS]) !== []) {
            return $this->find_router($actionclass) !== null;
        }

        return $this->adapter_for($actionclass, $managedactions) !== null;
    }
}
