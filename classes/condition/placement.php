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

namespace aiprovider_router\condition;

use aiprovider_router\evaluation_context;

/**
 * Restricts a rule to requests coming from one of the chosen placements.
 *
 * A placement that cannot be identified meets no placement condition. Pairing a
 * placement condition with an action condition is the more dependable way to express
 * most of what a placement condition is reached for, and the documentation says so.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placement extends set_base {
    #[\Override]
    protected function get_config_key(): string {
        return 'placements';
    }

    #[\Override]
    protected function get_actual(evaluation_context $context): array {
        $placement = $context->get_placement();

        return $placement === null ? [] : [$placement];
    }

    #[\Override]
    protected static function cast(mixed $value): int|string {
        return (string) $value;
    }
}
