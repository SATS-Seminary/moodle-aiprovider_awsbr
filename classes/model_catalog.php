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

defined('MOODLE_INTERNAL') || die();

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

    /**
     * @param provider $provider The Bedrock provider whose region/credentials to use.
     */
    public function __construct(
        protected provider $provider,
    ) {
    }

    /**
     * Discover the first enabled aiprovider_awsbr provider via the AI manager.
     *
     * @return self|null Null when no awsbr provider is configured/enabled.
     */
    public static function discover(): ?self {
        try {
            $manager   = \core\di::get(\core_ai\manager::class);
            $actioncls = \core_ai\aiactions\generate_text::class;
            $providers = $manager->get_providers_for_actions([$actioncls], true);
            foreach ($providers[$actioncls] ?? [] as $candidate) {
                if ($candidate instanceof provider) {
                    return new self($candidate);
                }
            }
        } catch (\Throwable $e) {
            debugging('model_catalog::discover failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return null;
    }

    /**
     * Return the invokable models as a list of records.
     *
     * @param bool $refresh Bypass the cache and re-query Bedrock.
     * @return array<int, array{id: string, name: string, status: string}>
     */
    public function list(bool $refresh = false): array {
        $region = (string) ($this->provider->config['region'] ?? 'af-south-1');
        $cache  = \cache::make(self::CACHE_AREA, self::CACHE_DEF);
        // Simple-key safe: strip non-alphanumerics (eu-west-1 -> euwest1).
        $key    = 'profiles_' . preg_replace('/[^a-z0-9]/i', '', $region);

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
     * @param bool $refresh
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
     * @param string $modelid
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
     * Query Bedrock ListInferenceProfiles for the region, paginating fully.
     *
     * @param string $region
     * @return array<int, array{id: string, name: string, status: string}>
     */
    private function fetch_from_bedrock(string $region): array {
        try {
            $client = (new bedrock_client_factory($this->provider))->get_control_client($region);
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
