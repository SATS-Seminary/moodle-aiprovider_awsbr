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
 * Tests for the model catalog allowlist-overlay logic.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\model_catalog::class)]
final class model_catalog_test extends \advanced_testcase {
    /**
     * parse_allowlist turns "id|Label" lines into an id => label map.
     */
    public function test_parse_allowlist(): void {
        $raw = "eu.anthropic.claude-sonnet-4-6|Chat\n  \namazon.nova-lite-v1:0\n#comment-stays-as-id";
        $map = model_catalog::parse_allowlist($raw);

        $this->assertSame('Chat', $map['eu.anthropic.claude-sonnet-4-6']);
        // No label -> id is used as the label.
        $this->assertSame('amazon.nova-lite-v1:0', $map['amazon.nova-lite-v1:0']);
        $this->assertArrayNotHasKey('', $map);
    }

    /**
     * options(): live catalog is source of truth; allowlist only curates +
     * relabels, and never lets a non-invokable ID through.
     */
    public function test_options_overlay(): void {
        $this->resetAfterTest();

        // Stub catalog with a fixed live list — no AWS calls.
        $catalog = new class extends model_catalog {
            // phpcs:ignore
            public function __construct() {
            }
            /**
             * Return a fixed live model list, bypassing AWS.
             *
             * @param bool $refresh Ignored in this stub.
             * @return array The stubbed model list.
             */
            public function list(bool $refresh = false): array {
                return [
                    ['id' => 'eu.anthropic.claude-sonnet-4-6', 'name' => 'EU Sonnet 4.6', 'status' => 'ACTIVE'],
                    ['id' => 'eu.amazon.nova-lite-v1:0', 'name' => 'EU Nova Lite', 'status' => 'ACTIVE'],
                ];
            }
        };

        // No allowlist -> the full live list, with catalog labels.
        $all = $catalog->options([]);
        $this->assertCount(2, $all);
        $this->assertSame('EU Sonnet 4.6', $all['eu.anthropic.claude-sonnet-4-6']);

        // Allowlist narrows to a valid subset and applies custom labels.
        $curated = $catalog->options([
            'eu.anthropic.claude-sonnet-4-6' => 'My Chat Model',
            'amazon.nova-2-lite-v1:0'        => 'Bare (invalid)',
        ]);
        $this->assertCount(1, $curated);
        $this->assertSame('My Chat Model', $curated['eu.anthropic.claude-sonnet-4-6']);
        $this->assertArrayNotHasKey('amazon.nova-2-lite-v1:0', $curated);

        // An allowlist where everything is invalid falls back to the full list
        // rather than rendering an empty selector.
        $fallback = $catalog->options(['totally.invalid.model' => 'Nope']);
        $this->assertCount(2, $fallback);
    }

    /**
     * When the live catalog is empty (e.g. control plane unreachable), options()
     * returns the raw allowlist so the UI still works.
     */
    public function test_options_falls_back_to_allowlist_when_catalog_empty(): void {
        $catalog = new class extends model_catalog {
            // phpcs:ignore
            public function __construct() {
            }
            /**
             * Return an empty live model list, simulating an unreachable control plane.
             *
             * @param bool $refresh Ignored in this stub.
             * @return array Always empty.
             */
            public function list(bool $refresh = false): array {
                return [];
            }
        };

        $raw = ['some.model.id' => 'Some Model'];
        $this->assertSame($raw, $catalog->options($raw));
    }

