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

namespace local_airouter\check;

use local_airouter\adapter_provider;
use local_airouter\managed_policy;
use local_airouter\order_inspector;
use local_airouter\provider;
use core\check\check;
use core\check\result;

/**
 * Shared behaviour for the router's status checks.
 *
 * The checks all describe the same thing from different angles, so they share one
 * order_inspector and cannot end up contradicting each other. A site that has not set
 * the router up is not misconfigured, so every check reports NA rather than a problem.
 *
 * Whether the router is set up is not the same question as whether a provider instance
 * exists. A site can route with no instance at all, and asking for the instance turned
 * every check off on exactly such a site: the rules, the budgets and the keys were
 * being used and nothing was watching any of them. What each check needs is asked for
 * separately, so a check about the provider order stands down where there is no
 * provider to order, and a check about the site's own rules keeps working.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends check {
    /**
     * Constructor.
     *
     * @param order_inspector|null $inspector The inspector to read the site through.
     */
    public function __construct(
        /** @var order_inspector|null The injected inspector, if any. */
        protected ?order_inspector $inspector = null,
    ) {
        $this->inspector ??= new order_inspector();
    }

    #[\Override]
    public function get_name(): string {
        return get_string('check:' . $this->get_id(), 'local_airouter');
    }

    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/local/airouter/order.php'),
            get_string('order:heading', 'local_airouter'),
        );
    }

    #[\Override]
    public function get_result(): result {
        if (!$this->router_is_set_up()) {
            return new result(result::NA, get_string('check:norouter', 'local_airouter'));
        }

        // Some of these are questions about a provider: where it sits in the site
        // order, whether two of it exist, what its stored action settings say. On a
        // site that routes without one there is nothing for them to be about.
        if ($this->requires_instance() && $this->inspector->get_primary_router() === null) {
            return new result(result::NA, get_string('check:notaprovider', 'local_airouter'));
        }

        if (!$this->depends_on_provider_order()) {
            return $this->check_router();
        }

        // An action the site has placed under the router does not go through the
        // provider order at all, so a check about that order has nothing to say about
        // it. Reporting the order as a problem anyway would be telling an administrator
        // to fix something that no longer decides anything.
        $unmanaged = $this->unmanaged_actions();
        if ($unmanaged === []) {
            return new result(result::OK, get_string('check:ordernotused', 'local_airouter'));
        }

        $result = $this->check_router();
        if (!managed_policy::is_active()) {
            return $result;
        }

        // Some go through the order and some do not, so say which ones this is about.
        $names = implode(', ', array_map(
            static fn(string $action): string => $action::get_basename(),
            $unmanaged,
        ));

        return new result(
            $result->get_status(),
            $result->get_summary(),
            trim($result->get_details() . ' ' . get_string('check:orderpartial', 'local_airouter', $names)),
        );
    }

    /**
     * Whether the router is set up on this site at all.
     *
     * Either way of configuring it counts: a provider instance, or the rules and
     * default target this plugin holds itself. A site with neither has not started.
     *
     * @return bool True when there is something to check.
     */
    protected function router_is_set_up(): bool {
        return $this->inspector->get_primary_router() !== null
            || adapter_provider::create()->is_provider_configured();
    }

    /**
     * Whether this check is about a stored provider instance rather than the site.
     *
     * @return bool True when the finding needs an instance to be about.
     */
    protected function requires_instance(): bool {
        return false;
    }

    /**
     * Whether this check is asking a question about the provider order.
     *
     * Those checks stop applying to an action the site has placed under the router,
     * because such a request never reaches the order. They are also about a provider,
     * so they need one to exist.
     *
     * @return bool True when the finding depends on where the router sits.
     */
    protected function depends_on_provider_order(): bool {
        return false;
    }

    /**
     * The actions the router carries that still go through the provider order.
     *
     * @return string[] Action class names.
     */
    protected function unmanaged_actions(): array {
        $managed = managed_policy::managed_actions();

        return array_values(array_filter(
            array_map(static fn(string $action): string => ltrim($action, '\\'), provider::get_action_list()),
            static fn(string $action): bool => !in_array($action, $managed, true),
        ));
    }

    /**
     * Run the check, knowing that a router instance exists.
     *
     * @return result The outcome.
     */
    abstract protected function check_router(): result;
}
