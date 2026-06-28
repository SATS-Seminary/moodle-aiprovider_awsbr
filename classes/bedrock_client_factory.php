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
use Aws\Bedrock\BedrockClient;
use Aws\Credentials\Credentials;
use Aws\Credentials\InstanceProfileProvider;
use Aws\Credentials\AssumeRoleCredentialProvider;
use Aws\Sts\StsClient;
use Aws\CommandInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Factory that builds a BedrockRuntimeClient with the configured credential chain.
 *
 * Credential modes:
 *  - static       : access key ID + secret access key stored in admin settings.
 *  - instance_role: IAM instance/task role via IMDSv2 (recommended for EC2/ECS).
 *  - assume_role  : wraps either above with STS AssumeRole; session credentials
 *                   are cached in MUC to avoid hammering STS.
 *
 * Proxy support mirrors the behaviour of the now-deprecated \core\aws\aws_helper but
 * is reimplemented here directly so we do not trigger deprecation notices.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bedrock_client_factory {
    /** MUC cache area used for assumed-role session credentials. */
    private const CACHE_AREA = 'aiprovider_awsbr';
    /** MUC cache key prefix for assumed-role credentials. */
    private const CACHE_KEY_PREFIX = 'assumed_creds_';

    /**
     * Constructor.
     *
     * @param provider $provider The instantiated Bedrock provider (holds admin config).
     */
    public function __construct(
        /** @var provider The provider instance whose config we read. */
        protected provider $provider,
    ) {
    }

    /**
     * Return a BedrockRuntimeClient for the given region.
     *
     * @param string|null $region  AWS region. Defaults to the provider's primary region.
     * @return BedrockRuntimeClient
     */
    public function get_runtime_client(?string $region = null): BedrockRuntimeClient {
        $region   = $region ?? ($this->provider->config['region'] ?? 'af-south-1');
        $authmode = $this->provider->config['authmode'] ?? 'instance_role';

        // Bearer-token auth (AWS Bedrock API keys): no SigV4 signing — we attach
        // an Authorization: Bearer <token> header via middleware instead.
        if ($authmode === 'api_key') {
            $apikey = (string) ($this->provider->config['apikey'] ?? '');
            $args = [
                'region'      => $region,
                'version'     => 'latest',
                'credentials' => false, // Disable SigV4.
                'http'        => ['connect_timeout' => 10, 'timeout' => 120],
            ];
            $client = new BedrockRuntimeClient($args);
            $this->add_bearer_token_middleware($client, $apikey);
            $this->configure_proxy($client);
            return $client;
        }

        // Standard IAM-based auth (static / instance_role / assume_role).
        $args = [
            'region'      => $region,
            'version'     => 'latest',
            'credentials' => $this->build_credentials_provider(),
            'http'        => ['connect_timeout' => 10, 'timeout' => 120],
        ];

        $client = new BedrockRuntimeClient($args);
        $this->configure_proxy($client);
        return $client;
    }

    /**
     * Return a Bedrock control-plane client (model/inference-profile listing).
     *
     * Same credential chain as get_runtime_client(), but the control-plane
     * service (ListInferenceProfiles, ListFoundationModels) rather than the
     * runtime (Converse). Used by model_catalog to validate model selections.
     *
     * @param string|null $region AWS region. Defaults to the provider's primary region.
     * @return BedrockClient
     */
    public function get_control_client(?string $region = null): BedrockClient {
        $region   = $region ?? ($this->provider->config['region'] ?? 'af-south-1');
        $authmode = $this->provider->config['authmode'] ?? 'instance_role';

        if ($authmode === 'api_key') {
            $apikey = (string) ($this->provider->config['apikey'] ?? '');
            $client = new BedrockClient([
                'region'      => $region,
                'version'     => 'latest',
                'credentials' => false,
                'http'        => ['connect_timeout' => 10, 'timeout' => 60],
            ]);
            $this->add_bearer_token_middleware($client, $apikey);
            $this->configure_proxy($client);
            return $client;
        }

        $client = new BedrockClient([
            'region'      => $region,
            'version'     => 'latest',
            'credentials' => $this->build_credentials_provider(),
            'http'        => ['connect_timeout' => 10, 'timeout' => 60],
        ]);
        $this->configure_proxy($client);
        return $client;
    }

    /**
     * Inject an Authorization: Bearer header into every outbound request.
     *
     * Used for AWS Bedrock API keys (long-term bearer tokens issued by the
     * Bedrock console). The SigV4 credentials chain is bypassed entirely.
     *
     * @param \Aws\AwsClient $client
     * @param string         $apikey
     */
    protected function add_bearer_token_middleware(\Aws\AwsClient $client, string $apikey): void {
        $client->getHandlerList()->appendBuild(
            static function (callable $fn) use ($apikey) {
                return static function (CommandInterface $command, ?RequestInterface $request = null) use ($fn, $apikey) {
                    if ($request !== null && $apikey !== '') {
                        $request = $request->withHeader('Authorization', 'Bearer ' . $apikey);
                    }
                    return $fn($command, $request);
                };
            },
            'bedrock_bearer_token',
        );
    }

    /**
     * Build the credentials provider / object according to the configured authmode.
     *
     * @return callable|\Aws\Credentials\CredentialsInterface
     */
    protected function build_credentials_provider(): callable|\Aws\Credentials\CredentialsInterface {
        $authmode = $this->provider->config['authmode'] ?? 'instance_role';

        switch ($authmode) {
            case 'static':
                return new Credentials(
                    key:    $this->provider->config['accesskeyid'] ?? '',
                    secret: $this->provider->config['secretaccesskey'] ?? '',
                );

            case 'instance_role':
                return new InstanceProfileProvider();

            case 'assume_role':
                return $this->build_assume_role_provider();

            default:
                // Fallback: let the SDK default chain decide (env vars, ~/.aws, instance role).
                return \Aws\Credentials\CredentialProvider::defaultProvider();
        }
    }

    /**
     * Build an AssumeRoleCredentialProvider, wrapping the configured base credentials.
     *
     * Session credentials are cached in MUC with a TTL that expires 5 minutes before the
     * session itself (so we always renew before AWS rejects a stale token).
     *
     * @return callable
     */
    protected function build_assume_role_provider(): callable {
        $rolearn     = $this->provider->config['assumerolearn'] ?? '';
        $sessionname = $this->provider->config['assumerolesessionname'] ?? 'moodle-bedrock';
        $region      = $this->provider->config['region'] ?? 'af-south-1';

        // Check MUC for a valid cached session first.
        $cachekey    = self::CACHE_KEY_PREFIX . md5($rolearn . $sessionname);
        $cache       = \cache::make(self::CACHE_AREA, 'credentials');
        $cachedcreds = $cache->get($cachekey);

        if ($cachedcreds !== false && is_array($cachedcreds)) {
            // Verify the cached credential hasn't expired.
            if (!empty($cachedcreds['expiry']) && $cachedcreds['expiry'] > time()) {
                return new Credentials(
                    key:     $cachedcreds['key'],
                    secret:  $cachedcreds['secret'],
                    token:   $cachedcreds['token'],
                    expires: $cachedcreds['expiry'],
                );
            }
        }

        // Determine the base credentials to use when calling STS.
        $basecreds = ($this->provider->config['authmode'] ?? 'instance_role') === 'static'
            ? new Credentials(
                key:    $this->provider->config['accesskeyid'] ?? '',
                secret: $this->provider->config['secretaccesskey'] ?? '',
            )
            : new InstanceProfileProvider();

        $stsclient = new StsClient([
            'region'      => $region,
            'version'     => 'latest',
            'credentials' => $basecreds,
        ]);

        $provider = new AssumeRoleCredentialProvider([
            'client'      => $stsclient,
            'assume_role_params' => [
                'RoleArn'         => $rolearn,
                'RoleSessionName' => $sessionname,
            ],
        ]);

        // Wrap in a memoize provider so repeated calls within the same request are free.
        $memoized = \Aws\Credentials\CredentialProvider::memoize($provider);

        // Resolve once to cache in MUC.
        try {
            /** @var Credentials $resolved */
            $resolved = $memoized()->wait();
            $expiry   = $resolved->getExpiration();
            if ($expiry) {
                // Cache for up to 5 minutes before the token expires.
                $ttl = max(0, $expiry - time() - 300);
                $cache->set($cachekey, [
                    'key'    => $resolved->getAccessKeyId(),
                    'secret' => $resolved->getSecretKey(),
                    'token'  => $resolved->getSecurityToken(),
                    'expiry' => $expiry,
                ], $ttl);
            }
            return $resolved;
        } catch (\Throwable $e) {
            // If resolution fails, return the lazy provider — the SDK will retry on first use.
            return $memoized;
        }
    }

    /**
     * Attach Moodle proxy middleware to an already-constructed AWS client.
     *
     * Reimplements the proxy logic from the deprecated \core\aws\aws_helper without
     * triggering deprecation notices.
     *
     * @param \Aws\AwsClient $client
     */
    protected function configure_proxy(\Aws\AwsClient $client): void {
        global $CFG;

        if (empty($CFG->proxyhost)) {
            return;
        }

        // Build the proxy string.
        $protocol = (isset($CFG->proxytype) && $CFG->proxytype === 'SOCKS5') ? 'socks5://' : '';
        $proxy    = $CFG->proxyhost;
        if (!empty($CFG->proxyport)) {
            $proxy .= ':' . $CFG->proxyport;
        }
        if (!empty($CFG->proxyuser) && !empty($CFG->proxypassword)) {
            $proxy = $protocol . $CFG->proxyuser . ':' . $CFG->proxypassword . '@' . $proxy;
        } else {
            $proxy = $protocol . $proxy;
        }

        $client->getHandlerList()->appendBuild(
            static function (callable $fn) use ($proxy) {
                return static function (CommandInterface $command, ?RequestInterface $request = null) use ($fn, $proxy) {
                    if (isset($request)) {
                        $target = (string) $request->getUri();
                        if (!is_proxybypass($target)) {
                            $command['@http']['proxy'] = $proxy;
                        }
                    }
                    return $fn($command, $request);
                };
            },
            'bedrock_proxy',
        );
    }
}
