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
 * Database upgrade for aiprovider_router.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin database.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool Always true. Failures are raised as exceptions by the DDL layer.
 */
function xmldb_aiprovider_router_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026091300) {
        // Routing rules, and the conditions that decide whether a rule matches.
        $table = new xmldb_table('aiprovider_router_rule');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('sortorder', XMLDB_INDEX_NOTUNIQUE, ['sortorder']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('aiprovider_router_condition');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('configdata', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('ruleid', XMLDB_KEY_FOREIGN, ['ruleid'], 'aiprovider_router_rule', ['id']);
        $table->add_index('ruleid-type', XMLDB_INDEX_UNIQUE, ['ruleid', 'type']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091300, 'aiprovider', 'router');
    }

    if ($oldversion < 2026091301) {
        // One row per AI request the router handled, including the ones it turned down.
        $table = new xmldb_table('aiprovider_router_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('actionname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('placement', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('rulename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('targetname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('targetprovider', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('success', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('errorcode', XMLDB_TYPE_INTEGER, '4', null, null, null, null);
        $table->add_field('reason', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completiontokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('cost', XMLDB_TYPE_NUMBER, '12, 6', null, null, null, null);
        $table->add_field('currency', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('keysource', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'site');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        $table->add_index('courseid-timecreated', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('aiprovider_router_price');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('provider', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('promptrate', XMLDB_TYPE_NUMBER, '12, 6', null, null, null, null);
        $table->add_field('completionrate', XMLDB_TYPE_NUMBER, '12, 6', null, null, null, null);
        $table->add_field('imagerate', XMLDB_TYPE_NUMBER, '12, 6', null, null, null, null);
        $table->add_field('timefrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('provider-model-timefrom', XMLDB_INDEX_UNIQUE, ['provider', 'model', 'timefrom']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091301, 'aiprovider', 'router');
    }

    if ($oldversion < 2026091302) {
        // A day of the log, summarised, so that reports outlive the detail rows.
        $table = new xmldb_table('aiprovider_router_daily');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('daystart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('actionname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('targetname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('targetprovider', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('currency', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('requests', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failures', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completiontokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cost', XMLDB_TYPE_NUMBER, '16, 6', null, null, null, null);
        $table->add_field('costedrequests', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('daystart', XMLDB_INDEX_NOTUNIQUE, ['daystart']);
        $table->add_index('courseid-daystart', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'daystart']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091302, 'aiprovider', 'router');
    }

    if ($oldversion < 2026091304) {
        // Which config field of a delegation target a brought key goes in.
        $table = new xmldb_table('aiprovider_router_target');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('keyfield', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('targetid', XMLDB_INDEX_UNIQUE, ['targetid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Keys brought by a user or registered for a course.
        $table = new xmldb_table('aiprovider_router_key');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('scope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scopeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('secret', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('hint', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timeverified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('verifystatus', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('scope-scopeid-targetid', XMLDB_INDEX_UNIQUE, ['scope', 'scopeid', 'targetid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091304, 'aiprovider', 'router');
    }

    if ($oldversion < 2026091401) {
        // Whose key pays for the requests a rule claims.
        $table = new xmldb_table('aiprovider_router_rule');
        $field = new xmldb_field('keysource', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'site', 'targetid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Which brought key paid for one request.
        $table = new xmldb_table('aiprovider_router_log');
        $field = new xmldb_field('keyid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'keysource');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The summary gains the same dimension, so that money somebody spent themselves
        // is never added into what the site spent. Existing summaries describe days
        // before any key could be brought, so the default is right for all of them.
        $table = new xmldb_table('aiprovider_router_daily');
        $field = new xmldb_field('keysource', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'site', 'model');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026091401, 'aiprovider', 'router');
    }

    if ($oldversion < 2026091900) {
        // Who made the requests a summary row counts. Nullable, because the rows written
        // before this column existed did not record it, and zero would read as a real
        // account rather than as "this was not kept".
        $table = new xmldb_table('aiprovider_router_daily');
        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('userid-daystart', XMLDB_INDEX_NOTUNIQUE, ['userid', 'daystart']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026091900, 'aiprovider', 'router');
    }

    if ($oldversion < 2026091903) {
        // Whether the site lets people bring a key to each provider, which until now
        // could only be said by claiming the provider took no key at all. Existing rows
        // were written by administrators who had just said where a key goes, so
        // allowing one is what they meant.
        $table = new xmldb_table('aiprovider_router_target');
        $field = new xmldb_field('byokmode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'allowed', 'keyfield');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026091903, 'aiprovider', 'router');
    }

    if ($oldversion < 2026092000) {
        // A limit the owner puts on their own key. Null means none, which is what
        // every key registered before this existed had, and what most will keep.
        $table = new xmldb_table('aiprovider_router_key');
        $fields = [
            new xmldb_field('capamount', XMLDB_TYPE_NUMBER, '12, 6', null, null, null, null, 'verifystatus'),
            new xmldb_field('capperiod', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'month', 'capamount'),
            new xmldb_field('capdays', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '30', 'capperiod'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026092000, 'aiprovider', 'router');
    }

    if ($oldversion < 2026092001) {
        // What has already been said about a budget, so that the daily task does not
        // say it again tomorrow.
        $table = new xmldb_table('aiprovider_router_notice');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('subjectid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('limitamount', XMLDB_TYPE_NUMBER, '12, 6', null, XMLDB_NOTNULL, null, null);
        $table->add_field('threshold', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '100');
        $table->add_field('timenotified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index(
            'kind-subjectid-limitamount-threshold',
            XMLDB_INDEX_UNIQUE,
            ['kind', 'subjectid', 'limitamount', 'threshold'],
        );
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092001, 'aiprovider', 'router');
    }

    if ($oldversion < 2026092002) {
        // A budget can now be counted in requests as well as in money, so what has
        // already been said has to say which of the two it was about. Every notice
        // written before this was about money, which is what the default says.
        $table = new xmldb_table('aiprovider_router_notice');
        $field = new xmldb_field('metric', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'cost', 'subjectid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The old key would let a limit of 3000 requests be silenced by a limit of
        // 3000 in money, so it is replaced rather than added to.
        $old = new xmldb_index('kind-subjectid-limitamount-threshold', XMLDB_INDEX_UNIQUE, [
            'kind', 'subjectid', 'limitamount', 'threshold',
        ]);
        if ($dbman->index_exists($table, $old)) {
            $dbman->drop_index($table, $old);
        }
        $new = new xmldb_index('kind-subjectid-metric-limitamount-threshold', XMLDB_INDEX_UNIQUE, [
            'kind', 'subjectid', 'metric', 'limitamount', 'threshold',
        ]);
        if (!$dbman->index_exists($table, $new)) {
            $dbman->add_index($table, $new);
        }

        upgrade_plugin_savepoint(true, 2026092002, 'aiprovider', 'router');
    }

    return true;
}
