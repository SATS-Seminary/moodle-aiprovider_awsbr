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
 * Lists the Bedrock inference profiles that are actually invokable for this
 * provider, so model selections can be validated instead of failing silently
 * at request time.
 *
 * Bedrock rejects on-demand invocation of bare foundation-model IDs ("Retry
 * with the ID or ARN of an inference profile"), so the set of *invokable* model
 * IDs is exactly the set of inference-profile IDs returned by
 * ListInferenceProfiles for the provider's region. Results are cached in MUC.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_catalog {
    /** MUC cache area. */
    private const CACHE_AREA = 'aiprovider_awsbr';
    /** MUC cache definition for the model list. */
    private const CACHE_DEF = 'model_catalog';
    /** Default embedding model, offered when the live list cannot be loaded. */
    public const DEFAULT_EMBEDDING_MODEL = 'amazon.titan-embed-text-v2:0';
    /** Default image model, offered when the live list cannot be loaded. */
    public const DEFAULT_IMAGE_MODEL = 'stability.stable-image-core-v1:1';
    /** The list was loaded and holds models. */
    public const STATE_OK = 'ok';
    /** The list was loaded and the region has no models of this kind. */
    public const STATE_EMPTY = 'empty';
    /** The list could not be loaded (credentials, permission or network problem). */
    public const STATE_ERROR = 'error';
    /** Pattern of the geo and global prefixes of inference profile ids (us., eu., global. ...). */
    private const GEO_PREFIX_PATTERN = '/^(?:us-gov|us|eu|apac|global|jp|au|ca)\./';
    /** ListFoundationModels output modality of embedding models. */
    public const MODALITY_EMBEDDING = 'EMBEDDING';
    /** ListFoundationModels output modality of image models. */
    public const MODALITY_IMAGE = 'IMAGE';
    /** @var string[] Id prefixes of the embedding models process_embed_text can call. */
    public const EMBEDDING_PREFIXES = [
        'amazon.titan-embed-text-',
        'amazon.titan-embed-g1-text-',
        'cohere.embed-english-v3',
        'cohere.embed-multilingual-v3',
    ];
    /**
     * @var string[] Id prefixes of the image models process_generate_image can call.
     *
     * The Stability prefixes are listed one by one on purpose: Bedrock also lists
     * Stability's editing models (inpaint, upscale, control, style guide ...) with
     * output modality IMAGE, and those need an input image.
     */
    public const IMAGE_PREFIXES = [
        'amazon.nova-canvas-',
        'amazon.titan-image-generator-',
        'stability.sd3-5-large-',
        'stability.sd3-large-',
        'stability.stable-image-core-',
        'stability.stable-image-ultra-',
    ];

    /** @var array<string, array> Results of foundation_result() already loaded by this object, by cache key. */
    private array $loaded = [];

    /**
     * Build a catalog bound to a specific Bedrock provider.
     *
     * @param provider $provider The Bedrock provider whose region/credentials to use.
     */
    public function __construct(
        /** @var provider The Bedrock provider whose region/credentials to use. */
        protected provider $provider,
    ) {
    }

    /**
     * Discover an enabled aiprovider_awsbr provider via the AI manager.
     *
     * Without a provider id this is the first enabled instance; with one it is
     * that instance (only if it is enabled).
     *
     * @param int|null $providerid Optional id of the provider instance wanted.
     * @return self|null Null when no matching awsbr provider is configured/enabled.
     */
    public static function discover(?int $providerid = null): ?self {
        try {
            $manager   = \core\di::get(\core_ai\manager::class);
            $actioncls = \core_ai\aiactions\generate_text::class;
            $providers = $manager->get_providers_for_actions([$actioncls], true);
            foreach ($providers[$actioncls] ?? [] as $candidate) {
                if (!($candidate instanceof provider)) {
                    continue;
                }
                if ($providerid === null || $providerid <= 0 || (int) $candidate->id === $providerid) {
                    return new self($candidate);
                }
            }
        } catch (\Throwable $e) {
            debugging('model_catalog::discover failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return null;
    }

    /**
     * Build a catalog for one specific provider instance, enabled or not.
     *
     * Used by the action settings forms, where an administrator may be
     * configuring an instance that is still disabled.
     *
     * @param int $id The id of the provider instance (ai_providers.id).
     * @return self|null Null when no aiprovider_awsbr instance has that id.
     */
    public static function for_provider_id(int $id): ?self {
        if ($id <= 0) {
            return null;
        }
        try {
            $manager   = \core\di::get(\core_ai\manager::class);
            $instances = $manager->get_provider_instances(['id' => $id]);
            $instance  = reset($instances);
            if ($instance instanceof provider) {
                return new self($instance);
            }
        } catch (\Throwable $e) {
            debugging('model_catalog::for_provider_id failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return null;
    }

    /**
     * The provider instance this catalog is bound to.
     *
     * @return provider
     */
    public function get_provider(): provider {
        return $this->provider;
    }

    /**
     * The instance's own allowlist (its availablemodels setting) as id => label.
     *
     * @return array<string,string>
     */
    public function get_allowlist(): array {
        return self::parse_allowlist((string) ($this->provider->config['availablemodels'] ?? ''));
    }

    /**
     * The models this provider instance allows, as id => label.
     *
     * The single source of truth for model choices: the instance's live catalog,
     * narrowed and relabelled by its availablemodels setting when that is not
     * empty. If the catalog cannot be loaded the instance's allowlist is returned.
     *
     * @return array Map of model id => label.
     */
    public function allowed_options(): array {
        // Embedding and image models are not inference profiles; keep them out of the chat list.
        $allowlist = array_filter(
            $this->get_allowlist(),
            static fn(string $id) => !self::is_embedding_id($id) && !self::is_image_id($id),
            ARRAY_FILTER_USE_KEY
        );
        return $this->options($allowlist);
    }

    /**
     * Whether a model id looks like an embedding model (Titan or Cohere embeddings).
     *
     * Broad on purpose: it keeps every embedding model out of the chat list,
     * including those that {@see is_supported_embedding_id()} rejects.
     *
     * @param string $id The model id.
     * @return bool
     */
    private static function is_embedding_id(string $id): bool {
        return str_starts_with($id, 'amazon.titan-embed') || str_starts_with($id, 'cohere.embed');
    }

    /**
     * Whether a model id looks like an image model (Nova Canvas, Titan Image, Stability).
     *
     * @param string $id The model id.
     * @return bool
     */
    private static function is_image_id(string $id): bool {
        $id = self::strip_geo_prefix($id);
        return str_starts_with($id, 'amazon.nova-canvas')
            || str_starts_with($id, 'amazon.titan-image')
            || str_starts_with($id, 'stability.');
    }

    /**
     * Whether process_embed_text can build a request for this embedding model.
     *
     * @param string $id The model id.
     * @return bool
     */
    public static function is_supported_embedding_id(string $id): bool {
        return self::has_prefix($id, self::EMBEDDING_PREFIXES);
    }

    /**
     * Whether process_generate_image can build a request for this image model.
     *
     * @param string $id The model id.
     * @return bool
     */
    public static function is_supported_image_id(string $id): bool {
        return self::has_prefix(self::strip_geo_prefix($id), self::IMAGE_PREFIXES);
    }

    /**
     * Remove a geo or global prefix (us., eu., apac., global., us-gov., jp., au., ca.) from a model id.
     *
     * Cross-Region inference profile ids are the foundation model id with such a prefix.
     *
     * @param string $id The model or inference profile id.
     * @return string The id without the prefix.
     */
    public static function strip_geo_prefix(string $id): string {
        return preg_replace(self::GEO_PREFIX_PATTERN, '', $id) ?? $id;
    }

    /**
     * Whether an id starts with any of the prefixes.
     *
     * @param string $id The model id.
     * @param string[] $prefixes The prefixes.
     * @return bool
     */
    private static function has_prefix(string $id, array $prefixes): bool {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($id, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a model id is supported for an output modality.
     *
     * @param string $modality MODALITY_EMBEDDING or MODALITY_IMAGE.
     * @param string $id The model id.
     * @return bool
     */
    private static function is_supported_id(string $modality, string $id): bool {
        return $modality === self::MODALITY_IMAGE
            ? self::is_supported_image_id($id)
            : self::is_supported_embedding_id($id);
    }

    /**
     * The embedding models this provider instance allows, as id => label.
     *
     * Embedding models are on-demand foundation models, not inference profiles,
     * so they come from ListFoundationModels (output modality EMBEDDING) rather
     * than from {@see allowed_options()}. Only models process_embed_text can call
     * are offered. See {@see foundation_options()} for narrowing and fallback.
     *
     * @return array Map of model id => label.
     */
    public function embedding_options(): array {
        return $this->foundation_options(self::MODALITY_EMBEDDING);
    }

    /**
     * The image models this provider instance allows in an image region, as id => label.
     *
     * Image models are on-demand foundation models (output modality IMAGE), plus
     * any inference profile of a supported text-to-image model. Only models
     * process_generate_image can call are offered. See {@see foundation_options()}
     * for narrowing and fallback. When the region has no such models the result is
     * empty (see {@see image_state()}); the default model is offered only when the
     * list could not be loaded.
     *
     * @param string $region The image generation region ('' = the instance's region).
     * @return array Map of model id => label.
     */
    public function image_options(string $region = ''): array {
        return $this->foundation_options(self::MODALITY_IMAGE, $region);
    }

    /**
     * Whether the image model list of a region loaded, was empty, or could not be loaded.
     *
     * @param string $region The image generation region ('' = the instance's region).
     * @return string One of the STATE_ constants.
     */
    public function image_state(string $region = ''): string {
        return $this->foundation_result(self::MODALITY_IMAGE, false, $region)['state'];
    }

    /**
     * The on-demand foundation models of one output modality, as id => label.
     *
     * The instance's availablemodels setting narrows and relabels the list,
     * using only the lines that are supported models of this modality; if there
     * are none, the whole list is offered. If the list cannot be loaded, the
     * modality's default model plus the supported lines of the setting are
     * returned. If it loaded and is empty, the result is empty: no model is
     * offered that does not exist in the region.
     *
     * @param string $modality MODALITY_EMBEDDING or MODALITY_IMAGE.
     * @param string $region The region override ('' = the instance's region).
     * @return array Map of model id => label.
     */
    protected function foundation_options(string $modality, string $region = ''): array {
        $allowlist = $this->get_allowlist();
        $result = $this->foundation_result($modality, false, $region);
        $live = [];
        foreach ($result['models'] as $m) {
            $live[$m['id']] = ($m['name'] !== '') ? $m['name'] : $m['id'];
        }

        if ($result['state'] === self::STATE_EMPTY) {
            return [];
        }
        if ($result['state'] === self::STATE_ERROR) {
            if ($modality === self::MODALITY_IMAGE) {
                $options = [self::DEFAULT_IMAGE_MODEL => get_string('action:generate_image:model_default', 'aiprovider_awsbr')];
            } else {
                $options = [self::DEFAULT_EMBEDDING_MODEL => get_string('action:embed_text:model_default', 'aiprovider_awsbr')];
            }
            foreach ($allowlist as $id => $label) {
                if (self::is_supported_id($modality, $id)) {
                    $options[$id] = $label;
                }
            }
            return $options;
        }

        $curated = [];
        foreach ($allowlist as $id => $label) {
            if (isset($live[$id])) {
                $curated[$id] = ($label !== '' && $label !== $id) ? $label : $live[$id];
            }
        }
        return !empty($curated) ? $curated : $live;
    }

    /**
     * The embedding options of a provider instance, for the settings forms.
     *
     * Makes no AWS call (and returns an empty array) when there is no provider id.
     *
     * @param int $providerid The provider instance id, 0 when unknown.
     * @return array Map of model id => label.
     */
    public static function embedding_options_for_provider_id(int $providerid): array {
        $catalog = self::for_provider_id($providerid);
        return $catalog !== null ? $catalog->embedding_options() : [];
    }

    /**
     * The image options of a provider instance, for the settings forms.
     *
     * Makes no AWS call (and returns an empty array) when there is no provider id.
     *
     * @param int $providerid The provider instance id, 0 when unknown.
     * @param string $region The image generation region ('' = the instance's region).
     * @return array Map of model id => label.
     */
    public static function image_options_for_provider_id(int $providerid, string $region = ''): array {
        $catalog = self::for_provider_id($providerid);
        return $catalog !== null ? $catalog->image_options($region) : [];
    }

    /**
     * The state of the image model list of a provider instance, for the settings forms.
     *
     * Makes no AWS call (and returns STATE_OK) when there is no provider id.
     *
     * @param int $providerid The provider instance id, 0 when unknown.
     * @param string $region The image generation region ('' = the instance's region).
     * @return string One of the STATE_ constants.
     */
    public static function image_state_for_provider_id(int $providerid, string $region = ''): string {
        $catalog = self::for_provider_id($providerid);
        return $catalog !== null ? $catalog->image_state($region) : self::STATE_OK;
    }

    /**
     * The region image calls go to: the given image region, else the instance's region.
     *
     * @param string $region The image generation region ('' = the instance's region).
     * @return string
     */
    public function effective_image_region(string $region = ''): string {
        $region = trim($region);
        return $region !== '' ? $region : (string) ($this->provider->config['region'] ?? 'us-east-1');
    }

    /**
     * The cache key of a provider instance's embedding model list.
     *
     * @param provider $provider The Bedrock provider.
     * @return string
     */
    public static function embedding_cache_key(provider $provider): string {
        return 'embed' . self::cache_key($provider);
    }

    /**
     * The cache key of a provider instance's image model list in an image region.
     *
     * Both the provider id and the region are part of the key.
     *
     * @param provider $provider The Bedrock provider.
     * @param string $region The image generation region ('' = the instance's region).
     * @return string
     */
    public static function image_cache_key(provider $provider, string $region = ''): string {
        $region = trim($region) !== '' ? trim($region) : (string) ($provider->config['region'] ?? 'us-east-1');
        return 'image_' . (int) $provider->id . '_' . preg_replace('/[^a-z0-9]/i', '', $region);
    }

    /**
     * The on-demand embedding models of the provider's region that can be called.
     *
     * @param bool $refresh Bypass the cache and re-query Bedrock.
     * @return array<int, array{id: string, name: string}>
     */
    public function embedding_list(bool $refresh = false): array {
        return $this->foundation_list(self::MODALITY_EMBEDDING, $refresh);
    }

    /**
     * The on-demand image models of an image region that can be called.
     *
     * @param string $region The image generation region ('' = the instance's region).
     * @param bool $refresh Bypass the cache and re-query Bedrock.
     * @return array<int, array{id: string, name: string}>
     */
    public function image_list(string $region = '', bool $refresh = false): array {
        return $this->foundation_result(self::MODALITY_IMAGE, $refresh, $region)['models'];
    }

    /**
     * The on-demand foundation models of one output modality, from ListFoundationModels.
     *
     * @param string $modality MODALITY_EMBEDDING or MODALITY_IMAGE.
     * @param bool $refresh Bypass the cache and re-query Bedrock.
     * @return array<int, array{id: string, name: string}>
     */
    protected function foundation_list(string $modality, bool $refresh = false): array {
        return $this->foundation_result($modality, $refresh)['models'];
    }

    /**
     * The on-demand foundation models of one output modality, and whether the list loaded.
     *
     * Only model families the plugin's processor for that modality can call are
     * kept. For images, inference profiles whose id (without its geo prefix) is a
     * supported text-to-image family are added; failing to list profiles does not
     * make the result an error. The result is cached per provider instance (and
     * region, for images); an empty result is not cached.
     *
     * The state is STATE_ERROR when ListFoundationModels failed, STATE_EMPTY when it
     * loaded and the region has no callable models, else STATE_OK.
     *
     * @param string $modality MODALITY_EMBEDDING or MODALITY_IMAGE.
     * @param bool $refresh Bypass the cache and re-query Bedrock.
     * @param string $region The region override for images ('' = the instance's region).
     * @return array{models: array<int, array{id: string, name: string}>, state: string}
     */
    protected function foundation_result(string $modality, bool $refresh = false, string $region = ''): array {
        $cache = \cache::make(self::CACHE_AREA, self::CACHE_DEF);
        $isimage = $modality === self::MODALITY_IMAGE;
        $region = $isimage ? $this->effective_image_region($region) : (string) ($this->provider->config['region'] ?? 'us-east-1');
        $key = $isimage
            ? self::image_cache_key($this->provider, $region)
            : self::embedding_cache_key($this->provider);
        if (!$refresh) {
            // The same object asks for the state and then the options: load once.
            if (isset($this->loaded[$key])) {
                return $this->loaded[$key];
            }
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return ['models' => $cached, 'state' => empty($cached) ? self::STATE_EMPTY : self::STATE_OK];
            }
        }

        $models = [];
        try {
            $client = $this->control_client($region);
            $resp = $client->listFoundationModels([
                'byOutputModality' => $modality,
                'byInferenceType'  => 'ON_DEMAND',
            ]);
            foreach (($resp['modelSummaries'] ?? []) as $m) {
                $id = (string) ($m['modelId'] ?? '');
                $types = $m['inferenceTypesSupported'] ?? null;
                if ($id === '' || (is_array($types) && !in_array('ON_DEMAND', $types, true))) {
                    continue;
                }
                if (!self::is_supported_id($modality, $id)) {
                    continue;
                }
                $models[$id] = ['id' => $id, 'name' => (string) ($m['modelName'] ?? $id)];
            }
        } catch (\Throwable $e) {
            debugging('model_catalog: ListFoundationModels failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $this->loaded[$key] = ['models' => [], 'state' => self::STATE_ERROR];
        }
        if ($isimage) {
            $models += $this->image_profiles($client, array_keys($models));
        }
        $models = array_values($models);
        usort($models, static fn($a, $b) => strcmp($a['id'], $b['id']));
        if (!empty($models)) {
            $cache->set($key, $models);
        }
        return $this->loaded[$key] = ['models' => $models, 'state' => empty($models) ? self::STATE_EMPTY : self::STATE_OK];
    }

    /**
     * The inference profiles of a region whose model is a supported text-to-image family.
     *
     * Future cross-region image profiles (us.stability..., global....) appear here
     * without code changes. Editing models stay out because only the supported
     * families match. A failure is logged and yields no profiles.
     *
     * @param \Aws\Bedrock\BedrockClient $client The control-plane client of the image region.
     * @param string[] $skip Ids already listed.
     * @return array<string, array{id: string, name: string}>
     */
    private function image_profiles(\Aws\Bedrock\BedrockClient $client, array $skip): array {
        $found = [];
        $next = null;
        try {
            do {
                $args = ['maxResults' => 100];
                if ($next !== null) {
                    $args['nextToken'] = $next;
                }
                $resp = $client->listInferenceProfiles($args);
                foreach (($resp['inferenceProfileSummaries'] ?? []) as $p) {
                    $id = (string) ($p['inferenceProfileId'] ?? '');
                    $status = (string) ($p['status'] ?? 'ACTIVE');
                    if ($id === '' || $status !== 'ACTIVE' || in_array($id, $skip, true) || !self::is_supported_image_id($id)) {
                        continue;
                    }
                    $found[$id] = ['id' => $id, 'name' => (string) ($p['inferenceProfileName'] ?? $id)];
                }
                $next = $resp['nextToken'] ?? null;
            } while ($next !== null);
        } catch (\Throwable $e) {
            debugging('model_catalog: ListInferenceProfiles for images failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $found;
    }

    /**
     * The Bedrock control-plane client for a region (overridable in tests).
     *
     * @param string $region The AWS region.
     * @return \Aws\Bedrock\BedrockClient
     */
    protected function control_client(string $region): \Aws\Bedrock\BedrockClient {
        return (new bedrock_client_factory($this->provider))->get_control_client($region);
    }

    /**
     * The allowed model options for a provider instance, for the settings forms.
     *
     * Makes no AWS call (and returns an empty array) when there is no provider id,
     * for example when the forms are built only to read default values.
     *
     * @param int $providerid The provider instance id, 0 when unknown.
     * @return array Map of model id => label.
     */
    public static function options_for_provider_id(int $providerid): array {
        $catalog = self::for_provider_id($providerid);
        return $catalog !== null ? $catalog->allowed_options() : [];
    }

    /**
     * The cache key of a provider instance's profile list.
     *
     * The provider id is part of the key: instances in one region may have
     * different credentials and so see different profiles. Only characters that
     * are safe in a simple key are used (eu-west-1 becomes euwest1).
     *
     * @param provider $provider The Bedrock provider.
     * @return string
     */
    public static function cache_key(provider $provider): string {
        $region = (string) ($provider->config['region'] ?? 'us-east-1');
        return 'profiles_' . (int) $provider->id . '_' . preg_replace('/[^a-z0-9]/i', '', $region);
    }

    /**
     * Return the invokable models as a list of records.
     *
     * @param bool $refresh Bypass the cache and re-query Bedrock.
     * @return array<int, array{id: string, name: string, status: string}>
     */
    public function list(bool $refresh = false): array {
        $region = (string) ($this->provider->config['region'] ?? 'us-east-1');
        $cache  = \cache::make(self::CACHE_AREA, self::CACHE_DEF);
        $key    = self::cache_key($this->provider);

        if (!$refresh) {
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $models = $this->fetch_from_bedrock($region);
        // Cache even an empty result briefly is undesirable; only cache non-empty
        // so a transient API failure doesn't pin an empty list for a day.
        if (!empty($models)) {
            $cache->set($key, $models);
        }
        return $models;
    }

    /**
     * Return just the invokable model/profile IDs.
     *
     * @param bool $refresh Bypass the cache and re-query Bedrock.
     * @return string[]
     */
    public function ids(bool $refresh = false): array {
        return array_map(static fn($m) => $m['id'], $this->list($refresh));
    }

    /**
     * Is the given model ID an invokable inference profile?
     *
     * Returns true (fail-open) when the catalog can't be loaded, so a transient
     * Bedrock/control-plane outage never blocks a legitimate save.
     *
     * @param string $modelid The model or inference profile ID to check.
     * @return bool
     */
    public function is_known(string $modelid): bool {
        $modelid = trim($modelid);
        if ($modelid === '') {
            return true; // Empty = "use provider default", always valid.
        }
        $ids = $this->ids();
        if (empty($ids)) {
            return true; // Catalog unavailable — don't block.
        }
        return in_array($modelid, $ids, true);
    }

    /**
     * Build the model dropdown options (id => label) for UI selectors.
     *
     * The live catalog is the source of truth. An optional allowlist (the
     * provider's hand-maintained `availablemodels`) acts purely as a curation
     * overlay: when supplied it narrows the list to that subset AND applies its
     * custom labels — but only for IDs that are actually invokable, so a stale
     * or mistyped allowlist entry can never reach a dropdown. Fail-safe: if the
     * live catalog can't be loaded, the raw allowlist is returned so the UI
     * still works.
     *
     * @param array $allowlist Optional curation map of model id => label.
     * @return array Map of model id => label.
     */
    public function options(array $allowlist = []): array {
        $live = [];
        foreach ($this->list() as $m) {
            $live[$m['id']] = ($m['name'] !== '') ? $m['name'] : $m['id'];
        }

        if (empty($live)) {
            return $allowlist; // Catalog unavailable — fall back to the manual list.
        }
        if (empty($allowlist)) {
            return $live; // No curation — offer every invokable profile.
        }

        // Curate: keep only allowlisted IDs that are genuinely invokable,
        // relabelling from the allowlist where a label is given.
        $curated = [];
        foreach ($allowlist as $id => $label) {
            if (isset($live[$id])) {
                $curated[$id] = ($label !== '' && $label !== $id) ? $label : $live[$id];
            }
        }
        // If every curated entry was invalid, don't show an empty box — fall
        // back to the full live list.
        return !empty($curated) ? $curated : $live;
    }

    /**
     * Parse an `availablemodels` textarea ("id|Label" per line) into id => label.
     *
     * @param string $raw The raw textarea value.
     * @return array<string,string>
     */
    public static function parse_allowlist(string $raw): array {
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 2);
            $id    = trim($parts[0]);
            $label = isset($parts[1]) ? trim($parts[1]) : $id;
            if ($id !== '') {
                $out[$id] = $label;
            }
        }
        return $out;
    }

    /**
     * Query Bedrock ListInferenceProfiles for the region, paginating fully.
     *
     * @param string $region The AWS region to query.
     * @return array<int, array{id: string, name: string, status: string}>
     */
    private function fetch_from_bedrock(string $region): array {
        try {
            $client = $this->control_client($region);
        } catch (\Throwable $e) {
            debugging('model_catalog: could not build control client — ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }

        $models = [];
        $next   = null;
        try {
            do {
                $args = ['maxResults' => 100];
                if ($next !== null) {
                    $args['nextToken'] = $next;
                }
                $resp = $client->listInferenceProfiles($args);
                foreach (($resp['inferenceProfileSummaries'] ?? []) as $p) {
                    $id = (string) ($p['inferenceProfileId'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $models[$id] = [
                        'id'     => $id,
                        'name'   => (string) ($p['inferenceProfileName'] ?? $id),
                        'status' => (string) ($p['status'] ?? ''),
                    ];
                }
                $next = $resp['nextToken'] ?? null;
            } while ($next !== null);
        } catch (\Throwable $e) {
            debugging('model_catalog: ListInferenceProfiles failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
            return array_values($models); // Return whatever we gathered before the error.
        }

        $models = array_values($models);
        usort($models, static fn($a, $b) => strcmp($a['id'], $b['id']));
        return $models;
    }
}
