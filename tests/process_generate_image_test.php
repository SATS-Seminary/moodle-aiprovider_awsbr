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
use core_ai\aiactions\generate_image;

/**
 * PHPUnit tests for process_generate_image.
 *
 * The Bedrock runtime client is replaced by one that answers from a queue of
 * mock results, so nothing is sent to AWS.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\process_generate_image::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\aws_error_mapping::class)]
final class process_generate_image_test extends \advanced_testcase {
    /** The prompt used in the tests; it must never appear in an error message. */
    private const PROMPT = 'A distinctive purple giraffe reading';

    /** @var provider The provider instance. */
    private provider $provider;

    /**
     * A small valid PNG, base64 encoded.
     *
     * @return string
     */
    private static function png_base64(): string {
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 60));
        ob_start();
        imagepng($image);
        return base64_encode((string) ob_get_clean());
    }

    /**
     * The result of a successful InvokeModel call with the given response body.
     *
     * @param array $body The decoded response body.
     * @return Result
     */
    private static function fake_result(array $body): Result {
        return new Result([
            'body'      => json_encode($body),
            '@metadata' => ['statusCode' => 200, 'headers' => ['x-amzn-requestid' => 'req-123']],
        ]);
    }

    /**
     * An AWS exception with an error code and message.
     *
     * @param string $code The AWS error code.
     * @param string $message The AWS error message.
     * @return AwsException
     */
    private static function aws_error(string $code, string $message = 'failed'): AwsException {
        return new AwsException($message, new Command('InvokeModel'), ['code' => $code, 'message' => $message]);
    }

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->provider = $this->make_provider('amazon.nova-canvas-v1:0');
    }

    /**
     * Create a provider instance with the given image model configured for generate_image.
     *
     * @param string $model The model id ('' = none).
     * @param array $extra Extra action settings.
     * @param string $fallbackregion The fallback region.
     * @return provider
     */
    private function make_provider(string $model, array $extra = [], string $fallbackregion = ''): provider {
        $manager = \core\di::get(\core_ai\manager::class);
        $provider = $manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'image-' . random_string(5),
            config:    [
                'authmode'        => 'static',
                'accesskeyid'     => 'AKID',
                'secretaccesskey' => 'SECRET',
                'region'          => 'us-east-1',
                'fallbackregion'  => $fallbackregion,
            ],
        );
        return $manager->update_provider_instance(
            provider:     $provider,
            actionconfig: [generate_image::class => [
                'enabled'  => true,
                'settings' => ['model' => $model] + $extra,
            ]],
        );
    }

    /**
     * Build an action.
     *
     * @param string $quality standard|hd.
     * @param string $aspect square|landscape|portrait.
     * @return generate_image
     */
    private function make_action(string $quality = 'standard', string $aspect = 'square'): generate_image {
        return new generate_image(
            contextid: \context_system::instance()->id,
            userid: (int) $this->getDataGenerator()->create_user()->id,
            prompttext: self::PROMPT,
            quality: $quality,
            aspectratio: $aspect,
            numimages: 1,
            style: 'vivid',
        );
    }

    /**
     * A processor whose runtime client answers from the mock handler.
     *
     * @param provider $provider The provider.
     * @param generate_image $action The action.
     * @param MockHandler $mock The queued results.
     * @param array $regions Receives the region of every client built.
     * @param array $allowed The allowed image models, for an action without a model.
     * @return process_generate_image
     */
    private function make_processor(
        provider $provider,
        generate_image $action,
        MockHandler $mock,
        array &$regions = [],
        array $allowed = ['amazon.nova-canvas-v1:0' => 'Nova Canvas'],
    ): process_generate_image {
        return new class ($provider, $action, $mock, $regions, $allowed) extends process_generate_image {
            /** @var array Receives the region of every client built. */
            private array $regions;

            /**
             * Build a processor with a mocked client.
             *
             * @param provider $provider The provider.
             * @param generate_image $action The action.
             * @param MockHandler $mock The queued results.
             * @param array $regions Receives the region of every client built.
             * @param array $allowed The allowed image models.
             */
            public function __construct(
                provider $provider,
                generate_image $action,
                /** @var MockHandler The queued results. */
                private MockHandler $mock,
                array &$regions,
                /** @var array The allowed image models. */
                private array $allowed,
            ) {
                $this->regions = &$regions;
                parent::__construct($provider, $action);
            }

            #[\Override]
            protected function get_client(?string $region): \Aws\BedrockRuntime\BedrockRuntimeClient {
                $this->regions[] = $region;
                return new \Aws\BedrockRuntime\BedrockRuntimeClient([
                    'region'      => $region ?? 'us-east-1',
                    'version'     => 'latest',
                    'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
                    'retries'     => 0,
                    'handler'     => $this->mock,
                ]);
            }

            #[\Override]
            protected function get_allowed_models(): array {
                return $this->allowed;
            }
        };
    }

    /**
     * The request body for a family, as an array.
     *
     * @param provider $provider The provider.
     * @param generate_image $action The action.
     * @param string $family The model family.
     * @return array
     */
    private function body_for(provider $provider, generate_image $action, string $family): array {
        $processor = new process_generate_image($provider, $action);
        $method = new \ReflectionMethod($processor, 'build_request_body');
        return json_decode($method->invoke($processor, $family), true);
    }

    /**
     * Sizes by family, quality and aspect ratio, checked against the models' documented limits.
     *
     * @return array
     */
    public static function size_provider(): array {
        return [
            'nova standard square'    => ['nova', 'standard', 'square', 1024, 1024, 'standard'],
            'nova standard landscape' => ['nova', 'standard', 'landscape', 1024, 576, 'standard'],
            'nova standard portrait'  => ['nova', 'standard', 'portrait', 576, 1024, 'standard'],
            'nova hd square'          => ['nova', 'hd', 'square', 1792, 1792, 'premium'],
            'nova hd landscape'       => ['nova', 'hd', 'landscape', 1792, 1024, 'premium'],
            'nova hd portrait'        => ['nova', 'hd', 'portrait', 1024, 1792, 'premium'],
            'titan standard square'    => ['titan', 'standard', 'square', 768, 768, 'standard'],
            'titan standard landscape' => ['titan', 'standard', 'landscape', 1152, 768, 'standard'],
            'titan standard portrait'  => ['titan', 'standard', 'portrait', 768, 1152, 'standard'],
            'titan hd square'          => ['titan', 'hd', 'square', 1024, 1024, 'premium'],
            'titan hd landscape'       => ['titan', 'hd', 'landscape', 1408, 768, 'premium'],
            'titan hd portrait'        => ['titan', 'hd', 'portrait', 768, 1408, 'premium'],
        ];
    }

    /**
     * The Nova Canvas and Titan request body.
     *
     * @param string $family nova|titan.
     * @param string $quality standard|hd.
     * @param string $aspect square|landscape|portrait.
     * @param int $width Expected width.
     * @param int $height Expected height.
     * @param string $expectedquality Expected Bedrock quality.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('size_provider')]
    public function test_request_body_nova_and_titan(
        string $family,
        string $quality,
        string $aspect,
        int $width,
        int $height,
        string $expectedquality,
    ): void {
        $body = $this->body_for($this->provider, $this->make_action($quality, $aspect), $family);

        $this->assertSame('TEXT_IMAGE', $body['taskType']);
        $this->assertSame(self::PROMPT, $body['textToImageParams']['text']);
        $this->assertArrayNotHasKey('negativeText', $body['textToImageParams']);
        $config = $body['imageGenerationConfig'];
        $this->assertSame(1, $config['numberOfImages']);
        $this->assertSame($width, $config['width']);
        $this->assertSame($height, $config['height']);
        $this->assertSame($expectedquality, $config['quality']);
        $this->assertArrayNotHasKey('cfgScale', $config);
        $this->assertArrayNotHasKey('seed', $config);
        // The core style option is ignored.
        $this->assertStringNotContainsString('vivid', json_encode($body));

        // Documented limits: multiples of 16, Nova pixel limit, Titan longest side.
        $this->assertSame(0, $width % 16);
        $this->assertSame(0, $height % 16);
        if ($family === 'nova') {
            $this->assertLessThan(4194304, $width * $height);
            $this->assertLessThanOrEqual(4, max($width, $height) / min($width, $height));
        } else {
            $this->assertLessThanOrEqual(1408, max($width, $height));
        }
    }

    /**
     * Every Titan size is in the model's documented list.
     */
    public function test_titan_sizes_are_documented_sizes(): void {
        $documented = ['1024x1024', '768x768', '512x512', '768x1152', '384x576', '1152x768', '576x384', '768x1280',
            '384x640', '1280x768', '640x384', '896x1152', '448x576', '1152x896', '576x448', '768x1408', '384x704',
            '1408x768', '704x384', '640x1408', '320x704', '1408x640', '704x320', '1152x640', '1173x640'];
        foreach (['standard', 'hd'] as $quality) {
            foreach (['square', 'landscape', 'portrait'] as $aspect) {
                $config = $this->body_for($this->provider, $this->make_action($quality, $aspect), 'titan')['imageGenerationConfig'];
                $this->assertContains($config['width'] . 'x' . $config['height'], $documented);
            }
        }
    }

    /**
     * An unknown aspect ratio falls back to square.
     */
    public function test_request_body_unknown_aspect_is_square(): void {
        $config = $this->body_for($this->provider, $this->make_action('standard', 'wide'), 'nova')['imageGenerationConfig'];
        $this->assertSame([1024, 1024], [$config['width'], $config['height']]);
    }

    /**
     * Negative prompt and CFG scale are sent when set and valid.
     */
    public function test_request_body_negative_prompt_and_cfgscale(): void {
        $provider = $this->make_provider('amazon.nova-canvas-v1:0', ['negativeprompt' => ' blurry, text ', 'cfgscale' => '7.5']);
        $body = $this->body_for($provider, $this->make_action(), 'nova');
        $this->assertSame('blurry, text', $body['textToImageParams']['negativeText']);
        $this->assertSame(7.5, $body['imageGenerationConfig']['cfgScale']);
    }

    /**
     * An out-of-range or non-numeric CFG scale is not sent.
     */
    public function test_request_body_invalid_cfgscale_is_dropped(): void {
        foreach (['0.5', '11', 'abc', ''] as $value) {
            $provider = $this->make_provider('amazon.nova-canvas-v1:0', ['cfgscale' => $value]);
            $body = $this->body_for($provider, $this->make_action(), 'nova');
            $this->assertArrayNotHasKey('cfgScale', $body['imageGenerationConfig'], $value);
        }
    }

    /**
     * The Stability request body, for each aspect ratio and quality.
     */
    public function test_request_body_stability(): void {
        $expected = ['square' => '1:1', 'landscape' => '16:9', 'portrait' => '9:16'];
        foreach ($expected as $aspect => $ratio) {
            foreach (['standard', 'hd'] as $quality) {
                $body = $this->body_for($this->provider, $this->make_action($quality, $aspect), 'stability');
                $this->assertSame(
                    ['prompt' => self::PROMPT, 'aspect_ratio' => $ratio, 'output_format' => 'png'],
                    $body
                );
            }
        }
        $provider = $this->make_provider('stability.sd3-5-large-v1:0', ['negativeprompt' => 'blurry', 'cfgscale' => '7']);
        $body = $this->body_for($provider, $this->make_action(), 'stability');
        $this->assertSame('blurry', $body['negative_prompt']);
        $this->assertArrayNotHasKey('cfg_scale', $body);
    }

    /**
     * Model ids map to their families; anything else has none.
     */
    public function test_get_family(): void {
        $this->assertSame('nova', process_generate_image::get_family('amazon.nova-canvas-v1:0'));
        $this->assertSame('titan', process_generate_image::get_family('amazon.titan-image-generator-v2:0'));
        $this->assertSame('stability', process_generate_image::get_family('stability.sd3-5-large-v1:0'));
        $this->assertSame('stability', process_generate_image::get_family('stability.stable-image-core-v1:1'));
        $this->assertSame('stability', process_generate_image::get_family('stability.stable-image-ultra-v1:1'));
        $this->assertNull(process_generate_image::get_family('stability.stable-image-inpaint-v1:0'));
        $this->assertNull(process_generate_image::get_family('amazon.titan-embed-text-v2:0'));
        $this->assertNull(process_generate_image::get_family('anthropic.claude-sonnet'));
    }

    /**
     * Geo and global prefixes are stripped before matching; editing models stay excluded.
     */
    public function test_get_family_with_geo_prefix(): void {
        foreach (['us.', 'eu.', 'apac.', 'global.', 'us-gov.', 'jp.', 'au.', 'ca.'] as $prefix) {
            $core = $prefix . 'stability.stable-image-core-v1:1';
            $this->assertSame('stability', process_generate_image::get_family($core), $prefix);
            $this->assertSame('stability', process_generate_image::get_family($prefix . 'stability.sd3-5-large-v1:0'), $prefix);
            $this->assertSame('nova', process_generate_image::get_family($prefix . 'amazon.nova-canvas-v1:0'), $prefix);
            $this->assertSame('titan', process_generate_image::get_family($prefix . 'amazon.titan-image-generator-v2:0'), $prefix);
            $this->assertNull(process_generate_image::get_family($prefix . 'stability.stable-image-inpaint-v1:0'), $prefix);
            $this->assertNull(process_generate_image::get_family($prefix . 'stability.stable-conservative-upscale-v1:0'), $prefix);
        }
        $this->assertNull(process_generate_image::get_family('xx.stability.stable-image-core-v1:1'));
    }

    /**
     * A geo-prefixed model id is sent to AWS unchanged, in the image region.
     */
    public function test_prefixed_model_is_invoked_with_its_full_id(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('us.stability.stable-image-core-v1:1', ['imageregion' => 'us-west-2']);
        $mock = new MockHandler([self::fake_result(['images' => [self::png_base64()], 'finish_reasons' => [null]])]);
        $regions = [];
        $response = $this->make_processor($provider, $this->make_action(), $mock, $regions)->process();
        $this->assertTrue($response->get_success(), $response->get_errormessage());
        $this->assertSame('us.stability.stable-image-core-v1:1', $mock->getLastCommand()['modelId']);
    }

    /**
     * An image region sends the call there; an empty one uses the instance's region.
     */
    public function test_image_region_selects_the_call_region(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('stability.stable-image-core-v1:1', ['imageregion' => ' us-west-2 ']);
        $mock = new MockHandler([self::fake_result(['images' => [self::png_base64()], 'finish_reasons' => [null]])]);
        $regions = [];
        $response = $this->make_processor($provider, $this->make_action(), $mock, $regions)->process();
        $this->assertTrue($response->get_success(), $response->get_errormessage());
        $this->assertSame(['us-west-2'], $regions);

        $provider = $this->make_provider('stability.stable-image-core-v1:1', ['imageregion' => '']);
        $mock = new MockHandler([self::fake_result(['images' => [self::png_base64()], 'finish_reasons' => [null]])]);
        $regions = [];
        $this->make_processor($provider, $this->make_action(), $mock, $regions)->process();
        $this->assertSame([null], $regions);
    }

    /**
     * With an image region the fallback region is not tried: a throttle is a 429 after one call.
     */
    public function test_fallback_region_is_not_used_with_an_image_region(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('stability.stable-image-core-v1:1', ['imageregion' => 'us-west-2'], 'eu-west-1');
        $mock = new MockHandler([self::aws_error('ThrottlingException')]);
        $regions = [];
        $response = $this->make_processor($provider, $this->make_action(), $mock, $regions)->process();
        $this->assertSame(429, $response->get_errorcode());
        $this->assertSame(['us-west-2'], $regions);
    }

    /**
     * The no-model error points to the image generation region setting.
     */
    public function test_no_model_message_mentions_the_image_region(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $mock = new MockHandler([]);
        $regions = [];
        $response = $this->make_processor($this->make_provider(''), $this->make_action(), $mock, $regions, [])->process();
        $this->assertSame('NoImageModel', $response->get_error());
        $this->assertStringContainsString('image generation region', $response->get_errormessage());
    }

    /**
     * A Nova Canvas call stores a valid, watermarked image in the user's draft area.
     */
    public function test_process_success_creates_draft_file(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $mock = new MockHandler([self::fake_result(['images' => [self::png_base64()]])]);
        $regions = [];
        $processor = $this->make_processor($this->provider, $this->make_action('hd', 'landscape'), $mock, $regions);

        $response = $processor->process();

        $this->assertTrue($response->get_success(), $response->get_errormessage());
        $this->assertInstanceOf(\core_ai\aiactions\responses\response_generate_image::class, $response);
        $data = $response->get_response_data();
        $this->assertInstanceOf(\stored_file::class, $data['draftfile']);
        $file = $data['draftfile'];
        $this->assertSame('user', $file->get_component());
        $this->assertSame('draft', $file->get_filearea());
        $this->assertSame(\context_user::instance($user->id)->id, (int) $file->get_contextid());
        $info = getimagesizefromstring($file->get_content());
        $this->assertNotFalse($info);
        $this->assertSame([300, 200], [$info[0], $info[1]]);
        $this->assertSame(self::PROMPT, $data['revisedprompt']);
        $this->assertSame('amazon.nova-canvas-v1:0', $data['model']);
        $this->assertSame('amazon.nova-canvas-v1:0', $response->get_model_used());
        $this->assertNull($data['sourceurl']);
        $this->assertSame([null], $regions);

        // What was sent.
        $command = $mock->getLastCommand();
        $this->assertSame('amazon.nova-canvas-v1:0', $command['modelId']);
        $sent = json_decode((string) $command['body'], true);
        $this->assertSame(1792, $sent['imageGenerationConfig']['width']);
        $this->assertSame(1, $sent['imageGenerationConfig']['numberOfImages']);
    }

    /**
     * The processor result carries the AWS request id as id and fingerprint.
     */
    public function test_query_returns_request_id(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $mock = new MockHandler([self::fake_result(['images' => [self::png_base64()]])]);
        $regions = [];
        $processor = $this->make_processor($this->provider, $this->make_action(), $mock, $regions);
        $method = new \ReflectionMethod($processor, 'query_ai_api');
        $result = $method->invoke($processor);
        $this->assertTrue($result['success']);
        $this->assertSame('req-123', $result['id']);
        $this->assertSame('req-123', $result['fingerprint']);
    }

    /**
     * A Stability call sends the Stability body and stores the image.
     */
    public function test_process_success_stability(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('stability.stable-image-core-v1:1');
        $mock = new MockHandler([
            self::fake_result(['seeds' => [1], 'finish_reasons' => [null], 'images' => [self::png_base64()]]),
        ]);
        $regions = [];
        $response = $this->make_processor($provider, $this->make_action('standard', 'portrait'), $mock, $regions)->process();

        $this->assertTrue($response->get_success(), $response->get_errormessage());
        $sent = json_decode((string) $mock->getLastCommand()['body'], true);
        $this->assertSame('9:16', $sent['aspect_ratio']);
        $this->assertSame(self::PROMPT, $sent['prompt']);
    }

    /**
     * Assert that a failed response is a content-filter refusal that does not echo the prompt.
     *
     * @param \core_ai\aiactions\responses\response_base $response The response.
     */
    private function assert_content_filtered(\core_ai\aiactions\responses\response_base $response): void {
        $this->assertFalse($response->get_success());
        $this->assertSame('ContentFiltered', $response->get_error());
        $this->assertSame(400, $response->get_errorcode());
        $this->assertSame(
            get_string('error:generate_image:content_filtered', 'aiprovider_awsbr'),
            $response->get_errormessage()
        );
        $this->assertStringNotContainsString('giraffe', $response->get_errormessage());
    }

    /**
     * A Nova or Titan ValidationException about content filters is ContentFiltered.
     */
    public function test_content_filter_exception(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        foreach (['amazon.nova-canvas-v1:0', 'amazon.titan-image-generator-v2:0'] as $model) {
            $provider = $this->make_provider($model);
            $mock = new MockHandler([self::aws_error(
                'ValidationException',
                'Your request was blocked by our content filters. A distinctive purple giraffe reading'
            )]);
            $regions = [];
            $this->assert_content_filtered($this->make_processor($provider, $this->make_action(), $mock, $regions)->process());
        }
    }

    /**
     * A Nova or Titan response with an error and no image is ContentFiltered.
     */
    public function test_content_filter_error_field(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $mock = new MockHandler([self::fake_result(['images' => [], 'error' => 'Blocked: A distinctive purple giraffe reading'])]);
        $regions = [];
        $this->assert_content_filtered($this->make_processor($this->provider, $this->make_action(), $mock, $regions)->process());
    }

    /**
     * Stability filter reasons are ContentFiltered, with or without an images key.
     */
    public function test_content_filter_stability(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('stability.sd3-5-large-v1:0');
        foreach (['Filter reason: prompt', 'Filter reason: output image'] as $reason) {
            $mock = new MockHandler([self::fake_result(['finish_reasons' => [$reason]])]);
            $regions = [];
            $this->assert_content_filtered($this->make_processor($provider, $this->make_action(), $mock, $regions)->process());
        }
        $mock = new MockHandler([self::fake_result(['finish_reasons' => ['Filter reason: prompt'], 'images' => ['x']])]);
        $this->assert_content_filtered($this->make_processor($provider, $this->make_action(), $mock, $regions)->process());
    }

    /**
     * A Stability inference error is a generic failure, not a content filter.
     */
    public function test_stability_inference_error(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('stability.sd3-5-large-v1:0');
        $mock = new MockHandler([self::fake_result(['finish_reasons' => ['Inference error']])]);
        $regions = [];
        $response = $this->make_processor($provider, $this->make_action(), $mock, $regions)->process();
        $this->assertFalse($response->get_success());
        $this->assertSame('BedrockError', $response->get_error());
        $this->assertSame(500, $response->get_errorcode());
    }

    /**
     * A ValidationException that is not about content filters keeps its AWS error name.
     */
    public function test_other_validation_exception(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $mock = new MockHandler([self::aws_error('ValidationException', 'Invalid width.')]);
        $regions = [];
        $response = $this->make_processor($this->provider, $this->make_action(), $mock, $regions)->process();
        $this->assertFalse($response->get_success());
        $this->assertSame('ValidationException', $response->get_error());
        $this->assertSame(400, $response->get_errorcode());
    }

    /**
     * AWS errors are mapped as in embed_text.
     */
    public function test_access_denied(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $mock = new MockHandler([self::aws_error('AccessDeniedException', 'No model access.')]);
        $regions = [];
        $response = $this->make_processor($this->provider, $this->make_action(), $mock, $regions)->process();
        $this->assertSame(403, $response->get_errorcode());
        $this->assertSame('AccessDeniedException', $response->get_error());
    }

    /**
     * Data that is not valid base64 or not an image fails without a coding exception.
     */
    public function test_invalid_image_payloads(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $payloads = ['!!! not base64 !!!', base64_encode('this is text, not an image')];
        foreach ($payloads as $payload) {
            $mock = new MockHandler([self::fake_result(['images' => [$payload]])]);
            $regions = [];
            $response = $this->make_processor($this->provider, $this->make_action(), $mock, $regions)->process();
            $this->assertFalse($response->get_success());
            $this->assertSame('InvalidResponseException', $response->get_error(), $payload);
            $this->assertSame(502, $response->get_errorcode());
        }
    }

    /**
     * A response with no image and no error, or a non-JSON body, fails cleanly.
     */
    public function test_empty_and_malformed_responses(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $mock = new MockHandler([
            self::fake_result(['images' => []]),
            new Result(['body' => 'not json', '@metadata' => ['headers' => []]]),
        ]);
        $regions = [];
        foreach ([0, 1] as $unused) {
            $response = $this->make_processor($this->provider, $this->make_action(), $mock, $regions)->process();
            $this->assertFalse($response->get_success());
            $this->assertSame('BedrockError', $response->get_error());
        }
    }

    /**
     * An unknown model family is an error, and nothing is sent.
     */
    public function test_unknown_model_family(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('anthropic.claude-sonnet-4-5');
        $mock = new MockHandler([]);
        $regions = [];
        $response = $this->make_processor($provider, $this->make_action(), $mock, $regions)->process();
        $this->assertFalse($response->get_success());
        $this->assertSame('UnsupportedModel', $response->get_error());
        $this->assertSame(400, $response->get_errorcode());
        $this->assertSame([], $regions);
    }

    /**
     * An action without a model uses the first allowed image model; with none it fails.
     */
    public function test_empty_model_uses_first_allowed_or_fails(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('');

        $mock = new MockHandler([self::fake_result(['images' => [self::png_base64()]])]);
        $regions = [];
        $allowed = ['amazon.titan-image-generator-v2:0' => 'Titan', 'amazon.nova-canvas-v1:0' => 'Nova'];
        $response = $this->make_processor($provider, $this->make_action(), $mock, $regions, $allowed)->process();
        $this->assertTrue($response->get_success(), $response->get_errormessage());
        $this->assertSame('amazon.titan-image-generator-v2:0', $mock->getLastCommand()['modelId']);

        $mock = new MockHandler([]);
        $response = $this->make_processor($provider, $this->make_action(), $mock, $regions, [])->process();
        $this->assertFalse($response->get_success());
        $this->assertSame('NoImageModel', $response->get_error());
        $this->assertSame(404, $response->get_errorcode());
    }

    /**
     * A throttled call is retried once on the fallback region.
     */
    public function test_throttle_then_fallback_success(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('amazon.nova-canvas-v1:0', [], 'eu-west-1');
        $mock = new MockHandler([
            self::aws_error('ThrottlingException', 'Too many requests'),
            self::fake_result(['images' => [self::png_base64()]]),
        ]);
        $regions = [];
        $response = $this->make_processor($provider, $this->make_action(), $mock, $regions)->process();
        $this->assertTrue($response->get_success(), $response->get_errormessage());
        $this->assertSame([null, 'eu-west-1'], $regions);
    }

    /**
     * Throttling in every region returns a 429.
     */
    public function test_throttle_everywhere(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $provider = $this->make_provider('amazon.nova-canvas-v1:0', [], 'eu-west-1');
        $mock = new MockHandler([
            self::aws_error('ThrottlingException'),
            self::aws_error('ThrottlingException'),
        ]);
        $regions = [];
        $response = $this->make_processor($provider, $this->make_action(), $mock, $regions)->process();
        $this->assertFalse($response->get_success());
        $this->assertSame(429, $response->get_errorcode());
        $this->assertSame('ThrottlingException', $response->get_error());
        $this->assertSame([null, 'eu-west-1'], $regions);

        // Without a fallback region there is a single attempt.
        $mock = new MockHandler([self::aws_error('ThrottlingException')]);
        $regions = [];
        $response = $this->make_processor($this->provider, $this->make_action(), $mock, $regions)->process();
        $this->assertSame(429, $response->get_errorcode());
        $this->assertSame([null], $regions);
    }

    /**
     * Image calls use the long timeout; other runtime calls keep the default.
     */
    public function test_image_timeout_is_set_through_the_factory_for_image_calls_only(): void {
        $method = new \ReflectionMethod(bedrock_client_factory::class, 'http_options');
        $this->assertSame(120, $method->invoke(null, bedrock_client_factory::DEFAULT_TIMEOUT)['timeout']);
        $this->assertSame(300, $method->invoke(null, bedrock_client_factory::IMAGE_TIMEOUT)['timeout']);
        // Both client types still build.
        $factory = new bedrock_client_factory($this->provider);
        $this->assertInstanceOf(\Aws\BedrockRuntime\BedrockRuntimeClient::class, $factory->get_runtime_client());
        $this->assertInstanceOf(
            \Aws\BedrockRuntime\BedrockRuntimeClient::class,
            $factory->get_runtime_client(null, bedrock_client_factory::IMAGE_TIMEOUT)
        );
    }
}
