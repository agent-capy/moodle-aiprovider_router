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
 * How much AI one course used, and which provider answered.
 *
 * Deliberately smaller than the site page. A teacher's question is whether the AI in
 * their course is working and how much of it there is, so this answers that and stops.
 * What it cost is a matter for whoever pays the bill, and who asked what is nobody's
 * business here: neither is shown.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\rule_repository;
use local_airouter\spend_ledger;
use local_airouter\usage_aggregator;
use local_airouter\usage_formatter;
use local_airouter\record\reader;
use local_airouter\record\summariser;

$courseid = required_param('id', PARAM_INT);
$days = optional_param('days', 30, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/airouter:viewusage', $context);

$url = new moodle_url('/local/airouter/courseusage.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('courseusage:heading', 'local_airouter'));
$PAGE->set_heading(format_string($course->fullname));

$periods = [7, 30, 90, 365];
if (!in_array($days, $periods, true)) {
    $days = 30;
}

// The figures come from the request and attempt records, each finished fact once.
// The budget bars below still read the older record until the ledger has moved.
$aggregator = new usage_aggregator($DB);
$report = new reader($DB);
$now = time();
$from = summariser::add_days(summariser::day_of($now), -($days - 1));

$series = $report->get_series($from, $now, $course->id);
$totals = reader::total($series);
$bytarget = $report->get_breakdown(reader::BY_TARGET, $from, $now, $course->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('courseusage:heading', 'local_airouter'));
echo $OUTPUT->box(get_string('courseusage:intro', 'local_airouter'));

$options = [];
foreach ($periods as $period) {
    $options[$period] = get_string('usage:period:days', 'local_airouter', $period);
}
// Spaced the same way as the other two report screens. A single_select carries no
// margin of its own, so without this it touches whatever follows it.
echo html_writer::start_div('d-flex flex-wrap align-items-center gap-3 mb-3 aiprovider-router-filters');
echo $OUTPUT->single_select($url, 'days', $options, $days, null, null, [
    'label' => get_string('usage:period', 'local_airouter'),
]);
echo html_writer::end_div();

// How close this course is to a budget the site has set on it. A share and not a
// figure: what the site spends is not a teacher's business, but how near the course is
// to the point where its AI starts behaving differently certainly is.
$ledger = new spend_ledger($DB, $aggregator, false);
$bars = [];
foreach ((new rule_repository($DB))->get_budgets($now) as $budget) {
    if ($budget->scope !== spend_ledger::SCOPE_COURSE) {
        continue;
    }
    if ($budget->courseids !== null && !in_array((int) $course->id, $budget->courseids, true)) {
        // A budget set by a rule about other courses. It could never have restricted
        // this one, so a bar for it here would describe something that is not
        // happening in this course.
        continue;
    }
    [$budgetfrom, $budgetto] = $ledger->get_window($budget->period, $budget->days, $now);
    $spend = $ledger->measure(spend_ledger::SCOPE_COURSE, (int) $course->id, $budgetfrom, $budgetto);
    if (!$spend->is_known($budget->metric)) {
        // Nothing this site can price, so there is no share to show. Saying nothing is
        // better than a bar at zero, which would read as plenty of room.
        continue;
    }
    $requests = $budget->metric === spend_ledger::METRIC_REQUESTS;
    $note = get_string(
        'courseusage:budget:' . ($budget->period === spend_ledger::PERIOD_MONTH ? 'month' : 'rolling'),
        'local_airouter',
        $budget->days,
    );
    if ($requests) {
        // A budget counted in requests can be shown in full. What is kept off this page
        // is what the site pays, and how many requests this course made is the page's
        // own subject, printed a few lines further down.
        $note = get_string('courseusage:budget:used', 'local_airouter', [
            'used' => number_format($spend->requests),
            'limit' => number_format($budget->amount),
        ]) . ' ' . $note;
    }
    $bars[] = usage_formatter::progress(
        $spend->get_measure($budget->metric) / $budget->amount,
        get_string($requests ? 'usage:budget:label:requests' : 'usage:budget:label', 'local_airouter'),
        $note,
    );
}
if ($bars) {
    echo $OUTPUT->heading(get_string('courseusage:budget', 'local_airouter'), 3);
    echo html_writer::div(get_string('courseusage:budget:intro', 'local_airouter'), 'text-muted');
    echo implode('', $bars);
}

if ((int) $totals->requests === 0) {
    echo $OUTPUT->notification(get_string('usage:none', 'local_airouter'), 'info');
} else {
    echo html_writer::div(
        get_string('courseusage:total', 'local_airouter', (object) [
            'requests' => number_format((int) $totals->requests),
            'failures' => number_format((int) $totals->failures),
        ]),
        'lead',
    );

    echo $OUTPUT->heading(get_string('usage:chart:daily', 'local_airouter'), 3);
    echo $OUTPUT->render_chart(usage_formatter::course_chart($series));

    echo $OUTPUT->heading(get_string('usage:chart:bytarget', 'local_airouter'), 3);
    echo $OUTPUT->render_chart(usage_formatter::breakdown_chart(
        $bytarget,
        fn($row) => usage_formatter::target_name($row),
    ));
}

echo $OUTPUT->footer();
