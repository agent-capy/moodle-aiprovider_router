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
 * How a request ended, as the strings stored in local_airouter_request.state.
 *
 * Plain strings rather than an enum, because they are read straight out of the
 * table by reports and by SQL, and a report should not need a map to read a row.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_state {
    /** @var string Being worked on. The only state a request is ever inserted in. */
    public const OPEN = 'open';

    /** @var string Somebody's answer reached the person who asked. */
    public const SUCCEEDED = 'succeeded';

    /**
     * @var string Turned down before any provider was asked.
     *
     * The site deciding: no rule claimed it and the site refuses such requests, a
     * budget had run out, a brought key could not be read, there was nowhere to send
     * it, or core's rate limit turned it away. Zero attempts, by definition.
     */
    public const DECLINED = 'declined';

    /** @var string Providers were asked, and none of their answers could be used. */
    public const FAILED = 'failed';

    /** @var string[] The states a request can end in. */
    public const TERMINAL = [self::SUCCEEDED, self::DECLINED, self::FAILED];
}
