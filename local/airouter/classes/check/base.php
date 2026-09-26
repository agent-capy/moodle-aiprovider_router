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

use local_airouter\adapter_provider;
use core\check\check;
use core\check\result;

/**
 * Shared behaviour for the router's status checks.
 *
 * A site that has not set the router up is not misconfigured, so every check reports NA
 * rather than a problem there. The router is set up once it has something to delegate
 * to: a default target, or rules.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends check {
    #[\Override]
    public function get_name(): string {
        return get_string('check:' . $this->get_id(), 'local_airouter');
    }

    #[\Override]
    public function get_result(): result {
        if (!$this->router_is_set_up()) {
            return new result(result::NA, get_string('check:norouter', 'local_airouter'));
        }

        return $this->check_router();
    }

    /**
     * Whether the router is set up on this site at all.
     *
     * @return bool True when there is something to check.
     */
    protected function router_is_set_up(): bool {
        return adapter_provider::create()->is_provider_configured();
    }

    /**
     * Run the check, knowing that the router is set up.
     *
     * @return result The outcome.
     */
    abstract protected function check_router(): result;
}
