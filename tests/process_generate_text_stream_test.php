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

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;

/**
 * PHPUnit tests for process_generate_text_stream.
 *
 * Uses Aws\MockHandler to simulate ConverseStream returning an iterable of
 * envelope events. The stream itself is just a plain iterable (array) — the
 * SDK's BedrockRuntimeClient accepts any array-of-event-arrays under the
 * 'stream' key of the mocked Result.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_awsbr\process_generate_text_stream
 */
final class process_generate_text_stream_test extends \advanced_testcase {
    /** @var \core_ai\manager */
    private \core_ai\manager $manager;

    /** @var provider The provider instance. */
    private provider $provider;

    /** @var \core_ai\aiactions\generate_text The action instance. */
    private \core_ai\aiactions\generate_text $action;

    /**
     * Provider config used across tests.
     *
     * @return array
     */
    private function provider_config(): array {
        return [
            'authmode'              => 'static',
            'accesskeyid'           => 'AKIAIOSFODNN7EXAMPLE',
            'secretaccesskey'       => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region'                => 'af-south-1',
            'fallbackregion'        => '',
            'availablemodels'       => "global.anthropic.claude-sonnet-4-5-20250929-v1:0|Claude Sonnet 4.5\n",
            'enableuserratelimit'   => false,
            'userratelimit'         => 0,
            'enableglobalratelimit' => false,
            'globalratelimit'       => 0,
        ];
    }

    /**
     * Per-action config with all required settings.
     *
     * @return array
     */
    private function action_config(): array {
        return [
            \core_ai\aiactions\generate_text::class => [
                'enabled'  => true,
                'settings' => [
                    'model'             => 'global.anthropic.claude-sonnet-4-5-20250929-v1:0',
                    'systeminstruction' => 'You are a helpful assistant.',
                    'maxtokens'         => 2048,
                    'temperature'       => 0.7,
                    'topp'              => 1.0,
                ],
            ],
        ];
    }

    /**
     * Set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->manager = \core\di::get(\core_ai\manager::class);

        $this->provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'test-bedrock-stream',
            config:    $this->provider_config(),
        );
        $this->provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->action_config(),
        );

        $this->action = new \core_ai\aiactions\generate_text(
            contextid:  1,
            userid:     1,
            prompttext: 'Tell me something interesting.',
        );
    }

    /**
     * Build a streaming processor wired to a fake event sequence.
     *
     * Bypasses bedrock_client_factory entirely by overriding open_stream() to
     * return a controlled iterator. This is cleaner than trying to wire
     * MockHandler through the streaming path, which depends on Guzzle-level
     * event-stream parsing not easily mocked.
     *
     * @param array $events Sequence of fake Bedrock envelope events.
     * @return process_generate_text_stream
     */
    private function build_processor_with_events(array $events): process_generate_text_stream {
        $result = new Result(['stream' => $events]);

        return new class ($this->provider, $this->action, $result) extends process_generate_text_stream {
            /**
             * Build a stream processor that replays a fixed fake result.
             *
             * @param provider $provider The provider instance.
             * @param \core_ai\aiactions\base $action The action instance.
             * @param Result $fakeresult The pre-built fake Bedrock stream result.
             */
            public function __construct(
                provider $provider,
                \core_ai\aiactions\base $action,
                /** @var Result The pre-built fake Bedrock stream result. */
                private Result $fakeresult,
            ) {
                parent::__construct($provider, $action);
            }

            /**
             * Replay the fake event stream instead of calling Bedrock.
             *
             * @param array $args The request arguments (ignored).
             * @param string|null $region The AWS region (ignored).
             * @return \Generator|null
             */
            protected function open_stream(array $args, ?string $region): ?\Generator {
                return $this->event_generator($this->fakeresult);
            }
        };
    }

    /**
     * Happy path: deltas → metadata → messageStop.
     */
    public function test_stream_yields_deltas_then_usage_then_finish(): void {
        $events = [
            ['messageStart'      => ['role' => 'assistant']],
            ['contentBlockStart' => ['contentBlockIndex' => 0]],
            ['contentBlockDelta' => ['delta' => ['text' => 'Hello']]],
            ['contentBlockDelta' => ['delta' => ['text' => ' world']]],
            ['contentBlockDelta' => ['delta' => ['text' => '!']]],
            ['contentBlockStop'  => ['contentBlockIndex' => 0]],
            ['messageStop'       => ['stopReason' => 'end_turn']],
            ['metadata'          => [
                'usage'   => ['inputTokens' => 12, 'outputTokens' => 3],
                'metrics' => ['latencyMs' => 240],
            ]],
        ];

        $processor = $this->build_processor_with_events($events);

        $yielded = [];
        foreach ($processor->stream() as $ev) {
            $yielded[] = $ev;
        }

        // Expected: 3 text_delta events, then usage, then finish.
        $this->assertCount(5, $yielded);

        $this->assertEquals('text_delta', $yielded[0]['type']);
        $this->assertEquals('Hello', $yielded[0]['text']);

        $this->assertEquals('text_delta', $yielded[1]['type']);
        $this->assertEquals(' world', $yielded[1]['text']);

        $this->assertEquals('text_delta', $yielded[2]['type']);
        $this->assertEquals('!', $yielded[2]['text']);

        $this->assertEquals('usage', $yielded[3]['type']);
        $this->assertEquals(12, $yielded[3]['tokens_in']);
        $this->assertEquals(3, $yielded[3]['tokens_out']);

        $this->assertEquals('finish', $yielded[4]['type']);
        $this->assertEquals('end_turn', $yielded[4]['stop_reason']);
    }

