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
 * Where somebody registers the key their AI requests are to be charged to.
 *
 * One screen for two subjects. Without a course it is the person's own keys, which they
 * pay for and the site's policy decides they may bring. With a course it is the key the
 * course pays with, which anybody who may edit the course can set, and which serves
 * everybody working in that course.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_router\eligibility_policy;
use aiprovider_router\form\key_form;
use aiprovider_router\key;
use aiprovider_router\key_formatter;
use aiprovider_router\key_repository;
use aiprovider_router\key_tester;
use aiprovider_router\provider;
use aiprovider_router\target_settings;

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$keyid = optional_param('keyid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if ($courseid > 0) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
    require_capability('aiprovider/router:managecoursekey', $context);
    $scope = key::SCOPE_COURSE;
    $scopeid = (int) $course->id;
    $url = new moodle_url('/ai/provider/router/keys.php', ['courseid' => $course->id]);
    $heading = get_string('keys:heading:course', 'aiprovider_router');
    $intro = get_string('keys:intro:course', 'aiprovider_router');
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_heading(format_string($course->fullname));
} else {
    require_login();
    $context = context_user::instance($USER->id);
    $scope = key::SCOPE_USER;
    $scopeid = (int) $USER->id;
    $url = new moodle_url('/ai/provider/router/keys.php');
    $heading = get_string('keys:heading', 'aiprovider_router');
    $intro = get_string('keys:intro', 'aiprovider_router');
    $PAGE->set_pagelayout('standard');
    $PAGE->set_heading(fullname($USER));
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title($heading);

// A course key belongs to the course, so who may set one is a matter of who may act for
// the course. A key somebody brings for themselves is not, and the site's policy decides.
$allowed = $scope === key::SCOPE_COURSE || (new eligibility_policy())->is_eligible((int) $USER->id);

$repository = new key_repository($DB);
$settings = new target_settings($DB);

$targets = [];
foreach (\core\di::get(\core_ai\manager::class)->get_provider_instances() as $instance) {
    if ($instance instanceof provider || !$settings->supports_byok((int) $instance->id)) {
        continue;
    }
    $targets[(int) $instance->id] = $instance;
}
$names = array_map(fn($instance) => format_string($instance->name), $targets);

$form = new key_form($url, ['targets' => $names]);

if ($allowed && $action === 'delete' && $confirm) {
    require_sesskey();
    $key = $repository->get_for($keyid, $scope, $scopeid);
    if ($key !== null) {
        $repository->delete((int) $key->get('id'));
    }
    redirect($url, get_string('keys:deleted', 'aiprovider_router'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($allowed && $action === 'test') {
    require_sesskey();
    $key = $repository->get_for($keyid, $scope, $scopeid);
    if ($key === null || !isset($targets[(int) $key->get('targetid')])) {
        redirect($url);
    }
    $target = $targets[(int) $key->get('targetid')];
    $result = (new key_tester($DB))->test($target, $key, (int) $context->id, (int) $USER->id);
    redirect(
        $url,
        get_string('keys:tested:' . $result, 'aiprovider_router'),
        null,
        $result === key::VERIFY_OK
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING,
    );
}

if ($allowed && $data = $form->get_data()) {
    if (isset($targets[(int) $data->targetid])) {
        $repository->save($scope, $scopeid, (int) $data->targetid, (string) $data->secret);
    }
    redirect($url, get_string('keys:saved', 'aiprovider_router'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo $OUTPUT->box($intro);

if (!$allowed) {
    // Not an error. The site has simply not said this person may bring one.
    echo $OUTPUT->notification(get_string('keys:notallowed', 'aiprovider_router'), 'info');
    echo $OUTPUT->footer();
    die;
}

if ($action === 'delete' && !$confirm) {
    $key = $repository->get_for($keyid, $scope, $scopeid);
    if ($key === null) {
        redirect($url);
    }
    echo $OUTPUT->confirm(
        get_string('keys:confirmdelete', 'aiprovider_router'),
        new moodle_url($url, ['action' => 'delete', 'keyid' => $keyid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url,
    );
    echo $OUTPUT->footer();
    die;
}

$keys = $repository->get_all($scope, $scopeid);
if ($keys) {
    echo html_writer::table(key_formatter::table($keys, $names, $url));
    echo html_writer::div(get_string('keys:testcost', 'aiprovider_router'), 'text-muted');
} else {
    echo $OUTPUT->notification(get_string('keys:none', 'aiprovider_router'), 'info');
}

if (!$targets) {
    // Nobody has said where a key goes for any provider on this site, so there is nothing
    // a key could be registered against.
    echo $OUTPUT->notification(get_string('keys:notargets', 'aiprovider_router'), 'warning');
    echo $OUTPUT->footer();
    die;
}

echo $OUTPUT->heading(get_string('keys:add', 'aiprovider_router'), 3);
$form->display();

echo $OUTPUT->footer();
