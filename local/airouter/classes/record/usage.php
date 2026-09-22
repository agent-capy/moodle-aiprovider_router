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
 * What an attempt used, as a value that can say "unknown" out loud.
 *
 * Zero and unknown are different facts. A target that answers nothing and charges
 * nothing used zero; a target that answers and does not say what it used has an
 * unknown usage. A total that adds the two together is wrong whichever way it
 * reads the unknown, so the difference is carried all the way to the row.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class usage {
    /**
     * Constructor.
     *
     * @param int|null $prompttokens Input tokens, or null when the target did not say.
     * @param int|null $completiontokens Output tokens, or null when the target did not say.
     * @param int $images Images produced, for actions costed per image.
     */
    public function __construct(
        /** @var int|null Input tokens, or null when not known. */
        public readonly ?int $prompttokens,
        /** @var int|null Output tokens, or null when not known. */
        public readonly ?int $completiontokens,
        /** @var int Images produced. */
        public readonly int $images = 0,
    ) {
    }

    /**
     * A usage nobody reported.
     *
     * @return self Unknown usage.
     */
    public static function unknown(): self {
        return new self(null, null, 0);
    }

    /**
     * Whether the token counts can be believed as counts.
     *
     * @return bool True when both were reported.
     */
    public function is_known(): bool {
        return $this->prompttokens !== null && $this->completiontokens !== null;
    }
}
