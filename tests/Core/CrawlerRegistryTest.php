<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\CrawlerRegistry;
use Cybermaps\Discovery\BotCategory;

final class CrawlerRegistryTest extends \WP_UnitTestCase {

	/**
	 * @dataProvider current_ai_user_agent_provider
	 */
	public function test_current_official_ai_user_agents_are_registered(
		string $ua,
		string $expected_id,
		BotCategory $expected_category
	): void {
		$bots = CrawlerRegistry::get_all();

		$this->assertSame( $expected_id, CrawlerRegistry::identify_bot( $ua ) );
		$this->assertSame( $expected_category, $bots[ $expected_id ]->category );
	}

	/**
	 * @return array<string, array{string, string, BotCategory}>
	 */
	public static function current_ai_user_agent_provider(): array {
		return array(
			'ChatGPT user'      => array( 'ChatGPT-User/1.0', 'chatgpt-user', BotCategory::AI_USER ),
			'OpenAI ads'        => array( 'OAI-AdsBot/1.0', 'oai-adsbot', BotCategory::DIAGNOSTIC ),
			'Claude user'       => array( 'Claude-User/1.0', 'claude-user', BotCategory::AI_USER ),
			'Claude search'     => array( 'Claude-SearchBot/1.0', 'claude-searchbot', BotCategory::AI_SEARCH ),
			'Perplexity user'   => array( 'Perplexity-User/1.0', 'perplexity-user', BotCategory::AI_USER ),
			'Amazon search'     => array( 'Amzn-SearchBot/0.1', 'amzn-searchbot', BotCategory::AI_SEARCH ),
			'Amazon user fetch' => array( 'Amzn-User/0.1', 'amzn-user', BotCategory::AI_USER ),
			'Mistral user fetch' => array( 'MistralAI-User/1.0', 'mistralai-user', BotCategory::AI_USER ),
			'Mistral search index' => array( 'MistralAI-Index/1.0', 'mistralai-index', BotCategory::AI_SEARCH ),
			'Meta model/index'  => array( 'meta-externalagent/1.1', 'meta-externalagent', BotCategory::AI_TRAINING ),
			'Meta AI search'    => array( 'meta-webindexer/1.1', 'meta-webindexer', BotCategory::AI_SEARCH ),
			'Meta user fetch'   => array( 'meta-externalfetcher/1.1', 'meta-externalfetcher', BotCategory::AI_USER ),
			'DuckDuckGo AI answers' => array( 'DuckAssistBot/1.2', 'duckassistbot', BotCategory::AI_SEARCH ),
			'Cloudflare AI Search' => array( 'Cloudflare-AI-Search/1.0', 'cloudflare-ai-search', BotCategory::AI_SEARCH ),
		);
	}

	public function test_amazon_searchbot_does_not_collide_with_amazonbot_documentation_url(): void {
		$ua = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amzn-SearchBot/0.1; +https://developer.amazon.com/amazonbot) Chrome/120.0 Safari/537.36';

		$this->assertSame( 'amzn-searchbot', CrawlerRegistry::identify_bot( $ua ) );
	}

	public function test_amazon_user_does_not_collide_with_amazonbot_documentation_url(): void {
		$ua = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amzn-User/0.1; +https://developer.amazon.com/amazonbot) Chrome/120.0 Safari/537.36';

		$this->assertSame( 'amzn-user', CrawlerRegistry::identify_bot( $ua ) );
	}

	public function test_documentation_url_alone_is_not_a_crawler_signature(): void {
		$ua = 'Mozilla/5.0 (documentation: https://developer.amazon.com/amazonbot)';

		$this->assertNull( CrawlerRegistry::identify_bot( $ua ) );
	}

	public function test_bot_name_in_comment_prose_is_not_a_crawler_signature(): void {
		$this->assertNull( CrawlerRegistry::identify_bot( 'Mozilla/5.0 (not Amazonbot)' ) );
		$this->assertNull( CrawlerRegistry::identify_bot( 'Mozilla/5.0 (Amazonbot documentation)' ) );
		$this->assertSame( 'amazonbot', CrawlerRegistry::identify_bot( 'Mozilla/5.0 (Amazonbot)' ) );
	}

