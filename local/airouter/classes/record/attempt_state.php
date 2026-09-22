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
 * How an attempt ended, as the strings stored in local_airouter_attempt.state.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_state {
    /** @var string Handed to the target and not back yet. The state an attempt is inserted in. */
    public const STARTED = 'started';

    /** @var string The target answered with something to show. */
    public const SUCCEEDED = 'succeeded';

    /**
     * @var string The target answered a success that carried nothing to show.
     *
     * Whether that was the token budget running out or the model simply saying
     * nothing is on the request's reason, not here: to the attempt it is the same
     * thing, a call that was made and charged for and produced no content.
     */
    public const EMPTY = 'empty';

    /** @var string The target answered a failure. */
    public const FAILED = 'failed';

    /** @var string The target threw instead of answering. */
    public const THREW = 'threw';

    /**
     * @var string Nothing came back and nothing will: the process did not live to write it.
     *
     * Written by whatever sweeps up attempts left started for too long. What such an
     * attempt used is unknown, and is recorded as unknown rather than as nothing.
     */
    public const LOST = 'lost';

    /** @var string[] The states an attempt can end in. */
    public const TERMINAL = [self::SUCCEEDED, self::EMPTY, self::FAILED, self::THREW, self::LOST];
}
