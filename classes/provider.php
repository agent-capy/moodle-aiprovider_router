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
 * AI Router provider.
 *
 * Skeleton implementation. The declared actions are the four core actions common to
 * Moodle 5.0 through 5.2, matching the "full router mode" design in which the router
 * declares every action and delegates the actual work to another provider.
 *
 * The delegation engine and rule evaluation are implemented in WP2 and WP3.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider extends \core_ai\provider {
    #[\Override]
    public static function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\generate_image::class,
            \core_ai\aiactions\summarise_text::class,
            \core_ai\aiactions\explain_text::class,
        ];
    }

    #[\Override]
    public function is_provider_configured(): bool {
        // Routing rules are not implemented yet, so the provider is never considered
        // configured. WP2 replaces this with a check for at least one delegation target.
        return false;
    }
}
