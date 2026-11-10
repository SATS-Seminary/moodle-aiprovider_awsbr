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
 * Processor for the core generate_image action via the AWS Bedrock InvokeModel API.
 *
 * Image models use the raw InvokeModel API, so like process_embed_text this class
 * extends \core_ai\process_base directly. The request body is built per model
 * family, chosen by model id prefix,
 * after removing any geo or global prefix (us., eu., global. ...):
 *  - amazon.nova-canvas-*             → Nova Canvas TEXT_IMAGE (retired by AWS)
 *  - amazon.titan-image-generator-*   → Titan Image Generator TEXT_IMAGE (retired by AWS)
 *  - stability.* (SD3 / SD3.5 Large, Stable Image Core, Stable Image Ultra)
 *                                     → Stability AI text-to-image
 * Any other model fails with UnsupportedModel; nothing is sent to AWS.
 *
 * Always exactly one image is requested (the core response holds one draft file).
 * The core `style` option (vivid / natural) has no counterpart on Bedrock and is
 * ignored. Content-filter refusals come back as ContentFiltered (400) with a fixed
 * message that does not repeat the prompt. The image is watermarked with
 * \core_ai\ai_image, as core's providers do, and stored in the user's draft area.
 *
 * Calls go to the action's image generation region when it is set (else the
 * instance's region). Throttling and fallback-region retry work as in process_embed_text,
 * except that the fallback region is only tried when no image region is set. Image calls
 * use a 300 second read timeout (AWS advises at least that), set through the
 * client factory for these calls only.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_generate_image extends \core_ai\process_base {
    use aws_error_mapping;

    /** Model family: Amazon Nova Canvas. */
    public const FAMILY_NOVA = 'nova';
    /** Model family: Amazon Titan Image Generator. */
    public const FAMILY_TITAN = 'titan';
    /** Model family: Stability AI. */
    public const FAMILY_STABILITY = 'stability';

    /** Lowest and highest cfgScale that Nova Canvas and Titan accept. */
    public const CFGSCALE_MIN = 1.1;
    /** Highest cfgScale that Nova Canvas and Titan accept. */
    public const CFGSCALE_MAX = 10.0;

    /**
     * Output sizes in pixels, by family, quality ('standard' or 'premium') and aspect ratio.
     *
     * Nova Canvas takes any size with sides of 320 to 4096 that are multiples of 16, an
     * aspect ratio between 1:4 and 4:1 and fewer than 4,194,304 pixels. Titan accepts
     * only the sizes in its documented list (longest side 1408). Every size below
     * meets those rules; widths and heights are multiples of 16.
     */
    private const SIZES = [
        self::FAMILY_NOVA => [
            'standard' => [
                'square'    => [1024, 1024],
                'landscape' => [1024, 576],
                'portrait'  => [576, 1024],
            ],
            'premium' => [
                'square'    => [1792, 1792],
                'landscape' => [1792, 1024],
                'portrait'  => [1024, 1792],
            ],
        ],
        self::FAMILY_TITAN => [
            'standard' => [
                'square'    => [768, 768],
                'landscape' => [1152, 768],
                'portrait'  => [768, 1152],
            ],
            'premium' => [
                'square'    => [1024, 1024],
                'landscape' => [1408, 768],
                'portrait'  => [768, 1408],
            ],
        ],
    ];

    /** Stability aspect_ratio by core aspect ratio. */
    private const STABILITY_RATIOS = [
        'square'    => '1:1',
        'landscape' => '16:9',
        'portrait'  => '9:16',
    ];

    /**
     * The model family of a model id, from its prefix.
     *
     * @param string $modelid The Bedrock model id.
     * @return string|null One of the FAMILY_ constants, null when not a supported image model.
     */
    public static function get_family(string $modelid): ?string {
        $modelid = model_catalog::strip_geo_prefix($modelid);
        if (str_starts_with($modelid, 'amazon.nova-canvas-')) {
            return self::FAMILY_NOVA;
        }
        if (str_starts_with($modelid, 'amazon.titan-image-generator-')) {
            return self::FAMILY_TITAN;
        }
        if (model_catalog::is_supported_image_id($modelid)) {
            return self::FAMILY_STABILITY;
        }
        return null;
    }

    /**
     * The settings of the generate_image action on this provider instance.
     *
     * @return array
     */
    protected function get_settings(): array {
        return $this->provider->actionconfig[\core_ai\aiactions\generate_image::class]['settings'] ?? [];
    }

    /**
     * The image generation region of the action ('' = the instance's region).
     *
     * @return string
     */
    protected function get_image_region(): string {
        return trim((string) ($this->get_settings()['imageregion'] ?? ''));
    }

    /**
     * The image models available in the image generation region (used when the action has no model set).
     *
     * @return array Map of model id => label.
     */
    protected function get_allowed_models(): array {
        return (new model_catalog($this->provider))->image_options($this->get_image_region());
    }

    /**
     * The model id for the action: the configured model, else the first allowed image model.
     *
     * @return string Empty when there is none.
     */
    protected function get_model_id(): string {
        $model = trim((string) ($this->get_settings()['model'] ?? ''));
        if ($model !== '') {
            return $model;
        }
        $allowed = array_keys($this->get_allowed_models());
        return $allowed ? (string) reset($allowed) : '';
    }

    /**
     * The configured cfgScale, when it is a valid value.
     *
     * @return float|null Null means "model default".
     */
    protected function get_cfgscale(): ?float {
        $value = $this->get_settings()['cfgscale'] ?? '';
        if (!is_numeric($value)) {
            return null;
        }
        $value = (float) $value;
        return ($value >= self::CFGSCALE_MIN && $value <= self::CFGSCALE_MAX) ? $value : null;
    }

    /**
     * The configured negative prompt ('' = none).
     *
     * @return string
     */
    protected function get_negative_prompt(): string {
        return trim((string) ($this->get_settings()['negativeprompt'] ?? ''));
    }

    /**
     * Build the InvokeModel request body for a model family.
     *
     * The core style option is not sent: Bedrock has no equivalent of vivid / natural.
     *
     * @param string $family One of the FAMILY_ constants.
     * @return string JSON-encoded request body.
     */
    protected function build_request_body(string $family): string {
        $prompt   = (string) $this->action->get_configuration('prompttext');
        $aspect   = (string) $this->action->get_configuration('aspectratio');
        $hd       = $this->action->get_configuration('quality') === 'hd';
        $negative = $this->get_negative_prompt();

        if ($family === self::FAMILY_STABILITY) {
            $body = [
                'prompt'        => $prompt,
                'aspect_ratio'  => self::STABILITY_RATIOS[$aspect] ?? '1:1',
                'output_format' => 'png',
            ];
            if ($negative !== '') {
                $body['negative_prompt'] = $negative;
            }
            return json_encode($body);
        }

        $quality = $hd ? 'premium' : 'standard';
        $sizes   = self::SIZES[$family][$quality];
        [$width, $height] = $sizes[$aspect] ?? $sizes['square'];

        $params = ['text' => $prompt];
        if ($negative !== '') {
            $params['negativeText'] = $negative;
        }
        $config = [
            'numberOfImages' => 1,
            'width'          => $width,
            'height'         => $height,
            'quality'        => $quality,
        ];
        $cfgscale = $this->get_cfgscale();
        if ($cfgscale !== null) {
            $config['cfgScale'] = $cfgscale;
        }
        return json_encode([
            'taskType'              => 'TEXT_IMAGE',
            'textToImageParams'     => $params,
            'imageGenerationConfig' => $config,
        ]);
    }

    /**
     * Whether an AWS error message says the content filters blocked the request.
     *
     * @param string $message The AWS error message.
     * @return bool
     */
    protected static function is_content_filter_message(string $message): bool {
        return (bool) preg_match('/content filter|responsible ai|content moderation|content policy|moderation/i', $message);
    }

    /**
     * The error array for a content-filter refusal. It never repeats the prompt.
     *
     * @return array
     */
    protected static function content_filtered_error(): array {
        return [
            'success'      => false,
            'errorcode'    => 400,
            'error'        => 'ContentFiltered',
            'errormessage' => get_string('error:generate_image:content_filtered', 'aiprovider_awsbr'),
        ];
    }

    /**
     * Build a generic error array.
     *
     * @param int $code The HTTP-like error code.
     * @param string $name The short error name.
     * @param string $stringid The lang string id (component aiprovider_awsbr).
     * @param mixed $a Optional string parameter.
     * @return array
     */
    protected static function error(int $code, string $name, string $stringid, mixed $a = null): array {
        return [
            'success'      => false,
            'errorcode'    => $code,
            'error'        => $name,
            'errormessage' => get_string($stringid, 'aiprovider_awsbr', $a),
        ];
    }

    /**
     * Extract the base64 image from an InvokeModel response body.
     *
     * Nova Canvas and Titan answer {images: [...], error?}; an error means the content
     * moderation policy flagged the input or the output. Stability answers
     * {seeds, finish_reasons: [null|"Filter reason: ...", "Inference error"], images}.
     *
     * @param string $family One of the FAMILY_ constants.
     * @param string $rawbody The JSON response body.
     * @return array{success: true, image: string}|array{success: false, errorcode: int, error: string, errormessage: string}
     */
    protected function parse_response_body(string $family, string $rawbody): array {
        $data = json_decode($rawbody, true);
        if (!is_array($data)) {
            return self::error(500, 'BedrockError', 'error:generate_image:invalid_json');
        }

        $image = $data['images'][0] ?? null;
        $hasimage = is_string($image) && $image !== '';

        if ($family === self::FAMILY_STABILITY) {
            $reason = $data['finish_reasons'][0] ?? null;
            if (is_string($reason) && $reason !== '') {
                if (stripos($reason, 'filter') !== false) {
                    return self::content_filtered_error();
                }
                return self::error(500, 'BedrockError', 'error:generate_image:inference');
            }
        } else if (!$hasimage && !empty($data['error'])) {
            return self::content_filtered_error();
        }

        if (!$hasimage) {
            return self::error(500, 'BedrockError', 'error:generate_image:empty');
        }
        return ['success' => true, 'image' => $image];
    }

    /**
     * The AWS client for image calls (overridable in tests).
     *
     * @param string|null $region Override region; null = the provider's primary region.
     * @return \Aws\BedrockRuntime\BedrockRuntimeClient
     */
    protected function get_client(?string $region): \Aws\BedrockRuntime\BedrockRuntimeClient {
        return (new bedrock_client_factory($this->provider))
            ->get_runtime_client($region, bedrock_client_factory::IMAGE_TIMEOUT);
    }

    /**
     * Execute a single InvokeModel call against the given (or primary) region.
     *
     * Returns null specifically when a ThrottlingException occurs, so the caller
     * can retry against the fallback region.
     *
     * @param string      $modelid The Bedrock model id.
     * @param string      $family  The model family.
     * @param string      $body    JSON request body.
     * @param string|null $region  Override region; null = primary region from config.
     * @return array|null On success ['success' => true, 'image' => base64, 'requestid' => string].
     */
    protected function call_invoke_model(string $modelid, string $family, string $body, ?string $region): ?array {
        try {
            $awsresult = $this->get_client($region)->invokeModel([
                'modelId'     => $modelid,
                'body'        => $body,
                'contentType' => 'application/json',
                'accept'      => 'application/json',
            ]);

            $parsed = $this->parse_response_body($family, (string) $awsresult->get('body'));
            if (!$parsed['success']) {
                return $parsed;
            }
            $headers = $awsresult['@metadata']['headers'] ?? [];
            $parsed['requestid'] = (string) ($headers['x-amzn-requestid'] ?? '');
            return $parsed;
        } catch (\Aws\Exception\AwsException $e) {
            $code = $e->getAwsErrorCode();
            if ($code === 'ThrottlingException') {
                return null; // Signal to caller: retry on fallback region.
            }
            if ($code === 'ValidationException' && self::is_content_filter_message((string) $e->getAwsErrorMessage())) {
                return self::content_filtered_error();
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
     * Decode, validate and watermark a base64 image and store it in the user's draft area.
     *
     * @param string $base64 The base64 image returned by Bedrock.
     * @return \stored_file The draft file.
     * @throws \moodle_exception When the data is not a usable image.
     */
    protected function base64_to_file(string $base64): \stored_file {
        global $CFG, $USER;
        require_once("{$CFG->libdir}/filelib.php");

        $binary = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);
        if ($binary === false || $binary === '') {
            throw new \moodle_exception('error:generate_image:invalid_image', 'aiprovider_awsbr');
        }
        $info = getimagesizefromstring($binary);
        $extension = ($info !== false && !empty($info[2]))
            ? image_type_to_extension($info[2], true) : false;
        if ($extension === false || !in_array($extension, ['.png', '.jpg', '.jpeg', '.gif'], true)) {
            throw new \moodle_exception('error:generate_image:invalid_image', 'aiprovider_awsbr');
        }
        $filename = substr(hash('sha256', $binary), 0, 16) . $extension;

        // Watermark in a private request directory, never in a predictable path.
        $path = make_request_directory() . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, $binary) === false) {
            throw new \moodle_exception('error:generate_image:invalid_image', 'aiprovider_awsbr');
        }
        (new \core_ai\ai_image($path))->add_watermark()->save();
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \moodle_exception('error:generate_image:invalid_image', 'aiprovider_awsbr');
        }

        // The placement, acting for the user, moves the file out of the draft area.
        $record = (object) [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => file_get_unused_draft_itemid(),
            'filepath'  => '/',
            'filename'  => $filename,
        ];
        return get_file_storage()->create_file_from_string($record, $content);
    }

    /**
     * Query the Bedrock InvokeModel API with throttling-aware fallback, then store the image.
     *
     * @return array Standard Moodle response array.
     */
    #[\Override]
    protected function query_ai_api(): array {
        global $USER;

        $modelid = $this->get_model_id();
        if ($modelid === '') {
            return self::error(404, 'NoImageModel', 'error:generate_image:no_model');
        }
        $family = self::get_family($modelid);
        if ($family === null) {
            return self::error(400, 'UnsupportedModel', 'error:generate_image:unsupported_model', $modelid);
        }
        if (empty($USER->id)) {
            return self::error(403, 'NoUser', 'error:generate_image:no_user');
        }

        $body = $this->build_request_body($family);
        \core_php_time_limit::raise(bedrock_client_factory::IMAGE_TIMEOUT + 60);

        // An explicit image region goes there; empty means the instance's region.
        $imageregion = $this->get_image_region();
        $result = $this->call_invoke_model($modelid, $family, $body, region: $imageregion !== '' ? $imageregion : null);
        if ($result === null && $imageregion === '') {
            // The fallback region is only tried for the instance's own region: with an
            // explicit image region it probably has no image models.
            $fallbackregion = trim((string) ($this->provider->config['fallbackregion'] ?? ''));
            if ($fallbackregion !== '') {
                $result = $this->call_invoke_model($modelid, $family, $body, region: $fallbackregion);
            }
        }
        if ($result === null) {
            return self::error(429, 'ThrottlingException', 'error:generate_image:throttled');
        }
        if (!$result['success']) {
            return $result;
        }

        try {
            $draftfile = $this->base64_to_file($result['image']);
        } catch (\Throwable $e) {
            return self::error(502, 'InvalidResponseException', 'error:generate_image:invalid_image');
        }

        return [
            'success'       => true,
            'draftfile'     => $draftfile,
            'revisedprompt' => (string) $this->action->get_configuration('prompttext'), // Bedrock does not revise prompts.
            'model'         => $modelid,
            'id'            => $result['requestid'] ?? '',
            'fingerprint'   => $result['requestid'] ?? '',
        ];
    }
}
