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
 * Admin setting for an AWS region: a text input with a datalist of known regions.
 *
 * Replaces \core\aws\admin_settings_aws_region, which core deprecated in 4.5 and
 * plans to remove in 6.0. Behaviour is the same: free text is still accepted, so a
 * region newer than the list below can still be typed in.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiprovider_awsbr;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Text setting with a datalist of AWS regions.
 *
 * Extends the legacy class name admin_setting_configtext, which exists on Moodle
 * 5.0 and is an alias of \core_admin\setting\configtext on 5.1 and later.
 */
class admin_setting_awsregion extends \admin_setting_configtext {
    /**
     * Known AWS commercial-partition regions (region code => description).
     *
     * @return array<string,string>
     */
    public static function get_regions(): array {
        return [
        'af-south-1' => 'Africa (Cape Town)',
        'ap-east-1' => 'Asia Pacific (Hong Kong)',
        'ap-east-2' => 'Asia Pacific (Taipei)',
        'ap-northeast-1' => 'Asia Pacific (Tokyo)',
        'ap-northeast-2' => 'Asia Pacific (Seoul)',
        'ap-northeast-3' => 'Asia Pacific (Osaka)',
        'ap-south-1' => 'Asia Pacific (Mumbai)',
        'ap-south-2' => 'Asia Pacific (Hyderabad)',
        'ap-southeast-1' => 'Asia Pacific (Singapore)',
        'ap-southeast-2' => 'Asia Pacific (Sydney)',
        'ap-southeast-3' => 'Asia Pacific (Jakarta)',
        'ap-southeast-4' => 'Asia Pacific (Melbourne)',
        'ap-southeast-5' => 'Asia Pacific (Malaysia)',
        'ap-southeast-6' => 'Asia Pacific (New Zealand)',
        'ap-southeast-7' => 'Asia Pacific (Thailand)',
        'ca-central-1' => 'Canada (Central)',
        'ca-west-1' => 'Canada West (Calgary)',
        'eu-central-1' => 'Europe (Frankfurt)',
        'eu-central-2' => 'Europe (Zurich)',
        'eu-north-1' => 'Europe (Stockholm)',
        'eu-south-1' => 'Europe (Milan)',
        'eu-south-2' => 'Europe (Spain)',
        'eu-west-1' => 'Europe (Ireland)',
        'eu-west-2' => 'Europe (London)',
        'eu-west-3' => 'Europe (Paris)',
        'il-central-1' => 'Israel (Tel Aviv)',
        'me-central-1' => 'Middle East (UAE)',
        'me-south-1' => 'Middle East (Bahrain)',
        'mx-central-1' => 'Mexico (Central)',
        'sa-east-1' => 'South America (Sao Paulo)',
        'us-east-1' => 'US East (N. Virginia)',
        'us-east-2' => 'US East (Ohio)',
        'us-west-1' => 'US West (N. California)',
        'us-west-2' => 'US West (Oregon)',
        ];
    }

    /**
     * Return part of form with setting.
     *
     * @param mixed $data Current value.
     * @param string $query Admin search query.
     * @return string
     */
    #[\Override]
    public function output_html($data, $query = '') {
        $default = $this->get_defaultsetting();
        $listid = $this->get_id() . '_regions';

        $input = \html_writer::empty_tag('input', [
            'type'  => 'text',
            'list'  => $listid,
            'name'  => $this->get_full_name(),
            'id'    => $this->get_id(),
            'value' => $data,
            'size'  => $this->size,
            'class' => 'form-control text-ltr',
        ]);
        $options = '';
        foreach (self::get_regions() as $code => $description) {
            $options .= \html_writer::empty_tag('option', ['value' => $code, 'label' => $code . ' - ' . $description]);
        }
        $datalist = \html_writer::tag('datalist', $options, ['id' => $listid]);
        $element = \html_writer::div($input . $datalist, 'form-text defaultsnext');

        return format_admin_setting($this, $this->visiblename, $element, $this->description, true, '', $default, $query);
    }
}
