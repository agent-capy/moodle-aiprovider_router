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

use core\check\check;
use core\check\result;
use local_airouter\managed_policy;
use local_airouter\setup\route_formatter;
use local_airouter\setup\route_inspection;
use local_airouter\setup\route_inspector;

/**
 * Checks that every action routed through the router has a target its settings allow.
 *
 * The other half of what the boundary check used to ask. That one asks whether a
 * request reaches the router; this one asks whether the router's settings name
 * anything that could carry it once it has. Keeping them apart matters because
 * "reaches the router" was being read as "is answered".
 *
 * It reads settings only. A provider that is set up and switched on may still refuse
 * a key or be unreachable, and nothing here can know; the result says so.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delegation extends check {
    #[\Override]
    public function get_name(): string {
        return get_string('check:delegation', 'local_airouter');
    }

    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/settings.php', ['section' => 'local_airouter_policy']),
            get_string('policy:heading', 'local_airouter'),
        );
    }

    #[\Override]
    public function get_result(): result {
        global $DB;

        if (!managed_policy::is_switched_on()) {
            return new result(result::NA, get_string('check:delegation:off', 'local_airouter'));
        }
        // An action the router does not declare is refused at the door, and its class
        // may have gone altogether, so nothing here may ask the class anything.
        $unsupported = managed_policy::unsupported_actions();
        $inspections = array_filter(
            (new route_inspector($DB))->inspect_managed(),
            static fn(route_inspection $inspection): bool =>
                $inspection->applied === route_inspection::APPLIED_ROUTED,
        );
        if ($inspections === [] && $unsupported === []) {
            return new result(result::NA, get_string('check:delegation:none', 'local_airouter'));
        }

        $details = \html_writer::alist(array_map(
            static fn(route_inspection $inspection): string =>
                \html_writer::tag('strong', s(managed_policy::label_for($inspection->actionclass)))
                . ' - ' . route_formatter::state_label($inspection) . ': '
                . implode(' ', route_formatter::explain($inspection)),
            $inspections,
        )) . \html_writer::tag('p', get_string('setup:unchecked', 'local_airouter'));

        // The worst finding decides the status; the details cover every action.
        $names = static fn(callable $filter): string => implode(', ', array_map(
            static fn(route_inspection $inspection): string => managed_policy::label_for($inspection->actionclass),
            array_filter($inspections, $filter),
        ));

        if ($unsupported !== []) {
            return new result(
                result::ERROR,
                get_string('check:delegation:noprocessor', 'local_airouter', implode(', ', array_map(
                    static fn(string $action): string => managed_policy::basename_for($action),
                    $unsupported,
                ))),
                $details,
            );
        }
        $stuck = $names(static fn(route_inspection $i): bool => $i->state === route_inspection::STATE_ACTION_NEEDED);
        if ($stuck !== '') {
            return new result(result::ERROR, get_string('check:delegation:actionneeded', 'local_airouter', $stuck), $details);
        }
        $broken = $names(static fn(route_inspection $i): bool => $i->default_is_broken());
        if ($broken !== '') {
            return new result(result::WARNING, get_string('check:delegation:brokendefault', 'local_airouter', $broken), $details);
        }
        // Declining everything for an action is a decision a site can make on purpose,
        // so it is reported, not raised.
        $refused = $names(static fn(route_inspection $i): bool => $i->state === route_inspection::STATE_POLICY_REFUSED);
        if ($refused !== '') {
            return new result(result::INFO, get_string('check:delegation:policyrefused', 'local_airouter', $refused), $details);
        }

        return new result(result::OK, get_string('check:delegation:ok', 'local_airouter'), $details);
    }
}
