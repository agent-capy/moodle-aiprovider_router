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
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\key;
use local_airouter\price_book;
use local_airouter\target_resolver;
use local_airouter\record\person_reader;
use local_airouter\record\summariser;
use local_airouter\user_report_formatter;

$days = optional_param('days', 30, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

require_login();
$context = context_system::instance();
require_capability('local/airouter:viewuserusage', $context);

$url = new moodle_url('/local/airouter/userusage.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('report:heading', 'local_airouter'));
$PAGE->set_heading(get_string('report:heading', 'local_airouter'));
$PAGE->navbar->add(get_string('pluginname', 'local_airouter'));
$PAGE->navbar->add(get_string('report:heading', 'local_airouter'), $url);

$periods = [7, 30, 90, 365];
if (!in_array($days, $periods, true)) {
    $days = 30;
}

// Read from the request and attempt records, each finished fact once.
$report = new person_reader($DB);
$now = time();
$from = summariser::add_days(summariser::day_of($now), -($days - 1));

$people = $report->get_people($from, $now);
$names = person_reader::get_names(array_column($people, 'userid'));

if ($download !== '') {
    require_sesskey();
    // Sent as a file because that is the form these figures leave in: somebody has been
    // asked to produce a report, and it will be read outside Moodle. Money is a pair of
    // columns per provider the period paid, each in that provider's currency, so that
    // nothing in the file adds across providers and nothing read from it can either.
    $money = user_report_formatter::money_of($people);
    \core\dataformat::download_data(
        'ai-router-usage-' . userdate($now, '%Y%m%d'),
        $download,
        user_report_formatter::export_columns($money),
        user_report_formatter::people_rows($people, $names, $money),
    );
    die;
}

echo $OUTPUT->header();
echo $OUTPUT->box(get_string('report:intro', 'local_airouter'));
// Said once, at the top, because every cost on this page has it and a figure quoted
// elsewhere without it would be read as a bill.
echo $OUTPUT->notification(get_string('report:estimate', 'local_airouter'), 'info');

$options = [];
foreach ($periods as $period) {
    $options[$period] = get_string('usage:period:days', 'local_airouter', $period);
}
// Spaced the same way as the other two report screens. A single_select carries no
// margin of its own, so without this it touches whatever follows it.
echo html_writer::start_div('d-flex flex-wrap align-items-center gap-3 mb-3 aiprovider-router-filters');
echo $OUTPUT->single_select(
    new moodle_url($url, $userid ? ['userid' => $userid] : []),
    'days',
    $options,
    $days,
    null,
    null,
    ['label' => get_string('usage:period', 'local_airouter')],
);
echo html_writer::end_div();

if ($userid > 0) {
    // One person. Their name is the heading, so there is no doubt whose figures these
    // are once the page has been printed or pasted somewhere.
    $person = \core_user::get_user($userid);
    echo $OUTPUT->heading(
        $person ? fullname($person) : get_string('report:goneuser', 'local_airouter', $userid),
        3,
    );
    echo html_writer::div(html_writer::link(
        new moodle_url($url, ['days' => $days]),
        get_string('report:back', 'local_airouter'),
    ));

    $perdays = $report->get_days($userid, $from, $now);
    if (!$perdays) {
        echo $OUTPUT->notification(get_string('usage:none', 'local_airouter'), 'info');
    } else {
        echo $OUTPUT->heading(get_string('report:bydays', 'local_airouter'), 4);
        echo html_writer::table(user_report_formatter::days($perdays));
    }

    $requests = $report->get_requests($userid, $from, $now);
    echo $OUTPUT->heading(get_string('report:requests', 'local_airouter'), 4);
    // Individual requests are the one thing here with nothing to fall back on: the
    // summaries say what a day held, not at what time, so this list stops where the
    // detail has been purged.
    echo html_writer::div(get_string('report:requests_intro', 'local_airouter'), 'text-muted');
    if (!$requests) {
        echo $OUTPUT->notification(get_string('report:norequests', 'local_airouter'), 'info');
    } else {
        if (count($requests) >= person_reader::MAX_REQUESTS) {
            echo $OUTPUT->notification(
                get_string('report:truncated', 'local_airouter', person_reader::MAX_REQUESTS),
                'warning',
            );
        }
        echo html_writer::table(user_report_formatter::requests($requests));
    }

    echo $OUTPUT->footer();
    die;
}

if (!$people) {
    echo $OUTPUT->notification(get_string('usage:none', 'local_airouter'), 'info');
} else {
    echo $OUTPUT->heading(get_string('report:bypeople', 'local_airouter'), 3);
    echo html_writer::table(user_report_formatter::people($people, $names, $url));

    echo html_writer::div(implode(' ', array_map(
        fn($format) => html_writer::link(
            new moodle_url($url, ['days' => $days, 'download' => $format, 'sesskey' => sesskey()]),
            get_string('report:download', 'local_airouter', strtoupper($format)),
        ),
        array_keys(\core_plugin_manager::instance()->get_enabled_plugins('dataformat') ?: []),
    )));
}

$holders = $report->get_key_holders();
echo $OUTPUT->heading(get_string('report:holders', 'local_airouter'), 3);
if (!$holders) {
    echo html_writer::div(get_string('report:noholders', 'local_airouter'), 'text-muted');
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
        person_reader::get_names($holderids),
        $courses,
        target_resolver::get_delegation_targets(),
    ));
}

echo $OUTPUT->footer();
