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
    private const LEGACY_DEFINITIONS = <<<'YAML'
ChatGPT:
  - chatgpt.com
Perplexity:
  - perplexity.ai
YAML;

    private const SIGNATURES = <<<'YAML'
Perplexity:
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
Lilo Chat IA:
  -
    urls:
      - search.lilo.org
    landing_params:
      utm_source:
        - lilo
      utm_campaign:
        - ai_chat
YAML;

    protected function setUp(): void
    {
        parent::setUp();
        AIAssistant::getInstance()->loadYmlData(self::LEGACY_DEFINITIONS);
        AIAssistant::getInstance()->loadSignatureYmlData(self::SIGNATURES);
    }

    protected function tearDown(): void
    {
        $legacy = file_get_contents(PIWIK_PATH_TEST_TO_ROOT . AIAssistant::DEFINITION_FILE);
        AIAssistant::getInstance()->loadYmlData($legacy);

        $signaturePath = PIWIK_PATH_TEST_TO_ROOT . AIAssistant::SIGNATURE_DEFINITION_FILE;
        AIAssistant::getInstance()->loadSignatureYmlData(file_exists($signaturePath) ? file_get_contents($signaturePath) : '');
        parent::tearDown();
    }

    public function testLegacyDomainDefinitionsRemainFlatAndSupported(): void
    {
        self::assertSame([
            'chatgpt.com' => 'ChatGPT',
            'perplexity.ai' => 'Perplexity',
        ], AIAssistant::getInstance()->getDefinitions());
        self::assertSame('ChatGPT', AIAssistant::getInstance()->getAIAssistantFromRequest('', 'utm_source=chatgpt.com'));
    }

    public function testPerplexityCanBeDetectedByDomainOrSupplementalUtmSignature(): void
    {
        self::assertSame('Perplexity', AIAssistant::getInstance()->getAIAssistantFromRequest('https://www.perplexity.ai/', ''));
        self::assertSame('Perplexity', AIAssistant::getInstance()->getAIAssistantFromRequest('', 'utm_source=perplexity'));
    }

    public function testQwantSignaturesDoNotMakeQwantAnUnconditionalAiDomain(): void
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
        self::assertArrayNotHasKey('qwant.com', AIAssistant::getInstance()->getDefinitions());
    }

    public function testAllowedEmptyReferrerDoesNotAcceptContradictoryReferrer(): void
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

    public function testLiloChatIaRequiresReferrerAndCompleteLandingSignature(): void
    {
        self::assertSame(
            'Lilo Chat IA',
            AIAssistant::getInstance()->getAIAssistantFromRequest(
                'https://search.lilo.org/',
                'utm_source=lilo&utm_medium=referral&utm_campaign=ai_chat'
            )
        );
        self::assertFalse(AIAssistant::getInstance()->getAIAssistantFromRequest('https://search.lilo.org/', ''));
        self::assertFalse(AIAssistant::getInstance()->getAIAssistantFromRequest('', 'utm_source=lilo&utm_campaign=ai_chat'));
    }

    public function testMainUrlCanBeResolvedFromSupplementalSignatureForReportingMetadata(): void
    {
        self::assertSame('qwant.com', AIAssistant::getInstance()->getMainUrlFromName('Qwant Chat IA'));
        self::assertSame('search.lilo.org', AIAssistant::getInstance()->getMainUrlFromName('Lilo Chat IA'));
    }
}
