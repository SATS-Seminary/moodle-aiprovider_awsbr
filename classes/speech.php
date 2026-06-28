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
 * Reusable AWS speech service for this provider: a thin, normalised wrapper over
 * Polly (text→speech), Transcribe (speech→text) and S3 (working storage).
 *
 * Deliberately NOT a synchronous core_ai action: Transcribe and long-form Polly
 * are async (minutes) and S3-backed, which does not fit the request/response
 * action model. Consumers (e.g. local_nida adhoc tasks) call these methods
 * directly and drive their own submit/poll loop. Every method returns a
 * normalised array `['success' => bool, …]` (never throws to the caller).
 *
 * Clients are injectable so the wrappers are unit-testable with the SDK's
 * MockHandler.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class speech {
    /** @var speech_client_factory */
    private speech_client_factory $factory;

    /**
     * @param provider                      $provider   the provider instance (IAM-auth config + speech settings).
     * @param PollyClient|null              $polly      injected client (tests); else built on demand.
     * @param TranscribeServiceClient|null  $transcribe injected client (tests).
     * @param S3Client|null                 $s3         injected client (tests).
     */
    public function __construct(
        private provider $provider,
        private ?PollyClient $polly = null,
        private ?TranscribeServiceClient $transcribe = null,
        private ?S3Client $s3 = null,
    ) {
        $this->factory = new speech_client_factory($provider);
    }

    /**
     * Synthesise SHORT text to speech in one synchronous call (Polly
     * `SynthesizeSpeech`, capped at ~3000 billed characters). Returns the audio
     * bytes inline — use {@see start_long_synthesis()} for anything large.
     *
     * @param string $text  plain text (not SSML).
     * @param array  $opts  voiceid, engine, languagecode, outputformat overrides.
     * @return array{success:bool, audio?:string, contenttype?:string, errorcode?:int, errormessage?:string}
     */
    public function synthesize(string $text, array $opts = []): array {
        try {
            $result = $this->polly()->synthesizeSpeech([
                'Text'         => $text,
                'TextType'     => 'text',
                'VoiceId'      => $opts['voiceid'] ?? $this->voice(),
                'Engine'       => $opts['engine'] ?? $this->engine(),
                'LanguageCode' => $opts['languagecode'] ?? 'fr-FR',
                'OutputFormat' => $opts['outputformat'] ?? 'mp3',
            ]);
            return [
                'success'     => true,
                'audio'       => (string) $result['AudioStream'],
                'contenttype' => (string) ($result['ContentType'] ?? 'audio/mpeg'),
            ];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * Start an ASYNCHRONOUS long-form synthesis task (Polly
     * `StartSpeechSynthesisTask`) writing the audio to S3. Poll with
     * {@see get_synthesis_task()}.
     *
     * @param string $text         the (long) text.
     * @param string $outputprefix S3 key prefix for the output object.
     * @param array  $opts         voiceid, engine, languagecode, outputformat.
     * @return array{success:bool, taskid?:string, outputuri?:string, errorcode?:int, errormessage?:string}
     */
    public function start_long_synthesis(string $text, string $outputprefix, array $opts = []): array {
        try {
            $result = $this->polly()->startSpeechSynthesisTask([
                'Text'              => $text,
                'TextType'          => 'text',
                'VoiceId'           => $opts['voiceid'] ?? $this->voice(),
                'Engine'            => $opts['engine'] ?? $this->engine(),
                'LanguageCode'      => $opts['languagecode'] ?? 'fr-FR',
                'OutputFormat'      => $opts['outputformat'] ?? 'mp3',
                'OutputS3BucketName' => $this->bucket(),
                'OutputS3KeyPrefix' => $outputprefix,
            ]);
            $task = $result['SynthesisTask'] ?? [];
            return [
                'success'   => true,
                'taskid'    => (string) ($task['TaskId'] ?? ''),
                'outputuri' => (string) ($task['OutputUri'] ?? ''),
                'status'    => (string) ($task['TaskStatus'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * Poll a long-form synthesis task.
     *
     * @param string $taskid
     * @return array{success:bool, status?:string, outputuri?:string, errorcode?:int, errormessage?:string}
     */
    public function get_synthesis_task(string $taskid): array {
        try {
            $result = $this->polly()->getSpeechSynthesisTask(['TaskId' => $taskid]);
            $task = $result['SynthesisTask'] ?? [];
            return [
                'success'   => true,
                'status'    => (string) ($task['TaskStatus'] ?? ''),
                'outputuri' => (string) ($task['OutputUri'] ?? ''),
                'reason'    => (string) ($task['TaskStatusReason'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * Start an asynchronous transcription job (Transcribe `StartTranscriptionJob`)
     * over media already in S3, optionally emitting WebVTT subtitles. Poll with
     * {@see get_transcription()}.
     *
     * @param string $jobname    a unique job name.
     * @param string $medias3uri s3://bucket/key of the source audio.
     * @param array  $opts        languagecode (default en-US), mediaformat, outputkey, subtitles (bool).
     * @return array{success:bool, jobname?:string, status?:string, errorcode?:int, errormessage?:string}
     */
    public function start_transcription(string $jobname, string $medias3uri, array $opts = []): array {
        try {
            $args = [
                'TranscriptionJobName' => $jobname,
                'LanguageCode'         => $opts['languagecode'] ?? 'en-US',
                'Media'                => ['MediaFileUri' => $medias3uri],
                'OutputBucketName'     => $this->bucket(),
            ];
            if (!empty($opts['mediaformat'])) {
                $args['MediaFormat'] = $opts['mediaformat'];
            }
            if (!empty($opts['outputkey'])) {
                $args['OutputKey'] = $opts['outputkey'];
            }
            if (!empty($opts['subtitles'])) {
                $args['Subtitles'] = ['Formats' => ['vtt']];
            }
            $result = $this->transcribe()->startTranscriptionJob($args);
            $job = $result['TranscriptionJob'] ?? [];
            return [
                'success' => true,
                'jobname' => (string) ($job['TranscriptionJobName'] ?? $jobname),
                'status'  => (string) ($job['TranscriptionJobStatus'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * Poll a transcription job.
     *
     * @param string $jobname
     * @return array{success:bool, status?:string, transcripturi?:string, subtitleuris?:array, errorcode?:int, errormessage?:string}
     */
    public function get_transcription(string $jobname): array {
        try {
            $result = $this->transcribe()->getTranscriptionJob(['TranscriptionJobName' => $jobname]);
            $job = $result['TranscriptionJob'] ?? [];
            return [
                'success'       => true,
                'status'        => (string) ($job['TranscriptionJobStatus'] ?? ''),
                'transcripturi' => (string) ($job['Transcript']['TranscriptFileUri'] ?? ''),
                'subtitleuris'  => (array) ($job['Subtitles']['SubtitleFileUris'] ?? []),
                'reason'        => (string) ($job['FailureReason'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * Store an object in the working S3 bucket.
     *
     * @param string $key
     * @param string $body
     * @param string|null $contenttype
     * @return array{success:bool, uri?:string, errorcode?:int, errormessage?:string}
     */
    public function put_object(string $key, string $body, ?string $contenttype = null): array {
        try {
            $args = ['Bucket' => $this->bucket(), 'Key' => $key, 'Body' => $body];
            if ($contenttype !== null) {
                $args['ContentType'] = $contenttype;
            }
            $this->s3()->putObject($args);
            return ['success' => true, 'uri' => 's3://' . $this->bucket() . '/' . $key];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * Fetch an object's bytes from the working S3 bucket.
     *
     * @param string $key
     * @return array{success:bool, body?:string, errorcode?:int, errormessage?:string}
     */
    public function get_object(string $key): array {
        try {
            $result = $this->s3()->getObject(['Bucket' => $this->bucket(), 'Key' => $key]);
            return ['success' => true, 'body' => (string) $result['Body']];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * Delete a working object from the S3 bucket (cleanup of intermediates).
     *
     * @param string $key
     * @return array{success:bool, errorcode?:int, errormessage?:string}
     */
    public function delete_object(string $key): array {
        try {
            $this->s3()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);
            return ['success' => true];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * A time-limited presigned GET URL for a private object, so a browser can
     * fetch it directly from S3 without exposing credentials or making the bucket
     * public. The caller is responsible for access control before issuing it.
     *
     * @param string $key
     * @param int $ttlseconds lifetime of the signed URL (default 1 hour).
     * @return string|null the URL, or null on failure.
     */
    public function presigned_get_url(string $key, int $ttlseconds = 3600): ?string {
        try {
            $cmd = $this->s3()->getCommand('GetObject', ['Bucket' => $this->bucket(), 'Key' => $key]);
            $request = $this->s3()->createPresignedRequest($cmd, '+' . max(60, $ttlseconds) . ' seconds');
            return (string) $request->getUri();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * List the available French voices for a given engine (for the settings UI).
     *
     * @param string $engine
     * @return array{success:bool, voices?:array, errorcode?:int, errormessage?:string}
     */
    public function list_french_voices(string $engine = 'generative'): array {
        try {
            $result = $this->polly()->describeVoices(['LanguageCode' => 'fr-FR', 'Engine' => $engine]);
            $voices = [];
            foreach (($result['Voices'] ?? []) as $v) {
                $voices[(string) $v['Id']] = (string) $v['Name'];
            }
            return ['success' => true, 'voices' => $voices];
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * @return string the configured working S3 bucket.
     */
    public function bucket(): string {
        return trim((string) ($this->provider->config['speechs3bucket'] ?? ''));
    }

    /**
     * @return string the configured Polly voice id.
     */
    private function voice(): string {
        // Default to a GENERATIVE-capable French voice (matches the generative
        // engine default). Neural-only voices (e.g. Lea) fail with that engine.
        $voice = trim((string) ($this->provider->config['speechvoice'] ?? ''));
        return $voice !== '' ? $voice : 'Ambre';
    }

    /**
     * @return string the synthesis engine (generative by default — best quality).
     */
    private function engine(): string {
        $engine = trim((string) ($this->provider->config['speechengine'] ?? ''));
        return $engine !== '' ? $engine : 'generative';
    }

    /**
     * @return PollyClient
     */
    private function polly(): PollyClient {
        return $this->polly ??= $this->factory->get_polly_client();
    }

    /**
     * @return TranscribeServiceClient
     */
    private function transcribe(): TranscribeServiceClient {
        return $this->transcribe ??= $this->factory->get_transcribe_client();
    }

    /**
     * @return S3Client
     */
    private function s3(): S3Client {
        return $this->s3 ??= $this->factory->get_s3_client();
    }

    /**
     * Normalise any AWS/SDK error into the standard failure shape.
     *
     * @param \Throwable $e
     * @return array{success:false, errorcode:int, errormessage:string}
     */
    private static function failure(\Throwable $e): array {
        $code = 500;
        if ($e instanceof \Aws\Exception\AwsException && $e->getStatusCode()) {
            $code = (int) $e->getStatusCode();
        }
        return ['success' => false, 'errorcode' => $code, 'errormessage' => $e->getMessage()];
    }
}
