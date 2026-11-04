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
 * Asks the model for structured output (a JSON document following the caller's
 * JSON Schema) and returns the decoded document as the result:
 *  - systemprompt  → system[0].text, followed by a cache point
 *  - cachedprompt  → first text block of the user message, followed by a cache
 *                    point (omitted when empty)
 *  Cache points are left out when Moodle's AWS SDK predates them (Moodle 5.0
 *  ships 3.342), because that SDK sends each one as an empty block, which
 *  Bedrock rejects. The call then works, without prompt caching.
 *  - prompttext    → last text block of the user message
 *  - outputschema  → additionalModelRequestFields.output_config.format
 *                    (type json_schema), merged with output_config.effort
 *  - inferenceConfig ← maxTokens, and temperature 0 where the model accepts it
 *
 * No toolConfig and no thinking configuration is ever sent: newer models reject
 * a forced tool choice. Reasoning blocks in the reply are ignored. Throttling, fallback-region retry and AWS
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

    /** @var bool|null Whether the AWS SDK can send cache points; null until checked. */
    private static ?bool $cachepoints = null;

    /**
     * Whether Moodle's AWS SDK knows the Converse cache point blocks.
     *
     * Read from the SDK's own model of the Bedrock Runtime API, once per request.
     *
     * @return bool
     */
    public static function sdk_has_cache_points(): bool {
        if (self::$cachepoints === null) {
            try {
                $api = (\Aws\Api\ApiProvider::defaultProvider())('api', 'bedrock-runtime', '2023-09-30');
                self::$cachepoints = isset($api['shapes']['SystemContentBlock']['members']['cachePoint'])
                    && isset($api['shapes']['ContentBlock']['members']['cachePoint']);
            } catch (\Throwable $e) {
                self::$cachepoints = false;
            }
        }
        return self::$cachepoints;
    }

    /**
     * Pretend the AWS SDK has, or lacks, cache points; null checks the SDK again.
     *
     * @param bool|null $has
     */
    public static function set_cache_points_for_testing(?bool $has): void {
        self::$cachepoints = $has;
    }

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
        $cachepoint = self::sdk_has_cache_points() ? [['cachePoint' => ['type' => 'default']]] : [];

        $content = [];
        if ($cachedprompt !== '') {
            $content[] = ['text' => $cachedprompt];
            array_push($content, ...$cachepoint);
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
                ...$cachepoint,
            ],
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
            'inferenceConfig' => $inferenceconfig,
        ];

        $outputconfig = [
            'format' => [
                'type'   => 'json_schema',
                'schema' => $this->action->get_configuration('outputschema'),
            ],
        ];
        // Effort is a model-native field, only sent to models that accept it.
        $effort = $this->get_effort();
        if ($effort !== '' && model_capabilities::supports_effort($modelid)) {
            $outputconfig['effort'] = $effort;
        }
        $args['additionalModelRequestFields'] = ['output_config' => $outputconfig];

        return $args;
    }

    #[\Override]
    protected function handle_api_success(\Aws\Result $result): array {
        $modelid    = $this->get_model_id();
        $data       = $result->toArray();
        $stopreason = (string) ($data['stopReason'] ?? '');

        // Only text blocks carry the answer; reasoningContent blocks are ignored.
        $text = '';
        foreach ($data['output']['message']['content'] ?? [] as $block) {
            if (isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }
        }

        // Error messages never include the model's text (it may echo student content).
        if ($stopreason === 'max_tokens') {
            return [
                'success'      => false,
                'errorcode'    => 422,
                'error'        => 'Truncated',
                'errormessage' => 'The response was cut off before it was complete (stopReason: max_tokens).',
            ];
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return [
                'success'      => false,
                'errorcode'    => 422,
                'error'        => 'InvalidJson',
                'errormessage' => 'The response was not a valid JSON document (stopReason: '
                    . ($stopreason !== '' ? $stopreason : 'unknown') . ').',
            ];
        }

        return [
            'success'          => true,
            'id'               => $data['ResponseMetadata']['RequestId'] ?? null,
            'result'           => $decoded,
            'model'            => $modelid,
            'prompttokens'     => (int) ($data['usage']['inputTokens'] ?? 0),
            'completiontokens' => (int) ($data['usage']['outputTokens'] ?? 0),
            'cachereadtokens'  => (int) ($data['usage']['cacheReadInputTokens'] ?? 0),
            'cachewritetokens' => (int) ($data['usage']['cacheWriteInputTokens'] ?? 0),
            'stopreason'       => $stopreason,
        ];
    }
}
