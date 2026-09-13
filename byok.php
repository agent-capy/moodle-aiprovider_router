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
 * Says where a brought key goes, for each provider the router can delegate to.
 *
 * There is no field name common to providers, so this is asked rather than assumed. Not
 * registered in the admin tree, for the reason the other pages here are not: core never
 * reads an aiprovider plugin's settings.php.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_router\form\byok_targets_form;
use aiprovider_router\key;
use aiprovider_router\provider;
use aiprovider_router\target_settings;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/ai/provider/router/byok.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('byok:heading', 'aiprovider_router'));
$PAGE->set_heading(get_string('byok:heading', 'aiprovider_router'));
$PAGE->navbar->add(get_string('pluginname', 'aiprovider_router'));
$PAGE->navbar->add(get_string('byok:heading', 'aiprovider_router'), $url);

$targets = [];
foreach (\core\di::get(\core_ai\manager::class)->get_provider_instances() as $instance) {
    if ($instance instanceof provider) {
        continue;
    }
    $targets[(int) $instance->id] = $instance;
}

$settings = new target_settings($DB);
$form = new byok_targets_form($url, ['targets' => $targets]);

if ($data = $form->get_data()) {
    foreach (array_keys($targets) as $targetid) {
        $element = 'keyfield_' . $targetid;
        $settings->set_key_field($targetid, (string) ($data->$element ?? ''));
    }
    redirect(
        $url,
        get_string('byok:saved', 'aiprovider_router'),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

$current = $settings->get_all();
$defaults = [];
foreach ($targets as $targetid => $target) {
    // An instance nobody has answered for is offered the guess, so that confirming is
    // usually a matter of agreeing rather than of going and looking something up.
    $defaults['keyfield_' . $targetid] = $current[$targetid] ?? target_settings::guess($target);
}
$form->set_data($defaults);

echo $OUTPUT->header();
echo $OUTPUT->box(get_string('byok:intro', 'aiprovider_router'));

if (!$targets) {
    echo $OUTPUT->notification(get_string('defaulttarget:none', 'aiprovider_router'), 'info');
    echo $OUTPUT->footer();
    die;
}

$unanswered = array_diff(array_keys($targets), array_keys($current));
if ($unanswered) {
    echo $OUTPUT->notification(
        get_string('byok:unanswered', 'aiprovider_router', count($unanswered)),
        'warning',
    );
}

$form->display();

$held = $DB->count_records(key::TABLE);
if ($held > 0) {
    echo html_writer::div(get_string('byok:held', 'aiprovider_router', $held), 'text-muted');
}

echo $OUTPUT->footer();
