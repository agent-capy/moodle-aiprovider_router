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
 * Chooses which actions Moodle must bring to the router, whatever the provider order says.
 *
 * Not registered in the admin tree, for the reason the other pages here are not: core
 * never reads an aiprovider plugin's settings.php.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_router\admin_page;
use aiprovider_router\form\managed_actions_form;
use aiprovider_router\managed_policy;
use aiprovider_router\provider;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/ai/provider/router/managed.php');
admin_page::setup($PAGE, $url, get_string('managed:heading', 'aiprovider_router'));

$form = new managed_actions_form($url);

if ($form->is_cancelled()) {
    redirect($url);
}

if ($data = $form->get_data()) {
    $chosen = [];
    foreach (provider::get_action_list() as $action) {
        if (!empty($data->{managed_actions_form::field_name(ltrim($action, '\\'))})) {
            $chosen[] = ltrim($action, '\\');
        }
    }
    managed_policy::set_managed_actions($chosen);

    redirect(
        $url,
        get_string('managed:saved', 'aiprovider_router'),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managed:heading', 'aiprovider_router'));

// An action brought to a router that cannot answer it is refused, which is the point,
// but an administrator arriving here should be told before it happens rather than
// after. The same question the status check asks, asked at the moment of choosing.
$manager = \core\di::get(\core_ai\manager::class);
foreach (managed_policy::managed_actions() as $action) {
    if ($manager instanceof \aiprovider_router\routing_manager && $manager->find_router($action) !== null) {
        continue;
    }
    echo $OUTPUT->notification(
        get_string('managed:unreachable', 'aiprovider_router', $action::get_name()),
        \core\output\notification::NOTIFY_WARNING,
    );
}

$form->display();

echo $OUTPUT->footer();
