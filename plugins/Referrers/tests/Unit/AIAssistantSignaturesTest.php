<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Referrers\tests\Unit;

use Piwik\Plugins\Referrers\AIAssistant;

/**
 * @group AIAssistant
 * @group Plugins
 */
class AIAssistantSignaturesTest extends \PHPUnit\Framework\TestCase
{
    private const DEFINITIONS = <<<'YAML'
ChatGPT:
  - chatgpt.com
Perplexity:
  -
    urls:
      - perplexity.ai
  -
    landing_params:
      utm_source:
        - perplexity
Qwant AI Flash:
  -
    urls:
      - qwant.com
      - www.qwant.com
    allow_empty_referrer: true
    landing_params:
      utm_source:
        - qwant
      utm_campaign:
        - ai_flash
Qwant Chat IA:
  -
    urls:
      - qwant.com
      - www.qwant.com
    allow_empty_referrer: true
    landing_params:
      utm_source:
        - qwant
      utm_campaign:
        - ai_chat
YAML;

    protected function setUp(): void
    {
        parent::setUp();
        AIAssistant::getInstance()->loadYmlData(self::DEFINITIONS);
    }

    protected function tearDown(): void
    {
        $yml = file_get_contents(PIWIK_PATH_TEST_TO_ROOT . AIAssistant::DEFINITION_FILE);
        AIAssistant::getInstance()->loadYmlData($yml);
        parent::tearDown();
    }

    public function testLegacyDomainDefinitionsRemainSupported(): void
    {
        self::assertTrue(AIAssistant::getInstance()->isAIAssistantUrl('https://chatgpt.com/'));
        self::assertSame('ChatGPT', AIAssistant::getInstance()->getAIAssistantFromRequest('https://chatgpt.com/', ''));
        self::assertSame('ChatGPT', AIAssistant::getInstance()->getAIAssistantFromRequest('', 'utm_source=chatgpt.com'));
    }

    public function testPerplexityCanBeDetectedByReferrerOrUtmSource(): void
    {
        self::assertSame('Perplexity', AIAssistant::getInstance()->getAIAssistantFromRequest('https://www.perplexity.ai/', ''));
        self::assertSame('Perplexity', AIAssistant::getInstance()->getAIAssistantFromRequest('', 'utm_source=perplexity'));
    }

    public function testQwantAiSurfacesRequireTheirCompleteParameterSignature(): void
    {
        self::assertSame(
            'Qwant Chat IA',
            AIAssistant::getInstance()->getAIAssistantFromRequest(
                'https://www.qwant.com/',
                'utm_source=qwant&utm_medium=referral&utm_campaign=ai_chat'
            )
        );
        self::assertSame(
            'Qwant AI Flash',
            AIAssistant::getInstance()->getAIAssistantFromRequest(
                'https://www.qwant.com/',
                'utm_source=qwant&utm_campaign=ai_flash'
            )
        );
        self::assertFalse(AIAssistant::getInstance()->getAIAssistantFromRequest('https://www.qwant.com/', ''));
        self::assertFalse(AIAssistant::getInstance()->getAIAssistantFromRequest('https://www.qwant.com/', 'utm_source=qwant'));
    }

    public function testQualifiedSignatureCanAllowAnEmptyButNotContradictoryReferrer(): void
    {
        self::assertSame(
            'Qwant Chat IA',
            AIAssistant::getInstance()->getAIAssistantFromRequest('', 'utm_source=qwant&utm_campaign=ai_chat')
        );
        self::assertFalse(
            AIAssistant::getInstance()->getAIAssistantFromRequest(
                'https://example.org/',
                'utm_source=qwant&utm_campaign=ai_chat'
            )
        );
    }

    public function testLandingParameterMatchingIsDecodedAndCaseInsensitive(): void
    {
        self::assertSame(
            'Qwant Chat IA',
            AIAssistant::getInstance()->getAIAssistantFromRequest(
                'https://qwant.com/',
                'UTM_SOURCE=QWANT&UTM_CAMPAIGN=AI%5FCHAT'
            )
        );
    }

    public function testConditionalDomainsAreNotExposedAsUnconditionalDefinitions(): void
    {
        $definitions = AIAssistant::getInstance()->getDefinitions();

        self::assertSame('ChatGPT', $definitions['chatgpt.com']);
        self::assertSame('Perplexity', $definitions['perplexity.ai']);
        self::assertArrayNotHasKey('qwant.com', $definitions);
        self::assertArrayNotHasKey('www.qwant.com', $definitions);
    }
}
