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
 * What the router handled, for the whole site.
 *
 * Moodle's own record says this plugin answered everything that came through it, so this
 * is where a site owner finds out where the requests actually went. Not registered in the
 * admin tree, for the reason the other pages here are not: core never reads an aiprovider
 * plugin's settings.php.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\admin_page;
use local_airouter\budget_notifier;
use local_airouter\form\usage_settings_form;
use local_airouter\price_book;
use local_airouter\rule;
use local_airouter\usage_formatter;
use local_airouter\record\reader;
use local_airouter\record\summariser;
use local_airouter\retention_policy;

$days = optional_param('days', 30, PARAM_INT);
$keysource = optional_param('keysource', rule::KEYSOURCE_SITE, PARAM_ALPHA);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/local/airouter/usage.php');
admin_page::setup($PAGE, $url, get_string('usage:heading', 'local_airouter'), section: 'local_airouter_usage');

$periods = [7, 30, 90, 365];
if (!in_array($days, $periods, true)) {
    $days = 30;
}

// What the site spent is the question this screen exists to answer, so it is the one
// asked first. Money somebody paid out of their own pocket is real and is recorded, but
// adding it in would produce a figure that is nobody's expenditure.
$keysources = array_merge(rule::get_keysources(), [reader::KEYSOURCE_ALL]);
if (!in_array($keysource, $keysources, true)) {
    $keysource = rule::KEYSOURCE_SITE;
}

