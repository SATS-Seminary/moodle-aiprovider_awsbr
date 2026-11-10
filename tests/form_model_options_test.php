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
 * Tests that the action settings forms offer the models of the provider instance
 * being configured, and never read the site-wide availablemodels setting.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\form\action_generate_text_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\form\action_embed_text_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\form\action_grade_response_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\form\action_generate_image_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\provider::class)]
final class form_model_options_test extends \advanced_testcase {
    /**
     * Create an instance and put a live profile list in its cache entry.
     *
     * @param string $region The instance's region.
     * @param string[] $ids The invokable profile ids.
     * @param string $availablemodels The instance's availablemodels setting.
     * @return provider
     */
    private function make_instance(string $region, array $ids, string $availablemodels = ''): provider {
        $manager  = \core\di::get(\core_ai\manager::class);
        $provider = $manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'form-' . $region,
            enabled:   true,
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
        $cache = \cache::make('aiprovider_awsbr', 'model_catalog');
        $cache->set(model_catalog::cache_key($provider), $models);
        // Embedding models are listed separately (not inference profiles).
        $cache->set(model_catalog::embedding_cache_key($provider), [
            ['id' => 'amazon.titan-embed-text-v2:0', 'name' => 'Titan ' . $region],
            ['id' => 'cohere.embed-english-v3', 'name' => 'Cohere ' . $region],
        ]);
        // So are image models.
        $cache->set(model_catalog::image_cache_key($provider), [
            ['id' => 'amazon.nova-canvas-v1:0', 'name' => 'Nova ' . $region],
            ['id' => 'stability.sd3-5-large-v1:0', 'name' => 'SD3.5 ' . $region],
        ]);
        return $provider;
    }

    /**
     * The option values of the model select of an action form.
     *
     * @param string $action The action class name.
     * @param int $providerid The provider instance id passed in the form's customdata (0 = none).
     * @param string $saved The model saved in the action's settings.
     * @return string[] The option values, in order.
     */
    private function model_options(string $action, int $providerid, string $saved = ''): array {
        $customdata = ['providerid' => $providerid, 'providername' => 'aiprovider_awsbr'];
        if ($saved !== '') {
            $customdata['actionconfig'] = ['settings' => ['model' => $saved]];
        }
        $form = provider::get_action_settings($action, $customdata);
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $element = $property->getValue($form)->getElement('model');
        return array_map(static fn($option) => $option['attr']['value'], $element->_options);
    }

    /**
     * Each action form offers the models of its own instance.
     */
    public function test_forms_use_the_models_of_their_own_instance(): void {
        $this->resetAfterTest();
        // Site-wide config must play no part.
        set_config('availablemodels', "site.model|Site wide\n", 'aiprovider_awsbr');
        $eu = $this->make_instance('eu-west-1', ['eu.a', 'eu.b']);
        $us = $this->make_instance('us-east-1', ['us.a']);

        foreach (
            [
                \core_ai\aiactions\generate_text::class,
                \core_ai\aiactions\summarise_text::class,
                \core_ai\aiactions\explain_text::class,
            ] as $action
        ) {
            $this->assertSame(['eu.a', 'eu.b'], $this->model_options($action, (int) $eu->id), $action);
            $this->assertSame(['us.a'], $this->model_options($action, (int) $us->id), $action);
        }
        // The embed form offers embedding models only, never the chat profiles.
        $embed = \aiprovider_awsbr\aiactions\embed_text::class;
        $this->assertSame(['amazon.titan-embed-text-v2:0', 'cohere.embed-english-v3'], $this->model_options($embed, (int) $eu->id));
        // The grade_response form has an extra first option for "first available model".
        $grade = \aiprovider_awsbr\aiactions\grade_response::class;
        $this->assertSame(['', 'eu.a', 'eu.b'], $this->model_options($grade, (int) $eu->id));
        $this->assertSame(['', 'us.a'], $this->model_options($grade, (int) $us->id));
    }

    /**
     * The instance's availablemodels narrows its dropdowns.
     */
    public function test_forms_are_narrowed_by_the_instance_allowlist(): void {
        $this->resetAfterTest();
        $instance = $this->make_instance('eu-west-1', ['m.a', 'm.b', 'm.c'], "m.c|Third\n");
        $this->assertSame(['m.c'], $this->model_options(\core_ai\aiactions\generate_text::class, (int) $instance->id));
    }

