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
}
