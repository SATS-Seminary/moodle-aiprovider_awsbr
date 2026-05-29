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
 * Upgrade steps for aiprovider_awsbr.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion The version we're upgrading from.
 * @return bool
 */
function xmldb_aiprovider_awsbr_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026052503) {
        // Create the audit table for per-call embed_text data (supplies unique
        // actionid values so ai_action_register's UNIQUE(actionname, actionid) holds).
        $table = new xmldb_table('aiprovider_awsbr_embed_text');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('prompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('vectordim', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '128', null, null, null, null);
        $table->add_field('responseid', XMLDB_TYPE_CHAR, '128', null, null, null, null);
        $table->add_field('fingerprint', XMLDB_TYPE_CHAR, '128', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026052503, 'aiprovider', 'awsbr');
    }

    return true;
}
