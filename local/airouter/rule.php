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
 * Creates and edits one routing rule.
 *
 * Not registered in the admin tree, for the same reason as the other pages here: core
 * never reads an aiprovider plugin's settings.php, so there is no admin_externalpage to
 * hang this on. It is reached from the rule list.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\admin_page;
use local_airouter\form\rule_form;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\target_resolver;

$id = optional_param('id', 0, PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$listurl = new moodle_url('/local/airouter/rules.php');
$url = new moodle_url('/local/airouter/rule.php', $id ? ['id' => $id] : []);
$heading = get_string($id ? 'rule:edit' : 'rule:add', 'local_airouter');
admin_page::setup($PAGE, $url, $heading, [
    get_string('rules:heading', 'local_airouter') => $listurl,
], 'local_airouter_rules');

$repository = new rule_repository($DB);
$existingconditions = [];
$rule = new rule();
if ($id) {
    $rule = $repository->get($id);
    if ($rule === null) {
        redirect($listurl);
    }
    $existingconditions = $repository->get_conditions($id);
}

$form = new rule_form($url, ['targets' => target_resolver::get_delegation_targets()]);

if ($form->is_cancelled()) {
    redirect($listurl);
}

if ($data = $form->get_data()) {
    $rule->set('name', $data->name);
    $rule->set('enabled', !empty($data->enabled));
    $rule->set('targetid', (int) $data->targetid);
    $rule->set('keysource', (string) ($data->keysource ?? rule::KEYSOURCE_SITE));
    $rule->set('timestart', (int) ($data->timestart ?? 0));
    $rule->set('timeend', (int) ($data->timeend ?? 0));

    $repository->save($rule, rule_form::read_conditions($data, $existingconditions));

    redirect(
        $listurl,
        get_string('rule:saved', 'local_airouter', $data->name),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

if ($id) {
    $form->set_data((object) ([
        'id' => $id,
        'name' => $rule->get('name'),
        'enabled' => $rule->get('enabled') ? 1 : 0,
        'targetid' => $rule->get('targetid'),
        'keysource' => $rule->get('keysource'),
        'timestart' => $rule->get('timestart'),
        'timeend' => $rule->get('timeend'),
    ] + rule_form::conditions_to_form_data($existingconditions)));
}

echo $OUTPUT->header();

if (!target_resolver::get_delegation_targets()) {
    // Without somewhere to delegate to there is nothing a rule could say.
    echo $OUTPUT->notification(get_string('defaulttarget:none', 'local_airouter'), 'warning');
}

$form->display();
echo $OUTPUT->footer();