    /**
     * Create a provider instance and put a live profile list in its cache entry.
     *
     * @param string $region The instance's region.
     * @param string[] $ids The invokable profile ids.
     * @param string $availablemodels The instance's availablemodels setting.
     * @param bool $enabled Whether the instance is enabled.
     * @return provider
     */
    private function make_instance(string $region, array $ids, string $availablemodels = '', bool $enabled = true): provider {
        $manager  = \core\di::get(\core_ai\manager::class);
        $provider = $manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'catalog-' . $region,
            enabled:   $enabled,
            config:    [
                'authmode'        => 'static',
                'accesskeyid'     => 'AKID',
                'secretaccesskey' => 'SECRET',
                'region'          => $region,
                'availablemodels' => $availablemodels,
            ],
        );
        $models = [];
        foreach ($ids as $id) {
            $models[] = ['id' => $id, 'name' => $region . ' ' . $id, 'status' => 'ACTIVE'];
        }
        \cache::make('aiprovider_awsbr', 'model_catalog')->set(model_catalog::cache_key($provider), $models);
        return $provider;
    }

    /**
     * Each instance sees its own models, even with the same region.
     */
    public function test_allowed_options_come_from_the_instance(): void {
        $this->resetAfterTest();
        $eu = $this->make_instance('eu-west-1', ['eu.model-a', 'eu.model-b']);
        $us = $this->make_instance('us-east-1', ['us.model-a']);
        $us2 = $this->make_instance('us-east-1', ['us2.model-a', 'us2.model-b', 'us2.model-c']);

        $this->assertNotSame(model_catalog::cache_key($us), model_catalog::cache_key($us2));
        $this->assertSame(
            ['eu.model-a' => 'eu-west-1 eu.model-a', 'eu.model-b' => 'eu-west-1 eu.model-b'],
            (new model_catalog($eu))->allowed_options()
        );
        $this->assertSame(['us.model-a' => 'us-east-1 us.model-a'], (new model_catalog($us))->allowed_options());
        $this->assertCount(3, (new model_catalog($us2))->allowed_options());
    }

    /**
     * The instance's availablemodels narrows and relabels its catalog.
     */
    public function test_allowed_options_are_narrowed_by_the_instance_allowlist(): void {
        $this->resetAfterTest();
        $instance = $this->make_instance('eu-west-1', ['m.a', 'm.b'], "m.b|Nice B\nm.gone|Stale\n");
        $this->assertSame(['m.b' => 'Nice B'], (new model_catalog($instance))->allowed_options());
    }

    /**
     * When the catalog cannot be loaded the instance's allowlist is used.
     */
    public function test_allowed_options_fall_back_to_the_instance_allowlist(): void {
        $this->resetAfterTest();
        $instance = $this->make_instance('eu-west-1', [], "m.a|A\nm.b|B");
        $this->assertSame(['m.a' => 'A', 'm.b' => 'B'], (new model_catalog($instance))->allowed_options());
    }

    /**
     * for_provider_id finds the right instance, enabled or not; discover can pick one by id.
     */
    public function test_for_provider_id_and_discover(): void {
        $this->resetAfterTest();
        $first  = $this->make_instance('eu-west-1', ['eu.a']);
        $second = $this->make_instance('us-east-1', ['us.a']);
        $off    = $this->make_instance('ap-south-1', ['ap.a'], '', false);

        $this->assertSame((int) $second->id, (int) model_catalog::for_provider_id((int) $second->id)->get_provider()->id);
        $this->assertSame((int) $off->id, (int) model_catalog::for_provider_id((int) $off->id)->get_provider()->id);
        $this->assertNull(model_catalog::for_provider_id(0));
        $this->assertNull(model_catalog::for_provider_id(999999));

        $this->assertSame((int) $first->id, (int) model_catalog::discover()->get_provider()->id, 'default: first enabled');
        $this->assertSame((int) $second->id, (int) model_catalog::discover((int) $second->id)->get_provider()->id);
        $this->assertNull(model_catalog::discover((int) $off->id), 'discover only returns enabled instances');
    }

    /**
     * options_for_provider_id gives the instance's options, and nothing without an id.
     */
    public function test_options_for_provider_id(): void {
        $this->resetAfterTest();
        $eu = $this->make_instance('eu-west-1', ['eu.a']);
        $us = $this->make_instance('us-east-1', ['us.a']);

        $this->assertSame(['eu.a' => 'eu-west-1 eu.a'], model_catalog::options_for_provider_id((int) $eu->id));
        $this->assertSame(['us.a' => 'us-east-1 us.a'], model_catalog::options_for_provider_id((int) $us->id));
        $this->assertSame([], model_catalog::options_for_provider_id(0));
    }

    /**
     * A catalog whose control client is wired to a mock handler (no AWS call).
     *
     * @param provider $provider The provider instance.
     * @param \Aws\MockHandler $mock The mock handler holding the queued results.
     * @return model_catalog
     */
    private function catalog_with_mock(provider $provider, \Aws\MockHandler $mock): model_catalog {
        return new class ($provider, $mock) extends model_catalog {
            /**
             * Build a catalog whose control client uses the mock handler.
             *
             * @param provider $provider The provider instance.
             * @param \Aws\MockHandler $mockhandler The mock handler.
             */
            public function __construct(
                provider $provider,
                /** @var \Aws\MockHandler The mock handler. */
                private \Aws\MockHandler $mockhandler,
            ) {
                parent::__construct($provider);
            }

            #[\Override]
            protected function control_client(string $region): \Aws\Bedrock\BedrockClient {
                return new \Aws\Bedrock\BedrockClient([
                    'region'      => $region,
                    'version'     => 'latest',
                    'credentials' => ['key' => 'AKID', 'secret' => 'SECRET'],
                    'retries'     => 0,
                    'handler'     => $this->mockhandler,
                ]);
            }
        };
    }

    /**
     * The embedding list comes from ListFoundationModels (EMBEDDING, on demand), not from the chat profiles.
     */
    public function test_embedding_options_live_list(): void {
        $this->resetAfterTest();
        $manager  = \core\di::get(\core_ai\manager::class);
        $provider = $manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'embed-live',
            enabled:   true,
            config:    ['authmode' => 'static', 'accesskeyid' => 'A', 'secretaccesskey' => 'S', 'region' => 'eu-west-1'],
        );
        $mock = new \Aws\MockHandler([new \Aws\Result(['modelSummaries' => [
            ['modelId' => 'cohere.embed-multilingual-v3', 'modelName' => 'Embed Multilingual',
                'inferenceTypesSupported' => ['ON_DEMAND']],
            ['modelId' => 'amazon.titan-embed-text-v2:0', 'modelName' => 'Titan Embed Text V2',
                'inferenceTypesSupported' => ['ON_DEMAND']],
            ['modelId' => 'amazon.titan-embed-image-v1', 'modelName' => 'Provisioned only',
                'inferenceTypesSupported' => ['PROVISIONED']],
        ]]), new \Aws\Result(['inferenceProfileSummaries' => [
            ['inferenceProfileId' => 'eu.chat-model', 'inferenceProfileName' => 'Chat', 'status' => 'ACTIVE'],
        ]])]);
        $catalog = $this->catalog_with_mock($provider, $mock);

        $options = $catalog->embedding_options();
        $this->assertSame(
            ['amazon.titan-embed-text-v2:0' => 'Titan Embed Text V2', 'cohere.embed-multilingual-v3' => 'Embed Multilingual'],
            $options
        );
        $command = $mock->getLastCommand();
        $this->assertSame('ListFoundationModels', $command->getName());
        $this->assertSame('EMBEDDING', $command['byOutputModality']);

        // Cached per instance: a second call makes no request (the mock queue is empty).
        $this->assertSame($options, $catalog->embedding_options());
        // Chat options never include embedding models.
        $this->assertSame(['eu.chat-model' => 'Chat'], $catalog->allowed_options());
    }

    /**
     * The allowlist narrows and relabels the embedding list, using only its embedding lines.
     */
    public function test_embedding_options_narrowing(): void {
        $this->resetAfterTest();
        $manager  = \core\di::get(\core_ai\manager::class);
        $summaries = ['modelSummaries' => [
            ['modelId' => 'amazon.titan-embed-text-v2:0', 'modelName' => 'Titan',
                'inferenceTypesSupported' => ['ON_DEMAND']],
            ['modelId' => 'cohere.embed-english-v3', 'modelName' => 'Cohere EN',
                'inferenceTypesSupported' => ['ON_DEMAND']],
        ]];

        $make = function (string $name, string $availablemodels) use ($manager, $summaries): model_catalog {
            $provider = $manager->create_provider_instance(
                classname: '\aiprovider_awsbr\provider',
                name:      $name,
                enabled:   true,
                config:    [
                    'authmode' => 'static', 'accesskeyid' => 'A', 'secretaccesskey' => 'S',
                    'region' => 'eu-west-1', 'availablemodels' => $availablemodels,
                ],
            );
            return $this->catalog_with_mock($provider, new \Aws\MockHandler([new \Aws\Result($summaries)]));
        };

        // An embedding line narrows and relabels; chat lines and unknown ids are ignored.
        $catalog = $make(
            'narrow',
            "global.anthropic.claude-sonnet|Chat\ncohere.embed-english-v3|My Cohere\ncohere.embed-gone|Gone"
        );
        $this->assertSame(['cohere.embed-english-v3' => 'My Cohere'], $catalog->embedding_options());

        // No embedding line at all: the whole list.
        $catalog = $make('whole', "global.anthropic.claude-sonnet|Chat");
        $this->assertCount(2, $catalog->embedding_options());
    }

    /**
     * When the list cannot be loaded: Titan v2 plus the embedding lines of the allowlist.
     */
    public function test_embedding_options_fallback(): void {
        $this->resetAfterTest();
        $manager  = \core\di::get(\core_ai\manager::class);
        $provider = $manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'embed-fallback',
            enabled:   true,
            config:    [
                'authmode' => 'static', 'accesskeyid' => 'A', 'secretaccesskey' => 'S', 'region' => 'eu-west-1',
                'availablemodels' => "global.anthropic.claude-sonnet|Chat\ncohere.embed-english-v3|My Cohere",
            ],
        );
        $mock = new \Aws\MockHandler([
            new \Aws\Exception\AwsException('down', new \Aws\Command('ListFoundationModels')),
        ]);
        $catalog = $this->catalog_with_mock($provider, $mock);

        $options = $catalog->embedding_options();
        $this->assertDebuggingCalled();
        $this->assertSame(['amazon.titan-embed-text-v2:0', 'cohere.embed-english-v3'], array_keys($options));
        $this->assertSame('My Cohere', $options['cohere.embed-english-v3']);
    }

    /**
     * Without a provider id there is no catalog and no AWS call.
     */
    public function test_embedding_options_for_provider_id_without_id(): void {
        $this->assertSame([], model_catalog::embedding_options_for_provider_id(0));
    }

    /**
     * Create an enabled instance for the foundation-model list tests.
     *
     * @param string $name The instance name.
     * @param string $availablemodels The availablemodels setting.
     * @return provider
     */
    private function make_list_instance(string $name, string $availablemodels = ''): provider {
        return \core\di::get(\core_ai\manager::class)->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      $name,
            enabled:   true,
            config:    [
                'authmode' => 'static', 'accesskeyid' => 'A', 'secretaccesskey' => 'S',
                'region' => 'us-east-1', 'availablemodels' => $availablemodels,
            ],
        );
    }

    /**
     * A ListFoundationModels result.
     *
     * @param array $models Model id => name.
     * @return \Aws\Result
     */
    private function models_result(array $models): \Aws\Result {
        $summaries = [];
        foreach ($models as $id => $name) {
            $summaries[] = ['modelId' => $id, 'modelName' => $name, 'inferenceTypesSupported' => ['ON_DEMAND']];
        }
        return new \Aws\Result(['modelSummaries' => $summaries]);
    }

    /**
     * The image list asks for IMAGE models and offers only the families the processor can call.
     */
    public function test_image_options_live_list_offers_only_callable_families(): void {
        $this->resetAfterTest();
        $provider = $this->make_list_instance('image-live');
        $mock = new \Aws\MockHandler([$this->models_result([
            'stability.stable-image-ultra-v1:1' => 'Stable Image Ultra',
            'amazon.nova-canvas-v1:0' => 'Nova Canvas',
            'amazon.titan-image-generator-v2:0' => 'Titan Image Generator v2',
            'stability.stable-image-inpaint-v1:0' => 'Stable Image Inpaint',
            'stability.stable-image-control-sketch-v1:0' => 'Control Sketch',
            'stability.stable-conservative-upscale-v1:0' => 'Upscale',
            'stability.sd3-5-large-v1:0' => 'SD3.5 Large',
            'amazon.titan-embed-image-v1' => 'Not an image generator',
        ])]);
        $catalog = $this->catalog_with_mock($provider, $mock);

        $options = $catalog->image_options();

        $this->assertSame([
            'amazon.nova-canvas-v1:0' => 'Nova Canvas',
            'amazon.titan-image-generator-v2:0' => 'Titan Image Generator v2',
            'stability.sd3-5-large-v1:0' => 'SD3.5 Large',
            'stability.stable-image-ultra-v1:1' => 'Stable Image Ultra',
        ], $options);
        $command = $mock->getLastCommand();
        $this->assertSame('ListFoundationModels', $command->getName());
        $this->assertSame('IMAGE', $command['byOutputModality']);
        $this->assertSame('ON_DEMAND', $command['byInferenceType']);
        // Cached per instance, under its own key.
        $this->assertSame($options, $catalog->image_options());
        $this->assertNotEmpty(\cache::make('aiprovider_awsbr', 'model_catalog')->get(model_catalog::image_cache_key($provider)));
        $this->assertNotSame(model_catalog::image_cache_key($provider), model_catalog::embedding_cache_key($provider));
        $this->assertStringContainsString('_' . $provider->id . '_', model_catalog::image_cache_key($provider));
    }

    /**
     * The embedding list offers only Titan text embeddings and Cohere Embed v3.
     */
    public function test_embedding_list_offers_only_callable_families(): void {
        $this->resetAfterTest();
        $provider = $this->make_list_instance('embed-families');
        $mock = new \Aws\MockHandler([$this->models_result([
            'amazon.titan-embed-text-v1' => 'Titan Embeddings G1 Text',
            'amazon.titan-embed-text-v2:0' => 'Titan Text Embeddings V2',
            'amazon.titan-embed-g1-text-02' => 'Titan Embeddings G1 Text 02',
            'amazon.titan-embed-image-v1' => 'Titan Multimodal Embeddings',
            'amazon.nova-2-multimodal-embeddings-v1:0' => 'Nova Multimodal Embeddings',
            'cohere.embed-english-v3' => 'Cohere English v3',
            'cohere.embed-multilingual-v3' => 'Cohere Multilingual v3',
            'cohere.embed-v4:0' => 'Cohere Embed v4',
        ])]);

        $this->assertSame([
            'amazon.titan-embed-g1-text-02',
            'amazon.titan-embed-text-v1',
            'amazon.titan-embed-text-v2:0',
            'cohere.embed-english-v3',
            'cohere.embed-multilingual-v3',
        ], array_keys($this->catalog_with_mock($provider, $mock)->embedding_options()));
    }

    /**
     * The embedding fallback keeps only the callable families of the allowlist.
     */
    public function test_embedding_fallback_filters_unsupported_lines(): void {
        $this->resetAfterTest();
        $provider = $this->make_list_instance(
            'embed-fallback-filter',
            "cohere.embed-v4:0|Cohere v4\namazon.titan-embed-image-v1|Multimodal\ncohere.embed-multilingual-v3|Cohere ML\n"
            . "amazon.nova-canvas-v1:0|Image\namazon.titan-embed-g1-text-02|G1"
        );
        $mock = new \Aws\MockHandler([new \Aws\Exception\AwsException('down', new \Aws\Command('ListFoundationModels'))]);

        $options = $this->catalog_with_mock($provider, $mock)->embedding_options();

        $this->assertDebuggingCalled();
        $this->assertSame(
            ['amazon.titan-embed-text-v2:0', 'cohere.embed-multilingual-v3', 'amazon.titan-embed-g1-text-02'],
            array_keys($options)
        );
    }

    /**
     * The allowlist narrows and relabels the image list, using only its image lines.
     */
    public function test_image_options_narrowing(): void {
        $this->resetAfterTest();
        $live = [
            'amazon.nova-canvas-v1:0' => 'Nova Canvas',
            'amazon.titan-image-generator-v2:0' => 'Titan v2',
        ];
        $make = function (string $name, string $availablemodels) use ($live): model_catalog {
            return $this->catalog_with_mock(
                $this->make_list_instance($name, $availablemodels),
                new \Aws\MockHandler([$this->models_result($live)])
            );
        };

        $catalog = $make(
            'image-narrow',
            "global.anthropic.claude-sonnet|Chat\namazon.titan-image-generator-v2:0|My Titan\namazon.gone|Gone"
        );
        $this->assertSame(['amazon.titan-image-generator-v2:0' => 'My Titan'], $catalog->image_options());

        // No image line at all: the whole list.
        $catalog = $make('image-whole', 'global.anthropic.claude-sonnet|Chat');
        $this->assertSame(['amazon.nova-canvas-v1:0', 'amazon.titan-image-generator-v2:0'], array_keys($catalog->image_options()));
    }

    /**
     * When the list cannot be loaded: Nova Canvas plus the callable image lines of the allowlist.
     */
    public function test_image_options_fallback(): void {
        $this->resetAfterTest();
        $provider = $this->make_list_instance(
            'image-fallback',
            "global.anthropic.claude-sonnet|Chat\nstability.sd3-5-large-v1:0|SD 3.5\nstability.stable-image-inpaint-v1:0|Inpaint\n"
            . "amazon.titan-embed-text-v2:0|Embed"
        );
        $mock = new \Aws\MockHandler([new \Aws\Exception\AwsException('down', new \Aws\Command('ListFoundationModels'))]);

        $options = $this->catalog_with_mock($provider, $mock)->image_options();

        $this->assertDebuggingCalled();
        $this->assertSame(['amazon.nova-canvas-v1:0', 'stability.sd3-5-large-v1:0'], array_keys($options));
        $this->assertSame('SD 3.5', $options['stability.sd3-5-large-v1:0']);
    }

    /**
     * A list where nothing is callable falls back to the default and is not cached.
     */
    public function test_image_options_when_no_listed_model_is_callable(): void {
        $this->resetAfterTest();
        $provider = $this->make_list_instance('image-none');
        $mock = new \Aws\MockHandler([$this->models_result(['stability.stable-image-inpaint-v1:0' => 'Inpaint'])]);

        $options = $this->catalog_with_mock($provider, $mock)->image_options();

        $this->assertSame(['amazon.nova-canvas-v1:0'], array_keys($options));
        $this->assertFalse(\cache::make('aiprovider_awsbr', 'model_catalog')->get(model_catalog::image_cache_key($provider)));
    }

    /**
     * Without a provider id there is no catalog and no AWS call.
     */
    public function test_image_options_for_provider_id_without_id(): void {
        $this->assertSame([], model_catalog::image_options_for_provider_id(0));
    }

    /**
     * Image model lines never reach the chat list.
     */
    public function test_chat_options_exclude_image_lines(): void {
        $this->resetAfterTest();
        $provider = $this->make_list_instance(
            'chat-no-image',
            "amazon.nova-canvas-v1:0|Image\nstability.sd3-5-large-v1:0|SD\nglobal.chat|Chat"
        );
        // The live profile list holds the chat profile only; a mock keeps AWS out of the test.
        $mock = new \Aws\MockHandler([new \Aws\Result(['inferenceProfileSummaries' => [
            ['inferenceProfileId' => 'global.chat', 'inferenceProfileName' => 'Chat', 'status' => 'ACTIVE'],
        ]])]);
        $this->assertSame(['global.chat' => 'Chat'], $this->catalog_with_mock($provider, $mock)->allowed_options());
    }
}
