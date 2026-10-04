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

namespace local_airouter\setup;

/**
 * One rule in force, as it bears on one action.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class route_rule {
    /**
     * Constructor.
     *
     * @param int $id The rule id.
     * @param string $name The rule name.
     * @param string $keysource Who pays, one of the rule key sources.
     * @param bool $brought Whether somebody other than the site pays.
     * @param int $targetid The target the rule names.
     * @param string|null $targetname The target's name, or null when it is gone.
     * @param string|null $problem Why the target cannot carry the action, or null when it can.
     * @param bool $hasbudget Whether the rule carries a budget condition.
     */
    public function __construct(
        /** @var int The rule id. */
        public readonly int $id,
        /** @var string The rule name. */
        public readonly string $name,
        /** @var string Who pays, one of the rule key sources. */
        public readonly string $keysource,
        /** @var bool Whether somebody other than the site pays. */
        public readonly bool $brought,
        /** @var int The target the rule names. */
        public readonly int $targetid,
        /** @var string|null The target's name, or null when it is gone. */
        public readonly ?string $targetname,
        /** @var string|null Why the target cannot carry the action, or null when it can. */
        public readonly ?string $problem,
        /** @var bool Whether the rule carries a budget condition. */
        public readonly bool $hasbudget,
    ) {
    }
}
