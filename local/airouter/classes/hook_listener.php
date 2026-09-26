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

use core\hook\di_configuration;
use core_course\hook\before_course_deleted;

/**
 * Callbacks for the core hooks this plugin listens to.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {
    /**
     * Put this plugin's manager in front of the AI subsystem.
     *
     * Every entry point core has for starting an AI request asks the container for a
     * manager, so a definition registered here is what decides which requests the
     * router is allowed to answer. Being first in the provider order is not the same
     * thing: an order says which provider is preferred, and a provider above the router
     * answers before any rule, budget or key of somebody's has been looked at.
     *
     * The definition is registered whether or not the site manages anything. Whether an
     * action is managed can change between one request and the next, and the container
     * is built once, so the question is asked when the request arrives instead. With
     * nothing managed the class behaves exactly as core's own manager does.
     *
     * The definition is a closure, so nothing is built while the container is being
     * assembled and the manager cannot end up depending on itself.
     *
     * @param di_configuration $hook The hook being handled.
     */
    public static function configure_di(di_configuration $hook): void {
        $hook->add_definition(
            id: \core_ai\manager::class,
            definition: static function (\moodle_database $db): \core_ai\manager {
                return new routing_manager($db);
            },
        );
    }

    /**
     * Remove the key a course paid with when the course goes.
     *
     * What a course used the AI for is history and stays: a removed course does not
     * unspend the money. Its key is not history. It is a secret this site can still
     * decrypt, for an account somebody is still paying for, and once the course is gone
     * there is nothing left that could ever use it and no screen that could reach it to
     * take it away -- the key screen is a page in a course. Moodle's privacy tools
     * cannot find it either, because they find a course key through the course context,
     * which is deleted with everything else.
     *
     * Done on the hook that fires before the deletion rather than on the event that
     * follows it, because the event arrives once the course, and the context the key is
     * reached through, have already gone.
     *
     * @param before_course_deleted $hook The hook being handled.
     */
    public static function delete_keys_for_deleted_course(before_course_deleted $hook): void {
        global $DB;

        (new key_repository($DB))->delete_for_course((int) $hook->course->id);
    }
}
