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
 * Processor for the generate_text action via AWS Bedrock Converse API.
 *
 * Maps \core_ai\aiactions\generate_text → Bedrock Converse:
 *  - prompttext  → messages[0].content[].text
 *  - images      → messages[0].content[].image (base64 bytes)
 *  - system      → system[0].text
 *  - inferenceConfig ← maxtokens / temperature / topP
 *
 * Multimodal: attaches PNG/JPEG image content blocks when the action carries
 * image data. Image validation / resizing is delegated to \core_ai\ai_image.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_generate_text extends abstract_processor {

    /** Maximum image size Bedrock accepts (3.75 MB in bytes). */
    private const MAX_IMAGE_BYTES = 3932160; // 3.75 * 1024 * 1024

    /** Maximum image dimension in pixels that Bedrock accepts. */
    private const MAX_IMAGE_PX = 8000;

    #[\Override]
    protected function build_request_args(): array {
        $modelid    = $this->get_model_id();
        $prompttext = $this->action->get_configuration('prompttext');

        // Build user content blocks.
        $contentblocks = [['text' => $prompttext]];

        // Attach image blocks if present.
        $imagepath = $this->action->get_configuration('imagepath') ?? '';
        if (!empty($imagepath) && file_exists($imagepath)) {
            $imageblocks = $this->build_image_blocks($imagepath);
            $contentblocks = array_merge($contentblocks, $imageblocks);
        }

        $args = [
            'modelId'  => $modelid,
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => $contentblocks,
                ],
            ],
            // Anthropic Claude models on Bedrock reject requests that set BOTH
            // `temperature` and `topP` (sampling parameters). Send only one:
            // prefer the admin-configured temperature; fall back to topP only if
            // temperature is null/unset and topP is set explicitly.
            'inferenceConfig' => $this->build_inference_config(),
        ];

        // Append system instruction if set.
        $systeminstruction = $this->get_system_instruction();
        if (!empty($systeminstruction)) {
            $args['system'] = [['text' => $systeminstruction]];
        }

        return $args;
    }

    /**
     * Build the inferenceConfig sub-array for Bedrock Converse, avoiding the
     * mutually-exclusive temperature/topP rule for Anthropic Claude models.
     *
     * Rule (from Bedrock Converse): when calling Claude, you may set either
     * `temperature` OR `topP` — never both. Other model families tolerate both.
     *
     * Strategy: detect Claude by inspecting the resolved model ID; if Claude,
     * pick whichever sampling parameter the admin explicitly set (preferring
     * temperature when both are at defaults). For non-Claude models, send both.
     *
     * @return array
     */
    protected function build_inference_config(): array {
        $config = [
            'maxTokens' => $this->get_max_tokens(),
        ];

        $temperature = $this->get_temperature();
        $topp        = $this->get_top_p();
        $modelid     = (string) $this->get_model_id();
        $isclaude    = stripos($modelid, 'anthropic.claude') !== false;

        if ($isclaude) {
            // Send temperature if set; otherwise fall back to topP.
            if ($temperature !== null && $temperature !== '') {
                $config['temperature'] = (float) $temperature;
            } else if ($topp !== null && $topp !== '') {
                $config['topP'] = (float) $topp;
            }
        } else {
            if ($temperature !== null && $temperature !== '') {
                $config['temperature'] = (float) $temperature;
            }
            if ($topp !== null && $topp !== '') {
                $config['topP'] = (float) $topp;
            }
        }

        return $config;
    }

    /**
     * Build Bedrock image content block(s) from a local image file path.
     *
     * Validates that the image is within the Bedrock size and dimension limits.
     * Any image that fails validation is silently omitted (the text prompt is
     * still sent).
     *
     * @param string $imagepath Absolute path to the image file.
     * @return array Array of Bedrock image content blocks.
     */
    protected function build_image_blocks(string $imagepath): array {
        if (!file_exists($imagepath) || !is_readable($imagepath)) {
            return [];
        }

        $imageinfo = @getimagesize($imagepath);
        if (empty($imageinfo)) {
            return [];
        }

        $mime = $imageinfo['mime'] ?? '';
        if ($mime === 'image/jpeg') {
            $format = 'jpeg';
        } else if ($mime === 'image/png') {
            $format = 'png';
        } else {
            // Bedrock Converse only supports jpeg, png, gif, webp.
            return [];
        }

        // Check dimension limits.
        $width  = $imageinfo[0] ?? 0;
        $height = $imageinfo[1] ?? 0;
        if ($width > self::MAX_IMAGE_PX || $height > self::MAX_IMAGE_PX) {
            // Over-sized image: skip.
            return [];
        }

        $rawbytes = file_get_contents($imagepath);
        if ($rawbytes === false || strlen($rawbytes) > self::MAX_IMAGE_BYTES) {
            return [];
        }

        return [
            [
                'image' => [
                    'format' => $format,
                    'source' => ['bytes' => $rawbytes],
                ],
            ],
        ];
    }

    #[\Override]
    protected function handle_api_success(\Aws\Result $result): array {
        $modelid = $this->get_model_id();
        $data    = $result->toArray();

        return [
            'success'          => true,
            'id'               => $data['ResponseMetadata']['RequestId'] ?? null,
            'fingerprint'      => $modelid,
            'generatedcontent' => $data['output']['message']['content'][0]['text'] ?? '',
            'finishreason'     => $data['stopReason'] ?? '',
            'prompttokens'     => $data['usage']['inputTokens'] ?? 0,
            'completiontokens' => $data['usage']['outputTokens'] ?? 0,
            'model'            => $modelid,
        ];
    }
}
