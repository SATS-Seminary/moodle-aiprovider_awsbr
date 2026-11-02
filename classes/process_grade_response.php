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

namespace aiprovider_awsbr;

/**
 * Processor for the grade_response action via the AWS Bedrock Converse API.
 *
 * Forces the model to answer by calling one tool whose input schema is the
 * caller's JSON Schema, and returns that tool input as the result:
 *  - systemprompt  → system[0].text, followed by a cache point
 *  - cachedprompt  → first text block of the user message, followed by a cache
 *                    point (omitted when empty)
 *  - prompttext    → last text block of the user message
 *  - toolname / tooldescription / inputschema → toolConfig with a forced toolChoice
 *  - inferenceConfig ← maxTokens, and temperature 0 where the model accepts it
 *  - additionalModelRequestFields.output_config.effort ← effort
 *
 * No thinking / reasoning configuration is ever sent: a forced tool choice is
 * incompatible with extended thinking. Throttling, fallback-region retry and AWS
 * error mapping come from {@see abstract_processor}. Validating the result
 * against the schema is left to the caller.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_grade_response extends abstract_processor {
    /** Default for the max tokens setting when neither the call nor the action sets one. */
    private const DEFAULT_MAX_TOKENS = 2048;

    /**
     * Reject a model override that the provider does not offer, then query Bedrock.
     *
     * A caller-supplied model must be listed in the provider's availablemodels
     * (compared on the id before '|') or be the action-configured model, so a
     * caller cannot point the request at an arbitrary model. The check happens
     * before any AWS call.
     *
     * @return array Standard Moodle response array.
     */
    #[\Override]
    protected function query_ai_api(): array {
        $override = $this->get_model_override();
        if ($override !== '' && !$this->is_model_allowed($override)) {
            return [
                'success'      => false,
                'errorcode'    => 400,
                'error'        => 'ModelNotAllowed',
                'errormessage' => 'The requested model is not available on this provider.',
            ];
        }
        return parent::query_ai_api();
    }

    /**
     * The caller's model override, trimmed ('' = none).
     *
     * @return string
     */
    protected function get_model_override(): string {
        return trim((string) $this->action->get_configuration('modelid'));
    }

    /**
     * Whether a model id may be used as an override.
     *
     * @param string $modelid
     * @return bool
     */
    protected function is_model_allowed(string $modelid): bool {
        $available = array_keys(model_catalog::parse_allowlist((string) ($this->provider->config['availablemodels'] ?? '')));
        if (in_array($modelid, $available, true)) {
            return true;
        }
        $configured = (string) ($this->provider->actionconfig[$this->action::class]['settings']['model'] ?? '');
        return $configured !== '' && $modelid === $configured;
    }

    /**
     * Resolve the model: the (validated) override, else the action-configured
     * model, else the first availablemodels entry.
     *
     * @return string Bedrock inference profile ID.
     */
    #[\Override]
    protected function get_model_id(): string {
        $override = $this->get_model_override();
        if ($override !== '') {
            return $override;
        }
        return parent::get_model_id();
    }

    /**
     * Max tokens: the caller's value, else the action setting, else the default.
     *
     * @return int
     */
    #[\Override]
    protected function get_max_tokens(): int {
        $requested = (int) $this->action->get_configuration('maxtokens');
        if ($requested > 0) {
            return $requested;
        }
        $configured = (int) ($this->provider->actionconfig[$this->action::class]['settings']['maxtokens'] ?? 0);
        return $configured > 0 ? $configured : self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Get the configured effort level for this action ('' = model default).
     *
     * @return string
     */
    protected function get_effort(): string {
        $effort = (string) ($this->provider->actionconfig[$this->action::class]['settings']['effort'] ?? '');
        return in_array($effort, model_capabilities::EFFORT_LEVELS, true) ? $effort : '';
    }

    #[\Override]
    protected function build_request_args(): array {
        $modelid      = $this->get_model_id();
        $cachedprompt = (string) $this->action->get_configuration('cachedprompt');
        $toolname     = (string) $this->action->get_configuration('toolname');

        $content = [];
        if ($cachedprompt !== '') {
            $content[] = ['text' => $cachedprompt];
            $content[] = ['cachePoint' => ['type' => 'default']];
        }
        $content[] = ['text' => (string) $this->action->get_configuration('prompttext')];

        $inferenceconfig = ['maxTokens' => $this->get_max_tokens()];
        if (model_capabilities::accepts_sampling_params($modelid)) {
            $inferenceconfig['temperature'] = 0;
        }

        $args = [
            'modelId'  => $modelid,
            'system'   => [
                ['text' => (string) $this->action->get_configuration('systemprompt')],
                ['cachePoint' => ['type' => 'default']],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
            'toolConfig' => [
                'tools' => [
                    ['toolSpec' => [
                        'name'        => $toolname,
                        'description' => (string) $this->action->get_configuration('tooldescription'),
                        'inputSchema' => ['json' => $this->action->get_configuration('inputschema')],
                    ]],
                ],
                'toolChoice' => ['tool' => ['name' => $toolname]],
            ],
            'inferenceConfig' => $inferenceconfig,
        ];

        // Effort is a model-native field, only sent to models that accept it.
        $effort = $this->get_effort();
        if ($effort !== '' && model_capabilities::supports_effort($modelid)) {
            $args['additionalModelRequestFields'] = ['output_config' => ['effort' => $effort]];
        }

        return $args;
    }

    #[\Override]
    protected function handle_api_success(\Aws\Result $result): array {
        $modelid  = $this->get_model_id();
        $data     = $result->toArray();
        $toolname = (string) $this->action->get_configuration('toolname');

        foreach ($data['output']['message']['content'] ?? [] as $block) {
            if (isset($block['toolUse']) && ($block['toolUse']['name'] ?? '') === $toolname) {
                // Normalise any stdClass the SDK left in the decoded document to arrays.
                $input = json_decode(json_encode($block['toolUse']['input'] ?? []), true);
                return [
                    'success'          => true,
                    'id'               => $data['ResponseMetadata']['RequestId'] ?? null,
                    'result'           => is_array($input) ? $input : [],
                    'model'            => $modelid,
                    'prompttokens'     => (int) ($data['usage']['inputTokens'] ?? 0),
                    'completiontokens' => (int) ($data['usage']['outputTokens'] ?? 0),
                    'cachereadtokens'  => (int) ($data['usage']['cacheReadInputTokens'] ?? 0),
                    'cachewritetokens' => (int) ($data['usage']['cacheWriteInputTokens'] ?? 0),
                    'stopreason'       => (string) ($data['stopReason'] ?? ''),
                ];
            }
        }

        return [
            'success'      => false,
            'errorcode'    => 422,
            'error'        => 'NoToolUse',
            'errormessage' => 'Bedrock did not call the "' . $toolname . '" tool (stopReason: '
                . ($data['stopReason'] ?? 'unknown') . ').',
        ];
    }
}
