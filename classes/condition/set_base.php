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
 * A condition satisfied by any one of the values it lists.
 *
 * Most conditions are of this shape: the request has some set of courses, roles or
 * actions attached to it, and the condition names the ones the rule is interested in.
 * Listing several is how a rule says "teacher or manager" without needing a second rule
 * or a general purpose expression builder.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class set_base extends base {
    /**
     * The configuration key holding the chosen values.
     *
     * @return string The key.
     */
    abstract protected function get_config_key(): string;

    /**
     * The values this request actually has.
     *
     * @param evaluation_context $context The request being evaluated.
     * @return array The values, of the same kind as the configured ones.
     */
    abstract protected function get_actual(evaluation_context $context): array;

    /**
     * The values the rule is looking for.
     *
     * @return array The configured values.
     */
    public function get_values(): array {
        $values = $this->config[$this->get_config_key()] ?? [];
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_map([static::class, 'cast'], $values)));
    }

    #[\Override]
    public function is_met(evaluation_context $context): bool {
        $wanted = $this->get_values();
        if (!$wanted) {
            // Nothing chosen is not the same as no restriction. A condition that is
            // present but empty is a condition the administrator has not finished, and
            // treating it as "anything" would widen the rule behind their back.
            return false;
        }

        return array_intersect($wanted, $this->get_actual($context)) !== [];
    }

    /**
     * Bring a stored value to the type it is compared as.
     *
     * Values arrive from JSON and from form submissions, so an id may turn up as a
     * string. Comparison is strict once they are here, so they are made to agree first.
     *
     * @param mixed $value The stored value.
     * @return int|string The value to compare with.
     */
    protected static function cast(mixed $value): int|string {
        return (int) $value;
    }
}
