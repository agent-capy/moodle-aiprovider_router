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
use aiprovider_router\order_inspector;
use aiprovider_router\provider;
use aiprovider_router\routing_manager;

$unmanage = optional_param('unmanage', '', PARAM_ALPHANUMEXT);
$confirmed = optional_param('confirmactions', null, PARAM_RAW);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/ai/provider/router/managed.php');
admin_page::setup($PAGE, $url, get_string('managed:heading', 'aiprovider_router'));

$manager = \core\di::get(\core_ai\manager::class);

// Whether a request for an action would reach the router right now. This is the same
// question the router itself asks when a request arrives, asked through the same
// method, so that what this screen promises and what happens cannot differ.
$answerable = function (string $actionclass) use ($manager): bool {
    return $manager instanceof routing_manager && $manager->find_router($actionclass) !== null;
};

// Taking one action back out, from the warning that says it is stuck. Reached from
// here and from the status check, which is where an administrator notices.
if ($unmanage !== '') {
    require_sesskey();
    $keep = array_values(array_filter(
        managed_policy::managed_actions(),
        static fn(string $action): bool => $action::get_basename() !== $unmanage,
    ));
    managed_policy::set_managed_actions($keep);

    redirect(
        $url,
        get_string('managed:unmanaged', 'aiprovider_router', $unmanage),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

// Store the chosen list of action class names and return to this page.
$save = function (array $chosen) use ($url): never {
    managed_policy::set_managed_actions($chosen);

    redirect(
        $url,
        get_string('managed:saved', 'aiprovider_router'),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
};

// Coming back from the confirmation below, with the same list that was asked about.
if ($confirmed !== null) {
    require_sesskey();
    $wanted = array_filter(array_map('trim', explode(',', $confirmed)));
    $chosen = array_values(array_filter(
        array_map(static fn(string $action): string => ltrim($action, '\\'), provider::get_action_list()),
        static fn(string $action): bool => in_array($action::get_basename(), $wanted, true),
    ));
    $save($chosen);
}

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

    // Choosing an action the router cannot answer stops that action working across the
    // site, which is the intended behaviour and an easy thing to do by accident. It is
    // worth one question before it takes effect rather than an error report afterwards.
    $stuck = array_values(array_filter($chosen, static fn(string $action): bool => !$answerable($action)));
    if ($stuck !== []) {
        $names = implode(', ', array_map(static fn(string $action): string => $action::get_name(), $stuck));
        $basenames = implode(',', array_map(static fn(string $action): string => $action::get_basename(), $chosen));

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('managed:heading', 'aiprovider_router'));
        echo $OUTPUT->confirm(
            get_string('managed:confirmstuck', 'aiprovider_router', $names),
            new moodle_url($url, ['confirmactions' => $basenames, 'sesskey' => sesskey()]),
            $url,
        );
        echo $OUTPUT->footer();
        die;
    }

    $save($chosen);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managed:heading', 'aiprovider_router'));

// An action brought to a router that cannot answer it is refused, which is the point,
// but an administrator arriving here should be told, and given the way out in the same
// place rather than having to work out which checkbox caused it.
foreach (managed_policy::managed_actions() as $action) {
    if ($answerable($action)) {
        continue;
    }
    echo $OUTPUT->notification(
        get_string('managed:unreachable', 'aiprovider_router', $action::get_name()) . ' ' . \html_writer::link(
            new moodle_url($url, ['unmanage' => $action::get_basename(), 'sesskey' => sesskey()]),
            get_string('managed:unmanage', 'aiprovider_router'),
        ),
        \core\output\notification::NOTIFY_WARNING,
    );
}

// Running alongside other providers means letting them answer what no rule claimed.
// An action placed under the router cannot do that, so the two settings are asking for
// opposite things and an administrator should hear it from the screen.
$router = (new order_inspector())->get_primary_router();
if ($router !== null && $router->get_mode() === provider::MODE_COEXIST && managed_policy::is_active()) {
    echo $OUTPUT->notification(
        get_string('managed:coexist', 'aiprovider_router'),
        \core\output\notification::NOTIFY_WARNING,
    );
}

$form->display();

echo $OUTPUT->footer();
