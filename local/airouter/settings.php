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
 * The router's place in the site administration tree.
 *
 * Moodle does not read an aiprovider plugin's settings.php, and offers no hook for
 * extending the administration tree, so while the router was an aiprovider its
 * screens could not be in the tree at all: every one of them had to be reached from
 * the provider's own settings form, and each carried a breadcrumb it had built
 * itself. A local plugin's settings.php is read, and it is read after the tree has
 * been built, so the AI category core makes is there to add to.
 *
 * This file is read on far more than the settings page, including during upgrades
 * and from the command line, so only the registration happens unconditionally. The
 * settings themselves, and the database query one of them needs, are built only when
 * the tree is being expanded.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_airouter\managed_policy;
use local_airouter\provider;
use local_airouter\target_resolver;

if (!$hassiteconfig) {
    return;
}

$category = new admin_category('local_airouter', get_string('pluginname', 'local_airouter'));

// Core builds the AI category in admin/settings/top.php. Should a release stop doing
// that, the settings still have somewhere to be rather than disappearing.
$ADMIN->add($ADMIN->locate('ai') !== null ? 'ai' : 'localplugins', $category);

$policy = new admin_settingpage('local_airouter_policy', get_string('policy:heading', 'local_airouter'));

if ($ADMIN->fulltree) {
    // An instance created before the settings moved here still decides what happens,
    // so say so rather than letting an administrator set something that is not read.
    if (provider::get_instance_ids() !== []) {
        $policy->add(new admin_setting_heading(
            'local_airouter/instanceinuse',
            get_string('policy:instanceinuse', 'local_airouter'),
            get_string('policy:instanceinuse_desc', 'local_airouter'),
        ));
    }

    $policy->add(new admin_setting_configcheckbox(
        'local_airouter/' . managed_policy::SWITCH,
        get_string('routing', 'local_airouter'),
        get_string('routing_help', 'local_airouter'),
        1,
    ));

    $policy->add(new admin_setting_configselect(
        'local_airouter/nomatch',
        get_string('nomatch', 'local_airouter'),
        get_string('nomatch_help', 'local_airouter'),
        provider::NOMATCH_DELEGATE,
        [
            provider::NOMATCH_DELEGATE => get_string('nomatch:delegate', 'local_airouter'),
            provider::NOMATCH_DECLINE => get_string('nomatch:decline', 'local_airouter'),
        ],
    ));

    $targets = target_resolver::get_delegation_targets();
    if ($targets === []) {
        $policy->add(new admin_setting_heading(
            'local_airouter/notargets',
            get_string('defaulttarget', 'local_airouter'),
            get_string('defaulttarget:none', 'local_airouter'),
        ));
    } else {
        $policy->add(new admin_setting_configselect(
            'local_airouter/defaulttarget',
            get_string('defaulttarget', 'local_airouter'),
            get_string('defaulttarget_help', 'local_airouter'),
            0,
            [0 => get_string('choosedots')] + $targets,
        ));
    }
}

$ADMIN->add('local_airouter', $policy);

// The screens themselves. Each one already checks the capability for itself; naming
// it here is what puts the page in the tree and gives it the settings navigation and
// a breadcrumb it does not have to invent.
$pages = [
    'managed' => 'managed:heading',
    'rules' => 'rules:heading',
    'order' => 'order:heading',
    'rates' => 'rates:heading',
    'byok' => 'byok:heading',
    'usage' => 'usage:heading',
];

foreach ($pages as $page => $stringid) {
    $ADMIN->add('local_airouter', new admin_externalpage(
        'local_airouter_' . $page,
        get_string($stringid, 'local_airouter'),
        new moodle_url('/local/airouter/' . $page . '.php'),
    ));
}
