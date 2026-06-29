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

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Credentials\Credentials;
use Aws\Credentials\InstanceProfileProvider;

/**
 * PHPUnit tests for bedrock_client_factory.
 *
 * Tests the credential-chain selection logic and client construction without
 * making any real AWS network calls.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_awsbr\bedrock_client_factory
 */
final class bedrock_client_factory_test extends \advanced_testcase {
    /** @var \core_ai\manager */
    private \core_ai\manager $manager;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->manager = \core\di::get(\core_ai\manager::class);
    }

    /**
     * Build a provider instance with the given config array.
     */
    private function make_provider(array $config): provider {
        return $this->manager->create_provider_instance(
            classname: '\aiprovider_awsbr\provider',
            name:      'factory-test',
            config:    $config,
        );
    }

    // Tests for get_runtime_client.

    /**
     * Test that get_runtime_client returns a BedrockRuntimeClient for static auth mode.
     */
    public function test_get_runtime_client_returns_bedrock_client_static(): void {
        $provider = $this->make_provider([
            'authmode'     => 'static',
            'accesskeyid'  => 'AKID',
            'secretaccesskey' => 'SECRET',
            'region'       => 'af-south-1',
        ]);

        $factory = new bedrock_client_factory($provider);
        $client  = $factory->get_runtime_client();

        $this->assertInstanceOf(BedrockRuntimeClient::class, $client);
    }

    /**
     * Test that get_runtime_client uses the supplied region override.
     */
    public function test_get_runtime_client_region_override(): void {
        $provider = $this->make_provider([
            'authmode'     => 'static',
            'accesskeyid'  => 'AKID',
            'secretaccesskey' => 'SECRET',
            'region'       => 'af-south-1',
        ]);

        $factory = new bedrock_client_factory($provider);
        $client  = $factory->get_runtime_client('eu-west-1');

        $this->assertInstanceOf(BedrockRuntimeClient::class, $client);
        // The client's region should reflect the override.
        $this->assertEquals('eu-west-1', $client->getRegion());
    }

    /**
     * Test that get_runtime_client falls back to provider's primary region when no override.
     */
    public function test_get_runtime_client_uses_primary_region_when_no_override(): void {
        $provider = $this->make_provider([
            'authmode'        => 'static',
            'accesskeyid'     => 'AKID',
            'secretaccesskey' => 'SECRET',
            'region'          => 'us-east-1',
        ]);

        $factory = new bedrock_client_factory($provider);
        $client  = $factory->get_runtime_client();

        $this->assertEquals('us-east-1', $client->getRegion());
    }

    // Tests for build_credentials_provider in static mode.

    /**
     * Test that static authmode produces a Credentials object with the right key.
     */
    public function test_build_credentials_provider_static(): void {
        $provider = $this->make_provider([
            'authmode'        => 'static',
            'accesskeyid'     => 'AKID_TEST',
            'secretaccesskey' => 'SECRET_TEST',
            'region'          => 'af-south-1',
        ]);

        $factory = new bedrock_client_factory($provider);
        $method  = new \ReflectionMethod($factory, 'build_credentials_provider');
        $creds   = $method->invoke($factory);

        $this->assertInstanceOf(Credentials::class, $creds);
        $this->assertEquals('AKID_TEST', $creds->getAccessKeyId());
        $this->assertEquals('SECRET_TEST', $creds->getSecretKey());
    }

    // Tests for build_credentials_provider in instance_role mode.

    /**
     * Test that instance_role authmode produces an InstanceProfileProvider callable.
     */
    public function test_build_credentials_provider_instance_role(): void {
        $provider = $this->make_provider([
            'authmode' => 'instance_role',
            'region'   => 'af-south-1',
        ]);

        $factory = new bedrock_client_factory($provider);
        $method  = new \ReflectionMethod($factory, 'build_credentials_provider');
        $creds   = $method->invoke($factory);

        // InstanceProfileProvider is a callable (invokable object).
        $this->assertInstanceOf(InstanceProfileProvider::class, $creds);
    }

    // Tests for build_credentials_provider unknown authmode fallback.

    /**
     * Test that an unknown authmode falls back to the SDK default provider (callable).
     */
    public function test_build_credentials_provider_unknown_authmode_uses_default(): void {
        $provider = $this->make_provider([
            'authmode' => 'unknown_mode',
            'region'   => 'af-south-1',
        ]);

        $factory = new bedrock_client_factory($provider);
        $method  = new \ReflectionMethod($factory, 'build_credentials_provider');
        $creds   = $method->invoke($factory);

        // The default provider chain is a callable.
        $this->assertIsCallable($creds);
    }

    // Tests for configure_proxy.

    /**
     * Test that configure_proxy does nothing when proxyhost is empty.
     */
    public function test_configure_proxy_no_proxy(): void {
        global $CFG;
        $CFG->proxyhost = '';

        $provider = $this->make_provider([
            'authmode'        => 'static',
            'accesskeyid'     => 'AKID',
            'secretaccesskey' => 'SECRET',
            'region'          => 'af-south-1',
        ]);

        $factory = new bedrock_client_factory($provider);
        // Should not throw and should return a functional client.
        $client = $factory->get_runtime_client();
        $this->assertInstanceOf(BedrockRuntimeClient::class, $client);
    }

    /**
     * Test that configure_proxy attaches proxy middleware when proxyhost is set.
     */
    public function test_configure_proxy_with_proxy(): void {
        global $CFG;
        $CFG->proxyhost     = 'proxy.example.com';
        $CFG->proxyport     = '3128';
        $CFG->proxytype     = 'HTTP';
        $CFG->proxyuser     = '';
        $CFG->proxypassword = '';

        $provider = $this->make_provider([
            'authmode'        => 'static',
            'accesskeyid'     => 'AKID',
            'secretaccesskey' => 'SECRET',
            'region'          => 'af-south-1',
        ]);

        $factory = new bedrock_client_factory($provider);
        $client  = $factory->get_runtime_client();

        // Verify that the handler list has our proxy middleware. (Aws\HandlerList
        // has no debug() method on this SDK version; hasMiddleware() reads the
        // same named-middleware registry that appendBuild('…','bedrock_proxy')
        // populates.)
        $this->assertTrue(
            $client->getHandlerList()->hasMiddleware('bedrock_proxy'),
            'Proxy middleware "bedrock_proxy" should be attached when proxyhost is set.',
        );

        // Clean up.
        $CFG->proxyhost = '';
    }

    /**
     * Test that configure_proxy builds a SOCKS5 proxy string correctly.
     */
    public function test_configure_proxy_socks5(): void {
        global $CFG;
        $CFG->proxyhost     = 'socks.example.com';
        $CFG->proxyport     = '1080';
        $CFG->proxytype     = 'SOCKS5';
        $CFG->proxyuser     = 'socksuser';
        $CFG->proxypassword = 'sockspass';

        $provider = $this->make_provider([
            'authmode'        => 'static',
            'accesskeyid'     => 'AKID',
            'secretaccesskey' => 'SECRET',
            'region'          => 'af-south-1',
        ]);

        $factory = new bedrock_client_factory($provider);
        $client  = $factory->get_runtime_client();
        $this->assertInstanceOf(BedrockRuntimeClient::class, $client);

        $CFG->proxyhost = '';
    }

    // Tests for is_provider_configured, checked via provider, not factory directly.

    /**
     * Static auth: configured when both key and secret are present.
     */
    public function test_is_provider_configured_static_fully_configured(): void {
        $provider = $this->make_provider([
            'authmode'        => 'static',
            'accesskeyid'     => 'AKID',
            'secretaccesskey' => 'SECRET',
        ]);
        $this->assertTrue($provider->is_provider_configured());
    }

    /**
     * Static auth: NOT configured when key is missing.
     */
    public function test_is_provider_configured_static_missing_key(): void {
        $provider = $this->make_provider([
            'authmode'        => 'static',
            'accesskeyid'     => '',
            'secretaccesskey' => 'SECRET',
        ]);
        $this->assertFalse($provider->is_provider_configured());
    }

    /**
     * Instance role: always configured (no keys needed).
     */
    public function test_is_provider_configured_instance_role(): void {
        $provider = $this->make_provider(['authmode' => 'instance_role']);
        $this->assertTrue($provider->is_provider_configured());
    }

    /**
     * Assume role: configured when ARN is present.
     */
    public function test_is_provider_configured_assume_role_with_arn(): void {
        $provider = $this->make_provider([
            'authmode'     => 'assume_role',
            'assumerolearn' => 'arn:aws:iam::123456789012:role/MoodleBedrock',
        ]);
        $this->assertTrue($provider->is_provider_configured());
    }

    /**
     * Assume role: NOT configured when ARN is blank.
     */
    public function test_is_provider_configured_assume_role_missing_arn(): void {
        $provider = $this->make_provider([
            'authmode'     => 'assume_role',
            'assumerolearn' => '',
        ]);
        $this->assertFalse($provider->is_provider_configured());
    }

    /**
     * Unknown authmode: never configured.
     */
    public function test_is_provider_configured_unknown_authmode(): void {
        $provider = $this->make_provider(['authmode' => 'magic_beans']);
        $this->assertFalse($provider->is_provider_configured());
    }
}
