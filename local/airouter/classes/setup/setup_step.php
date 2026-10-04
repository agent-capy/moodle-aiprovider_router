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
 * One step on the setup page, and where it stands.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class setup_step {
    /** @var string The step is in place. */
    public const DONE = 'done';

    /** @var string The step is what to do next. */
    public const TODO = 'todo';

    /** @var string The chosen purpose does not need this step. */
    public const NOT_NEEDED = 'notneeded';

    /** @var string Something the settings cannot answer, to be checked by hand. */
    public const CHECK = 'check';

    /**
     * Constructor.
     *
     * @param string $id A short name for the step, for the page and the tour to point at.
     * @param string $status One of the constants above.
     * @param string[] $lines What the step says, as HTML.
     * @param \moodle_url|null $link Where the step is done, if somewhere.
     * @param string|null $linktext The link's text.
     */
    public function __construct(
        /** @var string A short name for the step. */
        public readonly string $id,
        /** @var string One of the constants above. */
        public readonly string $status,
        /** @var string[] What the step says, as HTML. */
        public readonly array $lines,
        /** @var \moodle_url|null Where the step is done, if somewhere. */
        public readonly ?\moodle_url $link = null,
        /** @var string|null The link's text. */
        public readonly ?string $linktext = null,
    ) {
    }

    /**
     * The step's title.
     *
     * @return string The title.
     */
    public function get_title(): string {
        return get_string('setup:step:' . $this->id, 'local_airouter');
    }

    /**
     * The step's status in words.
     *
     * @return string The status.
     */
    public function get_status_label(): string {
        return get_string('setup:status:' . $this->status, 'local_airouter');
    }
}