	/**
	 * @dataProvider signature_boundary_provider
	 */
	public function test_bot_signatures_require_product_token_boundaries( string $ua ): void {
		$this->assertNull( CrawlerRegistry::identify_bot( $ua ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function signature_boundary_provider(): array {
		return array(
			'prefixed token' => array( 'FakeAmazonbot/1.0' ),
			'suffixed token' => array( 'Amazonbotics/1.0' ),
			'hyphen family'  => array( 'Not-Amazonbot/1.0' ),
			'path fragment'  => array( 'developer.amazon.com/amazonbot' ),
			'host fragment'  => array( 'Amazonbot.example.com' ),
			'plus fragment'  => array( 'Not+Amazonbot/1.0' ),
		);
	}

	/**
	 * @dataProvider explicit_alias_provider
	 */
	public function test_explicit_user_agent_aliases_are_recognized( string $ua, string $expected_id ): void {
		$this->assertSame( $expected_id, CrawlerRegistry::identify_bot( $ua ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function explicit_alias_provider(): array {
		return array(
			'Slack unfurler'      => array( 'Slackbot-LinkExpanding 1.0', 'slackbot' ),
			'Pinterest legacy UA' => array( 'Pinterest/0.2 (+https://www.pinterest.com/bot.html)', 'pinterestbot' ),
			'Pingdom root cause'  => array( 'Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)', 'pingdom' ),
			'Baidu image crawler' => array( 'Mozilla/5.0 (compatible; Baiduspider-image/2.0; +http://www.baidu.com/search/spider.html)', 'baiduspider' ),
			'Baidu news crawler'  => array( 'Baiduspider-news/2.0', 'baiduspider' ),
			'Sogou instant'       => array( 'Sogou inst spider/4.0', 'sogou-spider' ),
			'Qwant news crawler'  => array( 'Mozilla/5.0 (compatible; Qwantbot-news/2.0; +https://help.qwant.com/bot/)', 'qwantify' ),
			'Legacy Qwant crawler' => array( 'Qwantify-News/2.1', 'qwantify' ),
			'Legacy Naver token'  => array( 'Naverbot/1.0', 'naverbot' ),
			'Coc Coc web crawler' => array( 'Mozilla/5.0 (compatible; coccocbot-web/1.0; +http://help.coccoc.com/searchengine)', 'coccocbot' ),
			'Semrush token suffix' => array( 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)', 'semrushbot' ),
			'Semrush business'    => array( 'Mozilla/5.0 (compatible; SemrushBot-BA; +http://www.semrush.com/bot.html)', 'semrushbot' ),
			'Legacy Anthropic training token' => array( 'Anthropic-AI/1.0', 'claudebot' ),
			'Legacy Claude retrieval token' => array( 'Claude-Web/1.0', 'claude-user' ),
			'Legacy OpenAI search token' => array( 'SearchGPT-Lib/1.0', 'oai-searchbot' ),
		);
	}

	public function test_retired_agents_are_recognition_aliases_not_separate_policy_controls(): void {
		$bots = CrawlerRegistry::get_all();

		$this->assertArrayNotHasKey( 'anthropic-ai', $bots );
		$this->assertArrayNotHasKey( 'claude-web', $bots );
		$this->assertArrayNotHasKey( 'searchgpt-lib', $bots );
	}

	public function test_unsupported_or_misidentified_agents_are_not_public_controls(): void {
		$bots = CrawlerRegistry::get_all();

		foreach (
			array(
				'grok',
				'agentreadiness',
				'apple-pubsub',
				'cohere-ai',
				'bytespider',
				'piplbot',
				'metaphorbot',
				'meltwater',
				'sitelockspider',
			) as $unsupported_id
		) {
			$this->assertArrayNotHasKey( $unsupported_id, $bots );
		}

		$this->assertNull( CrawlerRegistry::identify_bot( 'Grok/1.0' ) );
		$this->assertNull( CrawlerRegistry::identify_bot( 'AgentReadinessScanner/1.0' ) );
		$this->assertNull( CrawlerRegistry::identify_bot( 'Apple-PubSub/65.28' ) );
		$this->assertNull( CrawlerRegistry::identify_bot( 'Meltwater/1.0' ) );
	}

	public function test_current_search_and_diagnostic_product_tokens_are_recognized(): void {
		$this->assertSame( 'qwantify', CrawlerRegistry::identify_bot( 'Qwantbot/1.0_12345' ) );
		$this->assertSame( 'naverbot', CrawlerRegistry::identify_bot( 'Mozilla/5.0 (compatible; Yeti/1.1; +http://naver.me/spd)' ) );
		$this->assertSame( 'sogou-spider', CrawlerRegistry::identify_bot( 'Sogou web spider/4.0' ) );
		$this->assertSame( 'pingdom', CrawlerRegistry::identify_bot( 'Pingdom.com_bot/1.0' ) );
		$this->assertSame( 'cloudflare-check', CrawlerRegistry::identify_bot( 'Cloudflare-Healthchecks/1.0' ) );
		$this->assertSame( 'ahrefs-site-audit', CrawlerRegistry::identify_bot( 'AhrefsSiteAudit/6.1' ) );
		$this->assertSame( 'meta-externalads', CrawlerRegistry::identify_bot( 'meta-externalads/1.1' ) );
	}

	public function test_request_only_aliases_do_not_broaden_robots_policy_tokens(): void {
		$pinterest = CrawlerRegistry::get_all()['pinterestbot'];

		$this->assertSame( array( 'Pinterestbot' ), $pinterest->get_robots_tokens() );
		$this->assertSame(
			array( 'Pinterestbot', 'Pinterest/0.2' ),
			$pinterest->get_user_agent_signatures()
		);
		$this->assertNull( CrawlerRegistry::identify_bot( 'Pinterest/12.0 mobile app' ) );
	}

	public function test_google_news_is_a_robots_product_token_not_a_request_user_agent(): void {
		$this->assertNull( CrawlerRegistry::identify_bot( 'Googlebot-News/2.1' ) );
	}

	public function test_non_training_data_collectors_are_not_labeled_as_ai_training(): void {
		$bots = CrawlerRegistry::get_all();

		foreach ( array( 'ccbot', 'imagesiftbot', 'diffbot', 'proximic' ) as $id ) {
			$this->assertSame( BotCategory::DATA_CRAWLER, $bots[ $id ]->category );
			$this->assertFalse( $bots[ $id ]->default['llm'] );
		}
	}

	public function test_manifest_target_policy_is_limited_to_ai_training_search_and_user_categories(): void {
		$bots = CrawlerRegistry::get_all();

		$this->assertTrue( CrawlerRegistry::supports_manifest_target( $bots['gptbot'] ) );
		$this->assertTrue( CrawlerRegistry::supports_manifest_target( $bots['claude-searchbot'] ) );
		$this->assertTrue( CrawlerRegistry::supports_manifest_target( $bots['chatgpt-user'] ) );
		$this->assertFalse( CrawlerRegistry::supports_manifest_target( $bots['googlebot'] ) );
		$this->assertFalse( CrawlerRegistry::supports_manifest_target( $bots['oai-adsbot'] ) );
		$this->assertFalse( CrawlerRegistry::category_supports_manifest_target( BotCategory::DATA_CRAWLER->value ) );
	}

	public function test_legacy_override_ids_normalize_without_overriding_current_values(): void {
		$this->assertEquals(
			array(
				'claudebot' => array( 'robots' => false ),
				'unknown-extension-agent' => array( 'tpm' => 12 ),
			),
			CrawlerRegistry::normalize_overrides(
				array(
					'anthropic-ai' => array( 'robots' => false ),
					'unknown-extension-agent' => array( 'tpm' => 12 ),
				)
			)
		);

		$this->assertSame(
			array( 'claudebot' => array( 'robots' => true ) ),
			CrawlerRegistry::normalize_overrides(
				array(
					'anthropic-ai' => array( 'robots' => false ),
					'claudebot'    => array( 'robots' => true ),
				)
			)
		);
	}

	public function test_richer_match_reports_stable_identity_and_basis(): void {
		$match = CrawlerRegistry::match_user_agent( 'Mozilla/5.0 (compatible; Amzn-SearchBot/0.1)' );

		$this->assertNotNull( $match );
		$this->assertSame( 'amzn-searchbot', $match->id );
		$this->assertSame( 'Amzn-SearchBot', $match->metadata->name );
		$this->assertSame( 'Amzn-SearchBot', $match->signature );
		$this->assertSame( 'ua_signature', $match->basis );
	}

	public function test_every_registered_primary_product_token_remains_recognized(): void {
		foreach ( CrawlerRegistry::get_all() as $id => $metadata ) {
			if ( ! $metadata->recognizes_requests ) {
				continue;
			}
			$ua = sprintf( 'Mozilla/5.0 (compatible; %s/1.0; +https://example.com/crawler)', $metadata->ua );
			$this->assertSame( $id, CrawlerRegistry::identify_bot( $ua ), 'Failed to recognize ' . $metadata->ua );
		}
	}

	public function test_registry_metadata_and_defaults_are_complete(): void {
		foreach ( CrawlerRegistry::get_all() as $id => $metadata ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
			$this->assertNotSame( '', trim( $metadata->name ), $id . ' has no display name.' );
			$this->assertNotSame( '', trim( $metadata->company ), $id . ' has no operator.' );
			$this->assertNotSame( '', trim( $metadata->ua ), $id . ' has no robots token.' );
			$this->assertNotSame( '', trim( $metadata->desc ), $id . ' has no purpose description.' );
			$this->assertSame( array( 'robots', 'llm' ), array_keys( $metadata->default ) );
			$this->assertIsBool( $metadata->default['robots'] );
			$this->assertIsBool( $metadata->default['llm'] );
		}
	}

	public function test_request_signatures_are_not_owned_by_multiple_controls(): void {
		$owners = array();

		foreach ( CrawlerRegistry::get_all() as $id => $metadata ) {
			foreach ( $metadata->get_user_agent_signatures() as $signature ) {
				$normalized = strtolower( $signature );
				$this->assertArrayNotHasKey(
					$normalized,
					$owners,
					sprintf( '%s and %s both claim the request signature %s.', $owners[ $normalized ] ?? '', $id, $signature )
				);
				$owners[ $normalized ] = $id;
			}
		}
	}

	public function test_robots_only_policy_tokens_are_not_classified_as_http_request_agents(): void {
		$this->assertNull( CrawlerRegistry::identify_bot( 'Google-Extended/1.0' ) );
		$this->assertNull( CrawlerRegistry::identify_bot( 'Applebot-Extended/1.0' ) );
		$this->assertNull( CrawlerRegistry::identify_bot( 'Googlebot-News/2.1' ) );

		$this->assertSame(
			'applebot',
			CrawlerRegistry::identify_bot( 'Applebot/1.0 Applebot-Extended/1.0' )
		);

		$policy_only = array_keys(
			array_filter(
				CrawlerRegistry::get_all(),
				static fn ( $metadata ): bool => ! $metadata->recognizes_requests
			)
		);
		$this->assertSame(
			array( 'google-extended', 'applebot-extended', 'googlebot-news' ),
			$policy_only
		);
	}

	public function test_category_list_contains_only_categories_with_registered_crawlers(): void {
		$categories  = CrawlerRegistry::get_categories();
		$represented = array();

		foreach ( CrawlerRegistry::get_all() as $bot ) {
			$represented[ $bot->category->value ] = true;
		}

		$this->assertEqualsCanonicalizing( array_keys( $represented ), array_keys( $categories ) );
		$this->assertCount( count( $represented ), $categories );
		$this->assertArrayNotHasKey( BotCategory::UNREGISTERED_BOT->value, $categories );
		$this->assertArrayHasKey( BotCategory::OTHER->value, $categories );
		$this->assertArrayNotHasKey( BotCategory::OTHER->value, CrawlerRegistry::get_policy_categories() );
	}
}
