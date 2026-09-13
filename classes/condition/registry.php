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

/**
 * The condition types a rule can be built from.
 *
 * Types are listed here rather than discovered, so that a class left behind in the
 * directory cannot quietly become part of the editing form, and so that the order they
 * appear in is decided rather than inherited from the filesystem.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registry {
    /**
     * The known condition types, in the order they are presented.
     *
     * Narrow and cheap first, so that reading a rule tells you what it is about before
     * it tells you how big the prompt has to be.
     *
     * @var string[]
     */
    protected const TYPES = [
        'action',
        'placement',
        'course',
        'category',
        'role',
        'promptlength',
    ];

    /**
     * Every condition type.
     *
     * @return string[] The type names.
     */
    public static function get_types(): array {
        return self::TYPES;
    }

    /**
     * Whether a stored type is one this version understands.
     *
     * @param string $type The type name.
     * @return bool True when it is known.
     */
    public static function is_known(string $type): bool {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Build a condition from its stored configuration.
     *
     * @param string $type The type name.
     * @param array $config The stored configuration.
     * @return base|null The condition, or null when this version does not know the type.
     */
    public static function make(string $type, array $config): ?base {
        if (!self::is_known($type)) {
            return null;
        }
        $class = __NAMESPACE__ . '\\' . $type;

        return new $class($config);
    }
}
