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

namespace local_airouter\record;

/**
 * Thrown when a fact read at the start of a summarising run is gone by the end of it.
 *
 * Not an error in anything: somebody asked to be forgotten while the run was on, and
 * the run must not put them back. The run is rolled back and taken again.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class facts_changed_underneath extends \RuntimeException {
    /**
     * Constructor.
     *
     * @param string $table Which table lost rows.
     * @param int $missing How many.
     */
    public function __construct(
        /** @var string Which table lost rows. */
        public readonly string $table,
        /** @var int How many rows were gone. */
        public readonly int $missing,
    ) {
        parent::__construct($missing . ' row(s) of ' . $table . ' read for summarising were deleted before it finished');
    }
}
