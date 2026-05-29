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

defined('MOODLE_INTERNAL') || die();

/**
 * Streaming variant of process_generate_text.
 *
 * Reuses the prompt-shaping (messages, system instruction, inferenceConfig,
 * image blocks, model selection) defined on the parent sync processor, but
 * dispatches via BedrockRuntimeClient::converseStream() and exposes the
 * response as a PHP \Generator. Callers iterate the generator and yield
 * each event as SSE / WebSocket frames to the client.
 *
 * Event shapes yielded (all arrays — JSON-encodable):
 *
 *   ['type' => 'text_delta', 'text'  => string]
 *   ['type' => 'usage',      'tokens_in' => int, 'tokens_out' => int]
 *   ['type' => 'finish',     'stop_reason' => string]
 *   ['type' => 'error',      'message' => string, 'code' => string|int]
 *
 * The terminal event is always exactly one of: usage+finish (success path)
 * or error (failure path). usage and finish are emitted in that order on
 * success; usage may be absent when Bedrock omits the metadata frame.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_generate_text_stream extends process_generate_text {
    /**
     * Open the streaming connection and yield typed events.
     *
     * On AwsException with code 'ThrottlingException' and a configured
     * fallback region, the stream is retried once against the fallback
     * region before yielding an error event.
     *
     * @return \Generator<int, array{type:string}>
     */
    public function stream(): \Generator {
        $args = $this->build_request_args();

        // Primary region attempt.
        $gen = $this->open_stream($args, region: null);
        if ($gen !== null) {
            yield from $gen;
            return;
        }

        // Throttling + fallback region: retry once.
        $fallbackregion = trim((string) ($this->provider->config['fallbackregion'] ?? ''));
        if ($fallbackregion !== '') {
            $gen = $this->open_stream($args, region: $fallbackregion);
            if ($gen !== null) {
                yield from $gen;
                return;
            }
        }

        yield [
            'type'    => 'error',
            'message' => 'Bedrock throttling — no fallback available.',
            'code'    => 'ThrottlingException',
        ];
    }

    /**
     * Make one streaming Converse call.
     *
     * Returns a Generator yielding events on success or non-throttling error.
     * Returns null when the call was rejected with ThrottlingException so the
     * caller can retry against the fallback region.
     *
     * @param array       $args   SDK args.
     * @param string|null $region Override region; null = primary.
     * @return \Generator|null
     */
    protected function open_stream(array $args, ?string $region): ?\Generator {
        try {
            $factory = new bedrock_client_factory($this->provider);
            $client  = $factory->get_runtime_client($region);
            $result  = $client->converseStream($args);
        } catch (\Aws\Exception\AwsException $e) {
            if ($e->getAwsErrorCode() === 'ThrottlingException') {
                return null;
            }
            $mapped = $this->map_aws_exception($e);
            return $this->error_generator(
                (string) ($mapped['errormessage'] ?? $e->getMessage()),
                (string) $e->getAwsErrorCode(),
            );
        } catch (\Throwable $e) {
            return $this->error_generator($e->getMessage(), 'exception');
        }

        return $this->event_generator($result);
    }

    /**
     * Iterate the SDK EventStream and yield normalised events.
     *
     * Bedrock returns a series of envelope events. The shapes we care about:
     *   - contentBlockDelta : ['delta' => ['text' => '...']]
     *   - messageStop       : ['stopReason' => '...']
     *   - metadata          : ['usage' => ['inputTokens' => N, 'outputTokens' => N]]
     *   - internalServerException / modelStreamErrorException / throttlingException /
     *     validationException : terminal error frames
     *
     * Other frames (messageStart, contentBlockStart, contentBlockStop) are ignored
     * — they don't carry information the chat widget needs.
     *
     * @param \Aws\Result $result
     * @return \Generator
     */
    protected function event_generator(\Aws\Result $result): \Generator {
        $stream = $result['stream'] ?? null;
        if ($stream === null) {
            yield ['type' => 'error', 'message' => 'No stream in Bedrock response.', 'code' => 'empty_stream'];
            return;
        }

        $stopreason = '';
        $tokensin   = 0;
        $tokensout  = 0;
        $hasusage   = false;

        try {
            foreach ($stream as $event) {
                // The SDK delivers events as associative arrays with a single
                // member key indicating the event type.
                if (!is_array($event)) {
                    continue;
                }

                if (isset($event['contentBlockDelta']['delta']['text'])) {
                    $text = (string) $event['contentBlockDelta']['delta']['text'];
                    if ($text !== '') {
                        yield ['type' => 'text_delta', 'text' => $text];
                    }
                    continue;
                }

                if (isset($event['messageStop'])) {
                    $stopreason = (string) ($event['messageStop']['stopReason'] ?? '');
                    continue;
                }

                if (isset($event['metadata']['usage'])) {
                    $usage     = $event['metadata']['usage'];
                    $tokensin  = (int) ($usage['inputTokens']  ?? 0);
                    $tokensout = (int) ($usage['outputTokens'] ?? 0);
                    $hasusage  = true;
                    continue;
                }

                // Terminal error events from the stream itself.
                foreach (['internalServerException', 'modelStreamErrorException',
                          'throttlingException', 'validationException',
                          'serviceUnavailableException'] as $errkey) {
                    if (isset($event[$errkey])) {
                        $msg = (string) ($event[$errkey]['message']
                            ?? $event[$errkey]['Message']
                            ?? $errkey);
                        yield ['type' => 'error', 'message' => $msg, 'code' => $errkey];
                        return;
                    }
                }
            }
        } catch (\Aws\Exception\AwsException $e) {
            yield [
                'type'    => 'error',
                'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
                'code'    => (string) $e->getAwsErrorCode(),
            ];
            return;
        } catch (\Throwable $e) {
            yield ['type' => 'error', 'message' => $e->getMessage(), 'code' => 'exception'];
            return;
        }

        // Terminal events on the success path.
        if ($hasusage) {
            yield ['type' => 'usage', 'tokens_in' => $tokensin, 'tokens_out' => $tokensout];
        }
        yield ['type' => 'finish', 'stop_reason' => $stopreason];
    }

    /**
     * Helper that returns a Generator yielding a single error event.
     *
     * Needed because PHP generators cannot be created inline — a function
     * that contains a `yield` statement must be invoked to produce one.
     *
     * @param string $message
     * @param string $code
     * @return \Generator
     */
    protected function error_generator(string $message, string $code): \Generator {
        yield ['type' => 'error', 'message' => $message, 'code' => $code];
    }
}
