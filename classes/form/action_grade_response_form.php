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
 * Per-action settings form for grade_response.
 *
 * Fields:
 *  - model     : default model, populated like the generate_text form (live
 *                catalog narrowed by availablemodels). Empty = the first
 *                available model.
 *  - maxtokens : default maximum tokens to generate (used when the caller does
 *                not set one). Default 2048.
 *  - effort    : '' (model default) | low | medium | high.
 *
 * There is no system instruction field (the caller supplies the system prompt)
 * and no temperature (it is fixed at 0 where the model accepts it).
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_grade_response_form extends action_settings_form {
    /** Default maximum tokens when none is configured. */
    private const DEFAULT_MAXTOKENS = 2048;

    #[\Override]
    protected function definition(): void {
        $mform        = $this->_form;
        $actionconfig = $this->_customdata['actionconfig']['settings'] ?? [];
        $returnurl    = $this->_customdata['returnurl'] ?? null;
        $actionname   = $this->_customdata['actionname']; // The 'grade_response' action name.
        $action       = $this->_customdata['action'];      // Fully-qualified action class name.
        $providerid   = $this->_customdata['providerid'] ?? 0;

        // Model dropdown.
        $mform->addElement(
            'select',
            'model',
            get_string("action:{$actionname}:model", 'aiprovider_awsbr'),
            $this->get_model_options(),
        );
        $mform->setType('model', PARAM_TEXT);
        $mform->setDefault('model', $actionconfig['model'] ?? '');
        $mform->addHelpButton('model', "action:{$actionname}:model", 'aiprovider_awsbr');

        // Max tokens.
        $mform->addElement(
            'text',
            'maxtokens',
            get_string("action:{$actionname}:maxtokens", 'aiprovider_awsbr'),
        );
        $mform->setType('maxtokens', PARAM_INT);
        $mform->setDefault('maxtokens', $actionconfig['maxtokens'] ?? self::DEFAULT_MAXTOKENS);
        $mform->addHelpButton('maxtokens', "action:{$actionname}:maxtokens", 'aiprovider_awsbr');

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
     * Return default values for this form (called by provider::get_action_setting_defaults()).
     *
     * @return array
     */
    public function get_defaults(): array {
        return [
            'model'     => '',
            'maxtokens' => self::DEFAULT_MAXTOKENS,
            'effort'    => '',
        ];
    }

    /**
     * Build the model dropdown options.
     *
     * Same source as the generate_text form: the live catalog of invokable
     * profiles, narrowed and relabelled by the optional availablemodels setting.
     * The first option leaves the model empty, meaning the first available model.
     *
     * @return array Associative array of profile_id => Display label.
     */
    private function get_model_options(): array {
        $rawmodels = (string) (get_config('aiprovider_awsbr', 'availablemodels') ?? '');
        $allowlist = \aiprovider_awsbr\model_catalog::parse_allowlist($rawmodels);
        $catalog   = \aiprovider_awsbr\model_catalog::discover();
        $options   = $catalog !== null ? $catalog->options($allowlist) : $allowlist;

        return ['' => get_string('action:grade_response:model_default', 'aiprovider_awsbr')] + $options;
    }
}
