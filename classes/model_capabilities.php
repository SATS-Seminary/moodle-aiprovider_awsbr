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
 * Request-parameter rules per Bedrock model, derived from the model ID.
 *
 * Claude models after the 4.6 generation (Opus 4.7+, Sonnet 5+, Haiku 5.5,
 * Fable, Mythos) reject non-default `temperature` / `topP` with a 400, so the
 * sampling parameters must be left out for them. The `effort` control
 * (output_config.effort) is accepted from the 4.6 generation onwards.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_capabilities {
    /** @var string[] Effort levels offered in the action settings. */
    public const EFFORT_LEVELS = ['low', 'medium', 'high'];

    /** @var string[] Claude families that have no sampling parameters at any version. */
    private const NO_SAMPLING_FAMILIES = ['fable', 'mythos'];

    /**
     * Parse a Claude model ID of the form [geo.]anthropic.claude-<family>-<major>[-<minor>]…
     *
     * Older family-last IDs (claude-3-5-sonnet, claude-v2, claude-instant) return null.
     *
     * @param string $modelid Bedrock model or inference-profile ID.
     * @return array{family: string, major: int, minor: int}|null
     */
    public static function parse_claude(string $modelid): ?array {
        // The minor version is one or two digits followed by '-', ':', '.' or the end,
        // so a date suffix (claude-sonnet-4-20250514) is not mistaken for it.
        $pattern = '/anthropic\.claude-(opus|sonnet|haiku|fable|mythos)(?:-(\d+)(?:-(\d{1,2})(?=[-:.]|$))?)?/';
        if (!preg_match($pattern, $modelid, $m)) {
            return null;
        }
        return [
            'family' => $m[1],
            'major'  => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0,
            'minor'  => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0,
        ];
    }

    /**
     * Whether the model accepts the temperature / topP sampling parameters.
     *
     * @param string $modelid
     * @return bool
     */
    public static function accepts_sampling_params(string $modelid): bool {
        $claude = self::parse_claude($modelid);
        if ($claude === null) {
            return true;
        }
        if (in_array($claude['family'], self::NO_SAMPLING_FAMILIES, true)) {
            return false;
        }
        return !self::is_after($claude, 4, 6);
    }

    /**
     * Whether the model accepts output_config.effort.
     *
     * @param string $modelid
     * @return bool
     */
    public static function supports_effort(string $modelid): bool {
        $claude = self::parse_claude($modelid);
        if ($claude === null) {
            return false;
        }
        if (in_array($claude['family'], self::NO_SAMPLING_FAMILIES, true)) {
            return true;
        }
        return $claude['major'] > 4 || ($claude['major'] === 4 && $claude['minor'] >= 6);
    }

    /**
     * Whether a parsed Claude version is strictly later than major.minor.
     *
     * @param array $claude Result of {@see parse_claude()}.
     * @param int $major
     * @param int $minor
     * @return bool
     */
    private static function is_after(array $claude, int $major, int $minor): bool {
        return $claude['major'] > $major || ($claude['major'] === $major && $claude['minor'] > $minor);
    }
}
