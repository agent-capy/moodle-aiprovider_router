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
 * The AI Router, as Moodle's AI subsystem sees it.
 *
 * Everything this provider does is in local_airouter. This class exists because
 * Moodle will not treat a provider as a provider unless it belongs to a plugin whose
 * name begins with aiprovider_: core decides whether an action is enabled, and which
 * actions a provider offers, by testing that name, and a provider class placed
 * anywhere else is silently handled as though it were a placement.
 *
 * So the plugin is in two halves. The routing, the rules, the budgets, the monitoring
 * and the keys people bring are all in local_airouter, where they can have a settings
 * page of their own. This half is the face core has to see.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider extends \local_airouter\provider {
}