    /**
     * Without a provider id (the defaults) nothing is read from the site config and no AWS call is made.
     */
    public function test_forms_without_provider_id_ignore_site_config(): void {
        $this->resetAfterTest();
        set_config('availablemodels', "site.model|Site wide\n", 'aiprovider_awsbr');

        $this->assertSame([''], $this->model_options(\core_ai\aiactions\generate_text::class, 0));
        $this->assertSame(
            ['amazon.titan-embed-text-v2:0'],
            $this->model_options(\aiprovider_awsbr\aiactions\embed_text::class, 0)
        );
        $this->assertSame([''], $this->model_options(\aiprovider_awsbr\aiactions\grade_response::class, 0));
    }

    /**
     * A model already saved for the action stays selectable, even if the catalog no longer lists it.
     */
    public function test_saved_model_is_kept_in_the_options(): void {
        $this->resetAfterTest();
        $instance = $this->make_instance('eu-west-1', ['m.a']);
        $this->assertSame(
            ['m.a', 'old.model'],
            $this->model_options(\core_ai\aiactions\generate_text::class, (int) $instance->id, 'old.model')
        );
    }

    /**
     * The default settings are built without any provider id, so without AWS or site config.
     */
    public function test_action_setting_defaults(): void {
        $this->resetAfterTest();
        set_config('availablemodels', "site.model|Site wide\n", 'aiprovider_awsbr');
        $defaults = provider::get_action_setting_defaults(\aiprovider_awsbr\aiactions\embed_text::class);
        $this->assertSame('amazon.titan-embed-text-v2:0', $defaults['model']);
        $defaults = provider::get_action_setting_defaults(\core_ai\aiactions\generate_text::class);
        $this->assertArrayHasKey('maxtokens', $defaults);
    }

    /**
     * The generate_image form offers the image models of its own instance, never chat or embedding models.
     */
    public function test_generate_image_form_options(): void {
        $this->resetAfterTest();
        $action = \core_ai\aiactions\generate_image::class;
        $eu = $this->make_instance('eu-west-1', ['eu.chat']);
        $this->assertSame(['amazon.nova-canvas-v1:0', 'stability.sd3-5-large-v1:0'], $this->model_options($action, (int) $eu->id));
        // A saved model stays in the list.
        $this->assertSame(
            ['amazon.nova-canvas-v1:0', 'stability.sd3-5-large-v1:0', 'old.image'],
            $this->model_options($action, (int) $eu->id, 'old.image')
        );
        // Narrowed by the instance's allowlist.
        $narrow = $this->make_instance('us-east-1', [], "stability.sd3-5-large-v1:0|SD\n");
        $this->assertSame(['stability.sd3-5-large-v1:0'], $this->model_options($action, (int) $narrow->id));
        // Without a provider id: the default model only, and no AWS call.
        $this->assertSame(['stability.stable-image-core-v1:1'], $this->model_options($action, 0));
    }

    /**
     * The generate_image defaults, and the action is part of the provider's action list.
     */
    public function test_generate_image_defaults_and_action_list(): void {
        $action = \core_ai\aiactions\generate_image::class;
        $this->assertContains($action, provider::get_action_list());
        $this->assertSame(
            ['model' => 'stability.stable-image-core-v1:1', 'imageregion' => '', 'negativeprompt' => '', 'cfgscale' => ''],
            provider::get_action_setting_defaults($action)
        );
    }

    /**
     * CFG scale and negative prompt are validated.
     */
    public function test_generate_image_form_validation(): void {
        $form = provider::get_action_settings(
            \core_ai\aiactions\generate_image::class,
            ['providerid' => 0, 'providername' => 'aiprovider_awsbr']
        );
        $validate = static fn(array $data) => $form->validation($data, []);
        $this->assertSame([], $validate(['cfgscale' => '', 'negativeprompt' => '']));
        $this->assertSame([], $validate(['cfgscale' => '1.1', 'negativeprompt' => str_repeat('x', 512)]));
        $this->assertSame([], $validate(['cfgscale' => '10']));
        foreach (['1.0', '10.5', 'abc'] as $bad) {
            $this->assertArrayHasKey('cfgscale', $validate(['cfgscale' => $bad]), $bad);
        }
        $this->assertArrayHasKey('negativeprompt', $validate(['negativeprompt' => str_repeat('x', 513)]));
    }

