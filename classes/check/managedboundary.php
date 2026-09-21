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

namespace aiprovider_router\check;

use aiprovider_router\managed_policy;
use aiprovider_router\routing_manager;
use core\check\check;
use core\check\result;

/**
 * Checks that managed actions really do reach the router.
 *
 * Placing an action under the router only takes effect if the manager Moodle asks for
 * is this plugin's. Another plugin may define the same entry in the dependency
 * injection container, and the last definition wins silently: the site would go on
 * displaying its rules and budgets while none of them ran. Nothing warns about that on
 * its own, so it is asked here.
 *
 * The other way it can fail is the site's own doing: an action is managed and no
 * router instance can carry it, so every request for it is refused. That is the
 * designed behaviour rather than a fault, but an administrator needs to be told, and
 * told which way to fix it.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class managedboundary extends check {
    #[\Override]
    public function get_name(): string {
        return get_string('check:managedboundary', 'aiprovider_router');
    }

    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/ai/provider/router/managed.php'),
            get_string('managed:heading', 'aiprovider_router'),
        );
    }

    #[\Override]
    public function get_result(): result {
        $managed = managed_policy::managed_actions();
        if ($managed === []) {
            return new result(result::NA, get_string('check:managedboundary:none', 'aiprovider_router'));
        }

        $names = implode(', ', array_map(
            static fn(string $action): string => $action::get_basename(),
            $managed,
        ));

        $manager = \core\di::get(\core_ai\manager::class);
        if (!$manager instanceof routing_manager) {
            return new result(
                result::ERROR,
                get_string('check:managedboundary:replaced', 'aiprovider_router'),
                get_string('check:managedboundary:details', 'aiprovider_router', [
                    'actions' => $names,
                    'manager' => $manager::class,
                ]),
            );
        }

        $unreachable = array_values(array_filter(
            $managed,
            static fn(string $action): bool => $manager->find_router($action) === null,
        ));
        if ($unreachable !== []) {
            return new result(
                result::ERROR,
                get_string('check:managedboundary:unreachable', 'aiprovider_router', implode(', ', array_map(
                    static fn(string $action): string => $action::get_basename(),
                    $unreachable,
                ))),
            );
        }

        return new result(result::OK, get_string('check:managedboundary:ok', 'aiprovider_router', $names));
    }
}
