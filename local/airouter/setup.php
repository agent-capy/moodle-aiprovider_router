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
 * Where the site stands, step by step, for what the administrator means to do with it.
 *
 * Reads settings only. Nothing on this page changes anything, and no provider is
 * asked anything; every step links to the page where it is done.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\admin_page;
use local_airouter\setup\setup_status;
use local_airouter\setup\setup_step;

$purpose = optional_param('purpose', setup_status::PURPOSE_ROUTE, PARAM_ALPHA);
if (!in_array($purpose, setup_status::purposes(), true)) {
    $purpose = setup_status::PURPOSE_ROUTE;
}

require_login();
require_capability('moodle/site:config', context_system::instance());

$url = new moodle_url('/local/airouter/setup.php', ['purpose' => $purpose]);
admin_page::setup($PAGE, $url, get_string('setup:heading', 'local_airouter'), section: 'local_airouter_setup');

$status = new setup_status($DB, time());

// One step, with its status in words as well as colour, so that nothing depends on
// telling the colours apart.
$renderstep = static function (setup_step $step): string {
    $badge = match ($step->status) {
        setup_step::DONE => 'bg-success',
        setup_step::TODO => 'bg-warning text-dark',
        setup_step::NOT_NEEDED => 'bg-secondary',
        default => 'bg-info text-dark',
    };
    $html = html_writer::tag(
        'h4',
        s($step->get_title()) . ' ' . html_writer::span($step->get_status_label(), 'badge ' . $badge),
        ['class' => 'h5 mt-3'],
    );
    $html .= html_writer::alist($step->lines);
    if ($step->link !== null) {
        $html .= html_writer::div(html_writer::link($step->link, $step->linktext ?? $step->link->out()), 'mb-2');
    }

    return html_writer::div($html, 'local-airouter-setup-step', ['id' => 'local-airouter-setup-' . $step->id]);
};

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setup:heading', 'local_airouter'));
echo html_writer::tag('p', get_string('setup:intro', 'local_airouter'));

$tabs = [];
foreach (setup_status::purposes() as $option) {
    $tabs[] = new tabobject(
        $option,
        new moodle_url('/local/airouter/setup.php', ['purpose' => $option]),
        get_string('setup:purpose:' . $option, 'local_airouter'),
    );
}
echo html_writer::div($OUTPUT->tabtree($tabs, $purpose), '', ['id' => 'local-airouter-setup-purpose']);

echo html_writer::start_div('', ['id' => 'local-airouter-setup-common']);
echo $OUTPUT->heading(get_string('setup:common', 'local_airouter'), 3);
foreach ($status->common_steps() as $step) {
    echo $renderstep($step);
}
echo html_writer::end_div();

echo html_writer::start_div('', ['id' => 'local-airouter-setup-forpurpose']);
$purposename = get_string('setup:purpose:' . $purpose, 'local_airouter');
echo $OUTPUT->heading(get_string('setup:forpurpose', 'local_airouter', $purposename), 3);
foreach ($status->purpose_steps($purpose) as $step) {
    echo $renderstep($step);
}
echo html_writer::end_div();

// The plugin's own status checks, so that what the system status report says is in
// front of whoever is setting the router up.
echo html_writer::start_div('', ['id' => 'local-airouter-setup-checks']);
echo $OUTPUT->heading(get_string('setup:checks', 'local_airouter'), 3);
$table = new html_table();
$table->attributes['class'] = 'admintable generaltable';
$table->head = [
    get_string('setup:checks:check', 'local_airouter'),
    get_string('status'),
    get_string('setup:checks:summary', 'local_airouter'),
];
foreach (local_airouter_status_checks() as $check) {
    $result = $check->get_result();
    $table->data[] = [
        s($check->get_name()),
        $OUTPUT->check_result($result),
        $result->get_summary(),
    ];
}
echo html_writer::table($table);
echo html_writer::div(html_writer::link(
    new moodle_url('/report/status/index.php'),
    get_string('setup:checks:all', 'local_airouter'),
));
echo html_writer::end_div();

echo html_writer::tag('p', get_string('setup:unchecked', 'local_airouter'), ['class' => 'mt-3']);

echo $OUTPUT->footer();
