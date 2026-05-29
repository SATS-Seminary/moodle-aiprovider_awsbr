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

namespace aiprovider_awsbr\integration;

use Aws\MockHandler;
use Aws\Result;
use aiprovider_awsbr\provider;
use aiprovider_awsbr\process_generate_text;
use aiprovider_awsbr\process_embed_text;
use aiprovider_awsbr\aiactions\embed_text;

/**
 * Integration smoke test — full Bedrock pipeline end-to-end with mocked AWS SDK.
 *
 * Exercises:
 *  - generate_text action (also covers summarise_text and explain_text which share the same pipeline)
 *  - embed_text action (Titan v2 shape)
 *  - DB row assertions on ai_action_register + ai_action_generate_text
 *  - Token count pass-through
 *
 * No real AWS credentials are used. All network I/O is replaced by Aws\MockHandler
 * injected through anonymous subclasses of the processor classes (matching the unit-
 * test patterns established in process_generate_text_test and process_embed_text_test).
 *
 * Stream A5 — integration smoke test.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_awsbr\process_generate_text
 * @covers     \aiprovider_awsbr\process_embed_text
 * @covers     \aiprovider_awsbr\provider
 * @group      aiprovider_awsbr_integration
 */
final class full_pipeline_test extends \advanced_testcase {

    // -------------------------------------------------------------------------
    // Constants & shared state
    // -------------------------------------------------------------------------

    /** Primary model used for text-generation actions in these tests. */
    private const GENERATE_MODEL = 'global.anthropic.claude-sonnet-4-5-20250929-v1:0';

    /** Embedding model used for embed_text tests. */
    private const EMBED_MODEL = 'amazon.titan-embed-text-v2:0';

    /** @var \core_ai\manager */
    private \core_ai\manager $manager;

    /** @var provider Configured Bedrock provider instance. */
    private provider $provider;

    /** @var \stdClass A real Moodle test user (not admin). */
    private \stdClass $testuser;

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    /**
     * Provider-level config (no real AWS creds — we never hit the network).
     */
    private function provider_config(): array {
        return [
            'authmode'              => 'static',
            'accesskeyid'          => 'AKIAIOSFODNN7EXAMPLE',
            'secretaccesskey'      => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region'               => 'af-south-1',
            'fallbackregion'       => '',
            'availablemodels'      => self::GENERATE_MODEL . "|Claude Sonnet 4.5\n"
                . self::EMBED_MODEL . "|Titan Embed v2\n",
            'enableuserratelimit'  => false,
            'userratelimit'        => 0,
            'enableglobalratelimit' => false,
            'globalratelimit'      => 0,
        ];
    }

    /**
     * Per-action config shared by generate_text, summarise_text, and explain_text.
     *
     * @param string $actionclass Fully-qualified action class name.
     */
    private function text_action_config(string $actionclass): array {
        return [
            $actionclass => [
                'enabled'  => true,
                'settings' => [
                    'model'             => self::GENERATE_MODEL,
                    'systeminstruction' => 'You are a helpful assistant.',
                    'maxtokens'         => 1024,
                    'temperature'       => 0.7,
                    'topp'              => 1.0,
                ],
            ],
        ];
    }

    /**
     * Per-action config for embed_text.
     */
    private function embed_action_config(): array {
        return [
            embed_text::class => [
                'enabled'  => true,
                'settings' => [
                    'model'      => self::EMBED_MODEL,
                    'dimensions' => '',  // Use model default (1024).
                ],
            ],
        ];
    }

    /**
     * Standard Bedrock Converse success payload mirroring the real API shape.
     *
     * @param string $text       Generated text to return.
     * @param int    $intokens   Simulated prompt token count.
     * @param int    $outtokens  Simulated completion token count.
     */
    private static function converse_success_result(
        string $text = 'Here is a helpful answer.',
        int $intokens = 55,
        int $outtokens = 22,
    ): Result {
        return new Result([
            'ResponseMetadata' => ['RequestId' => 'mock-req-' . uniqid()],
            'output' => [
                'message' => [
                    'role'    => 'assistant',
                    'content' => [['text' => $text]],
                ],
            ],
            'stopReason' => 'end_turn',
            'usage'      => ['inputTokens' => $intokens, 'outputTokens' => $outtokens],
        ]);
    }

    /**
     * Standard Titan v2 InvokeModel success payload.
     *
     * @param int $dim    Vector dimension (default 4 for fast tests).
     * @param int $tokens Input token count returned by Titan.
     */
    private static function titan_invoke_result(int $dim = 4, int $tokens = 9): Result {
        $vector = array_map(fn($i) => round(0.01 * ($i + 1), 4), range(0, $dim - 1));
        return new Result([
            'body'             => json_encode([
                'embedding'           => $vector,
                'inputTextTokenCount' => $tokens,
            ]),
            'contentType'      => 'application/json',
        ]);
    }

