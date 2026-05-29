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
 * MUC cache definitions for aiprovider_awsbr.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Caches resolved AWS STS AssumeRole session credentials (key, secret, token, expiry).
    // Session credentials are valid for up to 1 hour by default; we cache them for up to
    // 55 minutes (5-minute safety margin before expiry handled in bedrock_client_factory).
    'credentials' => [
        'mode'           => cache_store::MODE_APPLICATION,
        'simplekeys'     => true,
        'simpledata'     => true,
        'staticacceleration' => false,
        'ttl'            => HOURSECS - 300, // 55 minutes.
    ],
];
