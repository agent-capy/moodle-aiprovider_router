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

/**
 * The router as core needs to see it for the length of one request.
 *
 * Routing is a policy, not a provider. Everything that decides where a request goes --
 * the rules, the budgets, the keys people brought -- belongs to the site, and keeping
 * a row in ai_providers to hold it has cost more than it gave: the settings could not
 * live in the administration tree, the router appeared in lists of things that answer
 * requests when it answers none of them itself, and a site could create two.
 *
 * Core does not need the row. What it asks a provider for while running an action is
 * its name, its action list and its settings, and it builds the processor from the
 * first part of the class name. None of that is read from the database. So the policy
 * is read from the plugin's own configuration and handed to core as an object that
 * exists for this request and is then thrown away.
 *
 * The name core records against the result is local_airouter, which is true: this
 * plugin is what processed the request. Which provider it delegated to is a different
 * fact, and the router keeps that in its own records rather than overwriting core's.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class adapter_provider extends provider {
    /** @var request_policy|null The settings this request began with. */
    private ?request_policy $policy = null;

    /**
     * The router, built from the site's own settings.
     *
     * @param string[]|null $managedactions A policy to build it from instead of the
     *                                      saved one, for asking what a policy the
     *                                      site is about to save would do.
     * @return self An instance for this request. It is not stored anywhere.
     */
    public static function create(?array $managedactions = null, ?request_policy $policy = null): self {
        $adapter = new self(
            enabled: true,
            name: get_string('adapter:name', 'local_airouter'),
            config: json_encode(self::policy_settings($policy), JSON_THROW_ON_ERROR),
            actionconfig: json_encode(
                self::action_settings($managedactions ?? $policy?->managed_actions()),
                JSON_THROW_ON_ERROR,
            ),
            id: null,
        );
        $adapter->policy = $policy;

        return $adapter;
    }

    /**
     * How this site wants requests routed.
     *
     * Read at the start of the request and not looked at again, so that a setting
     * changed while a request is in flight does not decide half of it. The next
     * request picks the change up.
     *
     * @return array The settings, in the shape the provider expects.
     */
    private static function policy_settings(?request_policy $policy): array {
        $read = static fn(string $name): ?string => $policy !== null
            ? $policy->get($name)
            : (get_config('local_airouter', $name) ?: null);

        $target = (int) $read('defaulttarget');

        // No operating mode. It offered "alongside other providers", which means
        // letting core try the next one after a refusal -- and for an action placed
        // under the router there is no next one. On this path the setting decided
        // nothing, and two settings saying the same thing in different words is how
        // a site ends up configured one way and behaving another.
        return [
            'nomatch' => (string) ($read('nomatch') ?? ''),
            'defaulttarget' => $target > 0 ? $target : 0,
        ];
    }

    /**
     * Which actions this object says it answers.
     *
     * Derived from the managed policy rather than stored beside it. The site decides
     * what the router answers in one place, and this is that decision expressed in the
     * shape core reads. Holding it twice would let the two disagree, and the one core
     * happened to read would win.
     *
     * A policy can be passed in instead of the saved one. That is for the management
     * screen, which has to say what will happen once the administrator's choice is
     * saved, and would otherwise be told that every action being added for the first
     * time is one the router cannot answer -- true only until the save it is asking
     * about. Running requests never pass one: what they may do is what is saved.
     *
     * @param string[]|null $managedactions The policy to use, or null for the saved one.
     * @return array Action settings, keyed by action class name.
     */
    private static function action_settings(?array $managedactions = null): array {
        $managed = array_map(
            static fn(string $action): string => ltrim(trim($action), '\\'),
            $managedactions ?? managed_policy::managed_actions(),
        );
        $settings = [];
        foreach (self::get_action_list() as $action) {
            $settings[$action] = [
                'enabled' => in_array(ltrim($action, '\\'), $managed, true),
                'settings' => self::get_action_setting_defaults($action),
            ];
        }

        return $settings;
    }

    /**
     * Whether this site has told the router enough to route anything.
     *
     * The same question the stored instance answers, asked of the site instead. A
     * router with nowhere to send a request would take every managed request and fail
     * it, so it says it is not configured and the managed request is refused with a
     * reason instead.
     *
     * @return bool True when there is a rule or a default target.
     */
    #[\Override]
    public function is_provider_configured(): bool {
        if ($this->get_default_target_id() !== null) {
            return true;
        }

        // The rule count is a setting like the others, so a request that fixed its
        // settings at the start must read this one from there too.
        return $this->policy !== null
            ? (int) $this->policy->get('rulecount') > 0
            : self::has_rules();
    }
}