    // -------------------------------------------------------------------------
    // setUp
    // -------------------------------------------------------------------------

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->manager  = \core\di::get(\core_ai\manager::class);
        $this->testuser = $this->getDataGenerator()->create_user();

        // Create provider — action configs are applied per-test to keep tests independent.
        $this->provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'integration-test-bedrock',
            config:    $this->provider_config(),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers: mock-injectable processor subclasses
    // -------------------------------------------------------------------------

    /**
     * Build a process_generate_text subclass whose call_converse() uses a mock handler.
     *
     * This avoids any SigV4 signing or real HTTP — the mock returns queued results.
     *
     * @param provider              $provider     Configured provider.
     * @param \core_ai\aiactions\base $action     Action instance.
     * @param MockHandler           $mockhandler  Handler with queued results.
     * @return process_generate_text
     */
    private function make_generate_processor(
        provider $provider,
        \core_ai\aiactions\base $action,
        MockHandler $mockhandler,
    ): process_generate_text {
        return new class($provider, $action, $mockhandler) extends process_generate_text {
            public function __construct(
                provider $provider,
                \core_ai\aiactions\base $action,
                private MockHandler $handler,
            ) {
                parent::__construct($provider, $action);
            }

            protected function call_converse(array $args, ?string $region): ?array {
                $client = new \Aws\BedrockRuntime\BedrockRuntimeClient([
                    'region'      => $region ?? 'af-south-1',
                    'version'     => 'latest',
                    'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
                    'handler'     => $this->handler,
                ]);
                try {
                    $awsresult = $client->converse($args);
                    return $this->handle_api_success($awsresult);
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
     * Build a process_embed_text subclass whose call_invoke_model() uses a mock handler.
     *
     * @param provider    $provider     Configured provider.
     * @param embed_text  $action       Embed action instance.
     * @param MockHandler $mockhandler  Handler with queued results.
     * @return process_embed_text
     */
    private function make_embed_processor(
        provider $provider,
        embed_text $action,
        MockHandler $mockhandler,
    ): process_embed_text {
        return new class($provider, $action, $mockhandler) extends process_embed_text {
            public function __construct(
                provider $provider,
                embed_text $action,
                private MockHandler $handler,
            ) {
                parent::__construct($provider, $action);
            }

            protected function call_invoke_model(string $modelid, string $body, ?string $region): ?array {
                $client = new \Aws\BedrockRuntime\BedrockRuntimeClient([
                    'region'      => $region ?? 'af-south-1',
                    'version'     => 'latest',
                    'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
                    'handler'     => $this->handler,
                ]);
                try {
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
                    if ($e->getAwsErrorCode() === 'ThrottlingException') {
                        return null;
                    }
                    return $this->map_aws_exception($e);
                }
            }
        };
    }

    // =========================================================================
    // Tests — generate_text
    // =========================================================================

    /**
     * Full pipeline: generate_text → mock Converse → response object + DB rows.
     *
     * Asserts:
     *  - Response success flag is true.
     *  - Generated content matches the mock payload.
     *  - ai_action_register row written with correct provider/action.
     *  - ai_action_generate_text row written with prompt/completion token counts.
     */
    public function test_generate_text_full_pipeline(): void {
        global $DB;

        $this->setUser($this->testuser);

        $this->provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->text_action_config(\core_ai\aiactions\generate_text::class),
        );

        $action = new \core_ai\aiactions\generate_text(
            contextid:  \context_system::instance()->id,
            userid:     (int) $this->testuser->id,
            prompttext: 'What is the capital of South Africa?',
        );

        $mockhandler = new MockHandler([self::converse_success_result(
            text:      'The capital of South Africa is Pretoria.',
            intokens:  12,
            outtokens: 9,
        )]);

        $processor = $this->make_generate_processor($this->provider, $action, $mockhandler);
        $response  = $processor->process();

        // Response assertions.
        $this->assertInstanceOf(\core_ai\aiactions\responses\response_generate_text::class, $response);
        $this->assertTrue($response->get_success(), 'Expected a successful response from the mock pipeline.');
        $this->assertEquals(
            'The capital of South Africa is Pretoria.',
            $response->get_generated_content(),
        );

        // DB: ai_action_register.
        $registerrows = $DB->get_records('ai_action_register', [
            'userid'     => (int) $this->testuser->id,
            'actionname' => 'generate_text',
        ]);
        $this->assertCount(1, $registerrows, 'Exactly one ai_action_register row expected after generate_text.');
        $registerrow = reset($registerrows);
        $this->assertEquals('aiprovider_awsbr', $registerrow->provider);
        $this->assertEquals(1, (int) $registerrow->success);

        // DB: ai_action_generate_text.
        $generaterow = $DB->get_record('ai_action_generate_text', ['id' => $registerrow->actionid]);
        $this->assertNotFalse($generaterow, 'ai_action_generate_text row must exist.');
        $this->assertEquals(12, (int) $generaterow->prompttokens);
        $this->assertEquals(9, (int) $generaterow->completiontoken);
        $this->assertEquals(
            'The capital of South Africa is Pretoria.',
            $generaterow->generatedcontent,
        );
    }

    // =========================================================================
    // Tests — summarise_text
    // =========================================================================

    /**
     * Full pipeline: summarise_text — same Converse-based pipeline, different action class.
     *
     * Verifies that the correct action table (ai_action_summarise_text) is written
     * and that token counts pass through.
     */
    public function test_summarise_text_full_pipeline(): void {
        global $DB;

        $this->setUser($this->testuser);

        $this->provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->text_action_config(\core_ai\aiactions\summarise_text::class),
        );

        $action = new \core_ai\aiactions\summarise_text(
            contextid:  \context_system::instance()->id,
            userid:     (int) $this->testuser->id,
            prompttext: 'Please summarise the following long passage about Moodle.',
        );

        $mockhandler = new MockHandler([self::converse_success_result(
            text:      'Moodle is a learning management system.',
            intokens:  30,
            outtokens: 8,
        )]);

        $processor = $this->make_generate_processor($this->provider, $action, $mockhandler);

        // summarise_text uses a subclass of process_generate_text so we must
        // instantiate process_summarise_text — but that class is also just
        // process_generate_text under the hood. For the integration test we
        // use the shared make_generate_processor helper which exercises the
        // shared abstract_processor → Converse pathway.
        $response = $processor->process();

        $this->assertTrue($response->get_success());
        $this->assertEquals('Moodle is a learning management system.', $response->get_generated_content());

        // ai_action_register must record the correct action name.
        $registerrow = $DB->get_record('ai_action_register', [
            'userid'     => (int) $this->testuser->id,
            'actionname' => 'summarise_text',
        ]);
        $this->assertNotFalse($registerrow, 'ai_action_register row for summarise_text must exist.');
        $this->assertEquals('aiprovider_awsbr', $registerrow->provider);

        // Token counts.
        $summaryrow = $DB->get_record('ai_action_summarise_text', ['id' => $registerrow->actionid]);
        $this->assertNotFalse($summaryrow);
        $this->assertEquals(30, (int) $summaryrow->prompttokens);
        $this->assertEquals(8, (int) $summaryrow->completiontoken);
    }

    // =========================================================================
    // Tests — explain_text
    // =========================================================================

    /**
     * Full pipeline: explain_text — verifies the explain_text audit table is written.
     */
    public function test_explain_text_full_pipeline(): void {
        global $DB;

        $this->setUser($this->testuser);

        $this->provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->text_action_config(\core_ai\aiactions\explain_text::class),
        );

        $action = new \core_ai\aiactions\explain_text(
            contextid:  \context_system::instance()->id,
            userid:     (int) $this->testuser->id,
            prompttext: 'Explain what an LMS is in simple terms.',
        );

        $mockhandler = new MockHandler([self::converse_success_result(
            text:      'An LMS (Learning Management System) is software that helps educators deliver courses online.',
            intokens:  18,
            outtokens: 19,
        )]);

        $processor = $this->make_generate_processor($this->provider, $action, $mockhandler);
        $response  = $processor->process();

        $this->assertTrue($response->get_success());

        $registerrow = $DB->get_record('ai_action_register', [
            'userid'     => (int) $this->testuser->id,
            'actionname' => 'explain_text',
        ]);
        $this->assertNotFalse($registerrow);
        $this->assertEquals('aiprovider_awsbr', $registerrow->provider);

        $explainrow = $DB->get_record('ai_action_explain_text', ['id' => $registerrow->actionid]);
        $this->assertNotFalse($explainrow);
        $this->assertEquals(18, (int) $explainrow->prompttokens);
        $this->assertEquals(19, (int) $explainrow->completiontoken);
    }

    // =========================================================================
    // Tests — embed_text (Titan v2)
    // =========================================================================

    /**
     * Full pipeline: embed_text with Titan v2 mock response.
     *
     * Asserts:
     *  - Response success flag is true.
     *  - Response carries a non-empty embedding vector.
     *  - Vector dimension matches the mock payload.
     *  - Token count is passed through from the Titan response body.
     */
    public function test_embed_text_titan_full_pipeline(): void {
        $this->setUser($this->testuser);

        $this->provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->embed_action_config(),
        );

        $action = new embed_text(
            contextid:  \context_system::instance()->id,
            userid:     (int) $this->testuser->id,
            prompttext: 'What are the submission requirements for assignment 3?',
        );

        // 4-dim vector to keep the test fixture small.
        $mockhandler = new MockHandler([self::titan_invoke_result(dim: 4, tokens: 11)]);

        $processor = $this->make_embed_processor($this->provider, $action, $mockhandler);
        $response  = $processor->process();

        // Response object assertions.
        $this->assertInstanceOf(
            \aiprovider_awsbr\aiactions\responses\response_embed_text::class,
            $response,
        );
        $this->assertTrue($response->get_success(), 'Expected a successful embed_text response.');

        $vector = $response->get_embedding();
        $this->assertIsArray($vector, 'get_embedding() must return an array.');
        $this->assertCount(4, $vector, 'Vector dimension must match the mock payload (4).');
        $this->assertEqualsWithDelta(0.01, $vector[0], 0.0001, 'First vector element must match fixture.');

        $this->assertEquals(4, $response->get_dim(), 'get_dim() must match vector length.');
        $this->assertEquals(11, $response->get_prompt_tokens(), 'Token count must match Titan mock payload.');
        $this->assertEquals(self::EMBED_MODEL, $response->get_model(), 'Model string must match configured embed model.');
    }

