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

/**
 * What a fresh install sets up beyond the schema.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Install the plugin.
 *
 * @return bool Always true.
 */
function xmldb_local_airouter_install(): bool {
    // The secret that the hashes of brought keys are keyed with, so that the same key
    // entered again can be recognised without the key itself being kept. One per
    // site, made once: a hash made under another site's secret matches nothing here,
    // which is the right answer for a database moved between sites.
    set_config('walletsecret', bin2hex(random_bytes(32)), 'local_airouter');

    return true;
}
