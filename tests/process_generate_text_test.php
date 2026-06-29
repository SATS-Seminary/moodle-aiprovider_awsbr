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

use Aws\MockHandler;
use Aws\Result;
use Aws\Exception\AwsException;
use Aws\Command;

/**
 * PHPUnit tests for process_generate_text.
 *
 * Uses Aws\MockHandler to fake Bedrock API responses without network calls.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_awsbr\process_generate_text
 * @covers     \aiprovider_awsbr\abstract_processor
 */
final class process_generate_text_test extends \advanced_testcase {
    /** @var \core_ai\manager */
    private \core_ai\manager $manager;

    /** @var provider The provider instance. */
    private provider $provider;

    /** @var \core_ai\aiactions\generate_text The action instance. */
    private \core_ai\aiactions\generate_text $action;

    /**
     * Standard Bedrock Converse success payload.
     *
     * @return array
     */
    private static function success_result_data(): array {
        return [
            'ResponseMetadata' => ['RequestId' => 'req-abc-123'],
            'output'           => [
                'message' => [
                    'role'    => 'assistant',
                    'content' => [['text' => 'This is the generated text.']],
                ],
            ],
            'stopReason' => 'end_turn',
            'usage'      => ['inputTokens' => 42, 'outputTokens' => 18],
        ];
    }

    /**
     * Provider config used across tests.
     */
    private function provider_config(): array {
        return [
            'authmode'              => 'static',
            'accesskeyid'          => 'AKIAIOSFODNN7EXAMPLE',
            'secretaccesskey'      => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region'               => 'af-south-1',
            'fallbackregion'       => '',
            'availablemodels'      => "global.anthropic.claude-sonnet-4-5-20250929-v1:0|Claude Sonnet 4.5\n",
            'enableuserratelimit'  => false,
            'userratelimit'        => 0,
            'enableglobalratelimit' => false,
            'globalratelimit'      => 0,
        ];
    }

    /**
     * Per-action config with all required settings.
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
            name:      'test-bedrock',
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

    // -------------------------------------------------------------------------
    // Tests for build_request_args.

    /**
     * A per-request `modeloverride` pinned on the action takes precedence over
     * the configured per-action model — this is how callers (e.g. local_ezra)
     * route different prompts to different models.
     */
    public function test_build_request_args_honours_model_override(): void {
        // Pin an override that differs from the action-configured model.
        @$this->action->modeloverride = 'eu.amazon.nova-lite-v1:0';

        $processor = new process_generate_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'build_request_args');
        $args      = $method->invoke($processor);

