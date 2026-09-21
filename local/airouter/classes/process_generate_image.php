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

namespace local_airouter;

/**
 * Delegates the generate_image action to another provider.
 *
 * No content key is declared: the truncated generation check applies to text models
 * that spend their token budget on reasoning, which has no image equivalent.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_generate_image extends abstract_processor {
    #[\Override]
    protected function get_image_count(bool $success): int {
        if (!$success) {
            return 0;
        }
        // Image responses carry no token counts, so the monitor costs them per image,
        // and the only place the number asked for appears is the action itself.
        if (!property_exists($this->action, 'numimages')) {
            return 1;
        }

        return max(1, (int) $this->action->get_configuration('numimages'));
    }
}
