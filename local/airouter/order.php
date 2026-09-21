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
 * Shows where the AI Router sits in the site's provider order, and fixes it on request.
 *
 * This is the only place in the plugin that writes core_ai's provider_order. The status
 * checks and the provider settings form both link here rather than acting themselves, so
 * that a setting belonging to the whole site is only ever changed from one screen, after
 * the administrator has seen what the change would be.
 *
 * The page is not registered in the admin tree. Core calls load_settings() for aiplacement
 * plugins only, never for aiprovider ones, so an aiprovider plugin's settings.php is never
 * read on Moodle 5.0 or 5.2. It is reached from the site status report and from the
 * router's own settings form instead.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\admin_page;
use local_airouter\order_formatter;
use local_airouter\order_inspector;

$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/local/airouter/order.php');
admin_page::setup($PAGE, $url, get_string('order:heading', 'local_airouter'), section: 'local_airouter_order');

$manager = \core\di::get(\core_ai\manager::class);
$inspector = new order_inspector($manager);

$router = $inspector->get_primary_router();
$instances = $inspector->get_instances();
$routerid = $router === null ? null : (int) $router->id;

$targets = [
    'promote' => $inspector->build_promoted_order(),
    'clean' => $inspector->build_cleaned_order(),
];

// Apply. Reached only by POST from the confirmation screen below, with a session key.
if ($action !== '' && $confirm) {
    require_sesskey();
    // The session key alone would also be satisfied by a link, and this writes site
    // configuration, so the confirmation form's POST is required as well.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new moodle_exception('order:cannotapply', 'local_airouter');
    }
    if (!isset($targets[$action]) || $router === null) {
        throw new moodle_exception('order:cannotapply', 'local_airouter');
    }

    // Writing through set_provider_config() records the change in the config log, which a
    // plain set_config() would not. This is a site wide setting that an administrator may
    // later have to account for.
    $manager->set_provider_config(['provider_order' => $targets[$action]], 'core_ai');

    redirect(
        $url,
        get_string('order:applied', 'local_airouter'),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

echo $OUTPUT->header();

if ($router === null) {
    echo $OUTPUT->notification(get_string('check:norouter', 'local_airouter'), 'info');
    echo $OUTPUT->single_button(
        new moodle_url('/admin/settings.php', ['section' => 'aiprovider']),
        get_string('check:singleinstance:manage', 'local_airouter'),
        'get',
    );
    echo $OUTPUT->footer();
    die;
}

// Confirmation screen. A site wide setting is never one click away, and the value before
// and after is spelled out entry by entry rather than as two lists of numbers.
if ($action !== '') {
    if (!isset($targets[$action])) {
        throw new moodle_exception('order:cannotapply', 'local_airouter');
    }

    echo $OUTPUT->heading(get_string('order:confirm:' . $action, 'local_airouter'), 3);
    echo $OUTPUT->box(get_string('order:confirm:' . $action . '_help', 'local_airouter'));

    echo $OUTPUT->heading(get_string('order:before', 'local_airouter'), 4);
    echo order_formatter::render($inspector->get_entries(), $instances, $routerid);
    echo $OUTPUT->heading(get_string('order:after', 'local_airouter'), 4);
    echo order_formatter::render(explode(',', $targets[$action]), $instances, $routerid);

    // Core's single_button posts and adds the session key itself, so the confirmation uses
    // core's own markup rather than a hand built form that would have to repeat both.
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => $action, 'confirm' => 1]),
        get_string('order:apply', 'local_airouter'),
        'post',
    );
    echo $OUTPUT->single_button($url, get_string('cancel'), 'get');
    echo $OUTPUT->footer();
    die;
}

echo admin_page::back_button($url);

// Overview. Same columns as the site status report, so that an administrator arriving from
// there is not asked to read a second, differently shaped table.
echo $OUTPUT->heading(get_string('order:checks', 'local_airouter'), 3);

$table = new html_table();
$table->head = [get_string('status'), get_string('check'), get_string('summary')];
$table->attributes['class'] = 'admintable generaltable';
foreach (local_airouter_status_checks() as $check) {
    $result = $check->get_result();
    $summary = $result->get_summary();
    if ($result->get_details() !== '') {
        $summary .= html_writer::div($result->get_details(), 'text-muted');
    }
    $table->data[] = [$OUTPUT->check_result($result), $check->get_name(), $summary];
}
echo html_writer::table($table);

echo $OUTPUT->heading(get_string('order:current', 'local_airouter'), 3);
echo order_formatter::render($inspector->get_entries(), $instances, $routerid);

echo $OUTPUT->heading(get_string('order:actions', 'local_airouter'), 3);

$offered = false;
if (!$inspector->is_router_first()) {
    echo $OUTPUT->box(get_string('order:promote_help', 'local_airouter'));
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'promote']),
        get_string('order:promote', 'local_airouter'),
        'get',
    );
    $offered = true;
}
if ($inspector->get_stale_entries()) {
    echo $OUTPUT->box(get_string('order:clean_help', 'local_airouter'));
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'clean']),
        get_string('order:clean', 'local_airouter'),
        'get',
    );
    $offered = true;
}
if (!$offered) {
    echo $OUTPUT->notification(get_string('order:nothingtodo', 'local_airouter'), 'success');
}

echo $OUTPUT->footer();
