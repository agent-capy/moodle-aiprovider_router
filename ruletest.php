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
 * Tries the rules against a request the administrator describes, without sending anything.
 *
 * "Why did that request go there" is the question a rule set generates, and it cannot be
 * answered by reading the list: the answer depends on the user, the course, the action
 * and the size of the prompt at the same time. This runs the real evaluator over a real
 * action object and shows every rule it considered, including the ones it passed over.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_router\action_factory;
use aiprovider_router\condition\registry;
use aiprovider_router\evaluation_context;
use aiprovider_router\form\rule_test_form;
use aiprovider_router\rule_evaluator;
use aiprovider_router\rule_repository;
use aiprovider_router\target_resolver;
use aiprovider_router\token_estimator;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$listurl = new moodle_url('/ai/provider/router/rules.php');
$url = new moodle_url('/ai/provider/router/ruletest.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('ruletest:heading', 'aiprovider_router'));
$PAGE->set_heading(get_string('ruletest:heading', 'aiprovider_router'));
$PAGE->navbar->add(get_string('pluginname', 'aiprovider_router'));
$PAGE->navbar->add(get_string('rules:heading', 'aiprovider_router'), $listurl);
$PAGE->navbar->add(get_string('ruletest:heading', 'aiprovider_router'), $url);

$form = new rule_test_form($url);

echo $OUTPUT->header();
echo $OUTPUT->box(get_string('ruletest:intro', 'aiprovider_router'));
$form->display();

$data = $form->get_data();
if ($data) {
    $courseid = (int) ($data->courseid ?? 0);
    $requestcontext = $courseid > 0 ? context_course::instance($courseid) : $context;
    $user = trim((string) $data->username) === '' ? null : rule_test_form::find_user(trim($data->username));
    $placement = ($data->placement ?? '') === '' ? null : $data->placement;

    $action = action_factory::make(
        $data->actionclass,
        (int) $requestcontext->id,
        $user ? (int) $user->id : 0,
        (string) $data->prompt,
    );
    $evaluationcontext = new evaluation_context($action, placement: $placement);

    $estimator = new token_estimator();
    $counts = $estimator->describe((string) $data->prompt);

    echo $OUTPUT->heading(get_string('ruletest:request', 'aiprovider_router'), 3);
    $summary = new html_table();
    $summary->attributes['class'] = 'admintable generaltable';
    $summary->data = [
        [get_string('ruletest:course', 'aiprovider_router'),
            $courseid > 0 ? format_string(get_course($courseid)->fullname)
                : get_string('ruletest:course:none', 'aiprovider_router')],
        [get_string('ruletest:user', 'aiprovider_router'),
            $user ? fullname($user) : get_string('ruletest:user:none', 'aiprovider_router')],
        [get_string('ruletest:placement', 'aiprovider_router'),
            $placement === null ? get_string('ruletest:placement:none', 'aiprovider_router')
                : get_string('pluginname', $placement)],
        // The character count is what prompt length conditions compare against, so it
        // comes first and on its own. The token figure sits under it as a guide for
        // choosing a threshold, since tokens are the unit cost is thought about in.
        [get_string('ruletest:length', 'aiprovider_router'),
            get_string('ruletest:length:value', 'aiprovider_router', [
                'characters' => $evaluationcontext->get_prompt_length(),
            ])],
        [get_string('ruletest:tokens', 'aiprovider_router'),
            get_string('ruletest:tokens:value', 'aiprovider_router', [
                'tokens' => $evaluationcontext->get_estimated_tokens(),
                'cjk' => $counts['cjk'],
                'other' => $counts['other'],
                'cjkratio' => format_float($estimator->get_cjk_ratio(), 1),
                'otherratio' => format_float($estimator->get_other_ratio(), 1),
            ])],
    ];
    echo html_writer::table($summary);

    $repository = new rule_repository($DB);
    $evaluator = new rule_evaluator($repository);
    $trace = $evaluator->trace($evaluationcontext);
    $targets = target_resolver::get_delegation_targets();

    echo $OUTPUT->heading(get_string('ruletest:result', 'aiprovider_router'), 3);

    $winner = null;
    $table = new html_table();
    $table->head = [
        get_string('rules:priority', 'aiprovider_router'),
        get_string('rule:name', 'aiprovider_router'),
        get_string('ruletest:outcome', 'aiprovider_router'),
    ];
    $table->attributes['class'] = 'admintable generaltable';

    $position = 0;
    foreach ($trace as $entry) {
        $rule = $entry['rule'];
        $targetid = (int) $rule->get('targetid');
        $usable = isset($targets[$targetid]);

        if (!$entry['active']) {
            $outcome = get_string('ruletest:outcome:inactive', 'aiprovider_router');
        } else if (!$entry['matched']) {
            $names = [];
            foreach ($entry['unmet'] as $type) {
                $names[] = registry::is_known($type)
                    ? ('\\aiprovider_router\\condition\\' . $type)::get_label()
                    : $type;
            }
            $outcome = get_string('ruletest:outcome:unmet', 'aiprovider_router', s(implode(', ', $names)));
        } else if (!$usable) {
            // Matched, but there is nothing to carry it out with, so the search goes on.
            $outcome = get_string('ruletest:outcome:unusable', 'aiprovider_router', $targetid);
        } else if ($winner === null) {
            $winner = $rule;
            $outcome = get_string('ruletest:outcome:chosen', 'aiprovider_router', s($targets[$targetid]));
        } else {
            $outcome = get_string('ruletest:outcome:notreached', 'aiprovider_router');
        }

        $table->data[] = [(string) (++$position), s($rule->get('name')), $outcome];
    }

    if ($table->data) {
        echo html_writer::table($table);
    }

    if ($winner !== null) {
        echo $OUTPUT->notification(
            get_string('ruletest:wouldgo', 'aiprovider_router', [
                'rule' => s($winner->get('name')),
                'target' => s($targets[(int) $winner->get('targetid')]),
            ]),
            'success',
        );
    } else {
        // No rule claimed it, which is the commonest outcome on most sites and is decided
        // by the router's own setting rather than by anything on this page.
        echo $OUTPUT->notification(get_string('ruletest:nomatch', 'aiprovider_router'), 'info');
    }
}

echo $OUTPUT->footer();
