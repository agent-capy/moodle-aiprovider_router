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
 * Delegates the describe image action to another provider.
 *
 * Another action Moodle does not define: local_aimedia has it, because core's
 * generate_image goes the other way, writing text and returning a picture.
 *
 * Nothing special is needed here. A vision model answers in text and counts
 * tokens the same way a text model does, so the response carries the same field
 * the router already looks at, and costing works without knowing what was asked.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_describe_image extends abstract_processor {
    #[\Override]
    protected function get_content_key(): ?string {
        return 'generatedcontent';
    }
}
