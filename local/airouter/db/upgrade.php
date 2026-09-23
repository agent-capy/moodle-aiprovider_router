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
 * Upgrade steps for local_airouter.
 *
 * The plugin has not been released, so nothing here migrates data: a step creates
 * what a fresh install would create, so that a development site can follow along
 * without being reinstalled. Data that was recorded in the old shape stays in the
 * old table until that table goes.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool Always true.
 */
function xmldb_local_airouter_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026092205) {
        // Requests and attempts, recorded apart: one row per request however many
        // providers it took, and one row per provider asked whether or not it answered.
        $table = new xmldb_table('local_airouter_request');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('correlation', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('actionname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('placement', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('rulename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('keysource', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'site');
        $table->add_field('state', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open');
        $table->add_field('reason', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('errorcode', XMLDB_TYPE_INTEGER, '4', null, null, null, null);
        $table->add_field('answeredby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeended', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('applied', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('correlation', XMLDB_INDEX_UNIQUE, ['correlation']);
        $table->add_index('applied-timeended', XMLDB_INDEX_NOTUNIQUE, ['applied', 'timeended']);
        $table->add_index('contextid', XMLDB_INDEX_NOTUNIQUE, ['contextid']);
        $table->add_index('courseid-timeended', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timeended']);
        $table->add_index('timestarted', XMLDB_INDEX_NOTUNIQUE, ['timestarted']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_airouter_attempt');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('requestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('seq', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('targetprovider', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('keysource', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'site');
        $table->add_field('keyid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('state', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'started');
        $table->add_field('errorcode', XMLDB_TYPE_INTEGER, '4', null, null, null, null);
        $table->add_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completiontokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('images', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usageknown', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cost', XMLDB_TYPE_NUMBER, '12, 6', null, null, null, null);
        $table->add_field('currency', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeended', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('applied', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('requestid', XMLDB_KEY_FOREIGN, ['requestid'], 'local_airouter_request', ['id']);
        $table->add_index('requestid-seq', XMLDB_INDEX_UNIQUE, ['requestid', 'seq']);
        $table->add_index('applied-timeended', XMLDB_INDEX_NOTUNIQUE, ['applied', 'timeended']);
        $table->add_index('targetid-timeended', XMLDB_INDEX_NOTUNIQUE, ['targetid', 'timeended']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092205, 'local', 'airouter');
    }

    if ($oldversion < 2026092301) {
        // A day of the new records added up, built by applying each fact once.
        $table = new xmldb_table('local_airouter_summary');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('daystart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('actionname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('targetname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '-');
        $table->add_field('keysource', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'site');
        $table->add_field('currency', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '-');
        $table->add_field('requests', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failures', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('calls', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('knowncalls', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completiontokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cost', XMLDB_TYPE_NUMBER, '16, 6', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('costedcalls', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // The shape of the day this step was written; the next step widens the key.
        $table->add_index(
            'key',
            XMLDB_INDEX_UNIQUE,
            ['daystart', 'userid', 'courseid', 'actionname', 'targetid', 'model', 'keysource', 'currency']
        );
        $table->add_index('userid-daystart', XMLDB_INDEX_NOTUNIQUE, ['userid', 'daystart']);
        $table->add_index('courseid-daystart', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'daystart']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092301, 'local', 'airouter');
    }

    if ($oldversion < 2026092302) {
        // A rate now says what currency it is in. Until here one currency was a setting
        // for the whole site, which cannot be right once two providers billing in
        // different currencies are in use, so the setting goes and its value is
        // written onto every rate that was entered under it.
        $table = new xmldb_table('local_airouter_price');
        $field = new xmldb_field('currency', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'model');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $currency = strtoupper(trim((string) get_config('local_airouter', 'currency')));
            $DB->set_field('local_airouter_price', 'currency', $currency === '' ? 'USD' : $currency);
            $field->setNotNull(true);
            $dbman->change_field_notnull($table, $field);
        }
        unset_config('currency', 'local_airouter');

        upgrade_plugin_savepoint(true, 2026092302, 'local', 'airouter');
    }

    if ($oldversion < 2026092303) {
        // Money is added up by provider, so the summary says which provider each row
        // belongs to, and keeps the image count so that a day can be priced again.
        $table = new xmldb_table('local_airouter_summary');
        $field = new xmldb_field('targetprovider', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, '-', 'targetname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Rows written before this knew their target by id alone; the provider is
            // whatever that instance's plugin is, for the instances that still exist.
            // Core keeps the instance's class name, not its component, so it is turned
            // into the component the attempt table records, the way core names it.
            $targetids = $DB->get_fieldset_sql(
                'SELECT DISTINCT targetid FROM {local_airouter_summary} WHERE targetid > 0'
            );
            foreach ($targetids as $targetid) {
                $classname = $DB->get_field('ai_providers', 'provider', ['id' => $targetid]);
                if ($classname === false || $classname === null || $classname === '') {
                    continue;
                }
                $component = \core\component::get_component_from_classname((string) $classname);
                if ($component !== null && $component !== '') {
                    $DB->set_field('local_airouter_summary', 'targetprovider', $component, ['targetid' => $targetid]);
                }
            }
        }
        $field = new xmldb_field('images', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'completiontokens');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index(
            'key',
            XMLDB_INDEX_UNIQUE,
            ['daystart', 'userid', 'courseid', 'actionname', 'targetid', 'model', 'keysource', 'currency']
        );
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index(
            'key',
            XMLDB_INDEX_UNIQUE,
            ['daystart', 'userid', 'courseid', 'actionname', 'targetprovider', 'targetid', 'model', 'keysource', 'currency']
        );
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026092303, 'local', 'airouter');
    }

    if ($oldversion < 2026092304) {
        // A limit in money is about one provider, so what has been said about it says
        // which provider, and two limits on one subject at two providers are two notes.
        $table = new xmldb_table('local_airouter_notice');
        $field = new xmldb_field('provider', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, '-', 'metric');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index(
            'kind-subjectid-metric-limitamount-threshold-periodstart-perioddays',
            XMLDB_INDEX_UNIQUE,
            ['kind', 'subjectid', 'metric', 'limitamount', 'threshold', 'periodstart', 'perioddays']
        );
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index(
            'kind-subjectid-metric-provider-limitamount-threshold-periodstart-perioddays',
            XMLDB_INDEX_UNIQUE,
            ['kind', 'subjectid', 'metric', 'provider', 'limitamount', 'threshold', 'periodstart', 'perioddays']
        );
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026092304, 'local', 'airouter');
    }

    return true;
}
