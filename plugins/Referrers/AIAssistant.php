<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Referrers;

use Piwik\Cache;
use Piwik\Common;
use Piwik\Config;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\SettingsPiwik;
use Piwik\Singleton;

/**
 * Contains methods to access AI assistant definition data.
 */
class AIAssistant extends Singleton
{
    public const OPTION_STORAGE_NAME = 'AIAssistantDefinitions';

    /** @var string location of definition file (relative to PIWIK_INCLUDE_PATH) */
    public const DEFINITION_FILE = '/vendor/matomo/searchengine-and-social-list/AIAssistants.yml';

    /** @var null|array<string, string> */
    protected $definitionList = null;

    /** @var null|array<int, array<string, mixed>> */
    protected $signatureList = null;

    /** @var null|array<string, array<int, mixed>> */
    protected $storageData = null;

    /**
     * Returns list of AI assistants by unconditional URL.
     *
     * @return array<string, string>
     */
    public function getDefinitions(): array
    {
        $this->ensureDefinitionsLoaded();

        return $this->definitionList ?? [];
    }

    /**
     * Returns all AI assistant signatures.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSignatures(): array
    {
        $this->ensureDefinitionsLoaded();

        return $this->signatureList ?? [];
    }

    private function ensureDefinitionsLoaded(): void
    {
        if ($this->definitionList !== null && $this->signatureList !== null) {
            return;
        }

        $cache = Cache::getEagerCache();
        $cacheId = 'AIAssistant-' . self::OPTION_STORAGE_NAME;

        if ($cache->contains($cacheId)) {
            $list = $cache->fetch($cacheId);
            if (is_array($list)) {
                $this->loadStoredData($list);
            }
        }

        if ($this->definitionList === null || $this->signatureList === null) {
            $this->loadDefinitions();
            $cache->save($cacheId, $this->storageData ?? []);
        }
    }

    private function loadDefinitions(): void
    {
        if ($this->definitionList === null || $this->signatureList === null) {
            $referrerDefinitionSyncOpt = Config::getInstance()->General['enable_referrer_definition_syncs'];

            if ($referrerDefinitionSyncOpt == 1) {
                $this->loadRemoteDefinitions();
            } else {
                $this->loadLocalYmlData();
            }
        }

        Piwik::postEvent('Referrer.addAIAssistantUrls', [&$this->definitionList]);
        $this->addMissingUnconditionalSignatures();
        $this->storageData = $this->buildStorageDataFromSignatures();
    }

    /**
     * Loads definitions sourced from remote yaml with a local fallback.
     */
    private function loadRemoteDefinitions(): void
    {
        // Read first from the auto-updated list in database
        $list = Option::get(self::OPTION_STORAGE_NAME);

        if ($list && SettingsPiwik::isInternetEnabled()) {
            $list = Common::safe_unserialize(base64_decode($list));
            if (!empty($list) && is_array($list)) {
                $this->loadStoredData($list);
            }
        } else {
            // Fallback to reading the bundled list
            $this->loadLocalYmlData();
            Option::set(self::OPTION_STORAGE_NAME, base64_encode(serialize($this->storageData)));
        }
    }

    /**
     * Loads the definition data from the local definitions file.
     */
    private function loadLocalYmlData(): void
    {
        $yml = file_get_contents(PIWIK_INCLUDE_PATH . self::DEFINITION_FILE);
        if ($yml !== false) {
            $this->loadYmlData($yml);
        }
    }

    /**
     * Parses the given YML string and caches the resulting definitions.
     *
     * The returned data is suitable for storage by the definition sync task.
     *
     * @return null|array<string, array<int, mixed>>
     */
    public function loadYmlData(string $yml): ?array
    {
        $ais = \Spyc::YAMLLoadString($yml);

        if (is_array($ais)) {
            $this->loadStructuredData($ais);
        }

        return $this->storageData;
    }

    /**
     * Loads either the current structured storage format or the legacy flat domain map.
     *
     * @param array<mixed> $data
     */
    private function loadStoredData(array $data): void
    {
        if ($this->isLegacyFlatDefinitionList($data)) {
            $structured = [];
            foreach ($data as $url => $name) {
                $structured[$name][] = $url;
            }
            $this->loadStructuredData($structured);
            return;
        }

        $this->loadStructuredData($data);
    }

