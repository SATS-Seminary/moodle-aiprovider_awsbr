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

namespace aiprovider_awsbr\form;

use core_ai\form\action_settings_form;

/**
 * Per-action settings form for generate_text (also used by summarise_text and explain_text).
 *
 * Fields:
 *  - model            : dropdown of the models this provider instance allows.
 *  - systeminstruction: textarea (defaults to the action class's lang string default).
 *  - maxtokens        : integer (1–8192).
 *  - temperature      : float 0.0–1.0.
 *  - topp             : float 0.0–1.0.
 *  - effort           : '' (model default) | low | medium | high.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_generate_text_form extends action_settings_form {
    #[\Override]
    protected function definition() {
        $mform        = $this->_form;
        $actionconfig = $this->_customdata['actionconfig']['settings'] ?? [];
        $returnurl    = $this->_customdata['returnurl'] ?? null;
        $actionname   = $this->_customdata['actionname'];
        $action       = $this->_customdata['action'];
        $providerid   = $this->_customdata['providerid'] ?? 0;

        // Model dropdown.
        // Options come from this provider instance only.
        $modeloptions = $this->get_model_options((int) $providerid, (string) ($actionconfig['model'] ?? ''));
        $mform->addElement(
            'select',
            'model',
            get_string("action:{$actionname}:model", 'aiprovider_awsbr'),
            $modeloptions,
        );
        $mform->setType('model', PARAM_TEXT);
        $mform->setDefault('model', $actionconfig['model'] ?? '');
        $mform->addHelpButton('model', "action:{$actionname}:model", 'aiprovider_awsbr');

        // System instruction textarea.
        $mform->addElement(
            'textarea',
            'systeminstruction',
            get_string("action:{$actionname}:systeminstruction", 'aiprovider_awsbr'),
            'wrap="virtual" rows="5" cols="50"',
        );
        $mform->setType('systeminstruction', PARAM_TEXT);
        $mform->setDefault('systeminstruction', $actionconfig['systeminstruction'] ?? $action::get_system_instruction());
        $mform->addHelpButton('systeminstruction', "action:{$actionname}:systeminstruction", 'aiprovider_awsbr');

        // Max tokens.
        $mform->addElement(
            'text',
            'maxtokens',
            get_string("action:{$actionname}:maxtokens", 'aiprovider_awsbr'),
            'maxlength="5" size="6"',
        );
        $mform->setType('maxtokens', PARAM_INT);
        $mform->setDefault('maxtokens', $actionconfig['maxtokens'] ?? 4096);
        $mform->addRule('maxtokens', null, 'required', null, 'client');
        $mform->addRule('maxtokens', null, 'numeric', null, 'client');
        $mform->addHelpButton('maxtokens', "action:{$actionname}:maxtokens", 'aiprovider_awsbr');

        // Temperature.
        $mform->addElement(
            'text',
            'temperature',
            get_string("action:{$actionname}:temperature", 'aiprovider_awsbr'),
            'maxlength="5" size="6"',
        );
        $mform->setType('temperature', PARAM_FLOAT);
        $mform->setDefault('temperature', $actionconfig['temperature'] ?? 0.7);
        $mform->addHelpButton('temperature', "action:{$actionname}:temperature", 'aiprovider_awsbr');

        // Top-P.
        $mform->addElement(
            'text',
            'topp',
            get_string("action:{$actionname}:topp", 'aiprovider_awsbr'),
            'maxlength="5" size="6"',
        );
        $mform->setType('topp', PARAM_FLOAT);
        $mform->setDefault('topp', $actionconfig['topp'] ?? 1.0);
        $mform->addHelpButton('topp', "action:{$actionname}:topp", 'aiprovider_awsbr');

        // Effort (Claude 4.6 and later; ignored for other models).
        $effortoptions = ['' => get_string('effort_default', 'aiprovider_awsbr')];
        foreach (\aiprovider_awsbr\model_capabilities::EFFORT_LEVELS as $level) {
            $effortoptions[$level] = get_string("effort_{$level}", 'aiprovider_awsbr');
        }
        $mform->addElement('select', 'effort', get_string('effort', 'aiprovider_awsbr'), $effortoptions);
        $mform->setType('effort', PARAM_ALPHA);
        $mform->setDefault('effort', $actionconfig['effort'] ?? '');
        $mform->addHelpButton('effort', 'effort', 'aiprovider_awsbr');

        // Hidden fields.
        if ($returnurl) {
            $mform->addElement('hidden', 'returnurl', $returnurl);
            $mform->setType('returnurl', PARAM_LOCALURL);
        }

        $mform->addElement('hidden', 'action', $action);
        $mform->setType('action', PARAM_TEXT);

        $mform->addElement('hidden', 'provider', 'aiprovider_awsbr');
        $mform->setType('provider', PARAM_TEXT);

        $mform->addElement('hidden', 'providerid', $providerid);
        $mform->setType('providerid', PARAM_INT);

        $this->set_data($actionconfig);
    }

    /**
     * Build the model dropdown options for this provider instance.
     *
     * Comes from the instance's live catalog, narrowed by its availablemodels
     * setting. Nothing is read from site-wide config, and no AWS call is made
     * without a provider id. A model already saved for the action is kept in
     * the list so that saving the form does not silently change it.
     *
     * @param int $providerid The provider instance ID (0 when unknown).
     * @param string $saved The model currently saved for the action.
     * @return array Associative array of profile_id => Display label.
     */
    private function get_model_options(int $providerid, string $saved): array {
        $options = \aiprovider_awsbr\model_catalog::options_for_provider_id($providerid);
        if ($saved !== '' && !isset($options[$saved])) {
            $options[$saved] = $saved;
        }
        if (empty($options)) {
            $options[''] = get_string('action:generate_text:model_none', 'aiprovider_awsbr');
        }
        return $options;
    }
}
