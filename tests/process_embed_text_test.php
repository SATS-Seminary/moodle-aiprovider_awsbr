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
use aiprovider_awsbr\aiactions\embed_text;

/**
 * PHPUnit tests for process_embed_text.
 *
 * Uses a mock InvokeModel sub-class pattern (matching the generate_text test style)
 * to fake Bedrock API responses without network calls.
 *
 * Coverage targets:
 *  - Successful embed → returns vector of expected dimension
 *  - Token count returned from Titan v2 response
 *  - Cohere response shape (embeddings: [[...]]) unpacked correctly
 *  - Error handling: throttling, access denied, model not ready
 *  - Throttle + fallback region retry (two calls)
 *  - Throttle with no fallback → returns 429
 *  - Empty / malformed vector detection
 *  - build_request_body shape for Titan vs Cohere
 *  - parse_response_body for Titan vs Cohere
 *  - map_aws_exception error code mapping
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_awsbr\process_embed_text
 */
final class process_embed_text_test extends \advanced_testcase {

    /** @var \core_ai\manager */
    private \core_ai\manager $manager;

    /** @var provider The provider instance. */
    private provider $provider;

    /** @var embed_text The action instance. */
    private embed_text $action;

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * A sample 1024-dim float vector for test payloads (first 4 values differ).
     */
    private static function sample_vector(int $dim = 4): array {
        $v = [];
        for ($i = 0; $i < $dim; $i++) {
            $v[] = round(0.1 * ($i + 1), 4);
        }
        return $v;
    }

    /**
     * Titan v2 InvokeModel success response body (JSON string).
     */
    private static function titan_response_body(int $dim = 4, int $tokens = 7): string {
        return json_encode([
            'embedding'           => self::sample_vector($dim),
            'inputTextTokenCount' => $tokens,
        ]);
    }

    /**
     * Cohere v3 InvokeModel success response body (JSON string).
     */
    private static function cohere_response_body(int $dim = 4): string {
        return json_encode([
            'embeddings' => [self::sample_vector($dim)],
            'id'         => 'cohere-abc-123',
            'texts'      => ['text to embed'],
        ]);
    }

    /**
     * Provider config used across tests.
     */
    private function provider_config(): array {
        return [
            'authmode'               => 'static',
            'accesskeyid'            => 'AKIAIOSFODNN7EXAMPLE',
            'secretaccesskey'        => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region'                 => 'af-south-1',
            'fallbackregion'         => '',
            'availablemodels'        => "amazon.titan-embed-text-v2:0|Titan Embed v2\ncohere.embed-multilingual-v3|Cohere Embed v3\n",
            'enableuserratelimit'    => false,
            'userratelimit'          => 0,
            'enableglobalratelimit'  => false,
            'globalratelimit'        => 0,
        ];
    }

    /**
     * Per-action config for the embed_text action.
     */
    private function action_config(string $model = 'amazon.titan-embed-text-v2:0', string $dim = ''): array {
        return [
            embed_text::class => [
                'enabled'  => true,
                'settings' => [
                    'model'      => $model,
                    'dimensions' => $dim,
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helper: build a mockable processor subclass
    // -------------------------------------------------------------------------

    /**
     * Build an anonymous process_embed_text subclass whose call_invoke_model()
     * is driven by a simple $resultsfn callback instead of real AWS.
     *
     * $resultsfn receives ($modelid, $body, $region) and returns an array or null.
     */
    private function make_processor(
        provider $provider,
        embed_text $action,
        callable $resultsfn,
    ): process_embed_text {
        return new class($provider, $action, $resultsfn) extends process_embed_text {
            public function __construct(
                provider $provider,
                embed_text $action,
                private \Closure $fn,
            ) {
                parent::__construct($provider, $action);
            }

            protected function call_invoke_model(string $modelid, string $body, ?string $region): ?array {
                return ($this->fn)($modelid, $body, $region);
            }
        };
    }

    // -------------------------------------------------------------------------
    // Set up
    // -------------------------------------------------------------------------

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->manager = \core\di::get(\core_ai\manager::class);

        $this->provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'test-bedrock-embed',
            config:    $this->provider_config(),
        );
        $this->provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->action_config(),
        );

        $this->action = new embed_text(
            contextid:  1,
            userid:     1,
            prompttext: 'What is the submission deadline?',
        );
    }

    // =========================================================================
    // Tests — build_request_body
    // =========================================================================

    /**
     * Titan v2 request body must contain inputText, normalize, and optional dimensions.
     */
    public function test_build_request_body_titan_default_dim(): void {
        $processor = new process_embed_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'build_request_body');
        $json      = $method->invoke($processor, 'amazon.titan-embed-text-v2:0', 'hello world');

        $data = json_decode($json, true);
        $this->assertEquals('hello world', $data['inputText']);
        $this->assertTrue($data['normalize']);
        $this->assertArrayNotHasKey('dimensions', $data, 'No dimensions key when override is null');
    }

