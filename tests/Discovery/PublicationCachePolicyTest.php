<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\PublicationCachePolicy;
use PHPUnit\Framework\TestCase;

final class PublicationCachePolicyTest extends TestCase {
	protected function tearDown(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		parent::tearDown();
	}

	public function test_wildcard_vary_cannot_be_narrowed_by_negotiation(): void {
		$this->assertSame( '*', PublicationCachePolicy::merge_vary( '*', array( 'Accept' ) ) );
		$this->assertSame( '*', PublicationCachePolicy::merge_vary( 'Accept-Encoding, *', array( 'Accept' ) ) );
		$this->assertSame( '*', PublicationCachePolicy::merge_vary( 'Accept', array( '*' ) ) );
		$this->assertSame( 'accept, Accept-Encoding', PublicationCachePolicy::merge_vary( 'Accept', array( 'accept', 'Accept-Encoding' ) ) );
	}

	public function test_diagnostic_no_store_overrides_public_ttls(): void {
		$policy = PublicationCachePolicy::for_publication( 'sitemap', 'index' );
		$policy['no_store'] = true;
		$this->assertSame( 'no-store, no-cache, must-revalidate, max-age=0', PublicationCachePolicy::cache_control( $policy ) );
	}

	public function test_sitemap_policy_prevents_shared_storage_and_discovery_remains_cacheable(): void {
		update_option( 'cybermaps_static_generation', 12 );
		foreach ( array( array( 'sitemap', 'index' ), array( 'sitemap', 'post_type:post' ), array( 'sitemap', 'rss' ), array( 'discovery', 'ai_sitemap' ) ) as [ $family, $endpoint ] ) {
			$policy = PublicationCachePolicy::for_publication( $family, $endpoint );
			$this->assertSame( 'private, no-store, no-cache, must-revalidate, max-age=0', PublicationCachePolicy::cache_control( $policy ) );
			$this->assertSame( 12, $policy['generation'] );
		}
		$this->assertSame( 'public, max-age=3600, must-revalidate', PublicationCachePolicy::cache_control( PublicationCachePolicy::for_publication( 'discovery', 'feed' ) ) );
	}

	public function test_an_explicit_shared_cache_policy_can_set_sitemap_ttls(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_cache_policy'] = array(
			static function ( array $policy ): array {
				$policy['shared_cache'] = true;
				$policy['shared_ttl']   = 60;
				return $policy;
			},
		);
		$policy = PublicationCachePolicy::for_publication( 'sitemap', 'index' );
		$this->assertSame( 'public, max-age=3600, s-maxage=60, must-revalidate', PublicationCachePolicy::cache_control( $policy ) );
	}

	public function test_a_partial_filter_keeps_the_sitemap_private_and_uses_the_current_generation(): void {
		update_option( 'cybermaps_static_generation', 12 );
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_cache_policy'] = array(
			static function (): array {
				update_option( 'cybermaps_static_generation', 13 );
				return array( 'shared_ttl' => 600 );
			},
		);
		$policy = PublicationCachePolicy::for_publication( 'sitemap', 'index' );
		$this->assertFalse( $policy['shared_cache'] );
		$this->assertSame( 13, $policy['generation'] );
	}

	public function test_tags_are_bounded_ascii_and_do_not_include_the_site_url(): void {
		$policy = PublicationCachePolicy::for_publication( 'discovery', 'ai.json' );
		$tags   = PublicationCachePolicy::tags( $policy );

		$this->assertLessThanOrEqual( PublicationCachePolicy::MAX_TAGS, count( $tags ) );
		$this->assertContains( 'cm-family-discovery', $tags );
		$this->assertContains( 'cm-endpoint-ai-json', $tags );
		foreach ( $tags as $tag ) {
			$this->assertSame( 1, preg_match( '/^[A-Za-z0-9-]{1,96}$/', $tag ) );
			$this->assertStringNotContainsString( '://', $tag );
		}
	}

	public function test_stale_directives_require_an_explicit_revalidation_override(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_cache_policy'] = array(
			static function ( array $policy ): array {
				$policy['must_revalidate']        = false;
				$policy['stale_while_revalidate'] = 60;
				$policy['stale_if_error']         = 120;
				return $policy;
			},
		);

		$cache_control = PublicationCachePolicy::cache_control( PublicationCachePolicy::for_publication() );
		$this->assertStringContainsString( 'stale-while-revalidate=60', $cache_control );
		$this->assertStringContainsString( 'stale-if-error=120', $cache_control );
		$this->assertStringNotContainsString( 'must-revalidate', $cache_control );
	}
}
