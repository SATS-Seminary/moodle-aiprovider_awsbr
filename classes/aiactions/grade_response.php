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

namespace aiprovider_awsbr\aiactions;

use core_ai\aiactions\base;
use core_ai\aiactions\responses\response_base;

/**
 * Action: structured-output call, answered by a forced Bedrock tool call.
 *
 * A general purpose action (nothing here is specific to one caller). The caller
 * supplies a system prompt, an optional cacheable prefix, a variable suffix, a
 * JSON schema and optionally a model. Bedrock is forced to answer by calling a
 * single tool whose input matches the schema; that input is returned as the
 * `result` of {@see \aiprovider_awsbr\aiactions\responses\response_grade_response}.
 * Validating the result against the schema is the caller's job.
 *
 * Like embed_text this lives in aiprovider_awsbr rather than \core_ai\aiactions
 * because core does not ship it, and callers reach it through
 * \core_ai\manager::process_action().
 *
 * Store behaviour: core's ai_action_register keeps the shared audit record
 * (userid, contextid, model, tokens, timings). The per-action table
 * aiprovider_awsbr_grade_resp keeps token counts (including prompt cache reads
 * and writes) and a SHA-256 hash of the prompt. The prompt text itself is never
 * stored because it may contain a student's answer.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_response extends base {
    /** Pattern a Bedrock tool name must match. */
    private const TOOLNAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

    /**
     * Create a new grade_response action.
     *
     * @param int    $contextid       Moodle context ID for capability / audit purposes.
     * @param int    $userid          ID of the user the call is made for (core audit only; never sent to Bedrock).
     * @param string $systemprompt    The system prompt (required, non-empty).
     * @param string $cachedprompt    Stable prefix of the user message that is cached; '' for none.
     * @param string $prompttext      The variable part of the user message (required).
     * @param string $toolname        Name of the tool Bedrock is forced to call ([a-zA-Z0-9_-], 1-64 characters).
     * @param string $tooldescription Description of the tool.
     * @param array  $inputschema     JSON Schema (object type) the tool input must follow.
     * @param string $modelid         Model override; '' uses the action-configured default model.
     * @param int    $maxtokens       Maximum tokens to generate; 0 uses the action-configured default.
     * @throws \coding_exception If the system prompt, prompt text or tool name is invalid.
     */
    public function __construct(
        int $contextid,
        /** @var int The user ID the action is made for. */
        protected int $userid,
        /** @var string The system prompt. */
        protected string $systemprompt,
        /** @var string The cacheable prefix of the user message. */
        protected string $cachedprompt,
        /** @var string The variable part of the user message. */
        protected string $prompttext,
        /** @var string The name of the forced tool. */
        protected string $toolname,
        /** @var string The description of the forced tool. */
        protected string $tooldescription,
        /** @var array The JSON Schema for the tool input. */
        protected array $inputschema,
        /** @var string Model override ('' = configured default). */
        protected string $modelid = '',
        /** @var int Max tokens (0 = configured default). */
        protected int $maxtokens = 0,
    ) {
        if (trim($systemprompt) === '') {
            throw new \coding_exception('grade_response requires a non-empty system prompt.');
        }
        if (trim($prompttext) === '') {
            throw new \coding_exception('grade_response requires non-empty prompt text.');
        }
        if (!preg_match(self::TOOLNAME_PATTERN, $toolname)) {
            throw new \coding_exception('grade_response tool name must match [a-zA-Z0-9_-]{1,64}.');
        }
        if (($inputschema['type'] ?? null) !== 'object') {
            throw new \coding_exception('grade_response input schema must be a JSON Schema of type object.');
        }
        parent::__construct($contextid);
    }

    /**
     * Return the user ID that owns this action.
     *
     * @return int
     */
    public function get_userid(): int {
        return $this->userid;
    }

    /**
     * Return the fully-qualified classname of the response object.
     *
     * Overrides the base-class default which would look for
     * \core_ai\aiactions\responses\response_grade_response (which does not exist in core).
     *
     * @return string
     */
    public static function get_response_classname(): string {
        return \aiprovider_awsbr\aiactions\responses\response_grade_response::class;
    }

    /**
     * Display name for the action settings UI.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('action_grade_response', 'aiprovider_awsbr');
    }

    /**
     * Description for the action settings UI.
     *
     * @return string
     */
    public static function get_description(): string {
        return get_string('action_grade_response_desc', 'aiprovider_awsbr');
    }

    /**
     * Hash identifying the prompt without storing it.
     *
     * @return string SHA-256 of systemprompt|cachedprompt|prompttext.
     */
    public function get_prompt_hash(): string {
        return hash('sha256', $this->systemprompt . '|' . $this->cachedprompt . '|' . $this->prompttext);
    }

    /**
     * Store action-specific data after the processor finishes.
     *
     * Inserts a row into mdl_aiprovider_awsbr_grade_resp and returns its PK, which
     * core_ai\manager stores as `actionid` in ai_action_register (satisfying its
     * UNIQUE(actionname, actionid) index). The prompt text is never stored, only
     * its hash.
     *
     * @param response_base $response The response produced by the processor.
     * @return int The new row's PK (used as ai_action_register.actionid).
     */
    #[\Override]
    public function store(response_base $response): int {
        global $DB;

        $data = method_exists($response, 'get_response_data') ? $response->get_response_data() : [];

        $record = (object) [
            'model'            => isset($data['model']) ? (string) $data['model'] : null,
            'prompttokens'     => isset($data['prompttokens']) ? (int) $data['prompttokens'] : null,
            'completiontokens' => isset($data['completiontokens']) ? (int) $data['completiontokens'] : null,
            'cachereadtokens'  => isset($data['cachereadtokens']) ? (int) $data['cachereadtokens'] : null,
            'cachewritetokens' => isset($data['cachewritetokens']) ? (int) $data['cachewritetokens'] : null,
            'stopreason'       => isset($data['stopreason']) ? (string) $data['stopreason'] : null,
            'responseid'       => isset($data['id']) ? (string) $data['id'] : null,
            'prompthash'       => $this->get_prompt_hash(),
            'timecreated'      => \core\di::get(\core\clock::class)->time(),
        ];

        // Audit record in ai_action_register is added by core_ai\manager::store_action_result(),
        // which reads the PK we return here.
        return (int) $DB->insert_record('aiprovider_awsbr_grade_resp', $record);
    }
}
