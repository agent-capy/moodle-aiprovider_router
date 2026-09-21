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
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\eligibility_policy;
use local_airouter\form\key_cap_form;
use local_airouter\form\key_form;
use local_airouter\key;
use local_airouter\key_formatter;
use local_airouter\key_repository;
use local_airouter\key_tester;
use local_airouter\price_book;
use local_airouter\provider;
use local_airouter\spend_ledger;
use local_airouter\target_settings;

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$keyid = optional_param('keyid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if ($courseid > 0) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
    require_capability('local/airouter:managecoursekey', $context);
    $scope = key::SCOPE_COURSE;
    $scopeid = (int) $course->id;
    $url = new moodle_url('/local/airouter/keys.php', ['courseid' => $course->id]);
    $heading = get_string('keys:heading:course', 'local_airouter');
    $intro = get_string('keys:intro:course', 'local_airouter');
    // Before the heading, not after. set_heading() runs the text through
    // format_string(), which asks the page for its context and complains when there
    // is none. require_login($course) happens to leave one behind here and nothing
    // does in the branch below, which is how this went unnoticed on one of the two.
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_heading(format_string($course->fullname));
} else {
    require_login();
    $context = context_user::instance($USER->id);
    $scope = key::SCOPE_USER;
    $scopeid = (int) $USER->id;
    $url = new moodle_url('/local/airouter/keys.php');
    $heading = get_string('keys:heading', 'local_airouter');
    $intro = get_string('keys:intro', 'local_airouter');
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('standard');
    $PAGE->set_heading(fullname($USER));
}

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

// Removing a key is not gated on the policy. The key is a secret its owner handed over,
// and a site that tightens who may bring one must not thereby leave somebody holding a
// key they can no longer take back. The profile link is offered to anybody who has one
// for exactly this reason, and it would go nowhere if this screen refused them.
if ($action === 'delete' && $confirm) {
    require_sesskey();
    $key = $repository->get_for($keyid, $scope, $scopeid);
    if ($key !== null) {
        $repository->delete((int) $key->get('id'));
    }
    redirect($url, get_string('keys:deleted', 'local_airouter'), null, \core\output\notification::NOTIFY_SUCCESS);
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
        get_string('keys:tested:' . $result, 'local_airouter'),
        null,
        $result === key::VERIFY_OK
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING,
    );
}

$currency = price_book::get_currency();
// Not cached: somebody looking at their own limit is asking what it is now, and the
// figure is being shown rather than weighed on the path of a request.
$ledger = new spend_ledger($DB, null, false);

$capform = null;
if ($allowed && $action === 'cap') {
    $capkey = $repository->get_for($keyid, $scope, $scopeid);
    if ($capkey === null) {
        redirect($url);
    }
    $capform = new key_cap_form(
        new moodle_url($url),
        ['currency' => $currency] + ($courseid > 0 ? ['courseid' => $courseid] : []),
    );
    if ($capform->is_cancelled()) {
        redirect($url);
    }
    if ($capdata = $capform->get_data()) {
        $repository->set_cap(
            $capkey,
            key_cap_form::read_amount($capdata),
            (string) ($capdata->capperiod ?? spend_ledger::PERIOD_MONTH),
            (int) ($capdata->capdays ?? 30),
        );
        redirect(
            $url,
            get_string('keys:cap:saved', 'local_airouter'),
            null,
            \core\output\notification::NOTIFY_SUCCESS,
        );
    }
    $capform->set_data([
        'keyid' => $keyid,
        'courseid' => $courseid,
        'capamount' => $capkey->has_cap() ? (string) $capkey->get_cap_amount() : '',
        'capperiod' => $capkey->get_cap_period(),
        'capdays' => $capkey->get_cap_days(),
    ]);
}

if ($allowed && $data = $form->get_data()) {
    if (isset($targets[(int) $data->targetid])) {
        $repository->save($scope, $scopeid, (int) $data->targetid, (string) $data->secret);
    }
    redirect($url, get_string('keys:saved', 'local_airouter'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo $OUTPUT->box($intro);

$keys = $repository->get_all($scope, $scopeid);

if (!$allowed) {
    // Not an error. The site has simply not said this person may bring one. If they
    // registered one before it said so, the key is still here and still theirs, so the
    // screen goes on to list it -- with nothing offered but removing it.
    echo $OUTPUT->notification(get_string('keys:notallowed', 'local_airouter'), 'info');
    if (!$keys) {
        echo $OUTPUT->footer();
        die;
    }
    echo $OUTPUT->notification(get_string('keys:notallowed:held', 'local_airouter'), 'warning');
}

if ($action === 'delete' && !$confirm) {
    $key = $repository->get_for($keyid, $scope, $scopeid);
    if ($key === null) {
        redirect($url);
    }
    echo $OUTPUT->confirm(
        get_string('keys:confirmdelete', 'local_airouter'),
        new moodle_url($url, ['action' => 'delete', 'keyid' => $keyid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url,
    );
    echo $OUTPUT->footer();
    die;
}

if ($capform !== null) {
    echo $OUTPUT->heading(get_string('keys:cap:heading', 'local_airouter'), 3);
    // Said here as well as in the table, because this is where somebody decides the
    // number, and a limit read as a bill would be the wrong thing to decide against.
    echo html_writer::div(get_string('keys:cap:estimate', 'local_airouter'), 'text-muted');
    $capform->display();
    echo $OUTPUT->footer();
    die;
}

if ($keys) {
    echo html_writer::table(key_formatter::table($keys, $names, $url, $ledger, $currency, $allowed));
    if ($allowed) {
        echo html_writer::div(get_string('keys:testcost', 'local_airouter'), 'text-muted');
    }
} else {
    echo $OUTPUT->notification(get_string('keys:none', 'local_airouter'), 'info');
}

if (!$allowed) {
    // Nothing further: registering one is what they may not do.
    echo $OUTPUT->footer();
    die;
}

if (!$targets) {
    // Nobody has said where a key goes for any provider on this site, so there is nothing
    // a key could be registered against.
    echo $OUTPUT->notification(get_string('keys:notargets', 'local_airouter'), 'warning');
    echo $OUTPUT->footer();
    die;
}

echo $OUTPUT->heading(get_string('keys:add', 'local_airouter'), 3);
$form->display();

echo $OUTPUT->footer();
