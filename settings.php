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
 * Admin settings for aiprovider_awsbr.
 *
 * Implements the full credential / region / model / rate-limit settings page
 * as specified in contracts.md (stream A1).
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Moodle's AI subsystem registers the provider under its own admin category.
    // We add our settings into the provider's settings page via the standard
    // admin_settingpage that the AI subsystem creates for each provider plugin.
    // This file is included by Moodle's admin settings framework when the
    // 'aiprovider_awsbr' section is requested.

    // Load the AWS region class (deprecated notice suppressed — we still need it
    // until Moodle ships a replacement; this is the prescribed approach for now).
    require_once($CFG->libdir . '/classes/aws/admin_settings_aws_region.php');

    // Credentials block.

    $settings->add(new admin_setting_heading(
        'aiprovider_awsbr/credentials_heading',
        get_string('credentials_heading', 'aiprovider_awsbr'),
        get_string('credentials_heading_desc', 'aiprovider_awsbr'),
    ));

    // Authentication mode.
    $settings->add(new admin_setting_configselect(
        'aiprovider_awsbr/authmode',
        get_string('authmode', 'aiprovider_awsbr'),
        get_string('authmode_help', 'aiprovider_awsbr'),
        'static',
        [
            'static'        => get_string('authmode_static', 'aiprovider_awsbr'),
            'instance_role' => get_string('authmode_instance_role', 'aiprovider_awsbr'),
            'assume_role'   => get_string('authmode_assume_role', 'aiprovider_awsbr'),
        ],
    ));

    // Static access key ID (shown when authmode = static or as base creds for assume_role).
    $settings->add(new admin_setting_configtext(
        'aiprovider_awsbr/accesskeyid',
        get_string('accesskeyid', 'aiprovider_awsbr'),
        get_string('accesskeyid_desc', 'aiprovider_awsbr'),
        '',
        PARAM_TEXT,
    ));

    // Secret access key.
    $settings->add(new admin_setting_configpasswordunmask(
        'aiprovider_awsbr/secretaccesskey',
        get_string('secretaccesskey', 'aiprovider_awsbr'),
        get_string('secretaccesskey_desc', 'aiprovider_awsbr'),
        '',
    ));

    // STS role ARN (for assume_role mode).
    $settings->add(new admin_setting_configtext(
        'aiprovider_awsbr/assumerolearn',
        get_string('assumerolearn', 'aiprovider_awsbr'),
        get_string('assumerolearn_desc', 'aiprovider_awsbr'),
        '',
        PARAM_TEXT,
    ));

    // STS session name.
    $settings->add(new admin_setting_configtext(
        'aiprovider_awsbr/assumerolesessionname',
        get_string('assumerolesessionname', 'aiprovider_awsbr'),
        get_string('assumerolesessionname_desc', 'aiprovider_awsbr'),
        'moodle-bedrock',
        PARAM_TEXT,
    ));

    // Region block.

    $settings->add(new admin_setting_heading(
        'aiprovider_awsbr/region_heading',
        get_string('region_heading', 'aiprovider_awsbr'),
        '',
    ));

    // Primary region.
    $settings->add(new \core\aws\admin_settings_aws_region(
        'aiprovider_awsbr/region',
        get_string('region', 'aiprovider_awsbr'),
        get_string('region_help', 'aiprovider_awsbr'),
        'af-south-1',
    ));

    // Fallback region (optional; blank = disabled).
    $settings->add(new \core\aws\admin_settings_aws_region(
        'aiprovider_awsbr/fallbackregion',
        get_string('fallbackregion', 'aiprovider_awsbr'),
        get_string('fallbackregion_help', 'aiprovider_awsbr'),
        '',
    ));

    // Speech (Polly / Transcribe) config is per-instance — defined on the
    // provider-instance form via hook_listener, not as a global setting.

    // Models block.

    $settings->add(new admin_setting_heading(
        'aiprovider_awsbr/models_heading',
        get_string('models_heading', 'aiprovider_awsbr'),
        '',
    ));

    $defaultmodels = implode("\n", [
        'global.anthropic.claude-sonnet-4-5-20250929-v1:0|Claude Sonnet 4.5',
        'global.anthropic.claude-haiku-4-5-20251001-v1:0|Claude Haiku 4.5',
        'global.anthropic.claude-opus-4-5-20250929-v1:0|Claude Opus 4.5',
    ]);

    $settings->add(new admin_setting_configtextarea(
        'aiprovider_awsbr/availablemodels',
        get_string('availablemodels', 'aiprovider_awsbr'),
        get_string('availablemodels_help', 'aiprovider_awsbr'),
        $defaultmodels,
        PARAM_TEXT,
    ));

    // Rate limiting block.

    $settings->add(new admin_setting_heading(
        'aiprovider_awsbr/ratelimit_heading',
        get_string('ratelimit_heading', 'aiprovider_awsbr'),
        '',
    ));

    // Per-user rate limit toggle.
    $settings->add(new admin_setting_configcheckbox(
        'aiprovider_awsbr/enableuserratelimit',
        get_string('enableuserratelimit', 'aiprovider_awsbr'),
        get_string('enableuserratelimit_desc', 'aiprovider_awsbr'),
        0,
    ));

    // Per-user rate limit value.
    $settings->add(new admin_setting_configtext(
        'aiprovider_awsbr/userratelimit',
        get_string('userratelimit', 'aiprovider_awsbr'),
        get_string('userratelimit_desc', 'aiprovider_awsbr'),
        0,
        PARAM_INT,
    ));

    // Global rate limit toggle.
    $settings->add(new admin_setting_configcheckbox(
        'aiprovider_awsbr/enableglobalratelimit',
        get_string('enableglobalratelimit', 'aiprovider_awsbr'),
        get_string('enableglobalratelimit_desc', 'aiprovider_awsbr'),
        0,
    ));

    // Global rate limit value.
    $settings->add(new admin_setting_configtext(
        'aiprovider_awsbr/globalratelimit',
        get_string('globalratelimit', 'aiprovider_awsbr'),
        get_string('globalratelimit_desc', 'aiprovider_awsbr'),
        0,
        PARAM_INT,
    ));
}
