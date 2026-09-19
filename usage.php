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
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_router\form\usage_settings_form;
use aiprovider_router\price_book;
use aiprovider_router\rule;
use aiprovider_router\usage_aggregator;
use aiprovider_router\usage_formatter;
use aiprovider_router\usage_report;

$days = optional_param('days', 30, PARAM_INT);
$keysource = optional_param('keysource', rule::KEYSOURCE_SITE, PARAM_ALPHA);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/ai/provider/router/usage.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('usage:heading', 'aiprovider_router'));
$PAGE->set_heading(get_string('usage:heading', 'aiprovider_router'));
$PAGE->navbar->add(get_string('pluginname', 'aiprovider_router'));
$PAGE->navbar->add(get_string('usage:heading', 'aiprovider_router'), $url);

$periods = [7, 30, 90, 365];
if (!in_array($days, $periods, true)) {
    $days = 30;
}

// What the site spent is the question this screen exists to answer, so it is the one
// asked first. Money somebody paid out of their own pocket is real and is recorded, but
// adding it in would produce a figure that is nobody's expenditure.
$keysources = array_merge(rule::get_keysources(), [usage_report::KEYSOURCE_ALL]);
if (!in_array($keysource, $keysources, true)) {
    $keysource = rule::KEYSOURCE_SITE;
}

$form = new usage_settings_form($url);
if ($data = $form->get_data()) {
    set_config(usage_aggregator::RETENTION_SETTING, max(0, (int) $data->logretentiondays), 'aiprovider_router');
    set_config(
        usage_aggregator::SUMMARY_RETENTION_SETTING,
        max(0, (int) $data->summaryretentiondays),
        'aiprovider_router',
    );
    redirect(
        new moodle_url($url, ['days' => $days]),
        get_string('usage:saved', 'aiprovider_router'),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

$aggregator = new usage_aggregator($DB);
$form->set_data([
    'logretentiondays' => $aggregator->get_retention_days(),
    'summaryretentiondays' => $aggregator->get_summary_retention_days(),
]);

$report = new usage_report($DB, $aggregator);
$currency = price_book::get_currency();
$now = time();
$from = $aggregator->add_days($aggregator->day_of($now), -($days - 1));

$series = $report->get_series($from, $now, null, $keysource);
$totals = usage_report::total($series);
$bytarget = $report->get_breakdown(usage_report::BY_TARGET, $from, $now, null, $keysource);
$byaction = $report->get_breakdown(usage_report::BY_ACTION, $from, $now, null, $keysource);
$bymodel = $report->get_breakdown(usage_report::BY_MODEL, $from, $now, null, $keysource);
$reasons = $report->get_failure_reasons($from, $now, null, $keysource);
// What the chosen payer leaves out, so that a filtered screen is never mistaken for the
// whole of what the site did.
$bykeysource = $report->get_breakdown(usage_report::BY_KEYSOURCE, $from, $now);

echo $OUTPUT->header();
echo $OUTPUT->box(get_string('usage:intro', 'aiprovider_router'));

$options = [];
foreach ($periods as $period) {
    $options[$period] = get_string('usage:period:days', 'aiprovider_router', $period);
}
echo $OUTPUT->single_select(
    new moodle_url($url, ['keysource' => $keysource]),
    'days',
    $options,
    $days,
    null,
    null,
    ['label' => get_string('usage:period', 'aiprovider_router')],
);

$payers = [];
foreach ($keysources as $payer) {
    $payers[$payer] = get_string('keysource:' . $payer, 'aiprovider_router');
}
echo $OUTPUT->single_select(
    new moodle_url($url, ['days' => $days]),
    'keysource',
    $payers,
    $keysource,
    null,
    null,
    ['label' => get_string('usage:keysource', 'aiprovider_router')],
);

if ((int) $totals->requests === 0) {
    echo $OUTPUT->notification(get_string('usage:none', 'aiprovider_router'), 'info');
    echo usage_formatter::elsewhere($bykeysource, $keysource);
} else {
    echo usage_formatter::totals($totals, $currency);
    echo usage_formatter::elsewhere($bykeysource, $keysource);

    echo $OUTPUT->heading(get_string('usage:chart:daily', 'aiprovider_router'), 3);
    echo $OUTPUT->render_chart(usage_formatter::daily_chart($series, $currency));

    echo $OUTPUT->heading(get_string('usage:chart:bytarget', 'aiprovider_router'), 3);
    echo $OUTPUT->render_chart(usage_formatter::breakdown_chart(
        $bytarget,
        fn($row) => usage_formatter::target_name($row),
    ));

    echo $OUTPUT->heading(get_string('usage:chart:byaction', 'aiprovider_router'), 3);
    echo $OUTPUT->render_chart(usage_formatter::breakdown_chart(
        $byaction,
        fn($row) => usage_formatter::action_name($row),
    ));

    echo $OUTPUT->heading(get_string('usage:table:bymodel', 'aiprovider_router'), 3);
    echo html_writer::table(usage_formatter::model_table($bymodel, $currency));
}

echo $OUTPUT->heading(get_string('usage:passthrough', 'aiprovider_router'), 3);
echo usage_formatter::passthrough($report->get_passthrough($from, $now));

echo $OUTPUT->heading(get_string('usage:reasons', 'aiprovider_router'), 3);
if (!$reasons) {
    echo html_writer::div(get_string('usage:reasons:none', 'aiprovider_router'), 'text-muted');
} else {
    echo html_writer::table(usage_formatter::reason_table($reasons));
}

// Reasons come from the detail rows alone, so the period they can speak for ends where
// the purge has reached. Saying so costs one line and saves a wrong conclusion.
$detailfrom = $report->get_detail_from();
if ($detailfrom !== null && $detailfrom > $from) {
    echo html_writer::div(
        get_string('usage:reasons:since', 'aiprovider_router', userdate($detailfrom)),
        'text-muted',
    );
}

echo $OUTPUT->heading(get_string('usage:settings', 'aiprovider_router'), 3);
echo html_writer::div(get_string('usage:settings_intro', 'aiprovider_router'));
$form->display();

echo $OUTPUT->footer();
