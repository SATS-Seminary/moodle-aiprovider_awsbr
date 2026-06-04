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

use core_ai\hook\after_ai_provider_form_hook;

/**
 * Hook listener for the AWS Bedrock AI provider.
 *
 * Adds the Bedrock credential and region fields to the provider-instance form
 * exposed at Site administration → AI → AI providers → Add (or Edit).
 *
 * AWS Bedrock authenticates via SigV4, NOT an API key. The form therefore exposes
 * AWS-style credentials: access key + secret, instance role, or assumed STS role.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {

    /**
     * Hook callback for the AI provider instance setup form.
     *
     * @param after_ai_provider_form_hook $hook The hook fired after the AI provider form is set up.
     */
    public static function handle_provider_form(after_ai_provider_form_hook $hook): void {
        if ($hook->plugin !== 'aiprovider_awsbr') {
            return;
        }

        $mform = $hook->mform;

        // === Authentication mode ===
        $mform->addElement(
            'select',
            'authmode',
            get_string('authmode', 'aiprovider_awsbr'),
            [
                'api_key'        => get_string('authmode_api_key', 'aiprovider_awsbr'),
                'static'         => get_string('authmode_static', 'aiprovider_awsbr'),
                'instance_role'  => get_string('authmode_instance_role', 'aiprovider_awsbr'),
                'assume_role'    => get_string('authmode_assume_role', 'aiprovider_awsbr'),
            ]
        );
        $mform->addHelpButton('authmode', 'authmode', 'aiprovider_awsbr');
        $mform->setDefault('authmode', 'api_key');

        // === Bedrock API key (bearer token) — shown when authmode = api_key ===
        $mform->addElement(
            'passwordunmask',
            'apikey',
            get_string('apikey', 'aiprovider_awsbr'),
            ['size' => 80]
        );
        $mform->addHelpButton('apikey', 'apikey', 'aiprovider_awsbr');
        $mform->hideIf('apikey', 'authmode', 'neq', 'api_key');

        // === Static credentials (shown when authmode = static) ===
        $mform->addElement(
            'text',
            'accesskeyid',
            get_string('accesskeyid', 'aiprovider_awsbr'),
            ['size' => 32]
        );
        $mform->setType('accesskeyid', PARAM_TEXT);
        $mform->addHelpButton('accesskeyid', 'accesskeyid', 'aiprovider_awsbr');
        $mform->hideIf('accesskeyid', 'authmode', 'neq', 'static');

        $mform->addElement(
            'passwordunmask',
            'secretaccesskey',
            get_string('secretaccesskey', 'aiprovider_awsbr'),
            ['size' => 60]
        );
        $mform->addHelpButton('secretaccesskey', 'secretaccesskey', 'aiprovider_awsbr');
        $mform->hideIf('secretaccesskey', 'authmode', 'neq', 'static');

        // === STS Assume-role (shown when authmode = assume_role) ===
        $mform->addElement(
            'text',
            'assumerolearn',
            get_string('assumerolearn', 'aiprovider_awsbr'),
            ['size' => 80]
        );
        $mform->setType('assumerolearn', PARAM_TEXT);
        $mform->addHelpButton('assumerolearn', 'assumerolearn', 'aiprovider_awsbr');
        $mform->hideIf('assumerolearn', 'authmode', 'neq', 'assume_role');

        $mform->addElement(
            'text',
            'assumerolesessionname',
            get_string('assumerolesessionname', 'aiprovider_awsbr'),
            ['size' => 40]
        );
        $mform->setType('assumerolesessionname', PARAM_TEXT);
        $mform->setDefault('assumerolesessionname', 'moodle-bedrock');
        $mform->hideIf('assumerolesessionname', 'authmode', 'neq', 'assume_role');

        // === Region ===
        $mform->addElement(
            'text',
            'region',
            get_string('region', 'aiprovider_awsbr'),
            ['size' => 20]
        );
        $mform->setType('region', PARAM_TEXT);
        $mform->setDefault('region', 'af-south-1');
        $mform->addHelpButton('region', 'region', 'aiprovider_awsbr');
        $mform->addRule('region', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'text',
            'fallbackregion',
            get_string('fallbackregion', 'aiprovider_awsbr'),
            ['size' => 20]
        );
        $mform->setType('fallbackregion', PARAM_TEXT);
        $mform->addHelpButton('fallbackregion', 'fallbackregion', 'aiprovider_awsbr');

        // === Available inference profile IDs ===
        $mform->addElement(
            'textarea',
            'availablemodels',
            get_string('availablemodels', 'aiprovider_awsbr'),
            ['rows' => 6, 'cols' => 80]
        );
        $mform->setType('availablemodels', PARAM_RAW);
        $mform->setDefault('availablemodels',
            "global.anthropic.claude-sonnet-4-5-20250929-v1:0|Claude Sonnet 4.5\n" .
            "global.anthropic.claude-haiku-4-5-20251001-v1:0|Claude Haiku 4.5\n" .
            "global.anthropic.claude-opus-4-5-20250929-v1:0|Claude Opus 4.5\n" .
            "amazon.titan-embed-text-v2:0|Amazon Titan Embeddings v2");
        $mform->addHelpButton('availablemodels', 'availablemodels', 'aiprovider_awsbr');

        // Link to the live model browser / refresh tool (admin UI, no CLI needed).
        $browseurl = new \moodle_url('/ai/provider/awsbr/models.php');
        $mform->addElement(
            'static',
            'models_browse',
            '',
            \html_writer::link($browseurl, get_string('models_browse_link', 'aiprovider_awsbr'),
                ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank'])
            . \html_writer::div(get_string('models_browse_desc', 'aiprovider_awsbr'), 'text-muted small mt-1')
        );

        // === Proxy / CA note ===
        $mform->addElement(
            'static',
            'proxy_note_body',
            '',
            get_string('proxy_note_body', 'aiprovider_awsbr')
        );
    }
}