$form = new usage_settings_form($url);
if ($data = $form->get_data()) {
    // Through the policy, which refuses a retention the site's limits could not be
    // measured over; the form has asked it the same question, so this is not
    // expected to refuse, and a refusal is shown rather than swallowed.
    try {
        $problems = (new retention_policy($DB))->save(
            max(0, (int) $data->logretentiondays),
            max(0, (int) $data->summaryretentiondays),
        );
    } catch (\moodle_exception $e) {
        if ($e->errorcode !== 'limits:error:busy') {
            throw $e;
        }
        $problems = [$e->getMessage()];
    }
    if ($problems) {
        redirect(
            new moodle_url($url, ['days' => $days]),
            implode(' ', $problems),
            null,
            \core\output\notification::NOTIFY_ERROR,
        );
    }
    set_config(budget_notifier::ENABLED_SETTING, empty($data->budgetnotify) ? 0 : 1, 'local_airouter');
    set_config(
        budget_notifier::SHARE_SETTING,
        min(99, max(0, (int) $data->budgetnotifyshare)),
        'local_airouter',
    );
    redirect(
        new moodle_url($url, ['days' => $days]),
        get_string('usage:saved', 'local_airouter'),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

$retention = new retention_policy($DB);
$form->set_data([
    'logretentiondays' => $retention->get_detail_days(),
    'summaryretentiondays' => $retention->get_summary_days(),
    'budgetnotify' => budget_notifier::is_enabled() ? 1 : 0,
    'budgetnotifyshare' => budget_notifier::get_share(),
]);

// Read from the request and attempt records: the summary for what has been counted,
// the detail for what has not, each fact once. The budget bars on the course page
// still read the older record until the ledger is moved across too.
$report = new reader($DB);
$now = time();
$from = summariser::add_days(summariser::day_of($now), -($days - 1));

// Money is shown by provider throughout, each provider in the currency it bills in:
// the headline, the chart and the tables lay the providers side by side and none of
// them adds across providers. Whether to add them is the site's decision, since a
// budget can be one figure for all AI or one per provider, and the table by provider
// is what it adds up by hand when it is one.
$series = $report->get_series($from, $now, null, $keysource);
$totals = reader::total($series);
$byprovider = $report->get_breakdown(reader::BY_PROVIDER, $from, $now, null, $keysource);
$bytarget = $report->get_breakdown(reader::BY_TARGET, $from, $now, null, $keysource);
$byaction = $report->get_breakdown(reader::BY_ACTION, $from, $now, null, $keysource);
$bymodel = $report->get_breakdown(reader::BY_MODEL, $from, $now, null, $keysource);
$reasons = $report->get_failure_reasons($from, $now, null, $keysource);
// What the chosen payer leaves out, so that a filtered screen is never mistaken for the
// whole of what the site did.
$bykeysource = $report->get_breakdown(reader::BY_KEYSOURCE, $from, $now);

echo $OUTPUT->header();
echo admin_page::back_button($url);
echo $OUTPUT->box(get_string('usage:intro', 'local_airouter'));

// The filters sit in one row with a gap between them. A single_select carries no
// margin of its own, so two of them rendered one after another end up separated by
// a single space, and the last one touches whatever follows. These are the utility
// classes the single_select template itself uses for the label and the menu inside.
echo html_writer::start_div('d-flex flex-wrap align-items-center gap-3 mb-3 aiprovider-router-filters');

$options = [];
foreach ($periods as $period) {
    $options[$period] = get_string('usage:period:days', 'local_airouter', $period);
}
echo $OUTPUT->single_select(
    new moodle_url($url, ['keysource' => $keysource]),
    'days',
    $options,
    $days,
    null,
    null,
    ['label' => get_string('usage:period', 'local_airouter')],
);

$payers = [];
foreach ($keysources as $payer) {
    $payers[$payer] = get_string('keysource:' . $payer, 'local_airouter');
}
echo $OUTPUT->single_select(
    new moodle_url($url, ['days' => $days]),
    'keysource',
    $payers,
    $keysource,
    null,
    null,
    ['label' => get_string('usage:keysource', 'local_airouter')],
);

echo html_writer::end_div();

if ((int) $totals->requests === 0) {
    echo $OUTPUT->notification(get_string('usage:none', 'local_airouter'), 'info');
    echo usage_formatter::elsewhere($bykeysource, $keysource);
} else {
    echo usage_formatter::totals($totals);
    echo usage_formatter::elsewhere($bykeysource, $keysource);

    echo $OUTPUT->heading(get_string('usage:table:byprovider', 'local_airouter'), 3);
    echo html_writer::div(get_string('usage:table:byprovider_intro', 'local_airouter'), 'text-muted');
    echo html_writer::table(usage_formatter::provider_table($byprovider));

    echo $OUTPUT->heading(get_string('usage:chart:daily', 'local_airouter'), 3);
    echo $OUTPUT->render_chart(usage_formatter::daily_chart($series));

    echo $OUTPUT->heading(get_string('usage:chart:bytarget', 'local_airouter'), 3);
    echo $OUTPUT->render_chart(usage_formatter::breakdown_chart(
        $bytarget,
        fn($row) => usage_formatter::target_name($row),
    ));

    echo $OUTPUT->heading(get_string('usage:chart:byaction', 'local_airouter'), 3);
    echo $OUTPUT->render_chart(usage_formatter::breakdown_chart(
        $byaction,
        fn($row) => usage_formatter::action_name($row),
    ));

    echo $OUTPUT->heading(get_string('usage:table:bymodel', 'local_airouter'), 3);
    echo html_writer::table(usage_formatter::model_table($bymodel));
}

echo $OUTPUT->heading(get_string('usage:passthrough', 'local_airouter'), 3);
echo usage_formatter::passthrough($report->get_passthrough($from, $now));

echo $OUTPUT->heading(get_string('usage:reasons', 'local_airouter'), 3);
if (!$reasons) {
    echo html_writer::div(get_string('usage:reasons:none', 'local_airouter'), 'text-muted');
} else {
    echo html_writer::table(usage_formatter::reason_table($reasons));
}

// Reasons come from the detail rows alone, so the period they can speak for ends where
// the purge has reached. Saying so costs one line and saves a wrong conclusion.
$detailfrom = $report->get_detail_from();
if ($detailfrom !== null && $detailfrom > $from) {
    echo html_writer::div(
        get_string('usage:reasons:since', 'local_airouter', userdate($detailfrom)),
        'text-muted',
    );
}

if (has_capability('local/airouter:viewuserusage', $context)) {
    // The question this page deliberately does not answer.
    echo $OUTPUT->single_button(
        new moodle_url('/local/airouter/userusage.php'),
        get_string('report:heading', 'local_airouter'),
        'get',
    );
}

echo $OUTPUT->heading(get_string('usage:settings', 'local_airouter'), 3);
echo html_writer::div(get_string('usage:settings_intro', 'local_airouter'));
$form->display();

echo $OUTPUT->footer();
