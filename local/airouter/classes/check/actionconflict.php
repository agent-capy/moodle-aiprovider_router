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

use core\check\result;

/**
 * Checks whether another provider would answer before the router is reached.
 *
 * Being second is only a problem when the provider in front can actually handle the same
 * actions, so this reports the instances that really would intercept rather than every
 * instance that happens to sit earlier.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class actionconflict extends base {
    #[\Override]
    protected function depends_on_provider_order(): bool {
        return true;
    }

    #[\Override]
    protected function check_router(): result {
        $intercepting = $this->inspector->get_intercepting_instances();
        if (!$intercepting) {
            return new result(result::OK, get_string('check:actionconflict:ok', 'local_airouter'));
        }

        $names = [];
        foreach ($intercepting as $instance) {
            $names[] = s($instance->name);
        }

        return new result(
            result::WARNING,
            get_string('check:actionconflict:found', 'local_airouter', count($intercepting)),
            get_string('check:actionconflict:found_details', 'local_airouter', implode(', ', $names)),
        );
    }
}
