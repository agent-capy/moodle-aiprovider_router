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
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_airouter\admin_page;
use local_airouter\form\price_form;
use local_airouter\form\rate_settings_form;
use local_airouter\price;
use local_airouter\price_book;

$action = optional_param('action', '', PARAM_ALPHA);
$priceid = optional_param('priceid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/local/airouter/rates.php');
admin_page::setup($PAGE, $url, get_string('rates:heading', 'local_airouter'), section: 'local_airouter_rates');

$book = new price_book($DB);

if ($action === 'delete' && $priceid > 0 && $confirm) {
    require_sesskey();
    $existing = price::get_record(['id' => $priceid]);
    if ($existing) {
        $existing->delete();
    }
    redirect($url, get_string('rates:deleted', 'local_airouter'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// The rate editor, reached with an id to change one or with "new" to add one.
if ($action === 'edit') {
    $existing = $priceid > 0 ? price::get_record(['id' => $priceid]) : null;
    $form = new price_form(new moodle_url($url, ['action' => 'edit', 'priceid' => $priceid]));

    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $currency = price::normalise_currency((string) $data->currency);
        $record = $existing ?? new price();
        $record->set('provider', $data->provider);
        $record->set('model', trim((string) $data->model));
        $record->set('currency', $currency);
        $record->set('promptrate', price_form::read_rate($data->promptrate));
        $record->set('completionrate', price_form::read_rate($data->completionrate));
        $record->set('imagerate', price_form::read_rate($data->imagerate));
        $record->set('timefrom', (int) $data->timefrom);
        $existing ? $record->update() : $record->create();

        // A provider bills in one currency, so the currency entered here is the
        // provider's, and its other rates follow. Said in the message when it
        // changed any, because a rate somebody else entered has just been relabelled.
        $relabelled = $book->set_provider_currency($data->provider, $currency);
        $message = $relabelled > 0
            ? get_string('rates:saved:currency', 'local_airouter', ['currency' => $currency, 'count' => $relabelled])
            : get_string('rates:saved', 'local_airouter');

        redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($existing) {
        $form->set_data((object) [
            'id' => $existing->get('id'),
            'provider' => $existing->get('provider'),
            'model' => $existing->get('model'),
            'currency' => $existing->get('currency'),
            'promptrate' => $existing->get('promptrate'),
            'completionrate' => $existing->get('completionrate'),
            'imagerate' => $existing->get('imagerate'),
            'timefrom' => $existing->get('timefrom'),
        ]);
    } else {
        $form->set_data((object) ['timefrom' => time()]);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($priceid ? 'rates:edit' : 'rates:add', 'local_airouter'), 3);
    $form->display();
    echo $OUTPUT->footer();
    die;
}

$settings = new rate_settings_form($url);
if ($data = $settings->get_data()) {
    require_sesskey();
    set_config('tokenratiocjk', (float) $data->tokenratiocjk, 'local_airouter');
    set_config('tokenratioother', (float) $data->tokenratioother, 'local_airouter');

    redirect($url, get_string('rates:saved', 'local_airouter'), null, \core\output\notification::NOTIFY_SUCCESS);
}
$estimator = new \local_airouter\token_estimator();
$settings->set_data((object) [
    'tokenratiocjk' => $estimator->get_cjk_ratio(),
    'tokenratioother' => $estimator->get_other_ratio(),
]);

echo $OUTPUT->header();

if ($action === 'delete' && $priceid > 0) {
    echo $OUTPUT->confirm(
        get_string('rates:confirmdelete', 'local_airouter'),
        new moodle_url($url, ['action' => 'delete', 'priceid' => $priceid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url,
    );
    echo $OUTPUT->footer();
    die;
}

echo admin_page::back_button($url);
echo $OUTPUT->box(get_string('rates:intro', 'local_airouter'));
// Said here because the difference is invisible afterwards: a request with no rate and
// a request that was free both show no money, and only one of them is a known figure.
echo html_writer::div(get_string('rates:zero', 'local_airouter'), 'text-muted');

echo $OUTPUT->heading(get_string('rates:prices', 'local_airouter'), 3);

$prices = $book->get_all();
if (!$prices) {
    echo $OUTPUT->notification(get_string('rates:noprices', 'local_airouter'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('price:provider', 'local_airouter'),
        get_string('price:model', 'local_airouter'),
        get_string('price:currency', 'local_airouter'),
        get_string('price:promptrate', 'local_airouter'),
        get_string('price:completionrate', 'local_airouter'),
        get_string('price:imagerate', 'local_airouter'),
        get_string('price:timefrom', 'local_airouter'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'admintable generaltable';
    $unset = get_string('rates:unset', 'local_airouter');
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
            $record->get('model') === '' ? get_string('price:model:any', 'local_airouter')
                : s($record->get('model')),
            s($record->get('currency')),
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
    get_string('rates:add', 'local_airouter'),
    'get',
);

echo $OUTPUT->heading(get_string('rates:settings', 'local_airouter'), 3);
$settings->display();

echo $OUTPUT->footer();
