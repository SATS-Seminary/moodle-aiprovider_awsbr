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

use core_ai\process_base;

/**
 * Abstract base processor for AWS Bedrock.
 *
 * Overrides {@see process_base::query_ai_api()} to use the AWS SDK's
 * BedrockRuntimeClient (SigV4-signed) instead of a Guzzle HTTP call.
 * Concrete subclasses implement {@see build_request_args()} and
 * {@see handle_api_success()}.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_processor extends process_base {

    /**
     * Build the argument array to pass to BedrockRuntimeClient::converse().
     *
     * @return array SDK argument array (modelId, messages, system, inferenceConfig, …).
     */
    abstract protected function build_request_args(): array;

    /**
     * Process the successful AWS result into the standard Moodle response array.
     *
     * @param \Aws\Result $result The AWS SDK result object.
     * @return array Response array with keys: success, id, fingerprint,
     *                generatedcontent, finishreason, prompttokens, completiontokens, model.
     */
    abstract protected function handle_api_success(\Aws\Result $result): array;

    /**
     * Get the system instruction for this action.
     *
     * Reads the per-action actionconfig first; falls back to the action class default.
     *
     * @return string
     */
    protected function get_system_instruction(): string {
        return $this->provider->actionconfig[$this->action::class]['settings']['systeminstruction']
            ?? $this->action::get_system_instruction();
    }

    /**
     * Get the model ID for this action.
     *
     * Resolution order:
     *   1. A per-request `modeloverride` property pinned on the action by the
     *      caller (e.g. local_ezra passes the active prompt's model_pref so each
     *      prompt role can target its own model).
     *   2. The per-action actionconfig model configured in admin.
     *   3. The first model in the availablemodels textarea.
     *
     * @return string Bedrock inference profile ID.
     */
    protected function get_model_id(): string {
        // 1. Per-request override. get_configuration() does a raw `return
        // $this->$name`, so guard with property_exists — the plain actions
        // (generate/summarise/explain) don't declare `modeloverride`.
        if (property_exists($this->action, 'modeloverride')) {
            $override = trim((string) ($this->action->get_configuration('modeloverride') ?? ''));
            if ($override !== '') {
                return $override;
            }
        }

        $model = $this->provider->actionconfig[$this->action::class]['settings']['model'] ?? '';
        if (!empty($model)) {
            return $model;
        }
        // Fall back: take the first entry from the availablemodels textarea.
        $rawmodels = $this->provider->config['availablemodels'] ?? '';
        foreach (explode("\n", $rawmodels) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 2);
            if (!empty($parts[0])) {
                return trim($parts[0]);
            }
        }
        return '';
    }

    /**
     * Get the max tokens setting for this action.
     *
     * @return int
     */
    protected function get_max_tokens(): int {
        return (int) ($this->provider->actionconfig[$this->action::class]['settings']['maxtokens'] ?? 4096);
    }

    /**
     * Get the temperature setting for this action.
     *
     * @return float
     */
    protected function get_temperature(): float {
        return (float) ($this->provider->actionconfig[$this->action::class]['settings']['temperature'] ?? 0.7);
    }

    /**
     * Get the top-P setting for this action.
     *
     * @return float
     */
    protected function get_top_p(): float {
        return (float) ($this->provider->actionconfig[$this->action::class]['settings']['topp'] ?? 1.0);
    }

    /**
     * Query the AWS Bedrock Converse API.
     *
     * On a ThrottlingException AND a fallback region being configured, retries
     * once against the fallback region before returning an error.
     *
     * @return array Standard Moodle response array.
     */
    #[\Override]
    protected function query_ai_api(): array {
        $args = $this->build_request_args();

        // Primary region attempt.
        $result = $this->call_converse($args, region: null);
        if ($result !== null) {
            return $result;
        }

        // Throttling + fallback region: retry once.
        $fallbackregion = trim($this->provider->config['fallbackregion'] ?? '');
        if (!empty($fallbackregion)) {
            $result = $this->call_converse($args, region: $fallbackregion);
            if ($result !== null) {
                return $result;
            }
        }

        // Should never reach here; call_converse always returns non-null on success or final error.
        return ['success' => false, 'errorcode' => 500, 'error' => 'BedrockError',
            'errormessage' => 'Unknown error contacting Bedrock.'];
    }

    /**
     * Make a single Converse API call.
     *
     * Returns the response array on success or non-throttling error.
     * Returns null if the error was a ThrottlingException and we should retry.
     *
     * @param array       $args   SDK args.
     * @param string|null $region Override region; null = primary region.
     * @return array|null
     */
    protected function call_converse(array $args, ?string $region): ?array {
        try {
            $factory = new bedrock_client_factory($this->provider);
            $client  = $factory->get_runtime_client($region);
            $awsresult = $client->converse($args);
            return $this->handle_api_success($awsresult);
        } catch (\Aws\Exception\AwsException $e) {
            $code = $e->getAwsErrorCode();
            // Return null so the caller can retry on throttling.
            if ($code === 'ThrottlingException') {
                return null;
            }
            return $this->map_aws_exception($e);
        } catch (\Throwable $e) {
            return [
                'success'      => false,
                'errorcode'    => 500,
                // 'error' (error name) is required on Moodle main's error
                // responses; harmless extra key on 5.0 (ignored).
                'error'        => 'BedrockError',
                'errormessage' => $e->getMessage(),
            ];
        }
    }

    /**
     * Map an AWS exception to a Moodle error array.
     *
     * @param \Aws\Exception\AwsException $e
     * @return array
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
            // Use the AWS error code as the error name (Moodle main requires a
            // non-empty error name on failures; ignored on 5.0).
            'error'        => $awscode ?: 'BedrockError',
            'errormessage' => $e->getAwsErrorMessage() ?: $e->getMessage(),
        ];
    }
}
