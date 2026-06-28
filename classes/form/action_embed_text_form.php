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
 * Per-action settings form for embed_text.
 *
 * Fields:
 *  - model      : dropdown populated from the provider's availablemodels setting.
 *                 Default: amazon.titan-embed-text-v2:0.
 *  - dimensions : optional integer override for Titan v2 output dimensions (256 / 512 / 1024).
 *                 Cohere does not support this; the field is shown for all models but
 *                 only applied when the active model is in the amazon.titan-embed-* family.
 *
 * Note: there is no system instruction field for embeddings — embedding APIs do not
 * accept a system prompt. Inference parameters (temperature, topP, maxTokens) also do
 * not apply to embedding models.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_embed_text_form extends action_settings_form {
    /** Default embedding model ID when no per-action override is set. */
    private const DEFAULT_MODEL = 'amazon.titan-embed-text-v2:0';

    #[\Override]
    protected function definition(): void {
        $mform        = $this->_form;
        $actionconfig = $this->_customdata['actionconfig']['settings'] ?? [];
        $returnurl    = $this->_customdata['returnurl'] ?? null;
        $actionname   = $this->_customdata['actionname']; // 'embed_text'
        $action       = $this->_customdata['action'];      // fully-qualified class name
        $providerid   = $this->_customdata['providerid'] ?? 0;

        // --- Model dropdown ---------------------------------------------------
        $modeloptions = $this->get_model_options($providerid);
        $mform->addElement(
            'select',
            'model',
            get_string("action:{$actionname}:model", 'aiprovider_awsbr'),
            $modeloptions,
        );
        $mform->setType('model', PARAM_TEXT);
        $mform->setDefault('model', $actionconfig['model'] ?? self::DEFAULT_MODEL);
        $mform->addHelpButton('model', "action:{$actionname}:model", 'aiprovider_awsbr');

        // --- Dimensions (Titan v2 only) ----------------------------------------
        $dimensionoptions = [
            ''     => get_string('action:embed_text:dimensions_default', 'aiprovider_awsbr'),
            '256'  => '256',
            '512'  => '512',
            '1024' => '1024',
        ];
        $mform->addElement(
            'select',
            'dimensions',
            get_string("action:{$actionname}:dimensions", 'aiprovider_awsbr'),
            $dimensionoptions,
        );
        $mform->setType('dimensions', PARAM_INT);
        $mform->setDefault('dimensions', (string) ($actionconfig['dimensions'] ?? ''));
        $mform->addHelpButton('dimensions', "action:{$actionname}:dimensions", 'aiprovider_awsbr');

        // --- Hidden fields ----------------------------------------------------
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
            'model'      => self::DEFAULT_MODEL,
            'dimensions' => '',
        ];
    }

    /**
     * Build the model dropdown options from the stored availablemodels admin setting.
     *
     * Reads the config for this specific provider instance (by provider ID) when
     * possible, otherwise reads the global plugin config. The list is not filtered
     * to embedding-only models so admins can choose any model, but the default
     * always points to Titan v2.
     *
     * @param int $providerid The provider instance ID (0 if creating a new provider).
     * @return array Associative array of profile_id => Display label.
     */
    private function get_model_options(int $providerid): array {
        $rawmodels = '';

        if ($providerid > 0) {
            try {
                global $DB;
                $record = $DB->get_record('ai_provider', ['id' => $providerid], 'config');
                if ($record) {
                    $config    = json_decode($record->config, true);
                    $rawmodels = $config['availablemodels'] ?? '';
                }
            } catch (\Throwable $e) {
                // Silently fall through to plugin config.
            }
        }

        if (empty($rawmodels)) {
            $rawmodels = get_config('aiprovider_awsbr', 'availablemodels') ?? '';
        }

        $options = [];
        foreach (explode("\n", $rawmodels) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 2);
            $id    = trim($parts[0]);
            $label = isset($parts[1]) ? trim($parts[1]) : $id;
            if (!empty($id)) {
                $options[$id] = $label;
            }
        }

        if (empty($options)) {
            // Always have the default model available even if availablemodels is empty.
            $options[self::DEFAULT_MODEL] = 'Titan Text Embeddings v2 (1024-dim)';
        }

        return $options;
    }
}
