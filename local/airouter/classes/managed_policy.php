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

/**
 * Which actions this site has placed under the router.
 *
 * A managed action is one the site has decided the router answers, whatever the
 * provider order says and whether or not the router happens to be working. That second
 * half matters: an action list derived from what the router can do right now would quietly
 * stop being managed the moment the router broke, which is when the rules and budgets
 * are most needed. The policy is therefore stored at plugin level, outside any provider
 * instance, so that disabling or deleting the instance does not erase it.
 *
 * The list is server side. Nothing a browser sends takes an action out of it.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class managed_policy {
    /** @var string Plugin level setting holding the managed action classes. */
    public const SETTING = 'managedactions';

    /** @var string Plugin level setting saying whether the router routes at all. */
    public const SWITCH = 'routing';

    /**
     * Whether this site routes AI requests through the router at all.
     *
     * One switch, above everything else the plugin decides. Turned off, the site
     * behaves exactly as a site without this plugin: core picks providers in its own
     * order and nothing here is consulted. The managed actions are left alone, so
     * turning it back on restores the arrangement rather than asking for it again.
     *
     * It is read once per request and not registered into the container, so a request
     * already being processed finishes under the policy it started with, and the next
     * one picks up the change.
     *
     * Not the same question as is_active(), which asks whether any action has been
     * placed under the router. A site can have the switch on and nothing managed,
     * which routes nothing, or actions managed and the switch off, which also routes
     * nothing but remembers what to do when it goes back on.
     *
     * @return bool True when the router decides where requests go.
     */
    public static function is_switched_on(): bool {
        $value = get_config('local_airouter', self::SWITCH);

        // Absent means on: the switch was added after the plugin, and a site that had
        // been routing must not stop because a setting it never saw is not there.
        return $value === false || (bool) $value;
    }

    /**
     * The action classes this site has placed under the router.
     *
     * What is stored is returned, including any class the router no longer declares.
     * Filtering the stored list against what the router can carry today was the one
     * thing this class says it must not do: an action would stop being managed the
     * moment it left that list, and the requests the site meant to hold inside the
     * router would go back to the provider order without anybody being told. Removing
     * an action from the policy is something an administrator does on purpose.
     *
     * An action that is managed and cannot be carried is refused when it is asked for,
     * and said out loud by the status check and the management screen. Callers that
     * display these names must not assume the class is still installed; label_for()
     * and basename_for() are safe when it is not.
     *
     * @return string[] Fully qualified action class names, without a leading separator.
     */
    public static function managed_actions(): array {
        $stored = get_config('local_airouter', self::SETTING);
        if ($stored === false || trim((string) $stored) === '') {
            return [];
        }

        return self::parse((string) $stored);
    }

    /**
     * Turn a stored policy into action class names.
     *
     * Shared with request_policy, which reads the same setting a different way, so
     * that the two cannot come to different conclusions about the same text.
     *
     * @param string $stored The setting value.
     * @return string[] Fully qualified action class names, without a leading separator.
     */
    public static function parse(string $stored): array {
        if (trim($stored) === '') {
            return [];
        }

        $wanted = array_filter(array_map(
            static fn(string $action): string => ltrim(trim($action), '\\'),
            explode(',', $stored),
        ));

        return array_values(array_unique($wanted));
    }

    /**
     * The action classes the router declares it can carry.
     *
     * @return string[] Fully qualified action class names, without a leading separator.
     */
    public static function declared_actions(): array {
        return array_map(
            static fn(string $action): string => ltrim($action, '\\'),
            provider::get_action_list(),
        );
    }

    /**
     * Managed actions the router no longer declares.
     *
     * These still hold their requests inside the router, which refuses them. Both the
     * management screen and the status check exist to make sure that is never a
     * surprise, and to offer the way out in one click.
     *
     * @return string[] Fully qualified action class names, without a leading separator.
     */
    public static function unsupported_actions(): array {
        $declared = self::declared_actions();

        return array_values(array_filter(
            self::managed_actions(),
            static fn(string $action): bool => !in_array($action, $declared, true),
        ));
    }

    /**
     * The name to show for an action, even one whose class has gone.
     *
     * @param string $actionclass The action class.
     * @return string The action name, or the class name when nothing can be asked.
     */
    public static function label_for(string $actionclass): string {
        return method_exists($actionclass, 'get_name') ? $actionclass::get_name() : $actionclass;
    }

    /**
     * The short name to show for an action, even one whose class has gone.
     *
     * @param string $actionclass The action class.
     * @return string The basename, or the class name when nothing can be asked.
     */
    public static function basename_for(string $actionclass): string {
        return method_exists($actionclass, 'get_basename') ? $actionclass::get_basename() : $actionclass;
    }

    /**
     * Whether this site has placed the given action under the router.
     *
     * @param action_base|string $action The action, or its class name.
     * @return bool True when the request must go through the router.
     */
    public static function is_managed(action_base|string $action): bool {
        $class = ltrim(is_string($action) ? $action : $action::class, '\\');

        return in_array($class, self::managed_actions(), true);
    }

    /**
     * Whether this site manages anything at all.
     *
     * @return bool True when at least one action is managed.
     */
    public static function is_active(): bool {
        return self::managed_actions() !== [];
    }

    /**
     * Record which actions the router answers.
     *
     * @param string[] $actions Fully qualified action class names.
     */
    public static function set_managed_actions(array $actions): void {
        $clean = array_map(
            static fn(string $action): string => ltrim(trim($action), '\\'),
            $actions,
        );
        set_config(self::SETTING, implode(',', array_unique($clean)), 'local_airouter');
    }
}
