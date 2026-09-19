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
 * Who used the AI, and what it cost.
 *
 * The dashboard adds everybody together, because what the site spent is the question
 * that usually needs asking. This is the other one, asked when somebody has to account
 * for the spending by name, and it is kept behind a capability of its own rather than
 * folded into the ordinary monitor.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_router\key;
use aiprovider_router\price_book;
use aiprovider_router\target_resolver;
use aiprovider_router\usage_aggregator;
use aiprovider_router\user_report;
use aiprovider_router\user_report_formatter;

$days = optional_param('days', 30, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

require_login();
$context = context_system::instance();
require_capability('aiprovider/router:viewuserusage', $context);

$url = new moodle_url('/ai/provider/router/userusage.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('report:heading', 'aiprovider_router'));
$PAGE->set_heading(get_string('report:heading', 'aiprovider_router'));
$PAGE->navbar->add(get_string('pluginname', 'aiprovider_router'));
$PAGE->navbar->add(get_string('report:heading', 'aiprovider_router'), $url);

$periods = [7, 30, 90, 365];
if (!in_array($days, $periods, true)) {
    $days = 30;
}

$aggregator = new usage_aggregator($DB);
$report = new user_report($DB, $aggregator);
$currency = price_book::get_currency();
$now = time();
$from = $aggregator->add_days($aggregator->day_of($now), -($days - 1));

$people = $report->get_people($from, $now);
$names = user_report::get_names(array_column($people, 'userid'));

if ($download !== '') {
    require_sesskey();
    // Sent as a file because that is the form these figures leave in: somebody has been
    // asked to produce a report, and it will be read outside Moodle.
    \core\dataformat::download_data(
        'ai-router-usage-' . userdate($now, '%Y%m%d'),
        $download,
        user_report_formatter::export_columns(),
        user_report_formatter::people_rows($people, $names, $currency),
    );
    die;
}

echo $OUTPUT->header();
echo $OUTPUT->box(get_string('report:intro', 'aiprovider_router'));
// Said once, at the top, because every cost on this page has it and a figure quoted
// elsewhere without it would be read as a bill.
echo $OUTPUT->notification(get_string('report:estimate', 'aiprovider_router'), 'info');

$options = [];
foreach ($periods as $period) {
    $options[$period] = get_string('usage:period:days', 'aiprovider_router', $period);
}
echo $OUTPUT->single_select(
    new moodle_url($url, $userid ? ['userid' => $userid] : []),
    'days',
    $options,
    $days,
    null,
    null,
    ['label' => get_string('usage:period', 'aiprovider_router')],
);

if ($userid > 0) {
    // One person. Their name is the heading, so there is no doubt whose figures these
    // are once the page has been printed or pasted somewhere.
    $person = \core_user::get_user($userid);
    echo $OUTPUT->heading(
        $person ? fullname($person) : get_string('report:goneuser', 'aiprovider_router', $userid),
        3,
    );
    echo html_writer::div(html_writer::link(
        new moodle_url($url, ['days' => $days]),
        get_string('report:back', 'aiprovider_router'),
    ));

    $perdays = $report->get_days($userid, $from, $now);
    if (!$perdays) {
        echo $OUTPUT->notification(get_string('usage:none', 'aiprovider_router'), 'info');
    } else {
        echo $OUTPUT->heading(get_string('report:bydays', 'aiprovider_router'), 4);
        echo html_writer::table(user_report_formatter::days($perdays, $currency));
    }

    $requests = $report->get_requests($userid, $from, $now);
    echo $OUTPUT->heading(get_string('report:requests', 'aiprovider_router'), 4);
    // Individual requests are the one thing here with nothing to fall back on: the
    // summaries say what a day held, not at what time, so this list stops where the
    // detail has been purged.
    echo html_writer::div(get_string('report:requests_intro', 'aiprovider_router'), 'text-muted');
    if (!$requests) {
        echo $OUTPUT->notification(get_string('report:norequests', 'aiprovider_router'), 'info');
    } else {
        if (count($requests) >= user_report::MAX_REQUESTS) {
            echo $OUTPUT->notification(
                get_string('report:truncated', 'aiprovider_router', user_report::MAX_REQUESTS),
                'warning',
            );
        }
        echo html_writer::table(user_report_formatter::requests($requests, $currency));
    }

    echo $OUTPUT->footer();
    die;
}

if (!$people) {
    echo $OUTPUT->notification(get_string('usage:none', 'aiprovider_router'), 'info');
} else {
    echo $OUTPUT->heading(get_string('report:bypeople', 'aiprovider_router'), 3);
    echo html_writer::table(user_report_formatter::people($people, $names, $currency, $url));

    echo html_writer::div(implode(' ', array_map(
        fn($format) => html_writer::link(
            new moodle_url($url, ['days' => $days, 'download' => $format, 'sesskey' => sesskey()]),
            get_string('report:download', 'aiprovider_router', strtoupper($format)),
        ),
        array_keys(\core_plugin_manager::instance()->get_enabled_plugins('dataformat') ?: []),
    )));
}

$holders = $report->get_key_holders();
echo $OUTPUT->heading(get_string('report:holders', 'aiprovider_router'), 3);
if (!$holders) {
    echo html_writer::div(get_string('report:noholders', 'aiprovider_router'), 'text-muted');
} else {
    $courseids = [];
    $holderids = [];
    foreach ($holders as $holder) {
        if ((string) $holder->scope === key::SCOPE_COURSE) {
            $courseids[] = (int) $holder->scopeid;
        } else {
            $holderids[] = (int) $holder->scopeid;
        }
    }
    $courses = [];
    if ($courseids) {
        foreach ($DB->get_records_list('course', 'id', $courseids, '', 'id, fullname') as $course) {
            $courses[(int) $course->id] = format_string($course->fullname);
        }
    }
    echo html_writer::table(user_report_formatter::holders(
        $holders,
        $report->get_key_usage($from, $now),
        user_report::get_names($holderids),
        $courses,
        target_resolver::get_delegation_targets(),
        $currency,
    ));
}

echo $OUTPUT->footer();
