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
 * Callbacks for aiprovider_router.
 *
 * @package    aiprovider_router
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
function aiprovider_router_status_checks(): array {
    $inspector = new \aiprovider_router\order_inspector();

    return [
        new \aiprovider_router\check\singleinstance($inspector),
        new \aiprovider_router\check\routerlisted($inspector),
        new \aiprovider_router\check\routerfirst($inspector),
        new \aiprovider_router\check\actionconflict($inspector),
        new \aiprovider_router\check\declinereach($inspector),
        new \aiprovider_router\check\staleentries($inspector),
        new \aiprovider_router\check\ruletargets($inspector),
        new \aiprovider_router\check\staleactions($inspector),
        new \aiprovider_router\check\byokkeys($inspector),
        new \aiprovider_router\check\byokeligibility($inspector),
        new \aiprovider_router\check\budgetrates($inspector),
        new \aiprovider_router\check\budgethistory($inspector),
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
function aiprovider_router_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context,
): void {
    global $DB;

    $used = has_capability('aiprovider/router:viewusage', $context)
        && ($DB->record_exists(\aiprovider_router\usage_logger::TABLE, ['courseid' => $course->id])
            || $DB->record_exists(\aiprovider_router\usage_aggregator::TABLE, ['courseid' => $course->id]));

    if ($used) {
        $navigation->add(
            get_string('courseusage:heading', 'aiprovider_router'),
            new moodle_url('/ai/provider/router/courseusage.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aiprovider_router_usage',
            new pix_icon('i/report', ''),
        );
    }

    // The key this course pays with. Offered only where a key could actually be used: a
    // site with no provider whose key field has been confirmed has nowhere to put one,
    // and showing the screen anyway invites somebody to paste a key into nothing.
    $cansetkey = has_capability('aiprovider/router:managecoursekey', $context)
        && $DB->record_exists_select(\aiprovider_router\target_settings::TABLE, "keyfield <> ''");
    if ($cansetkey) {
        $navigation->add(
            get_string('keys:heading:course', 'aiprovider_router'),
            new moodle_url('/ai/provider/router/keys.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aiprovider_router_coursekeys',
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
function aiprovider_router_myprofile_navigation(
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

    $eligible = (new \aiprovider_router\eligibility_policy())->is_eligible((int) $user->id);
    $has = $DB->record_exists(\aiprovider_router\key::TABLE, [
        'scope' => \aiprovider_router\key::SCOPE_USER,
        'scopeid' => $user->id,
    ]);
    if (!$eligible && !$has) {
        return;
    }

    $tree->add_node(new core_user\output\myprofile\node(
        'miscellaneous',
        'aiprovider_router_keys',
        get_string('keys:heading', 'aiprovider_router'),
        null,
        new moodle_url('/ai/provider/router/keys.php'),
    ));
}
