<?php
/**
 * Regression coverage for the sanitized 2026-08-30 crawler audit.
 *
 * @package Cybermaps
 */

declare(strict_types=1);

use Cybermaps\Core\CrawlerRegistry;

final class CrawlerAuditFixtureTest extends WP_UnitTestCase {
	/**
	 * Replay the first-party-identifiable user agents retained by the audit.
	 *
	 * @dataProvider recognized_user_agents
	 */
	public function test_audited_user_agent_is_recognized( string $user_agent, string $expected_id ): void {
		$this->assertSame( $expected_id, CrawlerRegistry::identify_bot( $user_agent ), $user_agent );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function recognized_user_agents(): array {
		return array(
			'meta official 1.1' => array( 'Mozilla/5.0 (compatible; meta-webindexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))', 'meta-webindexer' ),
			'meta official 1.0' => array( 'Mozilla/5.0 (compatible; meta-webindexer/1.0 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))', 'meta-webindexer' ),
			'linkup' => array( 'Mozilla/5.0 (compatible; LinkupBot/1.0; +https://www.linkup.so/bot)', 'linkupbot' ),
			'parallel' => array( 'Mozilla/5.0 (compatible; ShapBot/1.0; +https://parallel.ai/bot)', 'shapbot' ),
			'sofya' => array( 'Mozilla/5.0 (compatible; SofyaBot/1.0; +https://sofya.ai/sofya-bot)', 'sofyabot' ),
			'you' => array( 'Mozilla/5.0 (compatible; YouBot/1.0; +https://about.you.com/youbot/)', 'youbot' ),
			'ibou' => array( 'Mozilla/5.0 (compatible; IbouBot/1.0; +https://ibou.io/bot)', 'iboubot' ),
			'seekport' => array( 'Mozilla/5.0 (compatible; SeekportBot; +https://bot.seekport.com)', 'seekportbot' ),
			'serpstat' => array( 'Mozilla/5.0 (compatible; SerpstatBot/2.1; +https://serpstatbot.com/)', 'serpstatbot' ),
			'dataforseo' => array( 'Mozilla/5.0 (compatible; DataForSeoBot/1.0; +https://dataforseo.com/dataforseo-bot)', 'dataforseo-bot' ),
			'hubspot' => array( 'Mozilla/5.0 (compatible; HubSpot Crawler; +https://www.hubspot.com/products/cms/site-crawling)', 'hubspot-crawler' ),
			'seojuice' => array( 'Mozilla/5.0 (compatible; SEOJuice-SearchBot/1.0; +https://seojuice.io/bot)', 'seojuice-searchbot' ),
			'se ranking' => array( 'Mozilla/5.0 (compatible; SERankingBacklinksBot/1.0; +https://seranking.com/bot.html)', 'seranking-backlinksbot' ),
			'poweredby' => array( 'Mozilla/5.0 (compatible; PoweredByBot/1.0; +https://poweredby.com/bot)', 'poweredbybot' ),
			'internet archive' => array( 'Mozilla/5.0 (compatible; archive.org_bot +http://www.archive.org/details/archive.org_bot)', 'archive-org-bot' ),
			'velen' => array( 'Mozilla/5.0 (compatible; VelenPublicWebCrawler/1.0; +https://velen.io)', 'velen-crawler' ),
			'clueweb' => array( 'Mozilla/5.0 (compatible; ClueWeb-Crawler/1.0; +https://lemurproject.org/clueweb22/)', 'clueweb-crawler' ),
			'stripebot' => array( 'Stripebot/1.0 (+https://stripe.com/docs/bots)', 'stripebot' ),
			'known good 0.3' => array( 'KnownGood-Verifier/0.3 (+https://knowngood.io/bot)', 'knowngood-verifier' ),
			'known good 1.5' => array( 'KnownGood-Verifier/1.5 (+https://knowngood.io/bot)', 'knowngood-verifier' ),
			'feed fetcher' => array( 'FeedFetcher-Google; (+http://www.google.com/feedfetcher.html)', 'feedfetcher-google' ),
			'stripe security' => array( 'Stripe Merchant Security Scanner/1.0', 'stripe-merchant-security-scanner' ),
			'turnitin' => array( 'TurnitinBot/3.0 (https://www.turnitin.com/robot/crawlerinfo.html)', 'turnitin' ),
		);
	}

	public function test_recognition_only_services_are_excluded_from_policy_registry(): void {
		$all    = CrawlerRegistry::get_all();
		$policy = CrawlerRegistry::get_policy_bots();

		foreach ( array( 'feedfetcher-google', 'stripe-merchant-security-scanner', 'turnitin' ) as $id ) {
			$this->assertArrayHasKey( $id, $all );
			$this->assertArrayNotHasKey( $id, $policy );
			$this->assertSame( array(), $all[ $id ]->get_robots_tokens() );
		}
	}

	/**
	 * @dataProvider withheld_user_agents
	 */
	public function test_withheld_user_agent_stays_unidentified( string $user_agent ): void {
		$this->assertNull( CrawlerRegistry::identify_bot( $user_agent ), $user_agent );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function withheld_user_agents(): array {
		return array(
			'sleepbot' => array( 'Mozilla/5.0 (compatible; SleepBot/1.0; +https://sleepbot.example)' ),
			'redirect scanner' => array( 'redirect-scanner/1.0' ),
			'reflection' => array( 'ReflectionBot/1.0' ),
			'math dataset' => array( 'MathPicDatasetCrawler/1.0' ),
			'vulnerability scanner' => array( 'vuln_scanner/1.0' ),
			'yelp' => array( 'YelpBot/1.0' ),
			'seltz' => array( 'SeltzSitemapFetcher/1.0' ),
			'msp signal' => array( 'MSPSignalBot/1.0' ),
			'yisou' => array( 'YisouSpider/5.0' ),
			'aion' => array( 'AionBot/1.0' ),
			'glass dollar' => array( 'GlassDollarWWWIndexer/1.0' ),
			'bytespider' => array( 'Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)' ),
			'agent readiness' => array( 'AgentReadinessScanner/1.0' ),
			'automattic analytics' => array( 'Automattic Analytics Crawler/1.0' ),
			'commerce detector' => array( 'EcommercePaymentGatewayDetector/1.0' ),
			'lohisoft' => array( 'LohiSoftBot/1.0' ),
			'keys so' => array( 'keys-so-bot/1.0' ),
			'white circle' => array( 'WhiteCircleTrustBot/1.0' ),
			'example search bot' => array( 'SearchEngineBot/1.0 (+https://example.com/bot)' ),
			'querit' => array( 'QueritBot/1.0' ),
			'general crawl' => array( 'GeneralCrawlBot/1.0' ),
			'generic crawler' => array( 'crawler/1.0' ),
			'huawei' => array( 'HuaweiCrawler/1.0' ),
			'nextcloud' => array( 'Nextcloud Server Crawler' ),
			'bing preview' => array( 'BingPreview/1.0b' ),
			'ads txt' => array( 'ads-txt-crawler/1.0' ),
			'markos' => array( 'MarkosWebBot/1.0' ),
			'dns example' => array( 'DNSCrawler/1.0 (+https://example.com)' ),
			'grok' => array( 'GrokBot/1.0' ),
			'intelx' => array( 'intelx.io_bot' ),
			'image bot' => array( 'ImageBot/1.0' ),
			'ev crawler' => array( 'ev-crawler/1.0' ),
			'cloudflare scanner' => array( 'CloudflareWebScanner/1.0' ),
			'meridian' => array( 'MeridianBot/1.0' ),
			'mini search' => array( 'mini-search-domain-info/1.0' ),
			'trash hound' => array( 'TrashHound/1.0' ),
			'dnb' => array( 'DnBCrawler-Analytics/1.0' ),
		);
	}
}
