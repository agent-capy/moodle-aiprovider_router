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
 * Callbacks for local_airouter.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Status checks reported on the site status report.
 *
 * A router that is not reached is indistinguishable from a router that is not working, and
 * the admin has no reason to suspect the provider order, so the site status report is where
 * that gets said. One inspector is shared so the checks cannot describe different sites.
 *
 * @return \core\check\check[] The checks.
 */
function local_airouter_status_checks(): array {
    $inspector = new \local_airouter\order_inspector();

    return [
        new \local_airouter\check\managedboundary(),
        new \local_airouter\check\singleinstance($inspector),
        new \local_airouter\check\routerlisted($inspector),
        new \local_airouter\check\routerfirst($inspector),
        new \local_airouter\check\actionconflict($inspector),
        new \local_airouter\check\declinereach($inspector),
        new \local_airouter\check\staleentries($inspector),
        new \local_airouter\check\ruletargets($inspector),
        new \local_airouter\check\staleactions($inspector),
        new \local_airouter\check\byokkeys($inspector),
        new \local_airouter\check\byokeligibility($inspector),
        new \local_airouter\check\budgetrates($inspector),
        new \local_airouter\check\budgethistory($inspector),
        new \local_airouter\check\recordgaps($inspector),
    ];
}

/**
 * Put this plugin's course pages where a teacher will find them.
 *
 * Usage is offered only for courses that have actually used AI through the router: a link
 * to an empty report in every course on the site is noise, and the question only arises
 * once there is something to ask it about.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context_course $context Its context.
 */
function local_airouter_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context,
): void {
    global $DB;

    $used = has_capability('local/airouter:viewusage', $context)
        && ($DB->record_exists(\local_airouter\usage_logger::TABLE, ['courseid' => $course->id])
            || $DB->record_exists(\local_airouter\usage_aggregator::TABLE, ['courseid' => $course->id]));

    if ($used) {
        $navigation->add(
            get_string('courseusage:heading', 'local_airouter'),
            new moodle_url('/local/airouter/courseusage.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_airouter_usage',
            new pix_icon('i/report', ''),
        );
    }

    // The key this course pays with. Offered only where a key could actually be used: a
    // site with no provider whose key field has been confirmed has nowhere to put one,
    // and showing the screen anyway invites somebody to paste a key into nothing.
    $cansetkey = has_capability('local/airouter:managecoursekey', $context)
        && $DB->record_exists_select(\local_airouter\target_settings::TABLE, "keyfield <> ''");
    if ($cansetkey) {
        $navigation->add(
            get_string('keys:heading:course', 'local_airouter'),
            new moodle_url('/local/airouter/keys.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_airouter_coursekeys',
            new pix_icon('i/lock', ''),
        );
    }
}

/**
 * Put a person's own keys on their profile page.
 *
 * Offered to everybody the site's policy admits, and to anybody who already has a key,
 * so that a policy tightened afterwards never leaves somebody unable to reach a key of
 * theirs the site is still holding.
 *
 * @param core_user\output\myprofile\tree $tree The profile tree being built.
 * @param stdClass $user The user whose profile it is.
 * @param bool $iscurrentuser Whether they are looking at their own.
 * @param stdClass|null $course The course the profile is being viewed in, if any.
 */
function local_airouter_myprofile_navigation(
    core_user\output\myprofile\tree $tree,
    stdClass $user,
    bool $iscurrentuser,
    ?stdClass $course = null,
): void {
    global $DB;

    unset($course);
    // Somebody else's keys are nobody's business, including an administrator's: there is
    // nothing to see that would help, and the screen exists to register and remove them.
    if (!$iscurrentuser) {
        return;
    }

    $eligible = (new \local_airouter\eligibility_policy())->is_eligible((int) $user->id);
    $has = $DB->record_exists(\local_airouter\key::TABLE, [
        'scope' => \local_airouter\key::SCOPE_USER,
        'scopeid' => $user->id,
    ]);
    if (!$eligible && !$has) {
        return;
    }

    $tree->add_node(new core_user\output\myprofile\node(
        'miscellaneous',
        'local_airouter_keys',
        get_string('keys:heading', 'local_airouter'),
        null,
        new moodle_url('/local/airouter/keys.php'),
    ));
}
