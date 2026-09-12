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

use core_ai\aiactions\base as action_base;
use core_ai\aiactions\responses\response_base;
use core_ai\provider as ai_provider;

/**
 * Runs an action against another provider instance.
 *
 * Moodle resolves the processor class for a provider in
 * core_ai\manager::call_action_provider(), which is protected. Subclassing is the
 * supported way to reach it, and it keeps the resolution rule in one place: if core
 * changes how a processor is located, the router follows automatically.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delegator extends \core_ai\manager {
    /**
     * Run the action against the given provider instance.
     *
     * @param ai_provider $target The provider instance to delegate to.
     * @param action_base $action The action to run.
     * @return response_base The response from the target.
     */
    public function delegate(ai_provider $target, action_base $action): response_base {
        return $this->call_action_provider($target, $action);
    }

    /**
     * Whether the delegation primitive is still reachable in this Moodle release.
     *
     * The Check API reports this so that an incompatible core is visible to the
     * administrator instead of surfacing as a fatal error mid request.
     *
     * @return bool True if the router can delegate.
     */
    public static function is_available(): bool {
        if (!method_exists(\core_ai\manager::class, 'call_action_provider')) {
            return false;
        }
        $method = new \ReflectionMethod(\core_ai\manager::class, 'call_action_provider');

        return $method->isProtected() || $method->isPublic();
    }
}
