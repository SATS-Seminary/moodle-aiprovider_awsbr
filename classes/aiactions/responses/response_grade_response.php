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

namespace aiprovider_awsbr\aiactions\responses;

use core_ai\aiactions\responses\response_base;

/**
 * Response class for the grade_response action.
 *
 * Lives inside aiprovider_awsbr because Moodle core does not ship a response
 * class for this custom action. Extends the core response_base.
 *
 * Fields returned:
 *  - result           : array  (the input of the forced tool call, decoded)
 *  - model            : string (the Bedrock model ID used)
 *  - prompttokens     : int    (input tokens)
 *  - completiontokens : int    (output tokens)
 *  - cachereadtokens  : int    (input tokens read from the prompt cache)
 *  - cachewritetokens : int    (input tokens written to the prompt cache)
 *  - stopreason       : string (Converse stopReason)
 *  - id               : string (AWS request ID)
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_grade_response extends response_base {
    /** @var array The decoded tool input. */
    private array $result = [];

    /** @var int Input tokens. */
    private int $prompttokens = 0;

    /** @var int Output tokens. */
    private int $completiontokens = 0;

    /** @var int Input tokens read from the prompt cache. */
    private int $cachereadtokens = 0;

    /** @var int Input tokens written to the prompt cache. */
    private int $cachewritetokens = 0;

    /** @var string The Converse stop reason. */
    private string $stopreason = '';

    /** @var string The AWS request ID. */
    private string $id = '';

    /**
     * Constructor.
     *
     * @param bool   $success      Whether the action succeeded.
     * @param int    $errorcode    HTTP-ish error code (required when success=false).
     * @param string $error        Short error name (required on failure on Moodle main).
     * @param string $errormessage Human-readable error message (required when success=false).
     */
    public function __construct(
        bool $success,
        int $errorcode = 0,
        string $error = '',
        string $errormessage = '',
    ) {
        // Moodle main added a required-on-error `error` (error name) parameter to
        // response_base; Moodle 5.0 does not have it. Forward it only when the
        // running core supports it, so this class works on both branches.
        $args = [
            'success'      => $success,
            'actionname'   => 'grade_response',
            'errorcode'    => $errorcode,
            'errormessage' => $errormessage,
        ];
        if (self::response_base_supports_error()) {
            $args['error'] = $error;
        }
        parent::__construct(...$args);
    }

    /**
     * Whether the core response_base constructor accepts an `error` parameter
     * (added in Moodle main; absent in 5.0). Cached for the request.
     *
     * @return bool
     */
    private static function response_base_supports_error(): bool {
        static $supported = null;
        if ($supported === null) {
            $supported = false;
            foreach ((new \ReflectionMethod(response_base::class, '__construct'))->getParameters() as $param) {
                if ($param->getName() === 'error') {
                    $supported = true;
                    break;
                }
            }
        }
        return $supported;
    }

    /**
     * Populate the response fields from the provider's raw response array.
     *
     * @param array $response Raw response data from the processor.
     */
    #[\Override]
    public function set_response_data(array $response): void {
        $this->result           = (array) ($response['result'] ?? []);
        $this->model            = $response['model'] ?? null;
        $this->prompttokens     = (int) ($response['prompttokens'] ?? 0);
        $this->completiontokens = (int) ($response['completiontokens'] ?? 0);
        $this->cachereadtokens  = (int) ($response['cachereadtokens'] ?? 0);
        $this->cachewritetokens = (int) ($response['cachewritetokens'] ?? 0);
        $this->stopreason       = (string) ($response['stopreason'] ?? '');
        $this->id               = (string) ($response['id'] ?? '');
    }

    /**
     * Return the response data as an associative array.
     *
     * @return array{result: array, model: string|null, prompttokens: int, completiontokens: int,
     *               cachereadtokens: int, cachewritetokens: int, stopreason: string, id: string}
     */
    #[\Override]
    public function get_response_data(): array {
        return [
            'result'           => $this->result,
            'model'            => $this->model,
            'prompttokens'     => $this->prompttokens,
            'completiontokens' => $this->completiontokens,
            'cachereadtokens'  => $this->cachereadtokens,
            'cachewritetokens' => $this->cachewritetokens,
            'stopreason'       => $this->stopreason,
            'id'               => $this->id,
        ];
    }

    /**
     * Return the decoded tool input.
     *
     * @return array
     */
    public function get_result(): array {
        return $this->result;
    }
}