    /**
     * The image generation region is optional and must look like an AWS region code.
     */
    public function test_generate_image_form_region_validation(): void {
        $form = provider::get_action_settings(
            \core_ai\aiactions\generate_image::class,
            ['providerid' => 0, 'providername' => 'aiprovider_awsbr']
        );
        foreach (['', 'us-west-2', 'eu-central-1', 'us-gov-west-1', ' ap-southeast-2 '] as $good) {
            $this->assertArrayNotHasKey('imageregion', $form->validation(['imageregion' => $good], []), $good);
        }
        foreach (['us_west_2', 'uswest2', 'US-WEST-2', 'us-west-22', 'us-west', 'https://x.example'] as $bad) {
            $this->assertArrayHasKey('imageregion', $form->validation(['imageregion' => $bad], []), $bad);
        }
    }

    /**
     * The generate_image form built for an instance, saved region and catalog.
     *
     * @param int $providerid The instance id.
     * @param string $region The saved image region.
     * @param \aiprovider_awsbr\model_catalog|null $catalog The catalog to use (null = the instance's own).
     * @return \MoodleQuickForm
     */
    private function image_form(
        int $providerid,
        string $region,
        ?\aiprovider_awsbr\model_catalog $catalog = null,
    ): \MoodleQuickForm {
        $customdata = ['providerid' => $providerid, 'providername' => 'aiprovider_awsbr',
            'actionconfig' => ['settings' => ['imageregion' => $region]]];
        if ($catalog !== null) {
            $customdata['catalog'] = $catalog;
        }
        $form = provider::get_action_settings(\core_ai\aiactions\generate_image::class, $customdata);
        return (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
    }

    /**
     * A catalog whose control client answers from a queue (no AWS call).
     *
     * @param provider $provider The instance.
     * @param array $results The queued results and exceptions.
     * @return \aiprovider_awsbr\model_catalog
     */
    private function mock_catalog(provider $provider, array $results): \aiprovider_awsbr\model_catalog {
        return new class ($provider, new \Aws\MockHandler($results)) extends \aiprovider_awsbr\model_catalog {
            /**
             * Build a catalog whose control client uses the mock handler.
             *
             * @param provider $provider The instance.
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
                    'region' => $region, 'version' => 'latest', 'credentials' => ['key' => 'A', 'secret' => 'S'],
                    'retries' => 0, 'handler' => $this->mockhandler,
                ]);
            }
        };
    }

    /**
     * The notice is hidden when models exist, and shown, differently, when the region is empty or loading failed.
     */
    public function test_generate_image_form_notices(): void {
        $this->resetAfterTest();
        $eu = $this->make_instance('eu-west-1', []);
        $id = (int) $eu->id;

        // Models cached for the instance's region: no notice.
        $this->assertFalse($this->image_form($id, '')->elementExists('imagemodelsnotice'));

        // A region that has no models: the empty notice, naming the region and the AWS page.
        $catalog = $this->mock_catalog($eu, [
            new \Aws\Result(['modelSummaries' => []]),
            new \Aws\Result(['inferenceProfileSummaries' => []]),
        ]);
        $form = $this->image_form($id, 'eu-central-1', $catalog);
        $notice = $form->getElement('imagemodelsnotice')->toHtml();
        $this->assertStringContainsString('alert-warning', $notice);
        $this->assertStringContainsString('eu-central-1', $notice);
        $this->assertStringContainsString('models-region-compatibility.html', $notice);
        $this->assertStringContainsString('no text-to-image models', $notice);
        // No model that does not exist is offered.
        $this->assertSame([''], $this->model_options_of($form));

        // The list cannot be loaded: a different notice, and the default model is offered.
        $catalog = $this->mock_catalog($eu, [
            new \Aws\Exception\AwsException('denied', new \Aws\Command('ListFoundationModels')),
        ]);
        $form = $this->image_form($id, 'eu-north-1', $catalog);
        $notice = $form->getElement('imagemodelsnotice')->toHtml();
        $this->assertStringContainsString('bedrock:ListFoundationModels', $notice);
        $this->assertStringNotContainsString('no text-to-image models', $notice);
        $this->assertSame(['stability.stable-image-core-v1:1'], $this->model_options_of($form));
        $this->assertDebuggingCalled();
    }

    /**
     * The option values of the model select of a built form.
     *
     * @param \MoodleQuickForm $form The form.
     * @return string[]
     */
    private function model_options_of(\MoodleQuickForm $form): array {
        return array_map(static fn($option) => $option['attr']['value'], $form->getElement('model')->_options);
    }
}
