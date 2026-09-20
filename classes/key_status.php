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

namespace aiprovider_router;

/**
 * What happened when the router looked for a key to pay a request with.
 *
 * The distinction the whole of bring your own key rests on is between the first two
 * failures. A key that was never registered is a normal state of affairs: the rule asking
 * for one simply does not apply, and the next rule is tried. A key that is there and
 * cannot be read is a fault in the site, and carrying on to the next rule would quietly
 * charge somebody else for the request. They must never be handled alike.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum key_status: string {
    // A key was found and put where the provider expects it.
    case OK = 'ok';

    // Nobody has registered a key for this. Normal, and the rule does not apply.
    case ABSENT = 'absent';

    // Nobody has said which configuration field this provider's key goes in.
    case NO_FIELD = 'nofield';

    // The site has said that keys brought to this provider are not to be used. Like
    // ABSENT and NO_FIELD the rule does not apply, but it is told apart from them
    // because this one is a decision somebody made rather than a setting nobody
    // finished, and the two want different things said about them on a screen.
    case DISALLOWED = 'disallowed';

    // A key is stored and cannot be decrypted. A fault, and the request stops.
    case UNREADABLE = 'unreadable';
}