        $this->assertEquals('eu.amazon.nova-lite-v1:0', $args['modelId']);
    }

    /**
     * An empty override falls back to the configured per-action model.
     */
    public function test_build_request_args_empty_override_falls_back(): void {
        @$this->action->modeloverride = '';

        $processor = new process_generate_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'build_request_args');
        $args      = $method->invoke($processor);

        $this->assertEquals('global.anthropic.claude-sonnet-4-5-20250929-v1:0', $args['modelId']);
    }

    /**
     * Test that build_request_args produces the correct Bedrock Converse shape.
     */
    public function test_build_request_args_basic(): void {
        $processor = new process_generate_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'build_request_args');
        $args      = $method->invoke($processor);

        $this->assertEquals('global.anthropic.claude-sonnet-4-5-20250929-v1:0', $args['modelId']);
        $this->assertCount(1, $args['messages']);
        $this->assertEquals('user', $args['messages'][0]['role']);
        $this->assertEquals('Tell me something interesting.', $args['messages'][0]['content'][0]['text']);
        $this->assertEquals([['text' => 'You are a helpful assistant.']], $args['system']);
        $this->assertEquals(2048, $args['inferenceConfig']['maxTokens']);
        $this->assertEquals(0.7, $args['inferenceConfig']['temperature']);
        // Claude models reject temperature + topP together, so build_inference_config
        // sends only temperature when it is set — topP is intentionally absent.
        $this->assertArrayNotHasKey('topP', $args['inferenceConfig']);
    }

    /**
     * When systeminstruction is empty, no system key should be present.
     */
    public function test_build_request_args_no_system_instruction(): void {
        $provider = $this->manager->update_provider_instance(
            provider: $this->provider,
            actionconfig: [
                \core_ai\aiactions\generate_text::class => [
                    'enabled'  => true,
                    'settings' => [
                        'model'             => 'global.anthropic.claude-sonnet-4-5-20250929-v1:0',
                        'systeminstruction' => '',
                        'maxtokens'         => 2048,
                        'temperature'       => 0.7,
                        'topp'              => 1.0,
                    ],
                ],
            ],
        );

        $processor = new process_generate_text($provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'build_request_args');
        $args      = $method->invoke($processor);

        $this->assertArrayNotHasKey('system', $args);
    }

    // -------------------------------------------------------------------------
    // Tests for handle_api_success.

    /**
     * Test that handle_api_success correctly unpacks the Bedrock response.
     */
    public function test_handle_api_success(): void {
        $awsresult = new Result(self::success_result_data());
        $processor = new process_generate_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'handle_api_success');
        $result    = $method->invoke($processor, $awsresult);

        $this->assertTrue($result['success']);
        $this->assertEquals('req-abc-123', $result['id']);
        $this->assertEquals('global.anthropic.claude-sonnet-4-5-20250929-v1:0', $result['fingerprint']);
        $this->assertEquals('This is the generated text.', $result['generatedcontent']);
        $this->assertEquals('end_turn', $result['finishreason']);
        $this->assertEquals(42, $result['prompttokens']);
        $this->assertEquals(18, $result['completiontokens']);
        $this->assertEquals('global.anthropic.claude-sonnet-4-5-20250929-v1:0', $result['model']);
    }

    // -------------------------------------------------------------------------
    // Tests for query_ai_api via mock client.

    /**
     * Inject an Aws\MockHandler into the factory by subclassing bedrock_client_factory.
     *
     * @param array $queuedResults Results to queue in the mock.
     * @return MockHandler The mock handler, so tests can inspect call counts.
     */
    private function inject_mock_handler(array $queuedresults): MockHandler {
        $mock = new MockHandler($queuedresults);

        // Monkey-patch: replace the factory singleton for this test by hijacking
        // the get_runtime_client call via a partial mock subclass injected through
        // PHP's anonymous class override capability.
        $provider = $this->provider;
        $testcase = $this;

        // We override bedrock_client_factory inline using a local subclass stored
        // in a variable, then swap the processor's factory call via reflection.
        $factory = new class ($provider, $mock) extends bedrock_client_factory {
            /**
             * Build a factory that hands out clients wired to the mock handler.
             *
             * @param provider $provider The provider instance.
             * @param MockHandler $mockhandler The AWS SDK mock handler.
             */
            public function __construct(
                provider $provider,
                /** @var MockHandler The AWS SDK mock handler. */
                private MockHandler $mockhandler,
            ) {
                parent::__construct($provider);
            }

            /**
             * Return a runtime client wired to the mock handler.
             *
             * @param string|null $region The AWS region.
             * @return \Aws\BedrockRuntime\BedrockRuntimeClient
             */
            public function get_runtime_client(?string $region = null): \Aws\BedrockRuntime\BedrockRuntimeClient {
                return new \Aws\BedrockRuntime\BedrockRuntimeClient([
                    'region'      => $region ?? 'af-south-1',
                    'version'     => 'latest',
                    'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
                    'handler'     => $this->mockhandler,
                ]);
            }
        };

        return $mock;
    }

    /**
     * Test a successful full process() call using Aws\MockHandler.
     */
    public function test_process_success_with_mock_handler(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $mock = new MockHandler([
            new Result(self::success_result_data()),
        ]);

        // Build a processor using an anonymous subclass of abstract_processor that
        // uses our mock client instead of a real one.
        $processor = new class ($this->provider, $this->action, $mock) extends process_generate_text {
            /**
             * Build a processor wired to the mock handler instead of a real client.
             *
             * @param provider $provider The provider instance.
             * @param \core_ai\aiactions\base $action The action to process.
             * @param MockHandler $mockhandler The AWS SDK mock handler.
             */
            public function __construct(
                provider $provider,
                \core_ai\aiactions\base $action,
                /** @var MockHandler The AWS SDK mock handler. */
                private MockHandler $mockhandler,
            ) {
                parent::__construct($provider, $action);
            }

            /**
             * Run the Converse call against the mock client and unpack a success.
             *
             * @param array $args The Bedrock Converse request arguments.
             * @param string|null $region The AWS region to target.
             * @return array|null The processed result, or null to signal a throttle retry.
             */
            protected function call_converse(array $args, ?string $region): ?array {
                $client = new \Aws\BedrockRuntime\BedrockRuntimeClient([
                    'region'      => $region ?? 'af-south-1',
                    'version'     => 'latest',
                    'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
                    'handler'     => $this->mockhandler,
                ]);
                try {
                    $awsresult = $client->converse($args);
                    return $this->handle_api_success($awsresult);
                } catch (\Aws\Exception\AwsException $e) {
                    $code = $e->getAwsErrorCode();
                    if ($code === 'ThrottlingException') {
                        return null;
                    }
                    return $this->map_aws_exception($e);
                }
            }
        };

        $result = $processor->process();
        $this->assertInstanceOf(\core_ai\aiactions\responses\response_base::class, $result);
        $this->assertTrue($result->get_success());
        $this->assertEquals('generate_text', $result->get_actionname());
    }

    /**
     * Test that an AWS error maps to a failed response.
     */
    public function test_process_aws_error_access_denied(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $mockexception = new AwsException(
            'AccessDeniedException',
            new Command('Converse'),
            ['code' => 'AccessDeniedException', 'message' => 'Access denied to the requested resource.'],
        );

        $mock = new MockHandler([$mockexception]);

        $processor = new class ($this->provider, $this->action, $mock) extends process_generate_text {
            /**
             * Build a processor wired to the mock handler instead of a real client.
             *
             * @param provider $provider The provider instance.
             * @param \core_ai\aiactions\base $action The action to process.
             * @param MockHandler $mockhandler The AWS SDK mock handler.
             */
            public function __construct(
                provider $provider,
                \core_ai\aiactions\base $action,
                /** @var MockHandler The AWS SDK mock handler. */
                private MockHandler $mockhandler,
            ) {
                parent::__construct($provider, $action);
            }

            /**
             * Run the Converse call against the mock client, mapping any AWS error.
             *
             * @param array $args The Bedrock Converse request arguments.
             * @param string|null $region The AWS region to target.
             * @return array|null The processed result, or null to signal a throttle retry.
             */
            protected function call_converse(array $args, ?string $region): ?array {
                $client = new \Aws\BedrockRuntime\BedrockRuntimeClient([
                    'region'      => $region ?? 'af-south-1',
                    'version'     => 'latest',
                    'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
                    'handler'     => $this->mockhandler,
                ]);
                try {
                    $client->converse($args);
                    return ['success' => true];
                } catch (\Aws\Exception\AwsException $e) {
                    $code = $e->getAwsErrorCode();
                    if ($code === 'ThrottlingException') {
                        return null;
                    }
                    return $this->map_aws_exception($e);
                }
            }
        };

        $result = $processor->process();
        $this->assertFalse($result->get_success());
        $this->assertEquals(403, $result->get_errorcode());
    }

    /**
     * Test that ThrottlingException triggers a fallback-region retry.
     * When the fallback also throws, an error response is returned.
     */
    public function test_process_throttling_with_fallback(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        // Configure fallback region on the provider.
        $provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'test-bedrock-fallback',
            config:    array_merge($this->provider_config(), ['fallbackregion' => 'eu-west-1']),
        );
        $provider = $this->manager->update_provider_instance(
            provider:     $provider,
            actionconfig: $this->action_config(),
        );

        $callcount = 0;
        $processor = new class ($provider, $this->action, $callcount) extends process_generate_text {
            /**
             * Build a processor that counts Converse calls via a referenced counter.
             *
             * @param provider $provider The provider instance.
             * @param \core_ai\aiactions\base $action The action to process.
             * @param int $callcounter Reference to the caller's Converse call counter.
             */
            public function __construct(
                provider $provider,
                \core_ai\aiactions\base $action,
                /** @var int Reference to the caller's Converse call counter. */
                private int &$callcounter, // phpcs:ignore moodle.Commenting.VariableComment.Missing
            ) {
                parent::__construct($provider, $action);
            }

            /**
             * Throttle on the primary region and return an error on the fallback.
             *
             * @param array $args The Bedrock Converse request arguments.
             * @param string|null $region The AWS region to target.
             * @return array|null The error result, or null to signal a throttle retry.
             */
            protected function call_converse(array $args, ?string $region): ?array {
                $this->callcounter++;
                // Primary region → throttle; fallback region → error.
                if ($this->callcounter === 1) {
                    return null; // Simulate ThrottlingException.
                }
                return ['success' => false, 'errorcode' => 500, 'error' => 'BedrockError',
                    'errormessage' => 'Fallback region also failed.'];
            }
        };

        $result = $processor->process();
        $this->assertFalse($result->get_success());
        $this->assertEquals(2, $callcount, 'Should have been called twice (primary + fallback).');
    }

    /**
     * Test that ThrottlingException with no fallback region returns a 429 error.
     */
    public function test_process_throttling_no_fallback(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        // Provider with no fallbackregion.
        $callcount = 0;
        $processor = new class ($this->provider, $this->action, $callcount) extends process_generate_text {
            /**
             * Build a processor that counts Converse calls via a referenced counter.
             *
             * @param provider $provider The provider instance.
             * @param \core_ai\aiactions\base $action The action to process.
             * @param int $callcounter Reference to the caller's Converse call counter.
             */
            public function __construct(
                provider $provider,
                \core_ai\aiactions\base $action,
                /** @var int Reference to the caller's Converse call counter. */
                private int &$callcounter, // phpcs:ignore moodle.Commenting.VariableComment.Missing
            ) {
                parent::__construct($provider, $action);
            }

            /**
             * Always throttle to simulate a ThrottlingException on every region.
             *
             * @param array $args The Bedrock Converse request arguments.
             * @param string|null $region The AWS region to target.
             * @return array|null Always null to signal a throttle.
             */
            protected function call_converse(array $args, ?string $region): ?array {
                $this->callcounter++;
                return null; // Simulate ThrottlingException always.
            }
        };

        $result = $processor->process();
        $this->assertFalse($result->get_success());
        // Ensure only primary was called (no fallback region configured).
        $this->assertEquals(1, $callcount);
    }

    // -------------------------------------------------------------------------
    // Tests for rate limiting.

    /**
     * Test that user rate limiting is enforced.
     */
    public function test_process_with_user_rate_limiter(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);
        $clock = $this->mock_clock_with_frozen();

        $provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'rate-limited-user',
            config:    array_merge($this->provider_config(), [
                'enableuserratelimit' => true,
                'userratelimit'       => 1,
            ]),
        );
        $provider = $this->manager->update_provider_instance(
            provider:     $provider,
            actionconfig: $this->action_config(),
        );

        // Call count for mock.
        $callcount = 0;
        $makeprocessor = function (int $userid) use ($provider, &$callcount): process_generate_text {
            $action = new \core_ai\aiactions\generate_text(
                contextid:  1,
                userid:     $userid,
                prompttext: 'Hello',
            );
            return new class ($provider, $action, $callcount) extends process_generate_text {
                /**
                 * Build a processor that counts Converse calls via a referenced counter.
                 *
                 * @param provider $provider The provider instance.
                 * @param \core_ai\aiactions\base $action The action to process.
                 * @param int $counter Reference to the caller's Converse call counter.
                 */
                public function __construct(
                    provider $provider,
                    \core_ai\aiactions\base $action,
                    /** @var int Reference to the caller's Converse call counter. */
                    private int &$counter, // phpcs:ignore moodle.Commenting.VariableComment.Missing
                ) {
                    parent::__construct($provider, $action);
                }

                /**
                 * Return a canned successful Converse result and bump the counter.
                 *
                 * @param array $args The Bedrock Converse request arguments.
                 * @param string|null $region The AWS region to target.
                 * @return array|null The canned success result.
                 */
                protected function call_converse(array $args, ?string $region): ?array {
                    $this->counter++;
                    return [
                        'success'          => true,
                        'id'               => 'req-1',
                        'fingerprint'      => 'modelid',
                        'generatedcontent' => 'Hello back!',
                        'finishreason'     => 'end_turn',
                        'prompttokens'     => 5,
                        'completiontokens' => 3,
                        'model'            => 'modelid',
                    ];
                }
            };
        };

        // Request 1 for user1: should succeed.
        $result = $makeprocessor($user1->id)->process();
        $this->assertTrue($result->get_success());

        // Request 2 for user1 within the same hour: rate limited.
        $clock->bump(HOURSECS - 10);
        $result = $makeprocessor($user1->id)->process();
        $this->assertFalse($result->get_success());
        $this->assertEquals(429, $result->get_errorcode());

        // Request for user2 within the same period: should succeed (separate limit).
        $this->setUser($user2);
        $result = $makeprocessor($user2->id)->process();
        $this->assertTrue($result->get_success());
    }

    /**
     * Test that global rate limiting is enforced across users.
     */
    public function test_process_with_global_rate_limiter(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);
        $clock = $this->mock_clock_with_frozen();

        $provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'rate-limited-global',
            config:    array_merge($this->provider_config(), [
                'enableglobalratelimit' => true,
                'globalratelimit'       => 1,
            ]),
        );
        $provider = $this->manager->update_provider_instance(
            provider:     $provider,
            actionconfig: $this->action_config(),
        );

        $makeprocessor = function (int $userid) use ($provider): process_generate_text {
            $action = new \core_ai\aiactions\generate_text(
                contextid:  1,
                userid:     $userid,
                prompttext: 'Hello',
            );
            return new class ($provider, $action) extends process_generate_text {
                /**
                 * Return a canned successful Converse result without any client call.
                 *
                 * @param array $args The Bedrock Converse request arguments.
                 * @param string|null $region The AWS region to target.
                 * @return array|null The canned success result.
                 */
                protected function call_converse(array $args, ?string $region): ?array {
                    return [
                        'success'          => true,
                        'id'               => 'req-1',
                        'fingerprint'      => 'modelid',
                        'generatedcontent' => 'Hello back!',
                        'finishreason'     => 'end_turn',
                        'prompttokens'     => 5,
                        'completiontokens' => 3,
                        'model'            => 'modelid',
                    ];
                }
            };
        };

        // First request: succeeds.
        $result = $makeprocessor($user1->id)->process();
        $this->assertTrue($result->get_success());

        // Second request same hour: global limit hit.
        $clock->bump(HOURSECS - 10);
        $result = $makeprocessor($user2->id)->process();
        $this->assertFalse($result->get_success());
        $this->assertEquals(429, $result->get_errorcode());
        $this->assertEquals('Global rate limit exceeded', $result->get_errormessage());
    }

    // -------------------------------------------------------------------------
    // Tests for map_aws_exception.

    /**
     * Test that all known AWS error codes map to the correct HTTP status codes.
     *
     * @dataProvider aws_error_code_provider
     */
    public function test_map_aws_exception(string $awscode, int $expectedhttpcode): void {
        $processor = new process_generate_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'map_aws_exception');

        $exception = new AwsException(
            $awscode,
            new Command('Converse'),
            ['code' => $awscode, 'message' => 'Test error message.'],
        );

        $result = $method->invoke($processor, $exception);
        $this->assertFalse($result['success']);
        $this->assertEquals($expectedhttpcode, $result['errorcode']);
    }

    /**
     * Data provider mapping AWS error codes to their expected HTTP status codes.
     *
     * @return array
     */
    public static function aws_error_code_provider(): array {
        return [
            'throttling'          => ['ThrottlingException', 429],
            'access_denied'       => ['AccessDeniedException', 403],
            'resource_not_found'  => ['ResourceNotFoundException', 404],
            'model_not_ready'     => ['ModelNotReadyException', 404],
            'validation'          => ['ValidationException', 400],
        ];
    }

    // -------------------------------------------------------------------------
    // Tests for image block building.

    /**
     * Test that build_image_blocks returns empty array for non-existent file.
     */
    public function test_build_image_blocks_missing_file(): void {
        $processor = new process_generate_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'build_image_blocks');
        $blocks    = $method->invoke($processor, '/nonexistent/image.png');
        $this->assertEmpty($blocks);
    }

    /**
     * Test that build_image_blocks returns empty array for unsupported mime type.
     */
    public function test_build_image_blocks_unsupported_mime(): void {
        // Create a temp file masquerading as a TIFF.
        $tmpfile = tempnam(sys_get_temp_dir(), 'moodle_bedrock_test_');
        file_put_contents($tmpfile, 'not a real image');

        $processor = new process_generate_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'build_image_blocks');
        $blocks    = $method->invoke($processor, $tmpfile);
        $this->assertEmpty($blocks);

        unlink($tmpfile);
    }
}
