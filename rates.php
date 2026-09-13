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
 * Sets the rates the monitor costs requests with, and the ratios it estimates tokens by.
 *
 * Both are here because both are rates an administrator maintains, and neither changes
 * where a request goes. Not registered in the admin tree, for the reason the other pages
 * here are not: core never reads an aiprovider plugin's settings.php.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_router\form\price_form;
use aiprovider_router\form\rate_settings_form;
use aiprovider_router\price;
use aiprovider_router\price_book;

$action = optional_param('action', '', PARAM_ALPHA);
$priceid = optional_param('priceid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/ai/provider/router/rates.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('rates:heading', 'aiprovider_router'));
$PAGE->set_heading(get_string('rates:heading', 'aiprovider_router'));
$PAGE->navbar->add(get_string('pluginname', 'aiprovider_router'));
$PAGE->navbar->add(get_string('rates:heading', 'aiprovider_router'), $url);

$book = new price_book($DB);

if ($action === 'delete' && $priceid > 0 && $confirm) {
    require_sesskey();
    $existing = price::get_record(['id' => $priceid]);
    if ($existing) {
        $existing->delete();
    }
    redirect($url, get_string('rates:deleted', 'aiprovider_router'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// The rate editor, reached with an id to change one or with "new" to add one.
if ($action === 'edit') {
    $existing = $priceid > 0 ? price::get_record(['id' => $priceid]) : null;
    $form = new price_form(new moodle_url($url, ['action' => 'edit', 'priceid' => $priceid]));

    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $record = $existing ?? new price();
        $record->set('provider', $data->provider);
        $record->set('model', trim((string) $data->model));
        $record->set('promptrate', price_form::read_rate($data->promptrate));
        $record->set('completionrate', price_form::read_rate($data->completionrate));
        $record->set('imagerate', price_form::read_rate($data->imagerate));
        $record->set('timefrom', (int) $data->timefrom);
        $existing ? $record->update() : $record->create();

        redirect($url, get_string('rates:saved', 'aiprovider_router'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($existing) {
        $form->set_data((object) [
            'id' => $existing->get('id'),
            'provider' => $existing->get('provider'),
            'model' => $existing->get('model'),
            'promptrate' => $existing->get('promptrate'),
            'completionrate' => $existing->get('completionrate'),
            'imagerate' => $existing->get('imagerate'),
            'timefrom' => $existing->get('timefrom'),
        ]);
    } else {
        $form->set_data((object) ['timefrom' => time()]);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($priceid ? 'rates:edit' : 'rates:add', 'aiprovider_router'), 3);
    $form->display();
    echo $OUTPUT->footer();
    die;
}

$settings = new rate_settings_form($url);
if ($data = $settings->get_data()) {
    require_sesskey();
    set_config('currency', strtoupper(trim($data->currency)), 'aiprovider_router');
    set_config('tokenratiocjk', (float) $data->tokenratiocjk, 'aiprovider_router');
    set_config('tokenratioother', (float) $data->tokenratioother, 'aiprovider_router');

    redirect($url, get_string('rates:saved', 'aiprovider_router'), null, \core\output\notification::NOTIFY_SUCCESS);
}
$estimator = new \aiprovider_router\token_estimator();
$settings->set_data((object) [
    'currency' => price_book::get_currency(),
    'tokenratiocjk' => $estimator->get_cjk_ratio(),
    'tokenratioother' => $estimator->get_other_ratio(),
]);

echo $OUTPUT->header();

if ($action === 'delete' && $priceid > 0) {
    echo $OUTPUT->confirm(
        get_string('rates:confirmdelete', 'aiprovider_router'),
        new moodle_url($url, ['action' => 'delete', 'priceid' => $priceid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url,
    );
    echo $OUTPUT->footer();
    die;
}

echo $OUTPUT->box(get_string('rates:intro', 'aiprovider_router'));

echo $OUTPUT->heading(get_string('rates:prices', 'aiprovider_router'), 3);

$prices = $book->get_all();
if (!$prices) {
    echo $OUTPUT->notification(get_string('rates:noprices', 'aiprovider_router'), 'info');
} else {
    $currency = price_book::get_currency();
    $table = new html_table();
    $table->head = [
        get_string('price:provider', 'aiprovider_router'),
        get_string('price:model', 'aiprovider_router'),
        get_string('price:promptrate', 'aiprovider_router', $currency),
        get_string('price:completionrate', 'aiprovider_router', $currency),
        get_string('price:imagerate', 'aiprovider_router', $currency),
        get_string('price:timefrom', 'aiprovider_router'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'admintable generaltable';
    $unset = get_string('rates:unset', 'aiprovider_router');
    foreach ($prices as $id => $record) {
        $links = html_writer::link(
            new moodle_url($url, ['action' => 'edit', 'priceid' => $id]),
            $OUTPUT->pix_icon('t/edit', get_string('edit')),
        ) . ' ' . html_writer::link(
            new moodle_url($url, ['action' => 'delete', 'priceid' => $id]),
            $OUTPUT->pix_icon('t/delete', get_string('delete')),
        );
        $table->data[] = [
            s($record->get('provider')),
            $record->get('model') === '' ? get_string('price:model:any', 'aiprovider_router')
                : s($record->get('model')),
            $record->get('promptrate') === null ? $unset : format_float($record->get('promptrate'), 6, true, true),
            $record->get('completionrate') === null ? $unset
                : format_float($record->get('completionrate'), 6, true, true),
            $record->get('imagerate') === null ? $unset : format_float($record->get('imagerate'), 6, true, true),
            userdate($record->get('timefrom'), get_string('strftimedatetimeshort', 'langconfig')),
            $links,
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->single_button(
    new moodle_url($url, ['action' => 'edit']),
    get_string('rates:add', 'aiprovider_router'),
    'get',
);

echo $OUTPUT->heading(get_string('rates:settings', 'aiprovider_router'), 3);
$settings->display();

echo $OUTPUT->footer();
