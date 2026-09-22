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

use local_airouter\provider;
use core\check\result;

/**
 * Checks that the router instance can still carry every action it offers.
 *
 * An instance's action configuration is written once, when the instance is created,
 * from the list of actions the provider offered at that moment. The list is read
 * again on every request, but the configuration is not: an action that appears
 * later, because a plugin defining it was installed, is offered by the provider and
 * refused by core, which finds no entry for it and reads that as switched off.
 *
 * It cannot be switched on from the provider settings screen either. That switch
 * writes to core_ai's own namespace, so for an action defined anywhere else it
 * writes a key nothing reads. Nothing about the screen shows this: the action is
 * listed, its switch looks right, and requests for it go elsewhere.
 *
 * So it is said here instead, with the one thing that does fix it.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class staleactions extends base {
    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/local/airouter/order.php'),
            get_string('order:heading', 'local_airouter'),
        );
    }

    #[\Override]
    protected function requires_instance(): bool {
        return true;
    }

    #[\Override]
    protected function check_router(): result {
        $router = $this->inspector->get_primary_router();
        $missing = [];
        foreach (provider::get_action_list() as $class) {
            if (!array_key_exists($class, $router->actionconfig)) {
                $missing[] = $class::get_name();
            }
        }

        if (!$missing) {
            return new result(result::OK, get_string('check:staleactions:ok', 'local_airouter'));
        }

        return new result(
            result::WARNING,
            get_string('check:staleactions:missing', 'local_airouter', count($missing)),
            get_string('check:staleactions:missing_details', 'local_airouter', implode(', ', $missing)),
        );
    }
}
