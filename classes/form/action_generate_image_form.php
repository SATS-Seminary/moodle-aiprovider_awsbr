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
 * Per-action settings form for the core generate_image action.
 *
 * Fields:
 *  - model          : dropdown of the image models this provider instance allows.
 *                     Default: amazon.nova-canvas-v1:0.
 *  - negativeprompt : optional text describing what the image must not show.
 *  - cfgscale       : optional prompt-adherence strength (Nova Canvas and Titan only), 1.1 to 10.
 *
 * Quality and aspect ratio are chosen by the caller; the core style option is ignored.
 * There is no seed field: a fixed seed would return the same picture every time.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_generate_image_form extends action_settings_form {
    /** Longest negative prompt, in characters (Titan accepts 512, Nova Canvas 1024). */
    private const NEGATIVE_MAXLENGTH = 512;

    #[\Override]
    protected function definition(): void {
        $mform        = $this->_form;
        $actionconfig = $this->_customdata['actionconfig']['settings'] ?? [];
        $returnurl    = $this->_customdata['returnurl'] ?? null;
        $actionname   = $this->_customdata['actionname']; // The 'generate_image' action name.
        $action       = $this->_customdata['action'];      // Fully-qualified action class name.
        $providerid   = $this->_customdata['providerid'] ?? 0;

        // Model dropdown.
        $mform->addElement(
            'select',
            'model',
            get_string("action:{$actionname}:model", 'aiprovider_awsbr'),
            $this->get_model_options((int) $providerid, (string) ($actionconfig['model'] ?? '')),
        );
        $mform->setType('model', PARAM_TEXT);
        $mform->setDefault('model', $actionconfig['model'] ?? \aiprovider_awsbr\model_catalog::DEFAULT_IMAGE_MODEL);
        $mform->addHelpButton('model', "action:{$actionname}:model", 'aiprovider_awsbr');

        // Negative prompt.
        $mform->addElement(
            'textarea',
            'negativeprompt',
            get_string("action:{$actionname}:negativeprompt", 'aiprovider_awsbr'),
            ['rows' => 3, 'cols' => 60],
        );
        $mform->setType('negativeprompt', PARAM_TEXT);
        $mform->setDefault('negativeprompt', $actionconfig['negativeprompt'] ?? '');
        $mform->addHelpButton('negativeprompt', "action:{$actionname}:negativeprompt", 'aiprovider_awsbr');

        // CFG scale.
        $mform->addElement('text', 'cfgscale', get_string("action:{$actionname}:cfgscale", 'aiprovider_awsbr'));
        $mform->setType('cfgscale', PARAM_RAW_TRIMMED);
        $mform->setDefault('cfgscale', $actionconfig['cfgscale'] ?? '');
        $mform->addHelpButton('cfgscale', "action:{$actionname}:cfgscale", 'aiprovider_awsbr');

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

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $cfgscale = trim((string) ($data['cfgscale'] ?? ''));
        if (
            $cfgscale !== ''
            && (
                !is_numeric($cfgscale)
                || (float) $cfgscale < \aiprovider_awsbr\process_generate_image::CFGSCALE_MIN
                || (float) $cfgscale > \aiprovider_awsbr\process_generate_image::CFGSCALE_MAX
            )
        ) {
            $errors['cfgscale'] = get_string('action:generate_image:cfgscale_invalid', 'aiprovider_awsbr');
        }

        if (\core_text::strlen((string) ($data['negativeprompt'] ?? '')) > self::NEGATIVE_MAXLENGTH) {
            $errors['negativeprompt'] = get_string(
                'action:generate_image:negativeprompt_toolong',
                'aiprovider_awsbr',
                self::NEGATIVE_MAXLENGTH,
            );
        }
        return $errors;
    }

    /**
     * Return default values for this form (called by provider::get_action_setting_defaults()).
     *
     * @return array
     */
    public function get_defaults(): array {
        return [
            'model'          => \aiprovider_awsbr\model_catalog::DEFAULT_IMAGE_MODEL,
            'negativeprompt' => '',
            'cfgscale'       => '',
        ];
    }

    /**
     * Build the model dropdown options for this provider instance.
     *
     * Comes from the instance's image models (ListFoundationModels), narrowed by its
     * availablemodels setting. No AWS call is made without a provider id. A saved
     * model stays in the list, and the default model is offered when the list is empty.
     *
     * @param int $providerid The provider instance ID (0 when unknown).
     * @param string $saved The model currently saved for the action.
     * @return array Associative array of model id => display label.
     */
    private function get_model_options(int $providerid, string $saved): array {
        $options = \aiprovider_awsbr\model_catalog::image_options_for_provider_id($providerid);
        if ($saved !== '' && !isset($options[$saved])) {
            $options[$saved] = $saved;
        }
        if (empty($options)) {
            $options[\aiprovider_awsbr\model_catalog::DEFAULT_IMAGE_MODEL] =
                get_string('action:generate_image:model_default', 'aiprovider_awsbr');
        }
        return $options;
    }
}
