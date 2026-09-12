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

/**
 * Callbacks for aiprovider_router.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Status checks reported on the site status report.
 *
 * A router that is not reached is indistinguishable from a router that is not working, and
 * the admin has no reason to suspect the provider order, so the site status report is where
 * that gets said. One inspector is shared so the checks cannot describe different sites.
 *
 * @return \core\check\check[] The checks.
 */
function aiprovider_router_status_checks(): array {
    $inspector = new \aiprovider_router\order_inspector();

    return [
        new \aiprovider_router\check\singleinstance($inspector),
        new \aiprovider_router\check\routerlisted($inspector),
        new \aiprovider_router\check\routerfirst($inspector),
        new \aiprovider_router\check\actionconflict($inspector),
        new \aiprovider_router\check\staleentries($inspector),
    ];
}
