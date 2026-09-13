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
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_router\usage_aggregator;
use aiprovider_router\usage_formatter;
use aiprovider_router\usage_report;

$courseid = required_param('id', PARAM_INT);
$days = optional_param('days', 30, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('aiprovider/router:viewusage', $context);

$url = new moodle_url('/ai/provider/router/courseusage.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('courseusage:heading', 'aiprovider_router'));
$PAGE->set_heading(format_string($course->fullname));

$periods = [7, 30, 90, 365];
if (!in_array($days, $periods, true)) {
    $days = 30;
}

$aggregator = new usage_aggregator($DB);
$report = new usage_report($DB, $aggregator);
$now = time();
$from = $aggregator->add_days($aggregator->day_of($now), -($days - 1));

$series = $report->get_series($from, $now, $course->id);
$totals = usage_report::total($series);
$bytarget = $report->get_breakdown(usage_report::BY_TARGET, $from, $now, $course->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('courseusage:heading', 'aiprovider_router'));
echo $OUTPUT->box(get_string('courseusage:intro', 'aiprovider_router'));

$options = [];
foreach ($periods as $period) {
    $options[$period] = get_string('usage:period:days', 'aiprovider_router', $period);
}
echo $OUTPUT->single_select($url, 'days', $options, $days, null, null, [
    'label' => get_string('usage:period', 'aiprovider_router'),
]);

if ((int) $totals->requests === 0) {
    echo $OUTPUT->notification(get_string('usage:none', 'aiprovider_router'), 'info');
} else {
    echo html_writer::div(
        get_string('courseusage:total', 'aiprovider_router', (object) [
            'requests' => number_format((int) $totals->requests),
            'failures' => number_format((int) $totals->failures),
        ]),
        'lead',
    );

    echo $OUTPUT->heading(get_string('usage:chart:daily', 'aiprovider_router'), 3);
    echo $OUTPUT->render_chart(usage_formatter::course_chart($series));

    echo $OUTPUT->heading(get_string('usage:chart:bytarget', 'aiprovider_router'), 3);
    echo $OUTPUT->render_chart(usage_formatter::breakdown_chart(
        $bytarget,
        fn($row) => usage_formatter::target_name($row),
    ));
}

echo $OUTPUT->footer();
