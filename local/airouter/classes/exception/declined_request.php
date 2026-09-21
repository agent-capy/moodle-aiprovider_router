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

namespace local_airouter\exception;

/**
 * Thrown when the router turns a request down as a matter of site policy.
 *
 * Core tries each provider in turn and keeps going until one of them succeeds. It reads
 * a failed response as "this provider could not do it", which for the router is wrong:
 * a request the router declined because of a rule, a budget or a key belonging to
 * somebody in particular has been answered, and answering it from the site's own key
 * through the next provider in the order is the opposite of what the site asked for.
 *
 * Neither core_ai\manager::process_action() nor call_action_provider() catches anything,
 * so throwing is the only way a provider can say that a failure is final. That is a
 * blunt instrument and it is used narrowly: only for the reasons that mean the site
 * decided, never for a target that merely broke.
 *
 * The message reaches the end user, so it is a language string and it names no provider,
 * no rule and no instance id. The reason code is carried separately for the placements
 * that catch this and want to show something of their own.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class declined_request extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $reason One of the abstract_processor REASON_ codes.
     * @param string $stringid The language string already chosen for the user.
     * @param int $statuscode The HTTP style status code the failure carried.
     */
    public function __construct(
        /** @var string The reason code recorded for the administrator. */
        protected readonly string $reason,
        string $stringid,
        /** @var int The status code the equivalent failed response would have had. */
        protected readonly int $statuscode = 503,
    ) {
        parent::__construct($stringid, 'local_airouter');
    }

    /**
     * Why the request was declined.
     *
     * @return string The reason code.
     */
    public function get_reason(): string {
        return $this->reason;
    }

    /**
     * The status code the failure would have carried had it been returned.
     *
     * The parent class already uses errorcode for the language string identifier, so
     * the status this would have been reported with keeps a name of its own.
     *
     * @return int An HTTP style status code.
     */
    public function get_statuscode(): int {
        return $this->statuscode;
    }
}
