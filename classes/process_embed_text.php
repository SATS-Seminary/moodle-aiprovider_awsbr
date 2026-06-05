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

use aiprovider_awsbr\aiactions\responses\response_embed_text;

/**
 * Processor for the embed_text action via AWS Bedrock InvokeModel API.
 *
 * Embeddings endpoints use the raw InvokeModel API (not Converse), so this
 * class does NOT extend abstract_processor (which is Converse-specific). It
 * extends \core_ai\process_base directly and implements its own query_ai_api()
 * that calls BedrockRuntimeClient::invokeModel().
 *
 * Model dispatch (based on model ID prefix):
 *  - amazon.titan-embed-*  → Titan Text Embeddings v2 shape
 *  - cohere.embed-*        → Cohere Embed Multilingual v3 shape
 *
 * Throttling / fallback behaviour mirrors abstract_processor: on ThrottlingException
 * we retry once against the configured fallback region before returning an error.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_embed_text extends \core_ai\process_base {

    /**
     * Get the model ID configured for the embed_text action.
     *
     * Falls back to a sensible default so embeddings never silently use a
     * language-generation model.
     *
     * @return string
     */
    protected function get_model_id(): string {
        $model = $this->provider->actionconfig[\aiprovider_awsbr\aiactions\embed_text::class]['settings']['model'] ?? '';
        if (!empty($model)) {
            return $model;
        }
        // Hard default: Titan v2, the cheapest and most common embedding model.
        return 'amazon.titan-embed-text-v2:0';
    }

    /**
     * Get the dimension override for Titan v2 (256 / 512 / 1024).
     *
     * Cohere does not support a dimension override, so this is ignored for that model.
     *
     * @return int|null Null means "use model default".
     */
    protected function get_dimensions(): ?int {
        $dim = $this->provider->actionconfig[\aiprovider_awsbr\aiactions\embed_text::class]['settings']['dimensions'] ?? null;
        if ($dim !== null && $dim !== '') {
            $dimint = (int) $dim;
            if (in_array($dimint, [256, 512, 1024], true)) {
                return $dimint;
            }
        }
        return null; // Use model default (1024 for Titan v2).
    }

    /**
     * Build the InvokeModel request body for the configured model.
     *
     * Dispatches on the model ID prefix:
     *  - amazon.titan-embed-* → Titan v2 JSON shape
     *  - cohere.embed-*       → Cohere v3 JSON shape
     *
     * @param string $modelid The Bedrock model ID.
     * @param string $text    The text to embed.
     * @return string JSON-encoded request body.
     */
    protected function build_request_body(string $modelid, string $text): string {
        if (str_starts_with($modelid, 'amazon.titan-embed-')) {
            $body = ['inputText' => $text, 'normalize' => true];
            $dim  = $this->get_dimensions();
            if ($dim !== null) {
                $body['dimensions'] = $dim;
            }
            return json_encode($body);
        }

        if (str_starts_with($modelid, 'cohere.embed-')) {
            return json_encode([
                'texts'      => [$text],
                'input_type' => 'search_document',
                'truncate'   => 'END',
            ]);
        }

        // Unknown model family: attempt a Titan-shaped payload as a best guess.
        return json_encode(['inputText' => $text, 'normalize' => true]);
    }

    /**
     * Extract the embedding vector and token count from the InvokeModel response body.
     *
     * Response shapes:
     *  - Titan v2:  { "embedding": [...], "inputTextTokenCount": N }
     *  - Cohere v3: { "embeddings": [[...]], "id": "...", "texts": [...] }
     *
     * @param string $modelid  The Bedrock model ID (used for shape dispatch).
     * @param string $rawbody  The JSON response body string from Bedrock.
     * @return array{success: true, vector: array<float>, dim: int, tokens: int}
     *             |array{success: false, errorcode: int, errormessage: string}
     */
    protected function parse_response_body(string $modelid, string $rawbody): array {
        $data = json_decode($rawbody, true);
        if (!is_array($data)) {
            return [
                'success'      => false,
                'errorcode'    => 500,
                'error'        => 'BedrockError',
                'errormessage' => 'Bedrock InvokeModel returned non-JSON body.',
            ];
        }

        if (str_starts_with($modelid, 'amazon.titan-embed-')) {
            $vector = $data['embedding'] ?? null;
            $tokens = (int) ($data['inputTextTokenCount'] ?? 0);
        } else if (str_starts_with($modelid, 'cohere.embed-')) {
            $embeddings = $data['embeddings'] ?? null;
            $vector = is_array($embeddings) ? ($embeddings[0] ?? null) : null;
            $tokens = 0; // Cohere v3 InvokeModel does not return a token count.
        } else {
            // Fallback: try Titan shape first, then Cohere.
            $vector = $data['embedding'] ?? ($data['embeddings'][0] ?? null);
            $tokens = (int) ($data['inputTextTokenCount'] ?? 0);
        }

        if (!is_array($vector) || empty($vector)) {
            return [
                'success'      => false,
                'errorcode'    => 500,
                'error'        => 'BedrockError',
                'errormessage' => 'Bedrock returned an empty or malformed embedding vector.',
            ];
        }

        return [
            'success' => true,
            'vector'  => array_values(array_map('floatval', $vector)),
            'dim'     => count($vector),
            'tokens'  => $tokens,
        ];
    }

    /**
     * Execute a single InvokeModel call against the given (or default) region.
     *
     * Returns the parsed response array on success or on a non-throttling error.
     * Returns null specifically when a ThrottlingException occurs, so the caller
     * can retry against the fallback region.
     *
     * @param string      $modelid The Bedrock model ID.
     * @param string      $body    JSON request body.
     * @param string|null $region  Override region; null = primary region from config.
     * @return array|null
     */
    protected function call_invoke_model(string $modelid, string $body, ?string $region): ?array {
        try {
            $factory = new bedrock_client_factory($this->provider);
            $client  = $factory->get_runtime_client($region);

            $awsresult = $client->invokeModel([
                'modelId'     => $modelid,
                'body'        => $body,
                'contentType' => 'application/json',
                'accept'      => 'application/json',
            ]);

            $rawbody = (string) $awsresult->get('body');
            $parsed  = $this->parse_response_body($modelid, $rawbody);
            if (!$parsed['success']) {
                return $parsed;
            }

            return [
                'success' => true,
                'vector'  => $parsed['vector'],
                'dim'     => $parsed['dim'],
                'model'   => $modelid,
                'tokens'  => $parsed['tokens'],
            ];
        } catch (\Aws\Exception\AwsException $e) {
            $code = $e->getAwsErrorCode();
            if ($code === 'ThrottlingException') {
                return null; // Signal to caller: retry on fallback region.
            }
            return $this->map_aws_exception($e);
        } catch (\Throwable $e) {
            return [
                'success'      => false,
                'errorcode'    => 500,
                'error'        => 'BedrockError',
                'errormessage' => $e->getMessage(),
            ];
        }
    }

    /**
     * Map an AWS exception to a Moodle-compatible error array.
     *
     * @param \Aws\Exception\AwsException $e
     * @return array{success: false, errorcode: int, error: string, errormessage: string}
     */
    protected function map_aws_exception(\Aws\Exception\AwsException $e): array {
        $codemapping = [
            'ThrottlingException'       => 429,
            'AccessDeniedException'     => 403,
            'ResourceNotFoundException' => 404,
            'ModelNotReadyException'    => 404,
            'ValidationException'       => 400,
        ];

        $awscode  = $e->getAwsErrorCode();
        $httpcode = $codemapping[$awscode] ?? ($e->getStatusCode() ?: 500);

        return [
            'success'      => false,
            'errorcode'    => $httpcode,
            // AWS error code doubles as the error name (required on Moodle main).
            'error'        => $awscode ?: 'BedrockError',
            'errormessage' => $e->getAwsErrorMessage() ?: $e->getMessage(),
        ];
    }

    /**
     * Query the Bedrock InvokeModel API with throttling-aware fallback.
     *
     * On a ThrottlingException AND a fallback region configured, retries once
     * against the fallback region before returning an error.
     *
     * @return array Standard Moodle response array.
     */
    #[\Override]
    protected function query_ai_api(): array {
        $modelid = $this->get_model_id();
        $text    = $this->action->get_configuration('prompttext');
        $body    = $this->build_request_body($modelid, $text);

        // Primary region attempt.
        $result = $this->call_invoke_model($modelid, $body, region: null);
        if ($result !== null) {
            return $result;
        }

        // Throttled — try fallback region if configured.
        $fallbackregion = trim($this->provider->config['fallbackregion'] ?? '');
        if (!empty($fallbackregion)) {
            $result = $this->call_invoke_model($modelid, $body, region: $fallbackregion);
            if ($result !== null) {
                return $result;
            }
        }

        // Both attempts were throttled; return a 429.
        return [
            'success'      => false,
            'errorcode'    => 429,
            'errormessage' => 'Bedrock embedding request was throttled in all configured regions.',
        ];
    }
}
