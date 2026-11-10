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
 * Helper functions for the aiprovider_awsbr upgrade steps.
 *
 * Kept as plain functions that name no plugin class, so a step keeps working
 * however the plugin's classes change later.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the generate_image action, disabled, to existing provider instances.
 *
 * Existing instances keep their own action configuration, so a new action is not
 * present on them until it is added here. It is added disabled: image generation
 * costs money and would otherwise appear in the editors at once, so an administrator
 * opts in. Instances that already have the action, and all other actions, are left
 * untouched. The defaults are literal rather than read from the settings form,
 * whose model list queries Bedrock.
 *
 * @return int The number of instances changed.
 */
function aiprovider_awsbr_add_generate_image_disabled(): int {
    global $DB;

    $action = 'core_ai\\aiactions\\generate_image';
    $changed = 0;
    $providers = $DB->get_records('ai_providers', ['provider' => 'aiprovider_awsbr\\provider']);
    foreach ($providers as $record) {
        $actionconfig = json_decode((string) $record->actionconfig, true);
        $actionconfig = is_array($actionconfig) ? $actionconfig : [];
        if (array_key_exists($action, $actionconfig)) {
            continue;
        }
        $actionconfig[$action] = [
            'enabled'  => false,
            'settings' => [
                'model'          => 'stability.stable-image-core-v1:1',
                'imageregion'    => '',
                'negativeprompt' => '',
                'cfgscale'       => '',
            ],
        ];
        $DB->set_field('ai_providers', 'actionconfig', json_encode($actionconfig), ['id' => $record->id]);
        $changed++;
    }
    return $changed;
}

/**
 * Delete the settings of the removed site-wide settings page.
 *
 * The plugin used to have a site-wide settings page (credentials, region, models and
 * rate limits). All configuration now lives on provider instances, so nothing reads
 * these values. They are deleted because they may include an AWS access key and secret
 * that would otherwise stay in the database for nothing. Only these names are removed;
 * the plugin's version and any other setting are left alone.
 *
 * @return int The number of settings that existed and were deleted.
 */
function aiprovider_awsbr_remove_site_settings(): int {
    $names = [
        'authmode',
        'accesskeyid',
        'secretaccesskey',
        'assumerolearn',
        'assumerolesessionname',
        'region',
        'fallbackregion',
        'availablemodels',
        'enableuserratelimit',
        'userratelimit',
        'enableglobalratelimit',
        'globalratelimit',
    ];

    $removed = 0;
    foreach ($names as $name) {
        if (get_config('aiprovider_awsbr', $name) !== false) {
            unset_config($name, 'aiprovider_awsbr');
            $removed++;
        }
    }
    return $removed;
}

/**
 * Replace retired image models in the generate_image settings of existing instances.
 *
 * AWS retired Amazon Nova Canvas and Amazon Titan Image Generator v2, and the first
 * 1.7.0 build defaulted to Nova Canvas. For each instance whose generate_image model
 * is one of those two, the model becomes '' (the first available model), and the new
 * imageregion setting is added, empty, when it is missing. The enabled flag and all
 * other actions and settings are left untouched.
 *
 * @return int The number of instances changed.
 */
function aiprovider_awsbr_replace_retired_image_models(): int {
    global $DB;

    $action = 'core_ai\\aiactions\\generate_image';
    $retired = ['amazon.nova-canvas-v1:0', 'amazon.titan-image-generator-v2:0'];
    $changed = 0;
    $providers = $DB->get_records('ai_providers', ['provider' => 'aiprovider_awsbr\\provider']);
    foreach ($providers as $record) {
        $actionconfig = json_decode((string) $record->actionconfig, true);
        if (!is_array($actionconfig) || !isset($actionconfig[$action]) || !is_array($actionconfig[$action])) {
            continue;
        }
        $settings = $actionconfig[$action]['settings'] ?? [];
        $settings = is_array($settings) ? $settings : [];
        $update = false;
        if (in_array($settings['model'] ?? null, $retired, true)) {
            $settings['model'] = '';
            $update = true;
        }
        if (!array_key_exists('imageregion', $settings)) {
            $settings['imageregion'] = '';
            $update = true;
        }
        if (!$update) {
            continue;
        }
        $actionconfig[$action]['settings'] = $settings;
        $DB->set_field('ai_providers', 'actionconfig', json_encode($actionconfig), ['id' => $record->id]);
        $changed++;
    }
    return $changed;
}
