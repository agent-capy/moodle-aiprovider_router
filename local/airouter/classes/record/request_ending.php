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

use local_airouter\rule;

/**
 * How a request ended, as the record keeps it.
 *
 * Carried as one value so that a request can be closed on its own or together with
 * its last attempt, and the two ways write the same thing.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_ending {
    /**
     * Constructor.
     *
     * @param string $state One of request_state::TERMINAL.
     * @param string|null $reason Why it did not succeed, for anything but success.
     * @param int|null $errorcode The error code, for anything but success.
     * @param int|null $answeredby The target whose answer was used, for a success.
     * @param rule|null $rule The rule that claimed the request, if one did.
     * @param string $keysource Whose key the request was to be paid for with.
     * @param int $attempts How many targets the caller asked.
     * @throws \coding_exception When the state is not one a request can end in.
     */
    public function __construct(
        /** @var string One of request_state::TERMINAL. */
        public readonly string $state,
        /** @var string|null Why it did not succeed. */
        public readonly ?string $reason,
        /** @var int|null The error code. */
        public readonly ?int $errorcode,
        /** @var int|null The target whose answer was used. */
        public readonly ?int $answeredby,
        /** @var rule|null The rule that claimed the request. */
        public readonly ?rule $rule,
        /** @var string Whose key the request was to be paid for with. */
        public readonly string $keysource,
        /** @var int How many targets the caller asked. */
        public readonly int $attempts,
    ) {
        if (!in_array($state, request_state::TERMINAL, true)) {
            throw new \coding_exception('Not a state a request can end in: ' . $state);
        }
    }
}
