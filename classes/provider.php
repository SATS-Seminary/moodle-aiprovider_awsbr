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

use core_ai\form\action_settings_form;

/**
 * AWS Bedrock AI provider.
 *
 * Plugs into Moodle's \core_ai\manager to provide generate_text, summarise_text,
 * explain_text, and embed_text actions via AWS Bedrock Converse API.
 *
 * Stream A1 + A2 + A4 implementation.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider extends \core_ai\provider {

    /**
     * Get the list of actions that this provider supports.
     *
     * @return array Array of fully-qualified action class names.
     */
    public static function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\summarise_text::class,
            \core_ai\aiactions\explain_text::class,
            \aiprovider_awsbr\aiactions\embed_text::class,
        ];
    }

    /**
     * Bedrock authenticates via AWS SigV4 inside the SDK — no HTTP-level auth headers needed.
     *
     * {@inheritdoc}
     */
    #[\Override]
    public function add_authentication_headers(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\RequestInterface {
        return $request;
    }

    /**
     * Return the per-action settings form instance.
     *
     * All three text actions share the same form class. embed_text has no form yet
     * (owned by stream A3).
     *
     * {@inheritdoc}
     */
    #[\Override]
    public static function get_action_settings(
        string $action,
        array $customdata = [],
    ): action_settings_form|bool {
        $actionname = substr($action, (strrpos($action, '\\') + 1));
        $customdata['actionname'] = $actionname;
        $customdata['action']     = $action;

        if (in_array($actionname, ['generate_text', 'summarise_text', 'explain_text'], true)) {
            return new form\action_generate_text_form(customdata: $customdata);
        }

        // A3: embed_text has its own lighter form (no system instruction / inference params).
        if ($actionname === 'embed_text') {
            return new form\action_embed_text_form(customdata: $customdata);
        }

        return false;
    }

    /**
     * Return the default settings for a given action.
     *
     * {@inheritdoc}
     */
    #[\Override]
    public static function get_action_setting_defaults(string $action): array {
        $actionname = substr($action, (strrpos($action, '\\') + 1));
        $customdata = [
            'actionname' => $actionname,
            'action'     => $action,
        ];

        if (in_array($actionname, ['generate_text', 'summarise_text', 'explain_text'], true)) {
            $mform = new form\action_generate_text_form(customdata: $customdata);
            return $mform->get_defaults();
        }

        // A3: embed_text defaults.
        if ($actionname === 'embed_text') {
            $mform = new form\action_embed_text_form(customdata: $customdata);
            return $mform->get_defaults();
        }

        return [];
    }

    /**
     * Check this provider has the minimal configuration to work.
     *
     * @return bool True if the provider is sufficiently configured.
     */
    #[\Override]
    public function is_provider_configured(): bool {
        $authmode = $this->config['authmode'] ?? '';
        if ($authmode === 'api_key') {
            return !empty($this->config['apikey']);
        }
        if ($authmode === 'instance_role') {
            return true;
        }
        if ($authmode === 'static') {
            return !empty($this->config['accesskeyid']) && !empty($this->config['secretaccesskey']);
        }
        if ($authmode === 'assume_role') {
            return !empty($this->config['assumerolearn']);
        }
        return false;
    }

    /**
     * Check if the request is allowed by the rate limiter.
     *
     * Identical in behaviour to the base class implementation — repeated here
     * for clarity and to allow per-provider extension in a future stream.
     *
     * @param \core_ai\aiactions\base $action The action to check.
     * @return array|bool True on success; array with errorcode/errormessage on failure.
     */
    #[\Override]
    public function is_request_allowed(\core_ai\aiactions\base $action): array|bool {
        $ratelimiter = \core\di::get(\core_ai\rate_limiter::class);
        $component   = 'aiprovider_awsbr';

        // Per-user rate limit.
        if (!empty($this->config['enableuserratelimit'])) {
            $limit = (int) ($this->config['userratelimit'] ?? 0);
            if ($limit > 0) {
                if (!$ratelimiter->check_user_rate_limit(
                    component: $component,
                    ratelimit: $limit,
                    userid:    (int) $action->get_configuration('userid'),
                )) {
                    return [
                        'success'      => false,
                        'errorcode'    => 429,
                        'error'        => 'RateLimitExceeded',
                        'errormessage' => 'User rate limit exceeded',
                    ];
                }
            }
        }

        // Global rate limit.
        if (!empty($this->config['enableglobalratelimit'])) {
            $limit = (int) ($this->config['globalratelimit'] ?? 0);
            if ($limit > 0) {
                if (!$ratelimiter->check_global_rate_limit(
                    component: $component,
                    ratelimit: $limit,
                )) {
                    return [
                        'success'      => false,
                        'errorcode'    => 429,
                        'error'        => 'RateLimitExceeded',
                        'errormessage' => 'Global rate limit exceeded',
                    ];
                }
            }
        }

        return true;
    }
}
