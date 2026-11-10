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

namespace aiprovider_awsbr\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy Subsystem for aiprovider_awsbr.
 *
 * The plugin sends prompts (including image descriptions), images and responses
 * to AWS Bedrock, and text and audio to Amazon Polly, Transcribe and S3, and keeps
 * two small tables of its own:
 *  - aiprovider_awsbr_embed_text: the embedded text and request details of each
 *    embed_text call.
 *  - aiprovider_awsbr_grade_resp: token counts and a hash of the prompt for each
 *    grade_response call (never the prompt text).
 *
 * The generate_image action is a core action: its prompts and settings are stored
 * in core's ai_action_generate_image table, which core's privacy provider covers,
 * and the generated image is a draft file of the user, which the file subsystem
 * covers. The plugin keeps nothing extra for it.
 *
 * Neither table has a user column. Each row is linked to a user and a context
 * only through core's ai_action_register row, where actionname is the action's
 * basename ('embed_text', 'grade_response'), provider is 'aiprovider_awsbr' and
 * actionid is the id of our row.
 *
 * Core's own privacy provider blanks the content of its tables and keeps the
 * ai_action_register rows (it ignores actions it does not know), so our rows
 * can be found whichever provider runs first. We delete our rows and leave the
 * ai_action_register rows to core.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string The component name, as stored in ai_action_register.provider. */
    private const COMPONENT = 'aiprovider_awsbr';

    /** @var string[] The action name (ai_action_register.actionname) => the plugin table holding its rows. */
    private const ACTION_TABLES = [
        'embed_text'     => 'aiprovider_awsbr_embed_text',
        'grade_response' => 'aiprovider_awsbr_grade_resp',
    ];

    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('aiprovider_awsbr_embed_text', [
            'prompt'       => 'privacy:metadata:aiprovider_awsbr_embed_text:prompt',
            'vectordim'    => 'privacy:metadata:aiprovider_awsbr_embed_text:vectordim',
            'prompttokens' => 'privacy:metadata:aiprovider_awsbr_embed_text:prompttokens',
            'model'        => 'privacy:metadata:aiprovider_awsbr_embed_text:model',
            'responseid'   => 'privacy:metadata:aiprovider_awsbr_embed_text:responseid',
            'fingerprint'  => 'privacy:metadata:aiprovider_awsbr_embed_text:fingerprint',
        ], 'privacy:metadata:aiprovider_awsbr_embed_text');

        $collection->add_database_table('aiprovider_awsbr_grade_resp', [
            'model'            => 'privacy:metadata:aiprovider_awsbr_grade_resp:model',
            'prompttokens'     => 'privacy:metadata:aiprovider_awsbr_grade_resp:prompttokens',
            'completiontokens' => 'privacy:metadata:aiprovider_awsbr_grade_resp:completiontokens',
            'cachereadtokens'  => 'privacy:metadata:aiprovider_awsbr_grade_resp:cachereadtokens',
            'cachewritetokens' => 'privacy:metadata:aiprovider_awsbr_grade_resp:cachewritetokens',
            'stopreason'       => 'privacy:metadata:aiprovider_awsbr_grade_resp:stopreason',
            'responseid'       => 'privacy:metadata:aiprovider_awsbr_grade_resp:responseid',
            'prompthash'       => 'privacy:metadata:aiprovider_awsbr_grade_resp:prompthash',
            'timecreated'      => 'privacy:metadata:aiprovider_awsbr_grade_resp:timecreated',
        ], 'privacy:metadata:aiprovider_awsbr_grade_resp');

        $collection->add_external_location_link('aws_bedrock', [
            'prompt'   => 'privacy:metadata:aws_bedrock:prompt',
            'image'    => 'privacy:metadata:aws_bedrock:image',
            'response' => 'privacy:metadata:aws_bedrock:response',
        ], 'privacy:metadata:aws_bedrock');

        $collection->add_external_location_link('aws_speech', [
            'text'  => 'privacy:metadata:aws_speech:text',
            'audio' => 'privacy:metadata:aws_speech:audio',
        ], 'privacy:metadata:aws_speech');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contexts holding this plugin's rows for the user.
     */
    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        foreach (self::ACTION_TABLES as $actionname => $table) {
            $sql = "SELECT DISTINCT ctx.id
                      FROM {context} ctx
                      JOIN {ai_action_register} aar ON aar.contextid = ctx.id
                      JOIN {{$table}} t ON t.id = aar.actionid
                     WHERE aar.actionname = :actionname
                           AND aar.provider = :component
                           AND aar.userid = :userid";
            $contextlist->add_from_sql($sql, [
                'actionname' => $actionname,
                'component'  => self::COMPONENT,
                'userid'     => $userid,
            ]);
        }
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add users to.
     */
    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        foreach (self::ACTION_TABLES as $actionname => $table) {
            $sql = "SELECT aar.userid
                      FROM {ai_action_register} aar
                      JOIN {{$table}} t ON t.id = aar.actionid
                     WHERE aar.contextid = :contextid
                           AND aar.actionname = :actionname
                           AND aar.provider = :component";
            $userlist->add_from_sql('userid', $sql, [
                'contextid'  => $context->id,
                'actionname' => $actionname,
                'component'  => self::COMPONENT,
            ]);
        }
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->get_contextids())) {
            return;
        }
        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $userid = $contextlist->get_user()->id;

        foreach (self::ACTION_TABLES as $actionname => $table) {
            $fields = $actionname === 'embed_text'
                ? 't.prompt, t.vectordim, t.prompttokens, t.model, t.responseid, t.fingerprint'
                : 't.model, t.prompttokens, t.completiontokens, t.cachereadtokens, t.cachewritetokens,
                    t.stopreason, t.responseid, t.prompthash';
            $sql = "SELECT t.id, aar.contextid, aar.timecreated AS registertime, {$fields}
                      FROM {ai_action_register} aar
                      JOIN {{$table}} t ON t.id = aar.actionid
                     WHERE aar.actionname = :actionname
                           AND aar.provider = :component
                           AND aar.userid = :userid
                           AND aar.contextid {$contextsql}
                  ORDER BY aar.timecreated, t.id";
            $params = [
                'actionname' => $actionname,
                'component'  => self::COMPONENT,
                'userid'     => $userid,
            ] + $contextparams;

            $rows = $DB->get_recordset_sql($sql, $params);
            foreach ($rows as $row) {
                $context = \context::instance_by_id($row->contextid);
                $details = (object) ['actionname' => $actionname];
                foreach (get_object_vars($row) as $name => $value) {
                    if (!in_array($name, ['id', 'contextid', 'registertime'], true)) {
                        $details->$name = $value;
                    }
                }
                $details->timecreated = transform::datetime($row->registertime);

                $subcontext = [
                    get_string('pluginname', 'aiprovider_awsbr'),
                    $actionname,
                    $row->id,
                ];
                writer::with_context($context)->export_data($subcontext, $details);
            }
            $rows->close();
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        self::delete_rows('aar.contextid = :contextid', ['contextid' => $context->id]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->get_contextids())) {
            return;
        }
        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        self::delete_rows(
            "aar.userid = :userid AND aar.contextid {$contextsql}",
            ['userid' => $contextlist->get_user()->id] + $contextparams
        );
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (empty($userlist->get_userids())) {
            return;
        }
        [$usersql, $userparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        self::delete_rows(
            "aar.contextid = :contextid AND aar.userid {$usersql}",
            ['contextid' => $userlist->get_context()->id] + $userparams
        );
    }

    /**
     * Delete this plugin's rows for the ai_action_register rows matching a condition.
     *
     * The condition is an SQL fragment on the ai_action_register alias "aar". The
     * ai_action_register rows themselves belong to core and are left alone.
     *
     * @param string $where SQL condition on the "aar" alias.
     * @param array $params Named parameters for the condition.
     */
    private static function delete_rows(string $where, array $params): void {
        global $DB;

        foreach (self::ACTION_TABLES as $actionname => $table) {
            $sql = "SELECT DISTINCT aar.actionid
                      FROM {ai_action_register} aar
                      JOIN {{$table}} t ON t.id = aar.actionid
                     WHERE aar.actionname = :actionname
                           AND aar.provider = :component
                           AND {$where}";
            $ids = $DB->get_fieldset_sql($sql, [
                'actionname' => $actionname,
                'component'  => self::COMPONENT,
            ] + $params);
            if ($ids) {
                $DB->delete_records_list($table, 'id', $ids);
            }
        }
    }
}