    /**
     * embed_text smoke test: second distinct prompt in the same test run does not
     * bleed config from the first test (reset DB isolation check).
     */
    public function test_embed_text_second_call_isolated(): void {
        $this->setUser($this->testuser);

        $this->provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->embed_action_config(),
        );

        $action = new embed_text(
            contextid:  \context_system::instance()->id,
            userid:     (int) $this->testuser->id,
            prompttext: 'How do I reset my Moodle password?',
        );

        $mockhandler = new MockHandler([self::titan_invoke_result(dim: 4, tokens: 7)]);

        $processor = $this->make_embed_processor($this->provider, $action, $mockhandler);
        $response  = $processor->process();

        $this->assertTrue($response->get_success());
        $this->assertEquals(7, $response->get_prompt_tokens());
    }

    // =========================================================================
    // Tests — error path smoke
    // =========================================================================

    /**
     * When the Bedrock API returns an error, the response must be a failure object
     * and no audit row should be marked successful.
     */
    public function test_generate_text_error_response_not_stored_as_success(): void {
        global $DB;

        $this->setUser($this->testuser);

        $this->provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->text_action_config(\core_ai\aiactions\generate_text::class),
        );

        $action = new \core_ai\aiactions\generate_text(
            contextid:  \context_system::instance()->id,
            userid:     (int) $this->testuser->id,
            prompttext: 'This request will be rejected.',
        );

        // Simulate an AccessDeniedException.
        $awsexception = new \Aws\Exception\AwsException(
            'AccessDeniedException',
            new \Aws\Command('Converse'),
            ['code' => 'AccessDeniedException', 'message' => 'You do not have permission to invoke this model.'],
        );

        $mockhandler = new MockHandler([$awsexception]);

        $processor = new class($this->provider, $action, $mockhandler) extends process_generate_text {
            public function __construct(
                provider $provider,
                \core_ai\aiactions\base $action,
                private MockHandler $handler,
            ) {
                parent::__construct($provider, $action);
            }

            protected function call_converse(array $args, ?string $region): ?array {
                $client = new \Aws\BedrockRuntime\BedrockRuntimeClient([
                    'region'      => $region ?? 'af-south-1',
                    'version'     => 'latest',
                    'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
                    'handler'     => $this->handler,
                ]);
                try {
                    $client->converse($args);
                    return ['success' => true];
                } catch (\Aws\Exception\AwsException $e) {
                    if ($e->getAwsErrorCode() === 'ThrottlingException') {
                        return null;
                    }
                    return $this->map_aws_exception($e);
                }
            }
        };

        $response = $processor->process();

        $this->assertFalse($response->get_success(), 'A 403 from Bedrock must produce a failed response.');
        $this->assertEquals(403, $response->get_errorcode());

        // The audit row (if written) must mark success = 0.
        $registerrows = $DB->get_records('ai_action_register', [
            'userid'     => (int) $this->testuser->id,
            'actionname' => 'generate_text',
        ]);
        foreach ($registerrows as $row) {
            $this->assertEquals(0, (int) $row->success, 'Failed API calls must not be recorded as successful.');
        }
    }
}
