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

/**
 * AI Router provider.
 *
 * The declared actions are the four core actions common to Moodle 5.0 through 5.2,
 * matching the "full router mode" design in which the router declares every action and
 * delegates the actual work to another provider.
 *
 * This class holds what the site has decided; target_resolver decides where an
 * individual request goes.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider extends \core_ai\provider {
    #[\Override]
    public static function get_action_list(): array {
        $actions = [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\generate_image::class,
            \core_ai\aiactions\summarise_text::class,
            \core_ai\aiactions\explain_text::class,
        ];

        // Actions that are not core's are routed too, where something defines them.
        // ⚠ Offered only while the class is installed: an action named in this list
        // and absent from the site makes the provider settings screen fatal, because
        // that screen asks each action for its own name.
        foreach (self::EXTRA_ACTIONS as $class) {
            if (class_exists($class)) {
                $actions[] = $class;
            }
        }

        return $actions;
    }

    /**
     * @var string[] Actions defined outside core that this router can carry.
     *
     * Kept as strings rather than as ::class references, so that naming one here
     * does not require the plugin defining it to be installed.
     */
    protected const EXTRA_ACTIONS = [
        'local_aiaudio\\aiactions\\transcript_audio',
    ];

    /** @var string Delegate everything, and expect to be first in the provider order. */
    public const MODE_FULL = 'full';

    /** @var string Sit alongside other providers and decline what no rule matches. */
    public const MODE_COEXIST = 'coexist';

    /** @var string Send a request no rule claimed to the default target. */
    public const NOMATCH_DELEGATE = 'delegate';

    /** @var string Turn down a request no rule claimed. */
    public const NOMATCH_DECLINE = 'decline';

    /**
     * The operating mode of this router instance.
     *
     * @return string One of the MODE_ constants.
     */
    public function get_mode(): string {
        $mode = $this->config['mode'] ?? self::MODE_FULL;

        return $mode === self::MODE_COEXIST ? self::MODE_COEXIST : self::MODE_FULL;
    }

    /**
     * What to do with a request that no rule claimed.
     *
     * This is not an edge case. It is the most travelled path on a site that has just
     * installed the plugin, on one whose rules cover part of what it does, and on one
     * whose rules have expired, so each mode starts from the answer that suits it and
     * the administrator can choose the other.
     *
     * Declining means core carries on to the next provider in its own order. Alongside
     * other providers that leaves the site working exactly as before, with the router
     * having said only that this request was not its business. In router only mode
     * there is nobody behind the router, so declining stops the request; that is still
     * worth offering, because "only these courses may use AI, and nothing else may
     * spend money" is a reasonable way to run a site.
     *
     * @return string One of the NOMATCH_ constants.
     */
    public function get_nomatch_behaviour(): string {
        $behaviour = $this->config['nomatch'] ?? '';
        if (in_array($behaviour, [self::NOMATCH_DELEGATE, self::NOMATCH_DECLINE], true)) {
            return $behaviour;
        }

        return $this->get_mode() === self::MODE_COEXIST ? self::NOMATCH_DECLINE : self::NOMATCH_DELEGATE;
    }

    /**
     * The instance the router falls back to when no rule picks a target.
     *
     * @return int|null The provider instance id, or null when none is set.
     */
    public function get_default_target_id(): ?int {
        $targetid = (int) ($this->config['defaulttarget'] ?? 0);

        return $targetid > 0 ? $targetid : null;
    }

    /**
     * Whether the site has any routing rules at all.
     *
     * @return bool True when at least one rule exists.
     */
    public static function has_rules(): bool {
        return (int) get_config('aiprovider_router', 'rulecount') > 0;
    }

    /**
     * Ids of every router instance on the site, lowest first.
     *
     * Memoised because core asks whether a provider is configured on every request
     * that reaches the AI subsystem.
     *
     * @param bool $reset Discard the memoised value. For tests.
     * @return int[] The instance ids.
     */
    public static function get_instance_ids(bool $reset = false): array {
        static $ids = null;
        if ($reset) {
            $ids = null;

            return [];
        }
        if ($ids === null) {
            $ids = [];
            $manager = \core\di::get(\core_ai\manager::class);
            foreach ($manager->get_provider_instances(['provider' => ltrim(self::class, '\\')]) as $instance) {
                $ids[] = (int) $instance->id;
            }
            sort($ids);
        }

        return $ids;
    }

    /**
     * Whether this is the instance the site should actually be routing through.
     *
     * The form refuses a second instance, but nothing stops one being created from CLI
     * or by an upgrade script, so the rest of the plugin does not assume there is only
     * one. The lowest id wins because it does not move, unlike a position in
     * provider_order.
     *
     * @return bool True if this instance is the canonical one.
     */
    public function is_primary_instance(): bool {
        $ids = self::get_instance_ids();
        if (!$ids || empty($this->id)) {
            return true;
        }

        return (int) $this->id === $ids[0];
    }

    #[\Override]
    public function is_provider_configured(): bool {
        // A router with nothing to delegate to would take every request and fail it, so
        // it reports itself unconfigured and core skips it. Rules count as something to
        // delegate to: a site that routes everything by rule and declines the rest has
        // no use for a default target. The count is kept in the plugin configuration by
        // the one class that writes rules, because core asks this on every request that
        // reaches the AI subsystem and a query here would be paid for every time.
        if ($this->get_default_target_id() === null && !self::has_rules()) {
            return false;
        }

        // Second and later instances take themselves out of the running rather than
        // competing with the canonical one.
        return $this->is_primary_instance();
    }
}
