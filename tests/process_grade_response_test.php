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
use aiprovider_awsbr\aiactions\grade_response;
use aiprovider_awsbr\aiactions\responses\response_grade_response;

/**
 * PHPUnit tests for the grade_response action and process_grade_response.
 *
 * Uses Aws\MockHandler to fake Bedrock Converse responses without network calls.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\process_grade_response::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\aiactions\grade_response::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\aiactions\responses\response_grade_response::class)]
final class process_grade_response_test extends \advanced_testcase {
    /** Model with sampling parameters and no effort support. */
    private const SONNET45 = 'global.anthropic.claude-sonnet-4-5-20250929-v1:0';

    /** Newer model that rejects sampling parameters and accepts effort. */
    private const HAIKU55 = 'global.anthropic.claude-haiku-5-5';

    /** A model that is not offered by the test provider's allowed models. */
    private const OPUS_ID = 'global.anthropic.claude-opus-9';

    /** @var \core_ai\manager */
    private \core_ai\manager $manager;

    /** @var provider The provider instance. */
    private provider $provider;

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
            'availablemodels'       => self::SONNET45 . "|Claude Sonnet 4.5\n" . self::HAIKU55 . "|Claude Haiku 5.5\n",
            'enableuserratelimit'   => false,
            'userratelimit'         => 0,
            'enableglobalratelimit' => false,
            'globalratelimit'       => 0,
        ];
    }

    /**
     * Per-action config for grade_response.
     *
     * @param string $model
     * @param string $effort
     * @param int $maxtokens
     * @return array
     */
    private function action_config(string $model = self::SONNET45, string $effort = '', int $maxtokens = 2048): array {
        return [
            grade_response::class => [
                'enabled'  => true,
                'settings' => ['model' => $model, 'maxtokens' => $maxtokens, 'effort' => $effort],
            ],
        ];
    }

    /**
     * Build a grade_response action.
     *
     * @param array $overrides Named constructor arguments to replace.
     * @return grade_response
     */
    private function make_action(array $overrides = []): grade_response {
        $args = array_merge([
            'contextid'       => 1,
            'userid'          => 1,
            'systemprompt'    => 'You are a strict marker.',
            'cachedprompt'    => 'Question and rubric.',
            'prompttext'      => 'Student answer: secret-student-text',
            'outputschema'    => [
                'type'       => 'object',
                'properties' => ['score' => ['type' => 'number']],
                'required'   => ['score'],
            ],
            'modelid'         => '',
            'maxtokens'       => 0,
        ], $overrides);
        return new grade_response(...$args);
    }

    /**
     * A Converse success payload in which the model answered with a JSON text block.
     *
     * @param array $content Content blocks to return.
     * @param string $stopreason
     * @return array
     */
    private static function converse_data(array $content = [], string $stopreason = 'end_turn'): array {
        $content = $content ?: [['text' => '{"score": 7.5}']];
        return [
            'ResponseMetadata' => ['RequestId' => 'req-grade-1'],
            'output'           => ['message' => ['role' => 'assistant', 'content' => $content]],
            'stopReason'       => $stopreason,
            'usage'            => [
                'inputTokens'           => 120,
                'outputTokens'          => 30,
                'cacheReadInputTokens'  => 100,
                'cacheWriteInputTokens' => 20,
            ],
        ];
    }

    /**
     * Build a processor whose Converse client is an Aws\MockHandler. It records
     * every request (the number of entries in $processor->requests is the number
     * of Bedrock calls) and otherwise mirrors abstract_processor::call_converse().
     *
     * @param provider $provider
     * @param grade_response $action
     * @param MockHandler $mock
     * @return process_grade_response
     */
    private function make_processor(provider $provider, grade_response $action, MockHandler $mock): process_grade_response {
        return new class ($provider, $action, $mock) extends process_grade_response {
            /** @var array Converse arguments of each call made. */
            public array $requests = [];

            /** @var string[] Regions of each call made. */
            public array $regions = [];

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
             * Run the Converse call against the mock client.
             *
             * @param array $args The Bedrock Converse request arguments.
             * @param string|null $region The AWS region to target.
             * @return array|null The processed result, or null to signal a throttle retry.
             */
            protected function call_converse(array $args, ?string $region): ?array {
                $this->requests[] = $args;
                $this->regions[] = $region;
                $client = new \Aws\BedrockRuntime\BedrockRuntimeClient([
                    'region'      => $region ?? 'af-south-1',
                    'version'     => 'latest',
                    'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
                    // No SDK-level retries: the throttling retry under test is the provider's.
                    'retries'     => 0,
                    'handler'     => $this->mockhandler,
                ]);
                try {
                    return $this->handle_api_success($client->converse($args));
                } catch (\Aws\Exception\AwsException $e) {
                    if ($e->getAwsErrorCode() === 'ThrottlingException') {
                        return null;
                    }
                    return $this->map_aws_exception($e);
                }
            }
        };
    }

    /**
     * Build the Converse arguments for an action and model/effort settings.
     *
     * @param grade_response $action
     * @param string $model Action-configured model.
     * @param string $effort Action-configured effort.
     * @return array
     */
    private function args_for(grade_response $action, string $model = self::SONNET45, string $effort = ''): array {
        $provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->action_config($model, $effort),
        );
        $processor = new process_grade_response($provider, $action);
        return (new \ReflectionMethod($processor, 'build_request_args'))->invoke($processor);
    }

    /**
     * Put a live profile list in the catalog cache, so no AWS call is made.
     *
     * @param provider $provider The provider instance.
     * @param string[] $ids The invokable profile ids.
     */
    private function seed_catalog(provider $provider, array $ids): void {
        $models = [];
        foreach ($ids as $id) {
            $models[] = ['id' => $id, 'name' => 'Live ' . $id, 'status' => 'ACTIVE'];
        }
        \cache::make('aiprovider_awsbr', 'model_catalog')->set(model_catalog::cache_key($provider), $models);
    }

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->manager = \core\di::get(\core_ai\manager::class);

        $this->provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'test-bedrock-grade',
            config:    $this->provider_config(),
        );
        $this->provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->action_config(),
        );
        $this->seed_catalog($this->provider, [self::SONNET45, self::HAIKU55, self::OPUS_ID]);
        // The request shape must not depend on the AWS SDK of the Moodle under test.
        process_grade_response::set_cache_points_for_testing(true);
    }

    protected function tearDown(): void {
        process_grade_response::set_cache_points_for_testing(null);
        parent::tearDown();
    }

    // Request shape.

    /**
     * The Converse request has cache points, the output format and the configured limits.
     */
    public function test_build_request_args_shape(): void {
        $action = $this->make_action();
        $args = $this->args_for($action);

        $this->assertSame(self::SONNET45, $args['modelId']);
        $this->assertSame(
            [['text' => 'You are a strict marker.'], ['cachePoint' => ['type' => 'default']]],
            $args['system'],
        );
        $this->assertCount(1, $args['messages']);
        $this->assertSame('user', $args['messages'][0]['role']);
        $this->assertSame(
            [
                ['text' => 'Question and rubric.'],
                ['cachePoint' => ['type' => 'default']],
                ['text' => 'Student answer: secret-student-text'],
            ],
            $args['messages'][0]['content'],
        );

        $this->assertArrayNotHasKey('toolConfig', $args);
        $this->assertSame(
            ['output_config' => ['format' => ['type' => 'json_schema', 'schema' => $action->get_configuration('outputschema')]]],
            $args['additionalModelRequestFields'],
        );

        $this->assertSame(2048, $args['inferenceConfig']['maxTokens']);
        $this->assertStringNotContainsString('thinking', json_encode($args));
    }

    /**
     * Without a cached prompt the user message has no cache point and no empty text block.
     */
    public function test_build_request_args_without_cachedprompt(): void {
        $args = $this->args_for($this->make_action(['cachedprompt' => '']));

        $this->assertSame(
            [['text' => 'Student answer: secret-student-text']],
            $args['messages'][0]['content'],
        );
        // The system prompt still carries its cache point.
        $this->assertSame(['cachePoint' => ['type' => 'default']], $args['system'][1]);
    }

    /**
     * An AWS SDK without cache points gets none: it would send each as an empty block, which Bedrock rejects.
     */
    public function test_build_request_args_without_sdk_cache_points(): void {
        process_grade_response::set_cache_points_for_testing(false);
        $args = $this->args_for($this->make_action());

        $this->assertSame([['text' => 'You are a strict marker.']], $args['system']);
        $this->assertSame(
            [['text' => 'Question and rubric.'], ['text' => 'Student answer: secret-student-text']],
            $args['messages'][0]['content'],
        );
    }

    /**
     * The check reads the SDK of the Moodle under test.
     */
    public function test_sdk_cache_points_are_read_from_the_sdk(): void {
        process_grade_response::set_cache_points_for_testing(null);
        $api = (\Aws\Api\ApiProvider::defaultProvider())('api', 'bedrock-runtime', '2023-09-30');
        $this->assertSame(
            isset($api['shapes']['SystemContentBlock']['members']['cachePoint']),
            process_grade_response::sdk_has_cache_points(),
        );
    }

    /**
     * Temperature 0 is sent only to models that accept sampling parameters.
     */
    public function test_build_request_args_temperature_per_model_family(): void {
        $action = $this->make_action();

        $old = $this->args_for($action, self::SONNET45);
        $this->assertSame(0, $old['inferenceConfig']['temperature']);
        $this->assertArrayNotHasKey('topP', $old['inferenceConfig']);

        $new = $this->args_for($action, self::HAIKU55);
        $this->assertSame(['maxTokens' => 2048], $new['inferenceConfig']);
    }

    /**
     * Effort is merged into output_config next to the format, only when configured and supported.
     */
    public function test_build_request_args_effort(): void {
        $action = $this->make_action();
        $schema = $action->get_configuration('outputschema');
        $format = ['type' => 'json_schema', 'schema' => $schema];

        $args = $this->args_for($action, self::HAIKU55, 'low');
        $this->assertSame(
            ['output_config' => ['format' => $format, 'effort' => 'low']],
            $args['additionalModelRequestFields'],
        );

        foreach ([[self::HAIKU55, ''], [self::HAIKU55, 'turbo'], [self::SONNET45, 'low']] as [$model, $effort]) {
            $fields = $this->args_for($action, $model, $effort)['additionalModelRequestFields'];
            $this->assertSame(['output_config' => ['format' => $format]], $fields);
        }
    }

    /**
     * A caller's model and max tokens win over the action settings.
     */
    public function test_build_request_args_caller_overrides(): void {
        $args = $this->args_for($this->make_action(['modelid' => self::HAIKU55, 'maxtokens' => 512]));

        $this->assertSame(self::HAIKU55, $args['modelId']);
        $this->assertSame(['maxTokens' => 512], $args['inferenceConfig']);
    }

    /**
     * With no configured model the first availablemodels entry is used.
     */
    public function test_build_request_args_falls_back_to_first_available_model(): void {
        $args = $this->args_for($this->make_action(), '');
        $this->assertSame(self::SONNET45, $args['modelId']);
    }

    // Success and failure of process().

    /**
     * The parsed JSON comes back as the result with every token field.
     */
    public function test_process_success(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $mock = new MockHandler([new Result(self::converse_data())]);
        $processor = $this->make_processor($this->provider, $this->make_action(), $mock);

        $response = $processor->process();

        $this->assertInstanceOf(response_grade_response::class, $response);
        $this->assertTrue($response->get_success());
        $this->assertSame('grade_response', $response->get_actionname());
        $this->assertCount(1, $processor->requests);
        $this->assertSame(self::SONNET45, $response->get_model_used());

        $data = $response->get_response_data();
        $this->assertSame(['score' => 7.5], $data['result']);
        $this->assertSame(['score' => 7.5], $response->get_result());
        $this->assertSame(self::SONNET45, $data['model']);
        $this->assertSame(120, $data['prompttokens']);
        $this->assertSame(30, $data['completiontokens']);
        $this->assertSame(100, $data['cachereadtokens']);
        $this->assertSame(20, $data['cachewritetokens']);
        $this->assertSame('end_turn', $data['stopreason']);
        $this->assertSame('req-grade-1', $data['id']);
    }

    /**
     * Cache token counts default to 0 when Bedrock omits them.
     */
    public function test_process_success_without_cache_usage(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $data = self::converse_data();
        $data['usage'] = ['inputTokens' => 5, 'outputTokens' => 2];
        $processor = $this->make_processor($this->provider, $this->make_action(), new MockHandler([new Result($data)]));

        $response = $processor->process()->get_response_data();

        $this->assertSame(0, $response['cachereadtokens']);
        $this->assertSame(0, $response['cachewritetokens']);
    }

    /**
     * Reasoning blocks are ignored and every text block is concatenated.
     */
    public function test_process_reasoning_and_split_text(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $data = self::converse_data([
            ['reasoningContent' => ['reasoningText' => ['text' => '{"score": 1}']]],
            ['text' => '{"score": '],
            ['text' => '9}'],
        ]);
        $processor = $this->make_processor($this->provider, $this->make_action(), new MockHandler([new Result($data)]));

        $response = $processor->process();

        $this->assertTrue($response->get_success());
        $this->assertSame(['score' => 9], $response->get_result());
    }

    /**
     * A reply cut off at max_tokens is a 422 Truncated error that does not leak the model text.
     */
    public function test_process_truncated(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $data = self::converse_data([['text' => '{"score": secret-model-text']], 'max_tokens');
        $processor = $this->make_processor($this->provider, $this->make_action(), new MockHandler([new Result($data)]));

        $response = $processor->process();

        $this->assertFalse($response->get_success());
        $this->assertSame(422, $response->get_errorcode());
        $this->assertStringContainsString('max_tokens', $response->get_errormessage());
        $this->assertStringNotContainsString('secret-model-text', $response->get_errormessage());
        if (method_exists($response, 'get_error')) {
            $this->assertSame('Truncated', $response->get_error());
        }
    }

    /**
     * Text that is not a JSON object/array is a 422 InvalidJson error that does not leak the model text.
     */
    public function test_process_invalid_json(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $data = self::converse_data([['text' => 'I think secret-model-text.']]);
        $processor = $this->make_processor($this->provider, $this->make_action(), new MockHandler([new Result($data)]));

        $response = $processor->process();

        $this->assertFalse($response->get_success());
        $this->assertSame(422, $response->get_errorcode());
        $this->assertStringNotContainsString('secret-model-text', $response->get_errormessage());
        if (method_exists($response, 'get_error')) {
            $this->assertSame('InvalidJson', $response->get_error());
        }
    }

    // Model allow-list.

    /**
     * A model override that is not available is refused and AWS is never called.
     */
    public function test_process_model_not_allowed(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $mock = new MockHandler([new Result(self::converse_data())]);
        $processor = $this->make_processor(
            $this->provider,
            $this->make_action(['modelid' => 'global.anthropic.claude-opus-9']),
            $mock,
        );

        $response = $processor->process();

        $this->assertFalse($response->get_success());
        $this->assertSame(400, $response->get_errorcode());
        if (method_exists($response, 'get_error')) {
            $this->assertSame('ModelNotAllowed', $response->get_error());
        }
        $this->assertCount(0, $processor->requests, 'The Bedrock client must not be called.');
        $this->assertCount(1, $mock, 'The queued mock result must be untouched.');
    }

    /**
     * An override is matched on the id before the "|" label, and is then used.
     */
    public function test_process_model_override_in_availablemodels(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $processor = $this->make_processor(
            $this->provider,
            $this->make_action(['modelid' => self::HAIKU55]),
            new MockHandler([new Result(self::converse_data())]),
        );

        $response = $processor->process();

        $this->assertTrue($response->get_success());
        $this->assertSame(self::HAIKU55, $processor->requests[0]['modelId']);
        $this->assertSame(self::HAIKU55, $response->get_model_used());
        // The label is not part of the id.
        $this->assertFalse($this->make_processor(
            $this->provider,
            $this->make_action(['modelid' => self::HAIKU55 . '|Claude Haiku 5.5']),
            new MockHandler([]),
        )->process()->get_success());
    }

    /**
     * With availablemodels blank, any model the live catalog offers may be requested.
     */
    public function test_process_model_override_allowed_when_availablemodels_blank(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'test-bedrock-grade-blank-list',
            config:    array_merge($this->provider_config(), ['availablemodels' => '']),
        );
        $this->seed_catalog($provider, [self::SONNET45, self::HAIKU55]);

        $processor = $this->make_processor(
            $provider,
            $this->make_action(['modelid' => self::HAIKU55]),
            new MockHandler([new Result(self::converse_data())]),
        );
        $this->assertTrue($processor->process()->get_success());
        $this->assertSame(self::HAIKU55, $processor->requests[0]['modelId']);

        // A model the catalog does not offer is still refused.
        $refused = $this->make_processor(
            $provider,
            $this->make_action(['modelid' => self::OPUS_ID]),
            new MockHandler([]),
        );
        $this->assertSame(400, $refused->process()->get_errorcode());
    }

    /**
     * With no configured model and a blank availablemodels, the first live model is used.
     */
    public function test_build_request_args_uses_first_live_model_when_availablemodels_blank(): void {
        $provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'test-bedrock-grade-blank-first',
            config:    array_merge($this->provider_config(), ['availablemodels' => '']),
        );
        $this->seed_catalog($provider, [self::SONNET45, self::HAIKU55]);
        $provider = $this->manager->update_provider_instance(
            provider:     $provider,
            actionconfig: $this->action_config(''),
        );
        $processor = new process_grade_response($provider, $this->make_action());
        $args = (new \ReflectionMethod($processor, 'build_request_args'))->invoke($processor);
        $this->assertSame(self::SONNET45, $args['modelId'], 'the first model of the catalog');
    }

    /**
     * The action-configured model may be requested even if availablemodels is blank.
     */
    public function test_process_model_override_equal_to_configured_model(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'test-bedrock-grade-blank',
            config:    array_merge($this->provider_config(), ['availablemodels' => '']),
        );
        $provider = $this->manager->update_provider_instance(
            provider:     $provider,
            actionconfig: $this->action_config(self::HAIKU55),
        );
        // The catalog cannot be loaded (nothing cached for this instance and no AWS).
        $this->seed_catalog($provider, []);

        $allowed = $this->make_processor(
            $provider,
            $this->make_action(['modelid' => self::HAIKU55]),
            new MockHandler([new Result(self::converse_data())]),
        );
        $this->assertTrue($allowed->process()->get_success());

        $refused = $this->make_processor(
            $provider,
            $this->make_action(['modelid' => self::SONNET45]),
            new MockHandler([]),
        );
        $this->assertSame(400, $refused->process()->get_errorcode());
        $this->assertCount(0, $refused->requests);
    }

    // AWS errors.

    /**
     * A ValidationException maps to a 400 response named after the AWS error code.
     */
    public function test_process_validation_exception(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $exception = new AwsException(
            'ValidationException',
            new Command('Converse'),
            ['code' => 'ValidationException', 'message' => 'Malformed input request.'],
        );
        $processor = $this->make_processor($this->provider, $this->make_action(), new MockHandler([$exception]));

        $response = $processor->process();

        $this->assertFalse($response->get_success());
        $this->assertSame(400, $response->get_errorcode());
        $this->assertSame('Malformed input request.', $response->get_errormessage());
        if (method_exists($response, 'get_error')) {
            $this->assertSame('ValidationException', $response->get_error());
        }
    }

    /**
     * A throttled primary region is retried once on the fallback region.
     */
    public function test_process_throttling_then_fallback_success(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'test-bedrock-grade-fallback',
            config:    array_merge($this->provider_config(), ['fallbackregion' => 'eu-west-1']),
        );
        $provider = $this->manager->update_provider_instance(
            provider:     $provider,
            actionconfig: $this->action_config(),
        );
        $throttle = new AwsException(
            'ThrottlingException',
            new Command('Converse'),
            ['code' => 'ThrottlingException', 'message' => 'Too many requests.'],
        );
        $processor = $this->make_processor(
            $provider,
            $this->make_action(),
            new MockHandler([$throttle, new Result(self::converse_data())]),
        );

        $response = $processor->process();

        $this->assertTrue($response->get_success());
        $this->assertSame([null, 'eu-west-1'], $processor->regions);
        $this->assertSame(['score' => 7.5], $response->get_result());
    }

    /**
     * Throttling with no fallback region is a single attempt that fails.
     */
    public function test_process_throttling_without_fallback(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $throttle = new AwsException(
            'ThrottlingException',
            new Command('Converse'),
            ['code' => 'ThrottlingException', 'message' => 'Too many requests.'],
        );
        $processor = $this->make_processor($this->provider, $this->make_action(), new MockHandler([$throttle]));

        $response = $processor->process();

        $this->assertFalse($response->get_success());
        $this->assertCount(1, $processor->requests);
    }

    // Audit storage.

    /**
     * store() writes one audit row with token counts and a prompt hash, never the prompt text.
     */
    public function test_store_writes_audit_row_without_prompt_text(): void {
        global $DB;
        $this->setUser($this->getDataGenerator()->create_user());
        $action = $this->make_action();
        $processor = $this->make_processor(
            $this->provider,
            $action,
            new MockHandler([new Result(self::converse_data())]),
        );
        $response = $processor->process();

        $id = $action->store($response);

        $row = $DB->get_record('aiprovider_awsbr_grade_resp', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(self::SONNET45, $row->model);
        $this->assertEquals(120, $row->prompttokens);
        $this->assertEquals(30, $row->completiontokens);
        $this->assertEquals(100, $row->cachereadtokens);
        $this->assertEquals(20, $row->cachewritetokens);
        $this->assertSame('end_turn', $row->stopreason);
        $this->assertSame('req-grade-1', $row->responseid);
        $this->assertSame(
            hash('sha256', "You are a strict marker.|Question and rubric.|Student answer: secret-student-text"),
            $row->prompthash,
        );
        $this->assertGreaterThan(0, (int) $row->timecreated);

        $dump = json_encode($row);
        $this->assertStringNotContainsString('secret-student-text', $dump);
        $this->assertStringNotContainsString('strict marker', $dump);
        $this->assertStringNotContainsString('Question and rubric', $dump);

        // A second call gets its own row (unique actionid for ai_action_register).
        $this->assertNotSame($id, $action->store($response));
    }

    /**
     * A failed response is stored too, with null token fields.
     */
    public function test_store_failed_response(): void {
        global $DB;
        $this->setUser($this->getDataGenerator()->create_user());
        $action = $this->make_action(['modelid' => 'not.listed']);
        $response = $this->make_processor($this->provider, $action, new MockHandler([]))->process();
        $this->assertFalse($response->get_success());

        $row = $DB->get_record('aiprovider_awsbr_grade_resp', ['id' => $action->store($response)], '*', MUST_EXIST);

        $this->assertNull($row->model);
        $this->assertEquals(0, $row->prompttokens);
        $this->assertSame($action->get_prompt_hash(), $row->prompthash);
    }

    // Validation and wiring.

    /**
     * An output schema that is not of type object is rejected.
     */
    public function test_non_object_schema_throws(): void {
        foreach ([[], ['type' => 'array', 'items' => ['type' => 'string']]] as $schema) {
            try {
                $this->make_action(['outputschema' => $schema]);
                $this->fail('Expected coding_exception for schema ' . json_encode($schema));
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('type object', $e->getMessage());
            }
        }
    }

    /**
     * An empty system prompt or prompt text is rejected.
     */
    public function test_empty_prompts_throw(): void {
        try {
            $this->make_action(['systemprompt' => '  ']);
            $this->fail('Expected a coding_exception for the system prompt.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('system prompt', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        $this->make_action(['prompttext' => '']);
    }

    /**
     * Every constructor value is exposed through get_configuration().
     */
    public function test_configuration_exposes_properties(): void {
        $action = $this->make_action(['modelid' => self::HAIKU55, 'maxtokens' => 99]);

        foreach (
            [
                'userid' => 1,
                'systemprompt' => 'You are a strict marker.',
                'cachedprompt' => 'Question and rubric.',
                'prompttext' => 'Student answer: secret-student-text',
                'modelid' => self::HAIKU55,
                'maxtokens' => 99,
            ] as $name => $value
        ) {
            $this->assertSame($value, $action->get_configuration($name), $name);
        }
        $this->assertSame('object', $action->get_configuration('outputschema')['type']);
        $this->assertSame(response_grade_response::class, grade_response::get_response_classname());
    }

    /**
     * The action is listed, enabled by default on new instances, and has a settings form.
     */
    public function test_provider_wiring(): void {
        $this->assertContains(grade_response::class, provider::get_action_list());

        $defaults = provider::initialise_action_settings();
        $this->assertTrue($defaults[grade_response::class]['enabled']);
        $this->assertSame(2048, $defaults[grade_response::class]['settings']['maxtokens']);
        $this->assertSame('', $defaults[grade_response::class]['settings']['effort']);

        $this->assertTrue($this->manager->is_action_enabled('aiprovider_awsbr', grade_response::class, $this->provider->id));
        $this->assertInstanceOf(
            \aiprovider_awsbr\form\action_grade_response_form::class,
            provider::get_action_settings(grade_response::class, ['providerid' => $this->provider->id]),
        );
    }
}
