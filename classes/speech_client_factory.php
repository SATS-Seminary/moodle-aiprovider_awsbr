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

use Aws\Polly\PollyClient;
use Aws\TranscribeService\TranscribeServiceClient;
use Aws\S3\S3Client;

/**
 * Factory for the AWS SPEECH-service clients — Polly (text→speech), Transcribe
 * (speech→text) and S3 (working storage) — reusing this provider's credential
 * chain and proxy handling.
 *
 * These services are NOT Bedrock and do NOT accept the Bedrock API-key bearer
 * token, so the clients are ALWAYS built with IAM/SigV4 credentials
 * (`build_credentials_provider()`), regardless of the instance's `authmode`.
 * Run the speech capability on an instance configured with IAM auth
 * (static / instance_role / assume_role).
 *
 * The speech REGION is configured separately from the Bedrock translation region:
 * Polly's GENERATIVE engine is not in every region (e.g. not eu-west-1; it is in
 * eu-west-2 / eu-central-1), so `speechregion` is its own setting.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class speech_client_factory extends bedrock_client_factory {
    /** Default speech region — a generative-Polly-capable EU region. */
    public const DEFAULT_SPEECH_REGION = 'eu-central-1';

    /** Per-request timeout (seconds) for the large-upload S3 client. */
    public const UPLOAD_TIMEOUT = 900;

    /**
     * The configured speech region (or the generative-capable default).
     *
     * @param string|null $region explicit override.
     * @return string
     */
    public function speech_region(?string $region = null): string {
        if ($region !== null && $region !== '') {
            return $region;
        }
        $configured = trim((string) ($this->provider->config['speechregion'] ?? ''));
        return $configured !== '' ? $configured : self::DEFAULT_SPEECH_REGION;
    }

    /**
     * A Polly client (text-to-speech).
     *
     * @param string|null $region override the configured speech region.
     * @return PollyClient
     */
    public function get_polly_client(?string $region = null): PollyClient {
        $client = new PollyClient($this->speech_client_args($this->speech_region($region)));
        $this->configure_proxy($client);
        return $client;
    }

    /**
     * A Transcribe client (speech-to-text).
     *
     * @param string|null $region override the configured speech region.
     * @return TranscribeServiceClient
     */
    public function get_transcribe_client(?string $region = null): TranscribeServiceClient {
        $client = new TranscribeServiceClient($this->speech_client_args($this->speech_region($region)));
        $this->configure_proxy($client);
        return $client;
    }

    /**
     * An S3 client (working storage for media + outputs).
     *
     * @param string|null $region override the configured speech region.
     * @return S3Client
     */
    public function get_s3_client(?string $region = null): S3Client {
        $client = new S3Client($this->speech_client_args($this->speech_region($region)));
        $this->configure_proxy($client);
        return $client;
    }

    /**
     * An S3 client for large uploads ({@see speech::put_object_file()}): same
     * credentials and proxy, but a 15 minute timeout per request instead of 120 s.
     * Each part of a multipart upload is its own request, so this is a per-part
     * limit; the transfer as a whole has no time limit.
     *
     * @param string|null $region override the configured speech region.
     * @return S3Client
     */
    public function get_s3_upload_client(?string $region = null): S3Client {
        $client = new S3Client($this->upload_client_args($this->speech_region($region)));
        $this->configure_proxy($client);
        return $client;
    }

    /**
     * Constructor args for the large-upload S3 client: the common speech args
     * with a longer per-request timeout.
     *
     * @param string $region
     * @return array
     */
    protected function upload_client_args(string $region): array {
        $args = $this->speech_client_args($region);
        $args['http'] = ['connect_timeout' => 10, 'timeout' => self::UPLOAD_TIMEOUT];
        return $args;
    }

    /**
     * Common client constructor args for a speech-service client — always IAM
     * credentials (never the Bedrock bearer token).
     *
     * @param string $region
     * @return array
     */
    protected function speech_client_args(string $region): array {
        return [
            'region'      => $region,
            'version'     => 'latest',
            'credentials' => $this->build_credentials_provider(),
            'http'        => ['connect_timeout' => 10, 'timeout' => 120],
        ];
    }
}
