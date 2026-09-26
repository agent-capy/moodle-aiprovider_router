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
 * A router object for tests, made from a configuration given directly.
 *
 * The router a request uses is an adapter_provider, built from the plugin's settings.
 * A test that is about what the router does with a request rather than about where its
 * settings come from builds one of these instead, with the settings it needs, the way
 * core would build a provider from a stored row.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixture_router extends provider {
    #[\Override]
    public function is_provider_configured(): bool {
        return $this->get_default_target_id() !== null || self::has_rules();
    }
}
