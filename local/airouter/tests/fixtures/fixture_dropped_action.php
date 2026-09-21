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

/**
 * An action a site placed under the router, which the router no longer declares.
 *
 * This is the state that matters and cannot be built from real classes: the action is
 * still installed and still asked for, an ordinary provider still answers it, and the
 * router's own list has stopped mentioning it. A site reaches it by upgrading a plugin
 * whose extra actions changed, and what must not happen is the request quietly going
 * back to the provider order with the site's rules and budgets unread.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixture_dropped_action extends \core_ai\aiactions\base {
    #[\Override]
    public function store(\core_ai\aiactions\responses\response_base $response): int {
        return 0;
    }

    /**
     * The response type this action answers in.
     *
     * A core type, so that the fixture does not have to track what a failed response
     * has to carry on each release. What is under test here is which provider is
     * reached, not how the answer is shaped.
     *
     * @return string The response class name.
     */
    public static function get_response_classname(): string {
        return \core_ai\aiactions\responses\response_generate_text::class;
    }

    #[\Override]
    public static function get_name(): string {
        return 'Dropped action';
    }

    #[\Override]
    public static function get_description(): string {
        return 'An action the router has stopped declaring.';
    }
}
