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
 * How an attempt ended, as the record keeps it.
 *
 * Carried as one value so that an attempt can be closed on its own or together with
 * the request it belongs to, and the two ways write the same thing.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_ending {
    /**
     * Constructor.
     *
     * @param string $state One of attempt_state::TERMINAL.
     * @param usage $usage What the target used.
     * @param string|null $model The model the target reported.
     * @param int|null $errorcode The target's error code, for a failure.
     * @param string $component The target's provider plugin.
     * @throws \coding_exception When the state is not one an attempt can end in.
     */
    public function __construct(
        /** @var string One of attempt_state::TERMINAL. */
        public readonly string $state,
        /** @var usage What the target used. */
        public readonly usage $usage,
        /** @var string|null The model the target reported. */
        public readonly ?string $model,
        /** @var int|null The target's error code, for a failure. */
        public readonly ?int $errorcode,
        /** @var string The target's provider plugin. */
        public readonly string $component,
    ) {
        if (!in_array($state, attempt_state::TERMINAL, true)) {
            throw new \coding_exception('Not a state an attempt can end in: ' . $state);
        }
    }
}
