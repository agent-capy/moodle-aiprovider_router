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
use aiprovider_router\admin_page;
use aiprovider_router\condition\registry;
use aiprovider_router\evaluation_context;
use aiprovider_router\form\rule_test_form;
use aiprovider_router\order_inspector;
use aiprovider_router\rule_evaluator;
use aiprovider_router\rule_repository;
use aiprovider_router\target_resolver;
use aiprovider_router\token_estimator;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$listurl = new moodle_url('/ai/provider/router/rules.php');
$url = new moodle_url('/ai/provider/router/ruletest.php');
admin_page::setup($PAGE, $url, get_string('ruletest:heading', 'aiprovider_router'), [
    get_string('rules:heading', 'aiprovider_router') => $listurl,
]);

$form = new rule_test_form($url);

echo $OUTPUT->header();
echo admin_page::back_button($url);
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

    // Where the request would actually go is asked of the router, not worked out again
    // here. A rule naming a target is not a rule that can be carried out: the target
    // may be switched off, may not offer this action, may be set aside for brought keys,
    // or the person may hold no key for it. The list above is the one the rule form
    // offers and deliberately includes targets in all of those states, so deciding from
    // it produced a screen that named one provider while requests went to another.
    $router = (new order_inspector())->get_primary_router();
    $resolver = $router === null ? null : new target_resolver($router);
    $candidates = $resolver?->get_candidates($action) ?? [];
    $chosentarget = $candidates[0]->target ?? null;
    // A rule can be matched and still carry the request nowhere. The clearest case is
    // a key that is registered and cannot be decrypted: the resolver remembers the
    // rule, empties the candidates and stops the request. Reading the rule alone
    // produced a screen that said the request would be sent, to a provider it could
    // not name.
    $chosenrule = $chosentarget === null ? null : $resolver?->get_matched_rule();
    $chosenid = $chosenrule === null ? null : (int) $chosenrule->get('id');
    $unreadablekey = $resolver?->get_unreadable_key();

    echo $OUTPUT->heading(get_string('ruletest:result', 'aiprovider_router'), 3);

    if ($router === null) {
        // Nothing to ask. The conditions below are still worth showing, but which
        // target would carry the request is not a question this site can answer yet.
        echo $OUTPUT->notification(get_string('ruletest:norouter', 'aiprovider_router'), 'warning');
    }

    $table = new html_table();
    $table->head = [
        get_string('rules:priority', 'aiprovider_router'),
        get_string('rule:name', 'aiprovider_router'),
        get_string('ruletest:outcome', 'aiprovider_router'),
    ];
    $table->attributes['class'] = 'admintable generaltable';

    $position = 0;
    $past = false;
    foreach ($trace as $entry) {
        $rule = $entry['rule'];
        $targetid = (int) $rule->get('targetid');

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
        } else if ($chosenid !== null && (int) $rule->get('id') === $chosenid) {
            $past = true;
            $outcome = get_string(
                'ruletest:outcome:chosen',
                'aiprovider_router',
                s($chosentarget?->name ?? ($targets[$targetid] ?? (string) $targetid)),
            );
        } else if ($past) {
            $outcome = get_string('ruletest:outcome:notreached', 'aiprovider_router');
        } else {
            // Matched, and the router passed over it anyway: whatever it names cannot
            // carry this request, so the search went on to the rule below.
            $outcome = get_string('ruletest:outcome:unusable', 'aiprovider_router', $targetid);
        }

        $table->data[] = [(string) (++$position), s($rule->get('name')), $outcome];
    }

    if ($table->data) {
        echo html_writer::table($table);
    }

    if ($chosenrule !== null) {
        echo $OUTPUT->notification(
            get_string('ruletest:wouldgo', 'aiprovider_router', [
                'rule' => s($chosenrule->get('name')),
                'target' => s($chosentarget?->name ?? ''),
            ]),
            'success',
        );
    } else if ($unreadablekey !== null) {
        // The one outcome that is nobody's mistake on this screen and everybody's
        // problem on a real request: the request stops, and it stops for a reason an
        // administrator can act on.
        echo $OUTPUT->notification(get_string('ruletest:unreadablekey', 'aiprovider_router'), 'warning');
    } else if ($resolver !== null && $resolver->was_budget_spent()) {
        // Worth saying on its own. This is the one outcome that stops the request
        // outright rather than handing it on, and it reads on the screen above as
        // nothing more than a rule that did not match.
        echo $OUTPUT->notification(get_string('ruletest:budgetspent', 'aiprovider_router'), 'warning');
    } else if ($candidates) {
        // No rule claimed it, and the router has somewhere to send it anyway.
        echo $OUTPUT->notification(
            get_string('ruletest:default', 'aiprovider_router', s($candidates[0]->target->name)),
            'info',
        );
    } else {
        // No rule claimed it, which is the commonest outcome on most sites and is decided
        // by the router's own setting rather than by anything on this page.
        echo $OUTPUT->notification(get_string('ruletest:nomatch', 'aiprovider_router'), 'info');
    }
}

echo $OUTPUT->footer();
