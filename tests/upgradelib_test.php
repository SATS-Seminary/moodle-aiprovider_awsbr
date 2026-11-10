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

use core_ai\aiactions\generate_image;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../db/upgradelib.php');

/**
 * Tests for the upgrade steps: adding generate_image to existing instances, and removing the old site settings.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('aiprovider_awsbr_add_generate_image_disabled')]
#[\PHPUnit\Framework\Attributes\CoversFunction('aiprovider_awsbr_replace_retired_image_models')]
#[\PHPUnit\Framework\Attributes\CoversFunction('aiprovider_awsbr_remove_site_settings')]
final class upgradelib_test extends \advanced_testcase {
    /**
     * Create an instance, with its action config replaced by the given one.
     *
     * @param string $name The instance name.
     * @param array|null $actionconfig The stored action config (null = leave the defaults).
     * @return int The instance id.
     */
    private function make_instance(string $name, ?array $actionconfig): int {
        global $DB;
        $provider = \core\di::get(\core_ai\manager::class)->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      $name,
            config:    ['authmode' => 'instance_role', 'region' => 'us-east-1'],
        );
        if ($actionconfig !== null) {
            $DB->set_field('ai_providers', 'actionconfig', json_encode($actionconfig), ['id' => $provider->id]);
        }
        return (int) $provider->id;
    }

    /**
     * The stored action config of an instance.
     *
     * @param int $id The instance id.
     * @return array
     */
    private function stored_config(int $id): array {
        global $DB;
        return json_decode((string) $DB->get_field('ai_providers', 'actionconfig', ['id' => $id]), true);
    }

    /**
     * An old instance gets generate_image disabled, and its other actions are untouched.
     */
    public function test_adds_generate_image_disabled_without_touching_other_actions(): void {
        global $DB;
        $this->resetAfterTest();
        $old = [
            \core_ai\aiactions\generate_text::class => ['enabled' => true, 'settings' => ['model' => 'm1', 'maxtokens' => 100]],
            \core_ai\aiactions\summarise_text::class => ['enabled' => false, 'settings' => ['model' => 'm2']],
            \aiprovider_awsbr\aiactions\grade_response::class => ['enabled' => true, 'settings' => ['model' => '']],
        ];
        $oldid = $this->make_instance('old', $old);
        // An instance of another provider must not be touched.
        $other = $DB->insert_record('ai_providers', (object) [
            'name' => 'other', 'provider' => 'aiprovider_openai\provider', 'enabled' => 1,
            'config' => '{}', 'actionconfig' => '{"x":1}',
        ]);

        $changed = aiprovider_awsbr_add_generate_image_disabled();

        $this->assertSame(1, $changed);
        $config = $this->stored_config($oldid);
        $this->assertSame(
            [
                'enabled'  => false,
                'settings' => [
                    'model' => 'stability.stable-image-core-v1:1', 'imageregion' => '', 'negativeprompt' => '', 'cfgscale' => '',
                ],
            ],
            $config[generate_image::class]
        );
        unset($config[generate_image::class]);
        $this->assertSame($old, $config);
        $this->assertSame('{"x":1}', $DB->get_field('ai_providers', 'actionconfig', ['id' => $other]));
    }

    /**
     * An instance that already has the action keeps its setting, and a second run changes nothing.
     */
    public function test_existing_generate_image_is_left_alone_and_the_step_is_repeatable(): void {
        $this->resetAfterTest();
        $mine = [generate_image::class => ['enabled' => true, 'settings' => ['model' => 'stability.sd3-5-large-v1:0']]];
        $id = $this->make_instance('has-image', $mine);
        $this->assertSame(0, aiprovider_awsbr_add_generate_image_disabled());
        $this->assertSame($mine, $this->stored_config($id));

        $old = $this->make_instance('no-image', [\core_ai\aiactions\generate_text::class => ['enabled' => true, 'settings' => []]]);
        $this->assertSame(1, aiprovider_awsbr_add_generate_image_disabled());
        $after = $this->stored_config($old);
        $this->assertSame(0, aiprovider_awsbr_add_generate_image_disabled());
        $this->assertSame($after, $this->stored_config($old));
    }

    /**
     * An instance with an empty action config gets the action too, disabled.
     */
    public function test_empty_action_config(): void {
        global $DB;
        $this->resetAfterTest();
        $id = $this->make_instance('empty', null);
        $DB->set_field('ai_providers', 'actionconfig', '', ['id' => $id]);
        aiprovider_awsbr_add_generate_image_disabled();
        $this->assertSame([generate_image::class], array_keys($this->stored_config($id)));
        $this->assertFalse($this->stored_config($id)[generate_image::class]['enabled']);
    }

    /**
     * The removed settings page's values are deleted, and nothing else is.
     */
    public function test_remove_site_settings_deletes_only_the_old_settings(): void {
        $this->resetAfterTest();
        $old = [
            'authmode' => 'static', 'accesskeyid' => 'AKIAEXAMPLE', 'secretaccesskey' => 'topsecret',
            'assumerolearn' => 'arn:aws:iam::123456789012:role/x', 'assumerolesessionname' => 'moodle-bedrock',
            'region' => 'eu-west-1', 'fallbackregion' => 'us-east-1', 'availablemodels' => "a|A\nb|B",
            'enableuserratelimit' => 1, 'userratelimit' => 5, 'enableglobalratelimit' => 1, 'globalratelimit' => 50,
        ];
        foreach ($old as $name => $value) {
            set_config($name, $value, 'aiprovider_awsbr');
        }
        // Not a setting of the page: it must survive.
        set_config('somethingelse', 'keep', 'aiprovider_awsbr');
        set_config('authmode', 'static', 'aiprovider_openai');
        $version = get_config('aiprovider_awsbr', 'version');
        $this->assertNotEmpty($version);

        $this->assertSame(12, aiprovider_awsbr_remove_site_settings());

        foreach (array_keys($old) as $name) {
            $this->assertFalse(get_config('aiprovider_awsbr', $name), $name);
        }
        $this->assertSame('keep', get_config('aiprovider_awsbr', 'somethingelse'));
        $this->assertSame('static', get_config('aiprovider_openai', 'authmode'));
        $this->assertSame($version, get_config('aiprovider_awsbr', 'version'));

        // Repeatable, and a site that never had the values is fine.
        $this->assertSame(0, aiprovider_awsbr_remove_site_settings());
    }

    /**
     * Retired models are reset to the first available model, imageregion is added, and nothing else changes.
     */
    public function test_replace_retired_image_models(): void {
        global $DB;
        $this->resetAfterTest();
        $text = [\core_ai\aiactions\generate_text::class => [
            'enabled' => true, 'settings' => ['model' => 'amazon.nova-canvas-v1:0'],
        ]];
        $nova = $this->make_instance('nova', $text + [generate_image::class => [
            'enabled' => true, 'settings' => ['model' => 'amazon.nova-canvas-v1:0', 'negativeprompt' => 'x', 'cfgscale' => '5'],
        ]]);
        $titan = $this->make_instance('titan', [generate_image::class => [
            'enabled' => false, 'settings' => ['model' => 'amazon.titan-image-generator-v2:0', 'imageregion' => 'us-west-2'],
        ]]);
        $sd = $this->make_instance('sd', [generate_image::class => [
            'enabled' => true, 'settings' => ['model' => 'stability.sd3-5-large-v1:0'],
        ]]);
        $done = $this->make_instance('done', [generate_image::class => [
            'enabled' => true, 'settings' => ['model' => 'stability.stable-image-core-v1:1', 'imageregion' => 'us-west-2'],
        ]]);
        $none = $this->make_instance('none', $text);
        $beforedone = $this->stored_config($done);
        $beforenone = $this->stored_config($none);
        $other = $DB->insert_record('ai_providers', (object) [
            'name' => 'other', 'provider' => 'aiprovider_openai\provider', 'enabled' => 1,
            'config' => '{}', 'actionconfig' => '{"x":1}',
        ]);

        $this->assertSame(3, aiprovider_awsbr_replace_retired_image_models());

        $config = $this->stored_config($nova);
        $this->assertSame(
            ['enabled' => true, 'settings' => ['model' => '', 'negativeprompt' => 'x', 'cfgscale' => '5', 'imageregion' => '']],
            $config[generate_image::class]
        );
        $this->assertSame($text[\core_ai\aiactions\generate_text::class], $config[\core_ai\aiactions\generate_text::class]);
        // Titan: model reset, the existing region and the disabled flag are kept.
        $this->assertSame(
            ['enabled' => false, 'settings' => ['model' => '', 'imageregion' => 'us-west-2']],
            $this->stored_config($titan)[generate_image::class]
        );
        // A supported model stays; the region is added.
        $this->assertSame(
            ['enabled' => true, 'settings' => ['model' => 'stability.sd3-5-large-v1:0', 'imageregion' => '']],
            $this->stored_config($sd)[generate_image::class]
        );
        // Nothing to change, no generate_image action, and other providers: untouched.
        $this->assertSame($beforedone, $this->stored_config($done));
        $this->assertSame($beforenone, $this->stored_config($none));
        $this->assertSame('{"x":1}', $DB->get_field('ai_providers', 'actionconfig', ['id' => $other]));

        // Repeatable.
        $this->assertSame(0, aiprovider_awsbr_replace_retired_image_models());
    }
}
