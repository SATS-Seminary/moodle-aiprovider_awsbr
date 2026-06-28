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
 * Action: embed text into a vector for retrieval / similarity search.
 *
 * This class lives inside aiprovider_awsbr\aiactions rather than \core_ai\aiactions
 * because Moodle core does not yet ship an embeddings action. local_ezra calls
 * embeddings through \core_ai\manager::process_action() using this action class,
 * keeping all AWS calls inside the provider plugin.
 *
 * The response class is \aiprovider_awsbr\aiactions\responses\response_embed_text
 * which returns vector, dim, model and token-count fields.
 *
 * Store behaviour: we rely on core's ai_action_register table for the shared audit
 * record (userid, contextid, model, tokens, timings). There is no separate per-action
 * table for embeddings because vector data is persisted by local_ezra in its own
 * kb_chunk schema — not here.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class embed_text extends base {
    /**
     * Create a new embed_text action.
     *
     * @param int    $contextid  Moodle context ID for capability / audit purposes.
     * @param int    $userid     ID of the user triggering the embedding (often a cron user).
     * @param string $prompttext The raw text that should be embedded into a vector.
     */
    public function __construct(
        int $contextid,
        /** @var int The user ID requesting the action. */
        protected int $userid,
        /** @var string The text to embed. */
        protected string $prompttext,
    ) {
        parent::__construct($contextid);
    }

    /**
     * Return the text to embed.
     *
     * @return string
     */
    public function get_prompttext(): string {
        return $this->prompttext;
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
     * \core_ai\aiactions\responses\response_embed_text (which does not exist in core).
     * We redirect to the plugin-local response class.
     *
     * @return string
     */
    public static function get_response_classname(): string {
        return \aiprovider_awsbr\aiactions\responses\response_embed_text::class;
    }

    /**
     * Display name for the action settings UI.
     *
     * The base class hardcodes `core_ai` as the lang component; our string lives in
     * aiprovider_awsbr because embed_text is a plugin-local custom action.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('action_embed_text', 'aiprovider_awsbr');
    }

    /**
     * Description for the action settings UI.
     *
     * @return string
     */
    public static function get_description(): string {
        return get_string('action_embed_text_desc', 'aiprovider_awsbr');
    }

    /**
     * Store action-specific data after the processor finishes.
     *
     * Inserts a row into mdl_aiprovider_awsbr_embed_text and returns its PK. The shared
     * audit record in mdl_ai_action_register then stores this PK as `actionid`,
     * satisfying its UNIQUE(actionname, actionid) index — without that, the second
     * embedding call would collide on `embed_text-0`.
     *
     * The vector itself is persisted by local_ezra into local_ezra_kb_chunk.
     * We capture metadata (prompt, dim, tokens, model, request id) here for audit.
     *
     * @param response_base $response The response produced by the processor.
     * @return int The new row's PK (used as ai_action_register.actionid).
     */
    #[\Override]
    public function store(response_base $response): int {
        global $DB;

        $data = method_exists($response, 'get_response_data') ? $response->get_response_data() : [];

        // Truncate the embedded text to keep audit rows manageable.
        $prompt = mb_substr((string) $this->prompttext, 0, 4000);

        $record = (object) [
            'prompt'       => $prompt,
            'vectordim'    => isset($data['dim']) ? (int) $data['dim'] : null,
            'prompttokens' => isset($data['prompttokens']) ? (int) $data['prompttokens']
                              : (isset($data['tokens']) ? (int) $data['tokens'] : null),
            'model'        => isset($data['model']) ? (string) $data['model'] : null,
            'responseid'   => isset($data['id']) ? (string) $data['id'] : null,
            'fingerprint'  => isset($data['fingerprint']) ? (string) $data['fingerprint'] : null,
        ];

        // Audit record in ai_action_register is added by core_ai\manager::store_action_result(),
        // which reads the PK we return here.
        return (int) $DB->insert_record('aiprovider_awsbr_embed_text', $record);
    }
}
