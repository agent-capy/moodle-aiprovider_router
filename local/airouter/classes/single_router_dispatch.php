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

use local_airouter\exception\declined_request;
use core_ai\aiactions\base as action_base;
use core_ai\aiactions\responses\response_base;
use core_ai\provider as ai_provider;

/**
 * Runs one managed request, with the router as the only provider that can answer it.
 *
 * Core walks the provider order and stops at the first success. For a request the site
 * has placed under the router that order is the wrong question: the router is the
 * answer, and any other provider reached afterwards would answer on the site's own key
 * a request the rules, the budget or somebody's own key had already settled.
 *
 * Rather than reimplement what core does with the result, the candidate list is
 * narrowed to one and core's own process_action() is used. Everything after the call
 * is then core's: the action is stored, the row in ai_action_register is written, and
 * the placement gets the response type it expects. There is no second candidate, so
 * the loop ends whatever the result is.
 *
 * An instance of this exists for one request and is thrown away. Nothing about the
 * person asking, the candidates or their keys is kept on it, because the manager in the
 * dependency injection container is shared and this must not become a way to carry
 * state between requests.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class single_router_dispatch extends \core_ai\manager {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param ai_provider $router The router that will answer, already checked.
     */
    public function __construct(
        \moodle_database $db,
        /** @var ai_provider The one provider allowed to answer this request. */
        protected readonly ai_provider $router,
    ) {
        parent::__construct($db);
    }

    /**
     * The providers that may answer, which here is the router and nothing else.
     *
     * The caller has already established that the router is enabled, configured and
     * carries the action. Deciding that again here would mean a disabled provider could
     * be reached by a different route than the one the administrator can see.
     *
     * @param array $actions Fully qualified action class names.
     * @param bool $enabledonly Ignored: the single candidate was checked before it got here.
     * @return array The router, indexed by action name.
     */
    #[\Override]
    public function get_providers_for_actions(array $actions, bool $enabledonly = false): array {
        $carried = array_map(
            static fn(string $action): string => ltrim($action, '\\'),
            $this->router->get_action_list(),
        );

        $providers = [];
        foreach ($actions as $action) {
            $providers[$action] = in_array(ltrim($action, '\\'), $carried, true) ? [$this->router] : [];
        }

        return $providers;
    }

    /**
     * Run the action against the router, turning a deliberate refusal into a response.
     *
     * The router says "this is final" by throwing, because a provider has no other way
     * to stop core offering the request to the next one. Here there is no next one, so
     * the throw has nothing left to prevent, and carrying it further would only cost
     * the site core's own record of what happened and show the person an exception
     * instead of the failure their placement knows how to display.
     *
     * Only the router's own refusal is caught, and only in this class, which exists
     * solely where the candidate list is one long. Anything else thrown is a fault and
     * is left alone: turning arbitrary failures into policy refusals would hide them.
     *
     * @param ai_provider $provider The provider to call.
     * @param action_base $action The action to run.
     * @return response_base The result.
     */
    #[\Override]
    protected function call_action_provider(ai_provider $provider, action_base $action): response_base {
        try {
            return parent::call_action_provider($provider, $action);
        } catch (declined_request $e) {
            return response_factory::failure(
                $action,
                $e->get_statuscode(),
                $e->get_reason(),
                $e->getMessage(),
            );
        }
    }
}
