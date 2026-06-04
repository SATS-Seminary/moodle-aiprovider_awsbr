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

/**
 * Version information for the AWS Bedrock AI provider plugin.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'aiprovider_awsbr';
// 2026053100 — Renamed from aiprovider_bedrock to aiprovider_awsbr to avoid
// collision with the public Moodle plugin repository's aiprovider_bedrock
// (last updated for Moodle 4.5). Includes data-migration savepoint that:
//   - renames audit table aiprovider_bedrock_embed_text → aiprovider_awsbr_embed_text
//   - rewrites config_plugins rows from plugin='aiprovider_bedrock' to 'aiprovider_awsbr'
//   - rewrites ai_action_register rows from provider='aiprovider_bedrock' to 'aiprovider_awsbr'
$plugin->version   = 2026060400;
$plugin->release   = '0.1.0';
$plugin->requires  = 2025040800; // Moodle 5.0 AI subsystem.
$plugin->maturity  = MATURITY_ALPHA;
