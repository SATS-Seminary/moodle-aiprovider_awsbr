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

use Aws\MockHandler;
use Aws\Result;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Polly\PollyClient;
use Aws\TranscribeService\TranscribeServiceClient;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;

/**
 * Tests for the AWS speech service + client factory (Polly / Transcribe / S3),
 * with no real AWS calls (the SDK MockHandler captures commands + returns canned
 * results).
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\speech::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\speech_client_factory::class)]
final class speech_test extends \advanced_testcase {
    /** @var \core_ai\manager */
    private \core_ai\manager $manager;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->manager = \core\di::get(\core_ai\manager::class);
    }

    /**
     * A provider instance with IAM (static) auth + speech config.
     *
     * @param array $extra config overrides.
     * @return provider
     */
    private function make_provider(array $extra = []): provider {
        return $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'speech-test',
            config:    array_merge([
                'authmode'        => 'static',
                'accesskeyid'     => 'AKID',
                'secretaccesskey' => 'SECRET',
                'speechs3bucket'  => 'nida-speech',
            ], $extra),
        );
    }

    /**
     * A real SDK client of $class wired to a MockHandler.
     *
     * @param string      $class
     * @param MockHandler $mock
     * @param string      $region
     * @return mixed
     */
    private function mock_client(string $class, MockHandler $mock, string $region = 'eu-central-1') {
        return new $class([
            'region'      => $region,
            'version'     => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler'     => $mock,
        ]);
    }

    // Tests for the client factory.

    public function test_speech_region_defaults_to_generative_capable_region(): void {
        $factory = new speech_client_factory($this->make_provider());
        $this->assertSame('eu-central-1', $factory->speech_region());
        $this->assertSame('eu-central-1', $factory->get_polly_client()->getRegion());
    }

    public function test_speech_region_honours_config(): void {
        $factory = new speech_client_factory($this->make_provider(['speechregion' => 'eu-west-2']));
        $this->assertSame('eu-west-2', $factory->speech_region());
        $this->assertSame('eu-west-2', $factory->get_transcribe_client()->getRegion());
        $this->assertSame('eu-west-2', $factory->get_s3_client()->getRegion());
    }

    // Tests for Polly synthesis.

    public function test_synthesize_uses_generative_engine_and_returns_audio(): void {
        $mock = new MockHandler();
        $mock->append(new Result(['AudioStream' => Utils::streamFor('FRENCH_AUDIO'), 'ContentType' => 'audio/mpeg']));
        $service = new speech($this->make_provider(), polly: $this->mock_client(PollyClient::class, $mock));

        $result = $service->synthesize('Bonjour le monde');

        $this->assertTrue($result['success']);
        $this->assertSame('FRENCH_AUDIO', $result['audio']);
        $this->assertSame('audio/mpeg', $result['contenttype']);

        $cmd = $mock->getLastCommand();
        $this->assertSame('SynthesizeSpeech', $cmd->getName());
        $this->assertSame('generative', $cmd['Engine'], 'defaults to the generative engine');
        $this->assertSame('fr-FR', $cmd['LanguageCode']);
        $this->assertSame('text', $cmd['TextType'], 'never SSML for machine-translated content');
        $this->assertSame('Ambre', $cmd['VoiceId'], 'defaults to a generative-capable French voice');
        $this->assertSame('mp3', $cmd['OutputFormat']);
    }

    public function test_synthesize_options_override_defaults(): void {
        $mock = new MockHandler();
        $mock->append(new Result(['AudioStream' => Utils::streamFor('x')]));
        $service = new speech($this->make_provider(), polly: $this->mock_client(PollyClient::class, $mock));

        $service->synthesize('Hi', ['engine' => 'neural', 'voiceid' => 'Remi', 'outputformat' => 'ogg_vorbis']);

        $cmd = $mock->getLastCommand();
        $this->assertSame('neural', $cmd['Engine']);
        $this->assertSame('Remi', $cmd['VoiceId']);
        $this->assertSame('ogg_vorbis', $cmd['OutputFormat']);
    }

    public function test_start_long_synthesis_writes_to_the_bucket(): void {
        $mock = new MockHandler();
        $mock->append(new Result(['SynthesisTask' => [
            'TaskId' => 'task-1', 'TaskStatus' => 'scheduled',
            'OutputUri' => 'https://s3/nida-speech/out/task-1.mp3',
        ]]));
        $service = new speech($this->make_provider(), polly: $this->mock_client(PollyClient::class, $mock));

        $result = $service->start_long_synthesis('long text', 'out/');
        $this->assertTrue($result['success']);
        $this->assertSame('task-1', $result['taskid']);

        $cmd = $mock->getLastCommand();
        $this->assertSame('StartSpeechSynthesisTask', $cmd->getName());
        $this->assertSame('nida-speech', $cmd['OutputS3BucketName']);
        $this->assertSame('out/', $cmd['OutputS3KeyPrefix']);
        $this->assertSame('generative', $cmd['Engine']);
    }

    // Tests for Transcribe.

    public function test_start_transcription_requests_vtt_subtitles_to_bucket(): void {
        $mock = new MockHandler();
        $mock->append(new Result(['TranscriptionJob' => [
            'TranscriptionJobName' => 'job-1', 'TranscriptionJobStatus' => 'IN_PROGRESS',
        ]]));
        $service = new speech($this->make_provider(), transcribe: $this->mock_client(TranscribeServiceClient::class, $mock));

        $result = $service->start_transcription(
            'job-1',
            's3://nida-speech/in/lecture.mp3',
            ['subtitles' => true, 'mediaformat' => 'mp3', 'languagecode' => 'en-US']
        );

        $this->assertTrue($result['success']);
        $this->assertSame('job-1', $result['jobname']);

        $cmd = $mock->getLastCommand();
        $this->assertSame('StartTranscriptionJob', $cmd->getName());
        $this->assertSame('en-US', $cmd['LanguageCode']);
        $this->assertSame('s3://nida-speech/in/lecture.mp3', $cmd['Media']['MediaFileUri']);
        $this->assertSame('nida-speech', $cmd['OutputBucketName']);
        $this->assertSame(['vtt'], $cmd['Subtitles']['Formats']);
    }

    public function test_get_transcription_normalises_result(): void {
        $mock = new MockHandler();
        $mock->append(new Result(['TranscriptionJob' => [
            'TranscriptionJobStatus' => 'COMPLETED',
            'Transcript' => ['TranscriptFileUri' => 'https://s3/transcript.json'],
            'Subtitles' => ['SubtitleFileUris' => ['https://s3/subs.vtt']],
        ]]));
        $service = new speech($this->make_provider(), transcribe: $this->mock_client(TranscribeServiceClient::class, $mock));

        $result = $service->get_transcription('job-1');
        $this->assertTrue($result['success']);
        $this->assertSame('COMPLETED', $result['status']);
        $this->assertSame('https://s3/transcript.json', $result['transcripturi']);
        $this->assertSame(['https://s3/subs.vtt'], $result['subtitleuris']);
    }

    // Tests for S3 and failure handling.

    public function test_put_and_get_object_use_the_bucket(): void {
        $mock = new MockHandler();
        $mock->append(new Result([]));                                   // Mocked putObject response.
        $mock->append(new Result(['Body' => Utils::streamFor('BYTES')])); // Mocked getObject response.
        $service = new speech($this->make_provider(), s3: $this->mock_client(S3Client::class, $mock));

        $put = $service->put_object('out/a.mp3', 'BYTES', 'audio/mpeg');
        $this->assertTrue($put['success']);
        $this->assertSame('s3://nida-speech/out/a.mp3', $put['uri']);

        $get = $service->get_object('out/a.mp3');
        $this->assertTrue($get['success']);
        $this->assertSame('BYTES', $get['body']);
    }

    public function test_put_object_accepts_a_stream_resource_body(): void {
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $service = new speech($this->make_provider(), s3: $this->mock_client(S3Client::class, $mock));

        // A large upload streams from an open handle rather than a buffered string.
        $stream = Utils::tryFopen('php://temp', 'r+');
        fwrite($stream, 'STREAMED_BYTES');
        rewind($stream);

        $put = $service->put_object('out/big.mp3', $stream, 'audio/mpeg');
        $this->assertTrue($put['success']);
        $this->assertSame('s3://nida-speech/out/big.mp3', $put['uri']);

        $cmd = $mock->getLastCommand();
        $this->assertSame('PutObject', $cmd->getName());
        $this->assertSame('nida-speech', $cmd['Bucket']);
        $this->assertSame('out/big.mp3', $cmd['Key']);
        // The body was passed through as a stream, not buffered into a string — the
        // point of the streaming upload (we don't assert the consumed stream's bytes,
        // which a casted read would have drained).
        $this->assertIsNotString($cmd['Body']);
        fclose($stream);
    }

    /**
     * Write a temp file of $bytes zero bytes and return its path.
     *
     * @param int $bytes
     * @return string
     */
    private function make_temp_file(int $bytes): string {
        $path = make_request_directory() . '/upload.bin';
        $fh = fopen($path, 'wb');
        ftruncate($fh, $bytes);
        fclose($fh);
        return $path;
    }

    /**
     * Record the names of the commands sent through a client.
     *
     * @param S3Client $client
     * @param array $history filled by reference.
     */
    private function record(S3Client $client, array &$history): void {
        $client->getHandlerList()->appendSign(\Aws\Middleware::tap(function ($cmd) use (&$history) {
            $history[] = $cmd->getName();
        }));
    }

    public function test_put_object_file_small_file_is_a_single_put(): void {
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $client = $this->mock_client(S3Client::class, $mock);
        $history = [];
        $this->record($client, $history);
        $service = new speech($this->make_provider(), s3: $client);

        $put = $service->put_object_file('lectures/a.mp4', $this->make_temp_file(1024 * 1024), 'video/mp4');

        $this->assertTrue($put['success']);
        $this->assertSame('s3://nida-speech/lectures/a.mp4', $put['uri']);
        $this->assertSame(['PutObject'], $history);
        $cmd = $mock->getLastCommand();
        $this->assertSame('nida-speech', $cmd['Bucket']);
        $this->assertSame('lectures/a.mp4', $cmd['Key']);
        $this->assertSame('video/mp4', $cmd['ContentType']);
    }

    public function test_put_object_file_large_file_is_multipart(): void {
        $mock = new MockHandler();
        $mock->append(new Result(['UploadId' => 'UP1']));
        $mock->append(new Result(['ETag' => '"e1"']));
        $mock->append(new Result(['ETag' => '"e2"']));
        $mock->append(new Result(['ETag' => '"e3"']));
        $mock->append(new Result([]));
        $client = $this->mock_client(S3Client::class, $mock);
        $history = [];
        $this->record($client, $history);
        // 11 MB with 5 MB parts gives three parts; a threshold of 8 MB forces multipart.
        $service = new speech(
            $this->make_provider(),
            s3: $client,
            multipartthreshold: 8 * 1024 * 1024,
            multipartpartsize: 5 * 1024 * 1024,
        );

        $put = $service->put_object_file('lectures/big.mp4', $this->make_temp_file(11 * 1024 * 1024), 'video/mp4');

        $this->assertTrue($put['success']);
        $this->assertSame('s3://nida-speech/lectures/big.mp4', $put['uri']);
        $this->assertSame('CreateMultipartUpload', $history[0]);
        $this->assertCount(3, array_keys($history, 'UploadPart'));
        $this->assertSame('CompleteMultipartUpload', end($history));
        $this->assertNotContains('AbortMultipartUpload', $history);
        $this->assertSame(0, $mock->count(), 'every queued response was consumed');
    }

    public function test_put_object_file_aborts_multipart_on_failure(): void {
        $mock = new MockHandler();
        $mock->append(new Result(['UploadId' => 'UP2']));
        $mock->append(new AwsException('boom', new Command('UploadPart'), ['code' => 'InternalError']));
        $mock->append(new AwsException('boom', new Command('UploadPart'), ['code' => 'InternalError']));
        $mock->append(new AwsException('boom', new Command('UploadPart'), ['code' => 'InternalError']));
        $mock->append(new Result([])); // Mocked abortMultipartUpload response.
        $client = $this->mock_client(S3Client::class, $mock);
        $history = [];
        $this->record($client, $history);
        $service = new speech(
            $this->make_provider(),
            s3: $client,
            multipartthreshold: 8 * 1024 * 1024,
            multipartpartsize: 5 * 1024 * 1024,
        );

        $put = $service->put_object_file('lectures/big.mp4', $this->make_temp_file(11 * 1024 * 1024));

        $this->assertFalse($put['success']);
        $this->assertNotEmpty($put['errormessage']);
        $this->assertSame('AbortMultipartUpload', end($history));
        $this->assertNotContains('CompleteMultipartUpload', $history);
        $cmd = $mock->getLastCommand();
        $this->assertSame('UP2', $cmd['UploadId']);
        $this->assertSame('lectures/big.mp4', $cmd['Key']);
    }

    public function test_put_object_file_reports_a_missing_file(): void {
        $service = new speech($this->make_provider(), s3: $this->mock_client(S3Client::class, new MockHandler()));
        $put = $service->put_object_file('x', '/no/such/file.mp4');
        $this->assertFalse($put['success']);
    }

    public function test_put_object_file_accepts_a_stored_file(): void {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => 1,
            'filepath' => '/', 'filename' => 'a.mp3',
        ], 'AUDIO');
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $service = new speech($this->make_provider(), s3: $this->mock_client(S3Client::class, $mock));

        $put = $service->put_object_file('a.mp3', $file, 'audio/mpeg');

        $this->assertTrue($put['success']);
        $this->assertSame('PutObject', $mock->getLastCommand()->getName());
    }

    public function test_upload_client_has_long_timeout_and_default_client_is_unchanged(): void {
        $factory = new class ($this->make_provider()) extends speech_client_factory {
            /**
             * Expose the client args of both S3 clients.
             *
             * @return array{upload:array, normal:array}
             */
            public function args(): array {
                return [
                    'upload' => $this->upload_client_args('eu-central-1')['http'],
                    'normal' => $this->speech_client_args('eu-central-1')['http'],
                ];
            }
        };
        $args = $factory->args();
        $this->assertSame(speech_client_factory::UPLOAD_TIMEOUT, $args['upload']['timeout']);
        $this->assertSame(10, $args['upload']['connect_timeout']);
        $this->assertSame(120, $args['normal']['timeout']);
        $this->assertSame('eu-central-1', $factory->get_s3_upload_client()->getRegion());
    }

    public function test_delete_object(): void {
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $service = new speech($this->make_provider(), s3: $this->mock_client(S3Client::class, $mock));

        $this->assertTrue($service->delete_object('out/a.mp3')['success']);
        $cmd = $mock->getLastCommand();
        $this->assertSame('DeleteObject', $cmd->getName());
        $this->assertSame('nida-speech', $cmd['Bucket']);
        $this->assertSame('out/a.mp3', $cmd['Key']);
    }

    public function test_aws_error_is_normalised_to_failure(): void {
        $mock = new MockHandler();
        $mock->append(new AwsException('boom', new Command('SynthesizeSpeech')));
        $service = new speech($this->make_provider(), polly: $this->mock_client(PollyClient::class, $mock));

        $result = $service->synthesize('x');
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errormessage', $result);
        $this->assertSame(500, $result['errorcode']);
    }
}
