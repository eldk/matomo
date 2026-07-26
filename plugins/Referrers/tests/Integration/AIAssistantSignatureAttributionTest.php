<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Referrers\tests\Integration;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Referrers\AIAssistant;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Referrers
 * @group ReferrerAttribution
 * @group Plugins
 */
class AIAssistantSignatureAttributionTest extends IntegrationTestCase
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

    public function testPerplexityUtmSourceWithEmptyReferrerIsAttributedToAiAssistant(): void
    {
        $visit = $this->trackVisit('', 'utm_source=perplexity');

        self::assertSame((string) Common::REFERRER_TYPE_AI_ASSISTANT, (string) $visit['referer_type']);
        self::assertSame('Perplexity', $visit['referer_name']);
        self::assertSame('', $visit['referer_url']);
    }

    public function testQwantChatIaIsAttributedBeforeCampaignOrSearchDetection(): void
    {
        $visit = $this->trackVisit(
            'https://www.qwant.com/',
            'utm_source=qwant&utm_medium=referral&utm_campaign=ai_chat'
        );

        self::assertSame((string) Common::REFERRER_TYPE_AI_ASSISTANT, (string) $visit['referer_type']);
        self::assertSame('Qwant Chat IA', $visit['referer_name']);
    }

    public function testQwantAiFlashIsAttributedBeforeCampaignOrSearchDetection(): void
    {
        $visit = $this->trackVisit(
            'https://www.qwant.com/',
            'utm_source=qwant&utm_medium=referral&utm_campaign=ai_flash'
        );

        self::assertSame((string) Common::REFERRER_TYPE_AI_ASSISTANT, (string) $visit['referer_type']);
        self::assertSame('Qwant AI Flash', $visit['referer_name']);
    }

    public function testOrdinaryQwantReferrerIsNotAttributedToAiAssistant(): void
    {
        $visit = $this->trackVisit('https://www.qwant.com/', '');

        self::assertNotSame((string) Common::REFERRER_TYPE_AI_ASSISTANT, (string) $visit['referer_type']);
        self::assertNotSame('Qwant Chat IA', $visit['referer_name']);
        self::assertNotSame('Qwant AI Flash', $visit['referer_name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function trackVisit(string $referrerUrl, string $query): array
    {
        $idSite = Fixture::createWebsite('2020-01-01 02:00:00', true, 'test', 'https://matomo.org/');
        $tracker = Fixture::getTracker($idSite, '2020-01-01 05:00:00');
        $tracker->setUrlReferrer($referrerUrl);
        $tracker->setUrl('https://matomo.org/' . ($query !== '' ? '?' . $query : ''));
        Fixture::checkResponse($tracker->doTrackPageView('Home'));

        $visits = Db::fetchAll(
            'SELECT referer_type, referer_name, referer_keyword, referer_url FROM ' . Common::prefixTable('log_visit')
        );

        return $visits[0];
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }
}
