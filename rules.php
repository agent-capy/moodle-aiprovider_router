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
 * Lists the routing rules and changes their order.
 *
 * Not registered in the admin tree. Core calls load_settings() for aiplacement plugins
 * only, never for aiprovider ones, so an aiprovider plugin's settings.php is never read
 * on any release this plugin supports. The page is reached from the router's own
 * settings form and from the site status report, the same way the provider order page is.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_router\rule_formatter;
use aiprovider_router\rule_repository;
use aiprovider_router\target_resolver;

$action = optional_param('action', '', PARAM_ALPHA);
$ruleid = optional_param('ruleid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/ai/provider/router/rules.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('rules:heading', 'aiprovider_router'));
$PAGE->set_heading(get_string('rules:heading', 'aiprovider_router'));
$PAGE->navbar->add(get_string('pluginname', 'aiprovider_router'));
$PAGE->navbar->add(get_string('rules:heading', 'aiprovider_router'), $url);

$repository = new rule_repository($DB);

// Actions that change something. Each needs the session key, and deleting needs the
// administrator to have seen what they are deleting first.
if ($action !== '' && $ruleid > 0) {
    $rule = $repository->get($ruleid);
    if ($rule === null) {
        redirect($url);
    }

    if ($action === 'delete' && $confirm) {
        require_sesskey();
        $repository->delete($ruleid);
        redirect(
            $url,
            get_string('rules:deleted', 'aiprovider_router', $rule->get('name')),
            null,
            \core\output\notification::NOTIFY_SUCCESS,
        );
    }

    if (in_array($action, ['up', 'down', 'enable', 'disable', 'duplicate'], true)) {
        require_sesskey();
        match ($action) {
            'up' => $repository->move($ruleid, -1),
            'down' => $repository->move($ruleid, 1),
            'enable' => $repository->set_enabled($ruleid, true),
            'disable' => $repository->set_enabled($ruleid, false),
            'duplicate' => $repository->duplicate(
                $ruleid,
                get_string('rules:copyof', 'aiprovider_router', $rule->get('name')),
            ),
        };
        redirect($url);
    }
}

echo $OUTPUT->header();

// Deleting a rule cannot be undone and changes how the site routes, so the rule is shown
// once more before it goes.
if ($action === 'delete' && $ruleid > 0 && !$confirm) {
    $rule = $repository->get($ruleid);
    if ($rule === null) {
        redirect($url);
    }
    echo $OUTPUT->confirm(
        get_string('rules:confirmdelete', 'aiprovider_router', $rule->get('name')),
        new moodle_url($url, ['action' => 'delete', 'ruleid' => $ruleid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url,
    );
    echo $OUTPUT->footer();
    die;
}

$rules = $repository->get_all();
$conditions = $repository->get_conditions_for(array_keys($rules));
$targets = target_resolver::get_delegation_targets();

echo $OUTPUT->box(get_string('rules:intro', 'aiprovider_router'));

if (!$rules) {
    echo $OUTPUT->notification(get_string('rules:none', 'aiprovider_router'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('rules:priority', 'aiprovider_router'),
        get_string('rule:name', 'aiprovider_router'),
        get_string('rule:conditions', 'aiprovider_router'),
        get_string('rule:target', 'aiprovider_router'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'admintable generaltable';

    $position = 0;
    $last = count($rules) - 1;
    $reachable = true;
    foreach ($rules as $id => $rule) {
        $row = new html_table_row();
        $row->cells = [
            (string) ($position + 1),
            rule_formatter::name($rule, $reachable),
            rule_formatter::conditions($conditions[$id] ?? []),
            rule_formatter::target($rule, $targets),
            rule_formatter::actions($url, $rule, $position, $last),
        ];
        if (!$rule->get('enabled')) {
            $row->attributes['class'] = 'dimmed_text';
        }
        $table->data[] = $row;

        // Everything below a rule that takes every request is unreachable, and an
        // administrator staring at a rule that never fires deserves to be told why.
        if ($rule->get('enabled') && !($conditions[$id] ?? [])) {
            $reachable = false;
        }
        $position++;
    }
    echo html_writer::table($table);
}

echo $OUTPUT->single_button(
    new moodle_url('/ai/provider/router/rule.php'),
    get_string('rules:add', 'aiprovider_router'),
    'get',
);
echo $OUTPUT->single_button(
    new moodle_url('/ai/provider/router/ruletest.php'),
    get_string('ruletest:heading', 'aiprovider_router'),
    'get',
);
echo $OUTPUT->single_button(
    new moodle_url('/ai/provider/router/order.php'),
    get_string('order:heading', 'aiprovider_router'),
    'get',
);

echo $OUTPUT->footer();
