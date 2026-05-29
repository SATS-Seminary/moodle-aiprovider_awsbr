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
 * Response class for the embed_text action.
 *
 * This class lives inside aiprovider_awsbr rather than \core_ai\aiactions\responses
 * because Moodle core does not yet ship an embeddings response class. It extends
 * the core response_base and adds the embedding-specific fields consumed by
 * local_ezra's RAG layer.
 *
 * Fields returned:
 *  - vector  : array of floats (the embedding vector)
 *  - dim     : int             (vector dimension)
 *  - model   : string          (the Bedrock model ID used)
 *  - tokens  : int             (prompt token count returned by Bedrock)
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_embed_text extends response_base {

    /** @var array<float>|null The embedding vector. */
    private ?array $vector = null;

    /** @var int The vector dimension. */
    private int $dim = 0;

    /** @var int The number of input tokens consumed. */
    private int $tokens = 0;

    /**
     * Constructor.
     *
     * @param bool   $success      Whether the action succeeded.
     * @param int    $errorcode    HTTP-ish error code (required when success=false).
     * @param string $errormessage Human-readable error message (required when success=false).
     */
    public function __construct(
        bool $success,
        int $errorcode = 0,
        string $errormessage = '',
    ) {
        parent::__construct(
            success: $success,
            actionname: 'embed_text',
            errorcode: $errorcode,
            errormessage: $errormessage,
        );
    }

    /**
     * Populate the response fields from the provider's raw response array.
     *
     * Expected keys:
     *  - vector  (array<float>) — the embedding vector
     *  - dim     (int)          — vector dimension
     *  - model   (string)       — Bedrock model ID
     *  - tokens  (int)          — input token count
     *
     * @param array $response Raw response data from the processor.
     */
    #[\Override]
    public function set_response_data(array $response): void {
        $this->vector = $response['vector'] ?? null;
        $this->dim    = (int) ($response['dim'] ?? 0);
        $this->model  = $response['model'] ?? null;
        $this->tokens = (int) ($response['tokens'] ?? 0);
    }

    /**
     * Return the response data as an associative array.
     *
     * @return array{vector: array<float>|null, dim: int, model: string|null, tokens: int}
     */
    #[\Override]
    public function get_response_data(): array {
        return [
            'vector' => $this->vector,
            'dim'    => $this->dim,
            'model'  => $this->model,
            'tokens' => $this->tokens,
        ];
    }

    /**
     * Return the embedding vector.
     *
     * @return array<float>|null
     */
    public function get_vector(): ?array {
        return $this->vector;
    }

    /**
     * Return the vector dimension.
     *
     * @return int
     */
    public function get_dim(): int {
        return $this->dim;
    }

    /**
     * Return the number of prompt tokens consumed.
     *
     * @return int
     */
    public function get_tokens(): int {
        return $this->tokens;
    }
}
