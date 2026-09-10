<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\RequestIdentityClassifier;

final class RequestIdentityClassifierTest extends \WP_UnitTestCase {

	public function test_logged_in_wordpress_user_overrides_a_crawler_claim(): void {
		$identity = RequestIdentityClassifier::classify( 'OAI-SearchBot/1.0', true );

		$this->assertSame( 'Logged-in site user', $identity['bot'] );
		$this->assertSame( 'internal', $identity['identity_status'] );
		$this->assertSame( 'wordpress_session', $identity['verification_method'] );
		$this->assertSame( 0, $identity['recognized'] );
		$this->assertFalse( RequestIdentityClassifier::should_record_page( $identity ) );
	}

	public function test_registry_match_is_stored_as_a_claim_not_a_verified_identity(): void {
		$identity = RequestIdentityClassifier::classify( 'OAI-SearchBot/1.0', false );

		$this->assertSame( 'OAI-SearchBot', $identity['bot'] );
		$this->assertSame( 'oai-searchbot', $identity['crawler_id'] );
		$this->assertSame( 'claimed', $identity['identity_status'] );
		$this->assertSame( 'ua_signature', $identity['verification_method'] );
		$this->assertSame( 1, $identity['recognized'] );
		$this->assertTrue( RequestIdentityClassifier::should_record_page( $identity ) );
	}

	public function test_unregistered_bot_candidate_is_retained_for_content_analytics(): void {
		$identity = RequestIdentityClassifier::classify( 'ExampleResearchCrawler/2.1', false );

		$this->assertSame( 'Unregistered crawler candidate', $identity['bot'] );
		$this->assertSame( 'unregistered-bot', $identity['category'] );
		$this->assertSame( 'unregistered-bot', $identity['identity_status'] );
		$this->assertSame( 'bot-like', $identity['client_type'] );
		$this->assertTrue( RequestIdentityClassifier::should_record_page( $identity ) );
	}

	/**
	 * @dataProvider non_crawler_client_provider
	 */
	public function test_non_crawler_clients_are_classified_but_not_retained_on_pages(
		string $user_agent,
		string $expected_status
	): void {
		$identity = RequestIdentityClassifier::classify( $user_agent, false );

		$this->assertSame( $expected_status, $identity['identity_status'] );
		$this->assertFalse( RequestIdentityClassifier::should_record_page( $identity ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function non_crawler_client_provider(): array {
		return array(
			'browser' => array(
				'Mozilla/5.0 AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
				'browser',
			),
			'command line' => array( 'curl/8.5.0', 'automated-client' ),
			'missing UA' => array( '', 'no-user-agent' ),
			'ambiguous product' => array( 'InternalMonitor/1.0', 'unknown' ),
		);
	}

	public function test_amazon_search_claim_uses_the_specific_registry_identity(): void {
		$identity = RequestIdentityClassifier::classify(
			'Mozilla/5.0 (compatible; Amzn-SearchBot/1.0; +https://developer.amazon.com/support/amazonbot)',
			false
		);

		$this->assertSame( 'Amzn-SearchBot', $identity['bot'] );
		$this->assertSame( 'amzn-searchbot', $identity['crawler_id'] );
		$this->assertSame( 'ai-search', $identity['category'] );
	}
}
