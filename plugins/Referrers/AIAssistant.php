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
    public const SIGNATURE_OPTION_STORAGE_NAME = 'AIAssistantSignatureDefinitions';

    /** @var string location of legacy definition file (relative to PIWIK_INCLUDE_PATH) */
    public const DEFINITION_FILE = '/vendor/matomo/searchengine-and-social-list/AIAssistants.yml';

    /** @var string location of supplemental signature file (relative to PIWIK_INCLUDE_PATH) */
    public const SIGNATURE_DEFINITION_FILE = '/vendor/matomo/searchengine-and-social-list/AIAssistantSignatures.yml';

    /** @var null|array<string, string> */
    protected $definitionList = null;

    /** @var null|array<int, array<string, mixed>> */
    protected $signatureList = null;

    /**
     * Returns list of AI assistants by unconditional URL.
     *
     * @return array<string, string>
     */
    public function getDefinitions(): array
    {
        $cache = Cache::getEagerCache();
        $cacheId = 'AIAssistant-' . self::OPTION_STORAGE_NAME;

        if ($cache->contains($cacheId)) {
            /** @var array<string, string> $list */
            $list = $cache->fetch($cacheId);
        } else {
            $list = $this->loadDefinitions();
            $cache->save($cacheId, $list);
        }

        return $list;
    }

    /**
     * Returns supplemental parameter-qualified AI assistant signatures.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSignatures(): array
    {
        $cache = Cache::getEagerCache();
        $cacheId = 'AIAssistant-' . self::SIGNATURE_OPTION_STORAGE_NAME;

        if ($cache->contains($cacheId)) {
            /** @var array<int, array<string, mixed>> $list */
            $list = $cache->fetch($cacheId);
        } else {
            $list = $this->loadSignatures();
            $cache->save($cacheId, $list);
        }

        return $list;
    }

    /**
     * @return array<string, string>
     */
    private function loadDefinitions(): array
    {
        if ($this->definitionList === null) {
            $referrerDefinitionSyncOpt = Config::getInstance()->General['enable_referrer_definition_syncs'];

            if ($referrerDefinitionSyncOpt == 1) {
                $this->loadRemoteDefinitions();
            } else {
                $this->loadLocalYmlData();
            }
        }

        Piwik::postEvent('Referrer.addAIAssistantUrls', [&$this->definitionList]);

        return $this->definitionList ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSignatures(): array
    {
        if ($this->signatureList === null) {
            $referrerDefinitionSyncOpt = Config::getInstance()->General['enable_referrer_definition_syncs'];

            if ($referrerDefinitionSyncOpt == 1) {
                $this->loadRemoteSignatures();
            } else {
                $this->loadLocalSignatureYmlData();
            }
        }

        return $this->signatureList ?? [];
    }

    /**
     * Loads legacy definitions sourced from remote YAML with a local fallback.
     */
    private function loadRemoteDefinitions(): void
    {
        $list = Option::get(self::OPTION_STORAGE_NAME);

        if ($list && SettingsPiwik::isInternetEnabled()) {
            $list = Common::safe_unserialize(base64_decode($list));
            if (!empty($list) && is_array($list)) {
                $this->definitionList = $list;
            }
        } else {
            $this->loadLocalYmlData();
            Option::set(self::OPTION_STORAGE_NAME, base64_encode(serialize($this->definitionList)));
        }
    }

    /**
     * Loads supplemental signatures sourced from remote YAML with a local fallback.
     */
    private function loadRemoteSignatures(): void
    {
        $list = Option::get(self::SIGNATURE_OPTION_STORAGE_NAME);

        if ($list && SettingsPiwik::isInternetEnabled()) {
            $list = Common::safe_unserialize(base64_decode($list));
            if (!empty($list) && is_array($list)) {
                $this->signatureList = $list;
            }
        } else {
            $this->loadLocalSignatureYmlData();
            Option::set(self::SIGNATURE_OPTION_STORAGE_NAME, base64_encode(serialize($this->signatureList)));
        }
    }

    private function loadLocalYmlData(): void
    {
        $yml = file_get_contents(PIWIK_INCLUDE_PATH . self::DEFINITION_FILE);
        if ($yml !== false) {
            $this->definitionList = $this->loadYmlData($yml);
        }
    }

    private function loadLocalSignatureYmlData(): void
    {
        $path = PIWIK_INCLUDE_PATH . self::SIGNATURE_DEFINITION_FILE;
        if (!file_exists($path)) {
            $this->signatureList = [];
            return;
        }

        $yml = file_get_contents($path);
        if ($yml !== false) {
            $this->signatureList = $this->loadSignatureYmlData($yml);
        }
    }

    /**
     * Parses the legacy domain-only YML data.
     *
     * @return null|array<string, string>
     */
    public function loadYmlData(string $yml): ?array
    {
        $ais = \Spyc::YAMLLoadString($yml);

        if (is_array($ais)) {
            $this->definitionList = $this->transformData($ais);
        }

        return $this->definitionList;
    }

    /**
     * Parses supplemental parameter-qualified signature YML data.
     *
     * @return null|array<int, array<string, mixed>>
     */
    public function loadSignatureYmlData(string $yml): ?array
    {
        $ais = \Spyc::YAMLLoadString($yml);
        if (!is_array($ais)) {
            return $this->signatureList;
        }

        $this->signatureList = [];
        foreach ($ais as $name => $entries) {
            if (!is_string($name) || empty($entries) || !is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                $signature = $this->normalizeSignature($name, $entry);
                if ($signature !== null) {
                    $this->signatureList[] = $signature;
                }
            }
        }

        return $this->signatureList;
    }

    /**
     * @param array<string, string[]> $ais
     * @return array<string, string>
     */
    protected function transformData(array $ais): array
    {
        $urlToName = [];
        foreach ($ais as $name => $urls) {
            if (empty($urls) || !is_array($urls)) {
                continue;
            }

            foreach ($urls as $url) {
                if (is_string($url)) {
                    $urlToName[$url] = $name;
                }
            }
        }
        return $urlToName;
    }

    /**
     * @param mixed $entry
     * @return null|array<string, mixed>
     */
    private function normalizeSignature(string $name, $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $urls = [];
        foreach ($entry['urls'] ?? [] as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        $landingParams = [];
        foreach ($entry['landing_params'] ?? [] as $parameter => $values) {
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
     * Returns the assistant name matching the referrer and landing query.
     *
     * @return string|false
     */
    public function getAIAssistantFromRequest(string $referrerUrl, string $landingQuery = '')
    {
        $landingParameters = $this->parseLandingParameters($landingQuery);

        foreach ($this->getSignatures() as $signature) {
            if ($this->signatureMatches($signature, $referrerUrl, $landingParameters)) {
                return $signature['name'];
            }
        }

        if ($this->isAIAssistantUrl($referrerUrl)) {
            return $this->getAIAssistantFromDomain($referrerUrl);
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
    private function signatureMatches(array $signature, string $referrerUrl, array $landingParameters): bool
    {
        foreach ($signature['landing_params'] as $parameter => $acceptedValues) {
            if (empty($landingParameters[$parameter]) || !array_intersect($acceptedValues, $landingParameters[$parameter])) {
                return false;
            }
        }

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

    public function isAIAssistantUrl(string $url, ?string $aiAssistantName = null): bool
    {
        foreach ($this->getDefinitions() as $domain => $name) {
            if ($this->urlMatchesDefinition($url, $domain) && ($aiAssistantName === null || $name === $aiAssistantName)) {
                return true;
            }
        }

        return false;
    }

    public function getAIAssistantFromDomain(string $url): string
    {
        foreach ($this->getDefinitions() as $domain => $name) {
            if ($this->urlMatchesDefinition($url, $domain)) {
                return $name;
            }
        }

        return Piwik::translate('General_Unknown');
    }

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
