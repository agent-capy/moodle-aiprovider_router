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

use core_ai\manager;
use core_ai\provider as ai_provider;

/**
 * Reads the site wide provider order and answers questions about the router's place in it.
 *
 * Core tries providers in the order given by the core_ai provider_order setting and returns
 * the first success, so a router that is not first never sees the request. Creating an
 * instance puts it last, which makes "installed but nothing happens" the default outcome.
 * The status checks, the settings form notice and the order page all read the site's state
 * through this class so that they cannot disagree with each other.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class order_inspector {
    /** @var ai_provider[]|null Every instance on the site, keyed by id. */
    protected ?array $instances = null;

    /** @var ai_provider[]|null Instances in the order core tries them, keyed by id. */
    protected ?array $sorted = null;

    /** @var string[]|null The provider_order entries exactly as stored. */
    protected ?array $entries = null;

    /**
     * Constructor.
     *
     * @param manager|null $manager The AI manager to read through. Resolved from the
     *                              container when not given.
     */
    public function __construct(
        /** @var manager|null The injected manager, if any. */
        protected readonly ?manager $manager = null,
    ) {
    }

    /**
     * The AI manager.
     *
     * @return manager The manager.
     */
    protected function get_manager(): manager {
        return $this->manager ?? \core\di::get(manager::class);
    }

    /**
     * The provider_order entries, exactly as stored.
     *
     * Entries are not cleaned up here. An empty first entry is normal and load bearing,
     * and ids of deleted instances are reported rather than silently dropped.
     *
     * @return string[] The entries, in order.
     */
    public function get_entries(): array {
        if ($this->entries === null) {
            $this->entries = explode(',', (string) get_config('core_ai', 'provider_order'));
        }

        return $this->entries;
    }

    /**
     * Every provider instance on the site.
     *
     * @return ai_provider[] Instances keyed by id.
     */
    public function get_instances(): array {
        if ($this->instances === null) {
            $this->instances = $this->get_manager()->get_provider_instances();
        }

        return $this->instances;
    }

    /**
     * Provider instances in the order core tries them.
     *
     * Instances missing from provider_order are tried last, which is what core's own
     * sorting does with them.
     *
     * @return ai_provider[] Instances keyed by id, in order.
     */
    public function get_sorted_instances(): array {
        if ($this->sorted === null) {
            // Core's own comparison, rather than a second implementation of it that could
            // drift. get_sorted_providers() would do the same but query the database again.
            $this->sorted = [];
            foreach (manager::sort_providers_by_order($this->get_instances()) as $instance) {
                $this->sorted[(int) $instance->id] = $instance;
            }
        }

        return $this->sorted;
    }

    /**
     * Every router instance on the site, lowest id first.
     *
     * @return provider[] Router instances keyed by id.
     */
    public function get_routers(): array {
        $routers = [];
        foreach ($this->get_instances() as $id => $instance) {
            if ($instance instanceof provider) {
                $routers[(int) $id] = $instance;
            }
        }
        ksort($routers);

        return $routers;
    }

    /**
     * The router instance the site should be routing through.
     *
     * @return provider|null The lowest numbered router, or null when there is none.
     */
    public function get_primary_router(): ?provider {
        $routers = $this->get_routers();

        return $routers ? reset($routers) : null;
    }

    /**
     * Whether the primary router appears in provider_order at all.
     *
     * @return bool True when it is listed.
     */
    public function is_router_listed(): bool {
        $router = $this->get_primary_router();
        if ($router === null) {
            return false;
        }

        return in_array((string) $router->id, $this->get_entries(), true);
    }

    /**
     * Where the primary router sits among the instances that actually exist.
     *
     * @return int|null Zero based position, or null when there is no router.
     */
    public function get_router_position(): ?int {
        $router = $this->get_primary_router();
        if ($router === null) {
            return null;
        }
        $position = array_search((int) $router->id, array_keys($this->get_sorted_instances()), true);

        return $position === false ? null : (int) $position;
    }

    /**
     * Whether the primary router is the first instance core would try.
     *
     * @return bool True when it is first.
     */
    public function is_router_first(): bool {
        return $this->get_router_position() === 0;
    }

    /**
     * Entries in provider_order that name an instance which no longer exists.
     *
     * The empty first entry is not stale. It is the artefact of core writing the order for
     * the first time, and it keeps index 0 occupied, which is what stops a real instance
     * from landing there. Core's enable and disable handling both test the result of
     * array_search() for truthiness, so an instance at index 0 is duplicated when enabled
     * and left behind when disabled.
     *
     * @return string[] The stale entries, in the order they appear.
     */
    public function get_stale_entries(): array {
        $existing = array_map('strval', array_keys($this->get_instances()));
        $stale = [];
        foreach ($this->get_entries() as $entry) {
            if ($entry !== '' && !in_array($entry, $existing, true)) {
                $stale[] = $entry;
            }
        }

        return $stale;
    }

    /**
     * Instances that would take a request before the router ever sees it.
     *
     * Only instances that could really answer are reported: enabled, configured, and with
     * an action in common with the router that is itself enabled.
     *
     * @return ai_provider[] The instances, in order.
     */
    public function get_intercepting_instances(): array {
        $position = $this->get_router_position();
        if ($position === null) {
            return [];
        }

        return $this->filter_capable(array_slice($this->get_sorted_instances(), 0, $position, true));
    }

    /**
     * Instances core would try after the router had turned a request down.
     *
     * This is what decides whether a refusal by the router actually stops anything.
     * Core walks its order until something succeeds and cannot be told that a failure
     * was deliberate, so a provider listed behind the router will answer a declined
     * request on the site's own key unless the router throws. Knowing whether such a
     * provider exists is the difference between "the budget holds" and "the budget
     * holds until somebody asks twice", which is not something an administrator should
     * have to work out from the provider order by hand.
     *
     * @return ai_provider[] The instances, in order.
     */
    public function get_following_instances(): array {
        $position = $this->get_router_position();
        if ($position === null) {
            return [];
        }

        return $this->filter_capable(array_slice($this->get_sorted_instances(), $position + 1, null, true));
    }

    /**
     * The instances among these that could really answer something the router handles.
     *
     * Enabled, configured, not a router itself, and with an action in common with the
     * router that is enabled on both sides.
     *
     * @param ai_provider[] $instances The instances to consider.
     * @return ai_provider[] Those that could answer, in the order given.
     */
    protected function filter_capable(array $instances): array {
        $router = $this->get_primary_router();
        if ($router === null) {
            return [];
        }

        $routeractions = $router::get_action_list();
        $capable = [];
        foreach ($instances as $instance) {
            if ($instance instanceof provider || !$instance->enabled || !$instance->is_provider_configured()) {
                continue;
            }
            foreach ($instance::get_action_list() as $action) {
                if (!in_array($action, $routeractions, true)) {
                    continue;
                }
                if (!empty($instance->actionconfig[$action]['enabled'])) {
                    $capable[] = $instance;
                    break;
                }
            }
        }

        return $capable;
    }

    /**
     * The provider_order value that puts the router first.
     *
     * Everything else keeps its relative order, because the admin asked for the router to
     * be moved and not for the rest of the site to be rearranged. The router is placed
     * after the leading empty entry rather than at index 0, for the reason given on
     * get_stale_entries(). A site whose order has no leading empty entry gets one, since
     * otherwise promoting the router would park it on the one index core mishandles.
     *
     * @return string The value to store.
     */
    public function build_promoted_order(): string {
        $router = $this->get_primary_router();
        if ($router === null) {
            return implode(',', $this->get_entries());
        }

        $routerid = (string) $router->id;
        $rest = [];
        foreach ($this->get_entries() as $entry) {
            if ($entry !== $routerid) {
                $rest[] = $entry;
            }
        }

        // Step over the empty entries the order already starts with, so that the change is
        // the router moving and nothing else. A site whose order has none gets one.
        $offset = 0;
        while (($rest[$offset] ?? null) === '') {
            $offset++;
        }
        if ($offset === 0) {
            array_unshift($rest, '');
            $offset = 1;
        }
        array_splice($rest, $offset, 0, [$routerid]);

        return implode(',', $rest);
    }

    /**
     * The provider_order value with the stale entries taken out.
     *
     * The empty first entry stays. See get_stale_entries().
     *
     * @return string The value to store.
     */
    public function build_cleaned_order(): string {
        $stale = $this->get_stale_entries();
        $kept = [];
        foreach ($this->get_entries() as $entry) {
            if ($entry === '' || !in_array($entry, $stale, true)) {
                $kept[] = $entry;
            }
        }

        return implode(',', $kept);
    }
}
