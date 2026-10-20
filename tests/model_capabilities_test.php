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
 * Tests for the per-model request-parameter rules.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\model_capabilities::class)]
final class model_capabilities_test extends \basic_testcase {
    /**
     * Model IDs with the expected [accepts sampling, supports effort].
     *
     * @return array
     */
    public static function model_provider(): array {
        return [
            'haiku 4.5 dated'    => ['global.anthropic.claude-haiku-4-5-20251001-v1:0', true, false],
            'sonnet 4 dated'     => ['us.anthropic.claude-sonnet-4-20250514-v1:0', true, false],
            'opus 4.1'           => ['anthropic.claude-opus-4-1-20250805-v1:0', true, false],
            'sonnet 4.6'         => ['global.anthropic.claude-sonnet-4-6', true, true],
            'opus 4.6'           => ['global.anthropic.claude-opus-4-6-v1', true, true],
            'opus 4.7'           => ['global.anthropic.claude-opus-4-7', false, true],
            'sonnet 5'           => ['global.anthropic.claude-sonnet-5', false, true],
            'haiku 5.5'          => ['global.anthropic.claude-haiku-5-5', false, true],
            'sonnet 5.5'         => ['eu.anthropic.claude-sonnet-5-5', false, true],
            'opus 5.5'           => ['global.anthropic.claude-opus-5-5', false, true],
            'fable 5.1'          => ['global.anthropic.claude-fable-5-1', false, true],
            'mythos preview'     => ['anthropic.claude-mythos-preview', false, true],
            'claude 3.5 legacy'  => ['anthropic.claude-3-5-sonnet-20240620-v1:0', true, false],
            'nova'               => ['eu.amazon.nova-lite-v1:0', true, false],
            'openai'             => ['global.openai.gpt-6-astra', true, false],
        ];
    }

    /**
     * Sampling and effort support are derived from the model ID.
     *
     * @param string $modelid
     * @param bool $sampling
     * @param bool $effort
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('model_provider')]
    public function test_capabilities(string $modelid, bool $sampling, bool $effort): void {
        $this->assertSame($sampling, model_capabilities::accepts_sampling_params($modelid));
        $this->assertSame($effort, model_capabilities::supports_effort($modelid));
    }

    /**
     * A date suffix is not read as the minor version.
     */
    public function test_parse_ignores_date_suffix(): void {
        $this->assertSame(
            ['family' => 'sonnet', 'major' => 4, 'minor' => 0],
            model_capabilities::parse_claude('us.anthropic.claude-sonnet-4-20250514-v1:0'),
        );
        $this->assertNull(model_capabilities::parse_claude('anthropic.claude-v2'));
    }
}
