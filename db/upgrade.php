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

/**
 * Run the aiprovider_awsbr database upgrade steps.
 *
 * @param int $oldversion The version we're upgrading from.
 * @return bool
 */
function xmldb_aiprovider_awsbr_upgrade(int $oldversion): bool {
    global $DB;

    require_once(__DIR__ . '/upgradelib.php');

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

    if ($oldversion < 2026110200) {
        // Audit table for the grade_response action. Token counts and a hash of the
        // prompt only; the prompt text (which may contain a student's answer) is not stored.
        $table = new xmldb_table('aiprovider_awsbr_grade_resp');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completiontokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('cachereadtokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('cachewritetokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('stopreason', XMLDB_TYPE_CHAR, '32', null, null, null, null);
        $table->add_field('responseid', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('prompthash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Existing provider instances keep their own action configuration, so a new
        // action is not enabled on them unless it is added here (new instances get
        // it from provider::initialise_action_settings()). The defaults are literal
        // rather than read from the settings form, whose model list queries Bedrock.
        $action = \aiprovider_awsbr\aiactions\grade_response::class;
        $providers = $DB->get_records('ai_providers', ['provider' => \aiprovider_awsbr\provider::class]);
        foreach ($providers as $record) {
            $actionconfig = json_decode((string) $record->actionconfig, true);
            $actionconfig = is_array($actionconfig) ? $actionconfig : [];
            if (!array_key_exists($action, $actionconfig)) {
                $actionconfig[$action] = [
                    'enabled'  => true,
                    'settings' => ['model' => '', 'maxtokens' => 2048, 'effort' => ''],
                ];
                $DB->set_field('ai_providers', 'actionconfig', json_encode($actionconfig), ['id' => $record->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2026110200, 'aiprovider', 'awsbr');
    }

    if ($oldversion < 2026111000) {
        // The generate_image action is new, and added disabled to existing instances so
        // administrators opt in (image generation costs money). The cache definitions
        // need no step: the version bump purges all caches, and the model_catalog
        // definition now also holds a per-instance image model list.
        aiprovider_awsbr_add_generate_image_disabled();

        upgrade_plugin_savepoint(true, 2026111000, 'aiprovider', 'awsbr');
    }

    return true;
}
