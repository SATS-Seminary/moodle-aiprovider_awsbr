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
 * Tests for the upgrade step that adds generate_image to existing instances.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('aiprovider_awsbr_add_generate_image_disabled')]
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
            ['enabled' => false, 'settings' => ['model' => 'amazon.nova-canvas-v1:0', 'negativeprompt' => '', 'cfgscale' => '']],
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
}