    /**
     * @param array<mixed> $data
     */
    private function isLegacyFlatDefinitionList(array $data): bool
    {
        if ($data === []) {
            return false;
        }

        foreach ($data as $url => $name) {
            if (!is_string($url) || !is_string($name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $ais
     */
    private function loadStructuredData(array $ais): void
    {
        $this->definitionList = [];
        $this->signatureList = [];
        $this->storageData = [];

        foreach ($ais as $name => $entries) {
            if (!is_string($name) || empty($entries) || !is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                $signature = $this->normalizeSignature($name, $entry);
                if ($signature === null) {
                    continue;
                }

                $this->signatureList[] = $signature;
                $this->storageData[$name][] = $this->signatureToStorageEntry($signature);

                if (empty($signature['landing_params'])) {
                    foreach ($signature['urls'] as $url) {
                        $this->definitionList[$url] = $name;
                    }
                }
            }
        }
    }

    /**
     * @param mixed $entry
     * @return null|array<string, mixed>
     */
    private function normalizeSignature(string $name, $entry): ?array
    {
        if (is_string($entry) && $entry !== '') {
            return [
                'name' => $name,
                'urls' => [$entry],
                'landing_params' => [],
                'allow_empty_referrer' => false,
            ];
        }

        if (!is_array($entry)) {
            return null;
        }

        $urls = [];
        if (!empty($entry['urls']) && is_array($entry['urls'])) {
            foreach ($entry['urls'] as $url) {
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }

        $landingParams = [];
        if (!empty($entry['landing_params']) && is_array($entry['landing_params'])) {
            foreach ($entry['landing_params'] as $parameter => $values) {
                if (!is_string($parameter) || $parameter === '') {
                    continue;
                }

                if (!is_array($values)) {
                    $values = [$values];
                }

                foreach ($values as $value) {
                    if (is_scalar($value)) {
                        $landingParams[mb_strtolower(trim($parameter))][] = mb_strtolower(trim((string) $value));
                    }
                }
            }
        }

        if ($urls === [] && $landingParams === []) {
            return null;
        }

        return [
            'name' => $name,
            'urls' => array_values(array_unique($urls)),
            'landing_params' => $landingParams,
            'allow_empty_referrer' => !empty($entry['allow_empty_referrer']),
        ];
    }

    /**
     * @param array<string, mixed> $signature
     * @return mixed
     */
    private function signatureToStorageEntry(array $signature)
    {
        if (
            empty($signature['landing_params'])
            && empty($signature['allow_empty_referrer'])
            && count($signature['urls']) === 1
        ) {
            return reset($signature['urls']);
        }

        $entry = [];
        if (!empty($signature['urls'])) {
            $entry['urls'] = array_values($signature['urls']);
        }
        if (!empty($signature['allow_empty_referrer'])) {
            $entry['allow_empty_referrer'] = true;
        }
        if (!empty($signature['landing_params'])) {
            $entry['landing_params'] = $signature['landing_params'];
        }

        return $entry;
    }

    private function addMissingUnconditionalSignatures(): void
    {
        foreach ($this->definitionList ?? [] as $url => $name) {
            $found = false;
            foreach ($this->signatureList ?? [] as $signature) {
                if (
                    $signature['name'] === $name
                    && empty($signature['landing_params'])
                    && in_array($url, $signature['urls'], true)
                ) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $this->signatureList[] = [
                    'name' => $name,
                    'urls' => [$url],
                    'landing_params' => [],
                    'allow_empty_referrer' => false,
                ];
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function buildStorageDataFromSignatures(): array
    {
        $storageData = [];
        foreach ($this->signatureList ?? [] as $signature) {
            $storageData[$signature['name']][] = $this->signatureToStorageEntry($signature);
        }

        return $storageData;
    }

    /**
     * Returns the assistant name matching the referrer and landing query.
     *
     * @return string|false
     */
    public function getAIAssistantFromRequest(string $referrerUrl, string $landingQuery = '')
    {
        $landingParameters = $this->parseLandingParameters($landingQuery);

        foreach ($this->getSignatures() as $signature) {
            if (!$this->signatureMatchesLandingParameters($signature, $landingParameters)) {
                continue;
            }

            if (!$this->signatureMatchesReferrer($signature, $referrerUrl)) {
                continue;
            }

            return $signature['name'];
        }

        // Backward compatibility for assistants that send a known hostname as utm_source.
        foreach ($landingParameters['utm_source'] ?? [] as $utmSource) {
            if ($this->isAIAssistantUrl($utmSource)) {
                return $this->getAIAssistantFromDomain($utmSource);
            }
        }

        return false;
    }

    /**
     * @return array<string, string[]>
     */
    private function parseLandingParameters(string $query): array
    {
        $parameters = [];
        foreach (preg_split('/[&;]/', ltrim($query, '?#')) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $name = mb_strtolower(trim(urldecode($parts[0])));
            if ($name === '') {
                continue;
            }

            $value = isset($parts[1]) ? mb_strtolower(trim(urldecode($parts[1]))) : '';
            $parameters[$name][] = $value;
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $signature
     * @param array<string, string[]> $landingParameters
     */
    private function signatureMatchesLandingParameters(array $signature, array $landingParameters): bool
    {
        foreach ($signature['landing_params'] as $parameter => $acceptedValues) {
            if (empty($landingParameters[$parameter])) {
                return false;
            }

            if (!array_intersect($acceptedValues, $landingParameters[$parameter])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $signature
     */
    private function signatureMatchesReferrer(array $signature, string $referrerUrl): bool
    {
        if (empty($signature['urls'])) {
            return !empty($signature['landing_params']);
        }

        if ($referrerUrl === '') {
            return !empty($signature['allow_empty_referrer']) && !empty($signature['landing_params']);
        }

        foreach ($signature['urls'] as $url) {
            if ($this->urlMatchesDefinition($referrerUrl, $url)) {
                return true;
            }
        }

        return false;
    }

    private function urlMatchesDefinition(string $url, string $definition): bool
    {
        return (bool) preg_match('#(^|[\.\/])' . preg_quote($definition, '#') . '(\/|$)#i', $url);
    }

    /**
     * Returns true if a URL belongs unconditionally to an AI assistant, false otherwise.
     *
     * @param string $url The URL to check.
     * @param string|null $aiAssistantName The name of the AI assistant to check for, or null to check for any.
     */
    public function isAIAssistantUrl(string $url, ?string $aiAssistantName = null): bool
    {
        foreach ($this->getDefinitions() as $domain => $name) {
            if ($this->urlMatchesDefinition($url, $domain) && ($aiAssistantName === null || $name === $aiAssistantName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets AI assistant name from an unconditional URL definition.
     */
    public function getAIAssistantFromDomain(string $url): string
    {
        foreach ($this->getDefinitions() as $domain => $name) {
            if ($this->urlMatchesDefinition($url, $domain)) {
                return $name;
            }
        }

        return Piwik::translate('General_Unknown');
    }

    /**
     * Returns the main url of the AI assistant the given url matches.
     */
    public function getMainUrl(string $url): string
    {
        $ai = $this->getAIAssistantFromDomain($url);
        foreach ($this->getDefinitions() as $domain => $name) {
            if ($name === $ai) {
                return $domain;
            }
        }
        return $url;
    }

    /**
     * Returns the main url of the given AI assistant.
     */
    public function getMainUrlFromName(string $aiAssistant): ?string
    {
        foreach ($this->getDefinitions() as $domain => $name) {
            if ($name === $aiAssistant) {
                return $domain;
            }
        }

        foreach ($this->getSignatures() as $signature) {
            if ($signature['name'] === $aiAssistant && !empty($signature['urls'])) {
                return reset($signature['urls']);
            }
        }

        return null;
    }

    /**
     * Return AI assistant logo path by URL.
     *
     * @see plugins/Morpheus/icons/dist/aiAssistants/
     */
    public function getLogoFromUrl(string $url): string
    {
        $ai = $this->getAIAssistantFromDomain($url);
        $ais = $this->getDefinitions();
        $filePattern = 'plugins/Morpheus/icons/dist/aiAssistants/%s.png';

        $aiDomains = array_keys($ais, $ai);
        foreach ($aiDomains as $domain) {
            if (file_exists(PIWIK_INCLUDE_PATH . '/' . sprintf($filePattern, $domain))) {
                return sprintf($filePattern, $domain);
            }
        }

        return sprintf($filePattern, 'xx');
    }
}