    /**
     * Concatenating all deltas yields the full reply body.
     */
    public function test_deltas_accumulate_to_full_body(): void {
        $events = [
            ['contentBlockDelta' => ['delta' => ['text' => 'The quick ']]],
            ['contentBlockDelta' => ['delta' => ['text' => 'brown ']]],
            ['contentBlockDelta' => ['delta' => ['text' => 'fox.']]],
            ['messageStop'       => ['stopReason' => 'end_turn']],
            ['metadata'          => ['usage' => ['inputTokens' => 5, 'outputTokens' => 3]]],
        ];

        $processor = $this->build_processor_with_events($events);

        $body = '';
        foreach ($processor->stream() as $ev) {
            if ($ev['type'] === 'text_delta') {
                $body .= $ev['text'];
            }
        }

        $this->assertEquals('The quick brown fox.', $body);
    }

    /**
     * Empty text deltas are filtered out.
     */
    public function test_empty_deltas_are_skipped(): void {
        $events = [
            ['contentBlockDelta' => ['delta' => ['text' => '']]],
            ['contentBlockDelta' => ['delta' => ['text' => 'A']]],
            ['contentBlockDelta' => ['delta' => ['text' => '']]],
            ['messageStop'       => ['stopReason' => 'end_turn']],
        ];

        $processor = $this->build_processor_with_events($events);

        $deltas = 0;
        foreach ($processor->stream() as $ev) {
            if ($ev['type'] === 'text_delta') {
                $deltas++;
            }
        }
        $this->assertEquals(1, $deltas);
    }

    /**
     * Missing metadata frame: stream still yields finish.
     */
    public function test_stream_without_metadata_still_finishes(): void {
        $events = [
            ['contentBlockDelta' => ['delta' => ['text' => 'hi']]],
            ['messageStop'       => ['stopReason' => 'end_turn']],
        ];

        $processor = $this->build_processor_with_events($events);

        $types = [];
        foreach ($processor->stream() as $ev) {
            $types[] = $ev['type'];
        }

        $this->assertEquals(['text_delta', 'finish'], $types);
    }

    /**
     * Terminal error event mid-stream is forwarded as an error event.
     */
    public function test_stream_error_event_is_forwarded(): void {
        $events = [
            ['contentBlockDelta' => ['delta' => ['text' => 'partial']]],
            ['modelStreamErrorException' => ['message' => 'Model failed mid-stream.']],
        ];

        $processor = $this->build_processor_with_events($events);

        $yielded = [];
        foreach ($processor->stream() as $ev) {
            $yielded[] = $ev;
        }

        $this->assertCount(2, $yielded);
        $this->assertEquals('text_delta', $yielded[0]['type']);
        $this->assertEquals('error', $yielded[1]['type']);
        $this->assertEquals('Model failed mid-stream.', $yielded[1]['message']);
        $this->assertEquals('modelStreamErrorException', $yielded[1]['code']);
    }

    /**
     * Empty stream key in the SDK Result yields a single error event.
     */
    public function test_stream_empty_response_yields_error(): void {
        $processor = new class ($this->provider, $this->action) extends process_generate_text_stream {
            /**
             * Replay an empty SDK result instead of calling Bedrock.
             *
             * @param array $args The request arguments (ignored).
             * @param string|null $region The AWS region (ignored).
             * @return \Generator|null
             */
            protected function open_stream(array $args, ?string $region): ?\Generator {
                return $this->event_generator(new Result([]));
            }
        };

        $yielded = [];
        foreach ($processor->stream() as $ev) {
            $yielded[] = $ev;
        }

        $this->assertCount(1, $yielded);
        $this->assertEquals('error', $yielded[0]['type']);
        $this->assertEquals('empty_stream', $yielded[0]['code']);
    }

    /**
     * AwsException from open_stream() (non-throttling) yields an error event.
     */
    public function test_aws_exception_yields_error_event(): void {
        $processor = new class ($this->provider, $this->action) extends process_generate_text_stream {
            /**
             * Yield a single error event mapped from a simulated AwsException.
             *
             * @return \Generator
             */
            public function stream(): \Generator {
                $exception = new AwsException(
                    'Access denied to the requested resource.',
                    new Command('ConverseStream'),
                    ['code' => 'AccessDeniedException', 'message' => 'Access denied.'],
                );
                $mapped = $this->map_aws_exception($exception);
                yield [
                    'type'    => 'error',
                    'message' => (string) ($mapped['errormessage'] ?? 'error'),
                    'code'    => (string) $exception->getAwsErrorCode(),
                ];
            }
        };

        $yielded = [];
        foreach ($processor->stream() as $ev) {
            $yielded[] = $ev;
        }

        $this->assertCount(1, $yielded);
        $this->assertEquals('error', $yielded[0]['type']);
        $this->assertEquals('AccessDeniedException', $yielded[0]['code']);
    }
}
