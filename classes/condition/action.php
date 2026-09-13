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
 * Restricts a rule to one of the chosen AI actions.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action extends set_base {
    #[\Override]
    protected static function get_config_key(): string {
        return 'actions';
    }

    #[\Override]
    protected function get_actual(evaluation_context $context): array {
        return [$context->get_action_class()];
    }

    #[\Override]
    protected static function cast(mixed $value): int|string {
        // Class names are stored without a leading separator, the way core stores the
        // provider class in ai_providers, so that both spellings compare equal.
        return ltrim((string) $value, '\\');
    }
    #[\Override]
    protected static function get_options(): array {
        $options = [];
        foreach (\aiprovider_router\provider::get_action_list() as $class) {
            $options[ltrim($class, '\\')] = $class::get_name();
        }

        return $options;
    }
}
