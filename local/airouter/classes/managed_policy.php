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

    /**
     * The action classes this site has placed under the router.
     *
     * Classes the router does not declare are dropped. An action the router could never
     * carry is not a routing decision the site can make: managing it would refuse every
     * request for it and offer nothing in return.
     *
     * @return string[] Fully qualified action class names, without a leading separator.
     */
    public static function managed_actions(): array {
        $stored = get_config('local_airouter', self::SETTING);
        if ($stored === false || trim((string) $stored) === '') {
            return [];
        }

        $declared = array_map(
            static fn(string $action): string => ltrim($action, '\\'),
            provider::get_action_list(),
        );
        $wanted = array_map(
            static fn(string $action): string => ltrim(trim($action), '\\'),
            explode(',', (string) $stored),
        );

        return array_values(array_intersect($wanted, $declared));
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
