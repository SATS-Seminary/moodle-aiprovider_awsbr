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

/**
 * Maps an AWS exception from a Bedrock runtime call to a Moodle error array.
 *
 * Shared by the InvokeModel processors (embed_text, generate_image).
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait aws_error_mapping {
    /**
     * Map an AWS exception to a Moodle-compatible error array.
     *
     * @param \Aws\Exception\AwsException $e
     * @return array{success: false, errorcode: int, error: string, errormessage: string}
     */
    protected function map_aws_exception(\Aws\Exception\AwsException $e): array {
        $codemapping = [
            'ThrottlingException'       => 429,
            'AccessDeniedException'     => 403,
            'ResourceNotFoundException' => 404,
            'ModelNotReadyException'    => 404,
            'ValidationException'       => 400,
        ];

        $awscode  = $e->getAwsErrorCode();
        $httpcode = $codemapping[$awscode] ?? ($e->getStatusCode() ?: 500);

        return [
            'success'      => false,
            'errorcode'    => $httpcode,
            // AWS error code doubles as the error name (required on Moodle main).
            'error'        => $awscode ?: 'BedrockError',
            'errormessage' => $e->getAwsErrorMessage() ?: $e->getMessage(),
        ];
    }
}
