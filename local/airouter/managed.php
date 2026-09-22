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
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\admin_page;
use local_airouter\form\managed_actions_form;
use local_airouter\managed_policy;
use local_airouter\order_inspector;
use local_airouter\provider;
use local_airouter\routing_manager;

// Action class names, which carry separators. Nothing is built from these: they
// are only compared against lists this plugin holds, and anything that matches
// nothing falls out.
$unmanage = optional_param('unmanage', '', PARAM_RAW_TRIMMED);
$confirmed = optional_param('confirmactions', null, PARAM_RAW);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/local/airouter/managed.php');
admin_page::setup($PAGE, $url, get_string('managed:heading', 'local_airouter'), section: 'local_airouter_managed');

$manager = \core\di::get(\core_ai\manager::class);

// Whether a request for an action would reach the router right now. This is the same
// question the router itself asks when a request arrives, asked through the same
// method, so that what this screen promises and what happens cannot differ.
$answerable = function (string $actionclass) use ($manager): bool {
    return $manager instanceof routing_manager && $manager->find_router($actionclass) !== null;
};

// Whether it would reach the router once the choice below is saved, which is a
// different question and the one to ask before saving. Without a stored instance the
// router answers what the policy says, so an action being added for the first time is
// not one it answers yet -- and saying so would mean warning about every correct
// choice until the warning stopped being read.
$wouldanswer = function (string $actionclass, array $policy) use ($manager): bool {
    return $manager instanceof routing_manager && $manager->would_answer($actionclass, $policy);
};

// Taking one action back out, from the warning that says it is stuck. Reached from
// here and from the status check, which is where an administrator notices.
if ($unmanage !== '') {
    require_sesskey();
    $keep = array_values(array_filter(
        managed_policy::managed_actions(),
        static fn(string $action): bool => $action !== ltrim($unmanage, '\\'),
    ));
    managed_policy::set_managed_actions($keep);

    redirect(
        $url,
        get_string('managed:unmanaged', 'local_airouter', managed_policy::basename_for(ltrim($unmanage, '\\'))),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

// An action the router no longer declares has no checkbox on the form below, so
// saving the form would drop it and quietly hand its requests back to the provider
// order. It stays until somebody takes it out on purpose, which is what the warning
// above the form is for.
$keepunsupported = static function (array $chosen): array {
    return array_values(array_unique(array_merge($chosen, managed_policy::unsupported_actions())));
};

// Store the chosen list of action class names and return to this page.
$save = function (array $chosen) use ($url): never {
    managed_policy::set_managed_actions($chosen);

    redirect(
        $url,
        get_string('managed:saved', 'local_airouter'),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
};

// Coming back from the confirmation below, with the same list that was asked about.
if ($confirmed !== null) {
    require_sesskey();
    $wanted = array_filter(array_map(
        static fn(string $action): string => ltrim(trim($action), '\\'),
        explode(',', $confirmed),
    ));
    $chosen = array_values(array_filter(
        managed_policy::declared_actions(),
        static fn(string $action): bool => in_array($action, $wanted, true),
    ));
    $save($keepunsupported($chosen));
}

$form = new managed_actions_form($url);

if ($form->is_cancelled()) {
    redirect($url);
}

if ($data = $form->get_data()) {
    $chosen = [];
    foreach (managed_policy::declared_actions() as $action) {
        if (!empty($data->{managed_actions_form::field_name($action)})) {
            $chosen[] = $action;
        }
    }

    // Choosing an action the router cannot answer stops that action working across the
    // site, which is the intended behaviour and an easy thing to do by accident. It is
    // worth one question before it takes effect rather than an error report afterwards.
    $stuck = array_values(array_filter(
        $chosen,
        static fn(string $action): bool => !$wouldanswer($action, $chosen),
    ));
    if ($stuck !== []) {
        $names = implode(', ', array_map(static fn(string $action): string => $action::get_name(), $stuck));
        $classes = implode(',', $chosen);

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('managed:heading', 'local_airouter'));
        echo $OUTPUT->confirm(
            get_string('managed:confirmstuck', 'local_airouter', $names),
            new moodle_url($url, ['confirmactions' => $classes, 'sesskey' => sesskey()]),
            $url,
        );
        echo $OUTPUT->footer();
        die;
    }

    $save($keepunsupported($chosen));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managed:heading', 'local_airouter'));

// An action brought to a router that cannot answer it is refused, which is the point,
// but an administrator arriving here should be told, and given the way out in the same
// place rather than having to work out which checkbox caused it.
foreach (managed_policy::managed_actions() as $action) {
    if ($answerable($action)) {
        continue;
    }
    echo $OUTPUT->notification(
        get_string('managed:unreachable', 'local_airouter', managed_policy::label_for($action))
        . ' ' . \html_writer::link(
            new moodle_url($url, ['unmanage' => $action, 'sesskey' => sesskey()]),
            get_string('managed:unmanage', 'local_airouter'),
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
        get_string('managed:coexist', 'local_airouter'),
        \core\output\notification::NOTIFY_WARNING,
    );
}

$form->display();

echo $OUTPUT->footer();
