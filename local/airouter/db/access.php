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
 * Capabilities for local_airouter.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Registering and replacing the key a course pays with. Kept separate from bringing
    // a key of one's own, which is governed by the site's policy rather than by a
    // capability: a course key belongs to the course, so the question is who may act for
    // the course, and that is a capability like any other.
    'local/airouter:managecoursekey' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Seeing which named people used the AI and what it cost, which is what a site needs
    // when it is asked to account for its AI spending. Kept apart from the ordinary
    // monitor, and away from teachers by default: the same figures for one course are a
    // record of what each learner did, and whether anybody should hold that is a question
    // for the site rather than an assumption this plugin makes for it.
    'local/airouter:viewuserusage' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Seeing how much AI a course used, and which provider answered. The figures are
    // about the course rather than about the people in it: no prompt and no user is
    // shown, which is why teaching a course is enough to be allowed it. It is also what
    // decides who hears that a budget set on their course has been reached, and they
    // are told the share rather than the money for the same reason.
    'local/airouter:viewusage' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
