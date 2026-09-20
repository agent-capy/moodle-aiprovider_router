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

use aiprovider_router\admin_page;
use aiprovider_router\eligibility_policy;
use aiprovider_router\form\byok_policy_form;
use aiprovider_router\form\byok_targets_form;
use aiprovider_router\key;
use aiprovider_router\provider;
use aiprovider_router\target_settings;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/ai/provider/router/byok.php');
admin_page::setup($PAGE, $url, get_string('byok:heading', 'aiprovider_router'));

$targets = [];
foreach (\core\di::get(\core_ai\manager::class)->get_provider_instances() as $instance) {
    if ($instance instanceof provider) {
        continue;
    }
    $targets[(int) $instance->id] = $instance;
}

$settings = new target_settings($DB);
$policy = new eligibility_policy();

// Both forms post here. Moodle tells them apart by the hidden field each one adds.
$policyform = new byok_policy_form($url);
if ($data = $policyform->get_data()) {
    $policy->save(
        (string) $data->access,
        byok_policy_form::read_conditions($data),
        (string) ($data->match ?? eligibility_policy::MATCH_ANY),
    );
    redirect(
        $url,
        get_string('byok:saved', 'aiprovider_router'),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}
$policyform->set_data(byok_policy_form::to_form_data($policy));

$form = new byok_targets_form($url, ['targets' => $targets]);

if ($data = $form->get_data()) {
    foreach (array_keys($targets) as $targetid) {
        $field = 'keyfield_' . $targetid;
        $mode = 'byokmode_' . $targetid;
        $settings->set_key_field($targetid, (string) ($data->$field ?? ''));
        $settings->set_mode($targetid, (string) ($data->$mode ?? target_settings::MODE_ALLOWED));
    }
    redirect(
        $url,
        get_string('byok:saved', 'aiprovider_router'),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

$current = $settings->get_all();
$modes = $settings->get_all_modes();
$defaults = [];
foreach ($targets as $targetid => $target) {
    // An instance nobody has answered for is offered the guess, so that confirming is
    // usually a matter of agreeing rather than of going and looking something up.
    $defaults['keyfield_' . $targetid] = $current[$targetid] ?? target_settings::guess($target);
    $defaults['byokmode_' . $targetid] = $modes[$targetid] ?? target_settings::MODE_ALLOWED;
}
$form->set_data($defaults);

echo $OUTPUT->header();
echo $OUTPUT->box(get_string('byok:intro', 'aiprovider_router'));

echo $OUTPUT->heading(get_string('eligibility:heading', 'aiprovider_router'), 3);
echo html_writer::div(get_string('eligibility:intro', 'aiprovider_router'));
$unknown = $policy->get_unknown_conditions();
if ($unknown) {
    // A condition saved by a newer version of this plugin. Every condition has to hold,
    // so ignoring one can only admit somebody the newer version would have refused.
    echo $OUTPUT->notification(
        get_string('eligibility:unknown', 'aiprovider_router', s(implode(', ', $unknown))),
        'warning',
    );
}
$policyform->display();

echo $OUTPUT->heading(get_string('byok:targets', 'aiprovider_router'), 3);

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
