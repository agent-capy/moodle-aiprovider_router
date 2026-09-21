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

namespace aiprovider_mock;

/**
 * Mock processor for the action the router has stopped declaring.
 *
 * It answers, so that a request escaping the router is visible as a success rather
 * than as core running out of providers. Without it the leak would look the same as
 * a site with nothing configured.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_fixture_dropped_action extends abstract_processor {
    #[\Override]
    protected function get_content_key(): ?string {
        return 'generatedcontent';
    }
}