    /**
     * Titan v2 request body includes dimensions when an override is configured.
     */
    public function test_build_request_body_titan_with_dimension_override(): void {
        $provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->action_config('amazon.titan-embed-text-v2:0', '512'),
        );

        $processor = new process_embed_text($provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'build_request_body');
        $json      = $method->invoke($processor, 'amazon.titan-embed-text-v2:0', 'hello world');

        $data = json_decode($json, true);
        $this->assertSame(512, $data['dimensions']);
    }

    /**
     * Cohere v3 request body must contain texts array, input_type, and truncate.
     */
    public function test_build_request_body_cohere(): void {
        $processor = new process_embed_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'build_request_body');
        $json      = $method->invoke($processor, 'cohere.embed-multilingual-v3', 'hello world');

        $data = json_decode($json, true);
        $this->assertEquals(['hello world'], $data['texts']);
        $this->assertEquals('search_document', $data['input_type']);
        $this->assertEquals('END', $data['truncate']);
    }

    // =========================================================================
    // Tests — parse_response_body
    // =========================================================================

    /**
     * parse_response_body correctly unpacks a Titan v2 response.
     */
    public function test_parse_response_body_titan(): void {
        $processor = new process_embed_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'parse_response_body');
        $result    = $method->invoke($processor, 'amazon.titan-embed-text-v2:0', self::titan_response_body(4, 9));

        $this->assertTrue($result['success']);
        $this->assertCount(4, $result['vector']);
        $this->assertEquals(4, $result['dim']);
        $this->assertEquals(9, $result['tokens']);
        $this->assertIsFloat($result['vector'][0]);
    }

    /**
     * parse_response_body correctly unpacks a Cohere v3 response.
     */
    public function test_parse_response_body_cohere(): void {
        $processor = new process_embed_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'parse_response_body');
        $result    = $method->invoke($processor, 'cohere.embed-multilingual-v3', self::cohere_response_body(4));

        $this->assertTrue($result['success']);
        $this->assertCount(4, $result['vector']);
        $this->assertEquals(4, $result['dim']);
        $this->assertEquals(0, $result['tokens']); // Cohere does not return a token count.
    }

    /**
     * parse_response_body returns error when the vector is missing.
     */
    public function test_parse_response_body_empty_vector(): void {
        $processor = new process_embed_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'parse_response_body');
        $result    = $method->invoke($processor, 'amazon.titan-embed-text-v2:0', json_encode(['embedding' => []]));

        $this->assertFalse($result['success']);
        $this->assertEquals(500, $result['errorcode']);
    }

    /**
     * parse_response_body returns error when body is not JSON.
     */
    public function test_parse_response_body_invalid_json(): void {
        $processor = new process_embed_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'parse_response_body');
        $result    = $method->invoke($processor, 'amazon.titan-embed-text-v2:0', 'not json at all');

        $this->assertFalse($result['success']);
        $this->assertEquals(500, $result['errorcode']);
    }

    // =========================================================================
    // Tests — successful process() end-to-end (Titan v2)
    // =========================================================================

    /**
     * A full process() call returns a successful response_embed_text with vector + tokens.
     */
    public function test_process_success_titan(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $samplevector = self::sample_vector(4);
        $processor = $this->make_processor(
            $this->provider,
            $this->action,
            function (string $modelid, string $body, ?string $region) use ($samplevector): array {
                return [
                    'success' => true,
                    'vector'  => $samplevector,
                    'dim'     => count($samplevector),
                    'model'   => $modelid,
                    'tokens'  => 7,
                ];
            },
        );

        $response = $processor->process();

        $this->assertInstanceOf(
            \aiprovider_awsbr\aiactions\responses\response_embed_text::class,
            $response,
        );
        $this->assertTrue($response->get_success());
        $this->assertCount(4, $response->get_vector());
        $this->assertEquals(4, $response->get_dim());
        $this->assertEquals(7, $response->get_tokens());
        $this->assertEquals('embed_text', $response->get_actionname());
    }

    // =========================================================================
    // Tests — successful process() end-to-end (Cohere v3)
    // =========================================================================

    /**
     * process() correctly routes and returns a Cohere v3 embedding response.
     */
    public function test_process_success_cohere(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $provider = $this->manager->update_provider_instance(
            provider:     $this->provider,
            actionconfig: $this->action_config('cohere.embed-multilingual-v3'),
        );

        $cohereaction = new embed_text(1, 1, 'Submission deadline question');
        $samplecohvec = self::sample_vector(4);

        $processor = $this->make_processor(
            $provider,
            $cohereaction,
            function (string $modelid, string $body, ?string $region) use ($samplecohvec): array {
                // Verify Cohere body shape.
                $data = json_decode($body, true);
                assert(is_array($data['texts']));
                return [
                    'success' => true,
                    'vector'  => $samplecohvec,
                    'dim'     => count($samplecohvec),
                    'model'   => $modelid,
                    'tokens'  => 0,
                ];
            },
        );

        $response = $processor->process();

        $this->assertTrue($response->get_success());
        $this->assertCount(4, $response->get_vector());
        $this->assertEquals('cohere.embed-multilingual-v3', $response->get_model_used());
    }

    // =========================================================================
    // Tests — error handling
    // =========================================================================

    /**
     * An AccessDeniedException returns a 403 response.
     */
    public function test_process_access_denied(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $processor = $this->make_processor(
            $this->provider,
            $this->action,
            fn() => ['success' => false, 'errorcode' => 403, 'errormessage' => 'Access denied.'],
        );

        $response = $processor->process();
        $this->assertFalse($response->get_success());
        $this->assertEquals(403, $response->get_errorcode());
    }

    /**
     * A ModelNotReadyException returns a 404 response.
     */
    public function test_process_model_not_ready(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $processor = $this->make_processor(
            $this->provider,
            $this->action,
            fn() => ['success' => false, 'errorcode' => 404, 'errormessage' => 'Model not ready.'],
        );

        $response = $processor->process();
        $this->assertFalse($response->get_success());
        $this->assertEquals(404, $response->get_errorcode());
    }

    /**
     * ThrottlingException on primary + successful fallback region returns the vector.
     */
    public function test_process_throttle_then_fallback_success(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'bedrock-embed-fallback',
            config:    array_merge($this->provider_config(), ['fallbackregion' => 'eu-west-1']),
        );
        $provider = $this->manager->update_provider_instance(
            provider:     $provider,
            actionconfig: $this->action_config(),
        );

        $callcount    = 0;
        $samplefbvec  = self::sample_vector(4);

        $processor = $this->make_processor(
            $provider,
            $this->action,
            function (string $modelid, string $body, ?string $region) use (&$callcount, $samplefbvec): ?array {
                $callcount++;
                if ($callcount === 1) {
                    return null; // Throttled on primary.
                }
                return [
                    'success' => true,
                    'vector'  => $samplefbvec,
                    'dim'     => count($samplefbvec),
                    'model'   => $modelid,
                    'tokens'  => 5,
                ];
            },
        );

        $response = $processor->process();
        $this->assertTrue($response->get_success());
        $this->assertEquals(2, $callcount, 'Should have called primary + fallback.');
    }

    /**
     * ThrottlingException on both primary and fallback returns a 429.
     */
    public function test_process_throttle_both_regions(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $provider = $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'bedrock-embed-throttled',
            config:    array_merge($this->provider_config(), ['fallbackregion' => 'eu-west-1']),
        );
        $provider = $this->manager->update_provider_instance(
            provider:     $provider,
            actionconfig: $this->action_config(),
        );

        $callcount = 0;
        $processor = $this->make_processor(
            $provider,
            $this->action,
            function () use (&$callcount): ?array {
                $callcount++;
                return null; // Always throttled.
            },
        );

        $response = $processor->process();
        $this->assertFalse($response->get_success());
        $this->assertEquals(429, $response->get_errorcode());
        $this->assertEquals(2, $callcount);
    }

    /**
     * ThrottlingException with no fallback configured returns a 429 after one call.
     */
    public function test_process_throttle_no_fallback(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $callcount = 0;
        $processor = $this->make_processor(
            $this->provider,
            $this->action,
            function () use (&$callcount): ?array {
                $callcount++;
                return null;
            },
        );

        $response = $processor->process();
        $this->assertFalse($response->get_success());
        $this->assertEquals(429, $response->get_errorcode());
        $this->assertEquals(1, $callcount, 'Only primary should be attempted when no fallback region is set.');
    }

    // =========================================================================
    // Tests — map_aws_exception
    // =========================================================================

    /**
     * @dataProvider aws_error_code_provider
     */
    public function test_map_aws_exception(string $awscode, int $expectedhttpcode): void {
        $processor = new process_embed_text($this->provider, $this->action);
        $method    = new \ReflectionMethod($processor, 'map_aws_exception');

        $exception = new AwsException(
            $awscode,
            new Command('InvokeModel'),
            ['code' => $awscode, 'message' => 'Test error.'],
        );

        $result = $method->invoke($processor, $exception);
        $this->assertFalse($result['success']);
        $this->assertEquals($expectedhttpcode, $result['errorcode']);
    }

    /**
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

    // =========================================================================
    // Tests — response_embed_text class
    // =========================================================================

    /**
     * response_embed_text correctly stores and returns all fields.
     */
    public function test_response_embed_text_set_get_response_data(): void {
        $response = new \aiprovider_awsbr\aiactions\responses\response_embed_text(true);
        $vector   = [0.1, 0.2, -0.3, 0.4];
        $response->set_response_data([
            'vector' => $vector,
            'dim'    => 4,
            'model'  => 'amazon.titan-embed-text-v2:0',
            'tokens' => 12,
        ]);

        $this->assertEquals($vector, $response->get_vector());
        $this->assertEquals(4, $response->get_dim());
        $this->assertEquals(12, $response->get_tokens());
        $this->assertEquals('amazon.titan-embed-text-v2:0', $response->get_model_used());
        $this->assertTrue($response->get_success());
        $this->assertEquals('embed_text', $response->get_actionname());
    }

    /**
     * response_embed_text error constructor sets the error fields.
     */
    public function test_response_embed_text_error(): void {
        $response = new \aiprovider_awsbr\aiactions\responses\response_embed_text(
            success:      false,
            errorcode:    503,
            errormessage: 'Service unavailable.',
        );

        $this->assertFalse($response->get_success());
        $this->assertEquals(503, $response->get_errorcode());
        $this->assertEquals('Service unavailable.', $response->get_errormessage());
        $this->assertNull($response->get_vector());
    }

    // =========================================================================
    // Tests — embed_text action class
    // =========================================================================

    /**
     * embed_text::get_response_classname() returns the plugin-local response class.
     */
    public function test_action_get_response_classname(): void {
        $this->assertEquals(
            \aiprovider_awsbr\aiactions\responses\response_embed_text::class,
            embed_text::get_response_classname(),
        );
    }

    /**
     * embed_text getters return the values passed to the constructor.
     */
    public function test_action_getters(): void {
        $action = new embed_text(contextid: 2, userid: 99, prompttext: 'Hello RAG');

        $this->assertEquals(99, $action->get_userid());
        $this->assertEquals('Hello RAG', $action->get_prompttext());
        $this->assertEquals(99, $action->get_configuration('userid'));
        $this->assertEquals('Hello RAG', $action->get_configuration('prompttext'));
        $this->assertEquals(2, $action->get_configuration('contextid'));
    }

    /**
     * embed_text::store() returns 0 (no secondary table).
     */
    public function test_action_store_returns_zero(): void {
        $response = new \aiprovider_awsbr\aiactions\responses\response_embed_text(true);
        $response->set_response_data(['vector' => [0.1], 'dim' => 1, 'model' => 'titan', 'tokens' => 1]);

        $this->assertEquals(0, $this->action->store($response));
    }
}
