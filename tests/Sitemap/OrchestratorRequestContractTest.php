<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\ProviderIdentity;
use Cybermaps\Core\CacheManager;
use PHPUnit\Framework\TestCase;

final class OrchestratorRequestContractTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_caching' => '1',
		);
	}

	public function test_network_index_never_uses_a_site_local_response_cache(): void {
		$orchestrator = new Orchestrator();
		$method       = new \ReflectionMethod( Orchestrator::class, 'is_response_cache_enabled' );

		$this->assertFalse( $method->invoke( $orchestrator, 'network' ) );
		$this->assertTrue( $method->invoke( $orchestrator, 'index' ) );
		$this->assertTrue( $method->invoke( $orchestrator, ProviderIdentity::post_type( 'post' ) ) );
	}

	public function test_cache_keys_include_the_collision_proof_provider_identity(): void {
		$orchestrator = new Orchestrator();
		$method       = new \ReflectionMethod( Orchestrator::class, 'get_response_cache_key' );
		$post_key     = $method->invoke( $orchestrator, ProviderIdentity::post_type( 'shared' ), 1 );
		$tax_key      = $method->invoke( $orchestrator, ProviderIdentity::taxonomy( 'shared' ), 1 );
		$system_key   = $method->invoke( $orchestrator, ProviderIdentity::NEWS, 1 );

		$this->assertNotSame( $post_key, $tax_key );
		$this->assertNotSame( $post_key, $system_key );
		$this->assertNotSame( $tax_key, $system_key );
	}

	public function test_malformed_cached_response_is_discarded_instead_of_hashed_or_echoed(): void {
		$orchestrator = new Orchestrator();
		$key_method   = new \ReflectionMethod( Orchestrator::class, 'get_response_cache_key' );
		$read_method  = new \ReflectionMethod( Orchestrator::class, 'get_cached_response' );
		$cache_key    = $key_method->invoke( $orchestrator, 'index', 1 );
		CacheManager::put( $cache_key, array( '<xml/>' ), HOUR_IN_SECONDS, 'sitemap' );

		$this->assertNull( $read_method->invoke( $orchestrator, $cache_key ) );

		CacheManager::put( $cache_key, '<valid/>', HOUR_IN_SECONDS, 'sitemap' );
		$this->assertSame( '<valid/>', $read_method->invoke( $orchestrator, $cache_key ) );
		$this->assertStringContainsString( 'CacheManager::get(', $this->method_source( 'get_cached_response' ) );
	}

	public function test_generation_fence_rejects_a_sitemap_response_built_before_invalidation(): void {
		$cache_key  = 'cybermaps_test_sitemap_generation_fence';
		$generation = CacheManager::get_generation( 'sitemap', true );

		CacheManager::clear_family( 'sitemap' );
		$this->assertFalse(
			CacheManager::set_if_current(
				$cache_key,
				'<urlset/>',
				HOUR_IN_SECONDS,
				'sitemap',
				$generation
			)
		);
		$found = false;
		CacheManager::get( $cache_key, 'sitemap', $found );
		$this->assertFalse( $found );
	}

	public function test_public_get_handler_has_no_static_reconciliation_side_effect(): void {
		$source = $this->request_pipeline_source();

		$this->assertStringNotContainsString( 'request_sync', $source );
		$this->assertStringNotContainsString( 'file_exists', $source );
		$this->assertStringContainsString(
			'Content-Type: application/xml; charset=utf-8',
			$source
		);
		$this->assertStringNotContainsString( 'blog_charset', $source );
		$this->assertSame( 1, substr_count( $source, 'Integrity::send_representation_headers' ) );
		$this->assertStringContainsString( "PublicationCachePolicy::for_publication( 'sitemap'", $source );
		$this->assertStringContainsString( 'CacheManager::set_if_current(', $source );
		$this->assertStringNotContainsString( 'HTTP_IF_NONE_MATCH', $source );
	}

	public function test_legacy_routes_enforce_read_only_methods_before_redirect_or_404(): void {
		$source         = $this->method_source( 'handle_legacy_redirects' );
		$enforce        = strpos( $source, 'ReadOnlyRequest::enforce' );
		$first_redirect = strpos( $source, 'wp_safe_redirect' );
		$first_404      = strpos( $source, 'issue_404' );

		$this->assertNotFalse( $enforce );
		$this->assertNotFalse( $first_redirect );
		$this->assertNotFalse( $first_404 );
		$this->assertLessThan( $first_redirect, $enforce );
		$this->assertLessThan( $first_404, $enforce );
	}

	public function test_404_helper_returns_a_small_theme_independent_response(): void {
		$source = $this->method_source( 'issue_404' );

		$this->assertStringNotContainsString( 'get_404_template()', $source );
		$this->assertStringContainsString( 'Content-Type: text/plain; charset=utf-8', $source );
		$this->assertStringContainsString( 'ReadOnlyRequest::is_head()', $source );
	}

	public function test_disabled_news_route_uses_the_shared_safe_404_helper(): void {
		$source = $this->request_pipeline_source();

		$this->assertStringNotContainsString( 'include get_404_template()', $source );
		$this->assertStringContainsString( '$this->issue_404()', $source );
	}

	public function test_completed_manifest_authorizes_only_recorded_non_empty_child_pages(): void {
		$orchestrator = new Orchestrator();
		$method       = new \ReflectionMethod( Orchestrator::class, 'manifest_contains_child_page' );
		$record       = array( 'non_empty_pages' => array( '2', 4 ) );

		$this->assertFalse( $method->invoke( $orchestrator, $record, 1 ) );
		$this->assertTrue( $method->invoke( $orchestrator, $record, 2 ) );
		$this->assertTrue( $method->invoke( $orchestrator, $record, 4 ) );
		$this->assertFalse( $method->invoke( $orchestrator, array(), 1 ) );
	}

	public function test_ordinary_child_xml_must_contain_a_url_before_it_can_be_served_or_cached(): void {
		$orchestrator = new Orchestrator();
		$method       = new \ReflectionMethod( Orchestrator::class, 'child_xml_contains_url' );

		$this->assertFalse( $method->invoke( $orchestrator, '<?xml version="1.0"?><urlset></urlset>' ) );
		$this->assertTrue( $method->invoke( $orchestrator, '<?xml version="1.0"?><urlset><url></url></urlset>' ) );

		$source          = $this->request_pipeline_source();
		$occupancy_check = strpos( $source, 'manifest_contains_child_page' );
		$repair          = strpos( $source, 'request_repair_for_filename' );
		$cache_write     = strpos( $source, 'CacheManager::set' );
		$this->assertNotFalse( $occupancy_check );
		$this->assertNotFalse( $repair );
		$this->assertNotFalse( $cache_write );
		$this->assertLessThan( $repair, $occupancy_check );
		$this->assertStringContainsString( '! $this->child_xml_contains_url( $xml )', $source );
		$this->assertLessThan( $cache_write, strpos( $source, '! $this->child_xml_contains_url( $xml )' ) );
	}

	public function test_diagnostic_marker_requires_an_exact_random_challenge_and_disables_caching(): void {
		$method    = new \ReflectionMethod( Orchestrator::class, 'validate_diagnostic_challenge' );
		$challenge = 'AbCdEf0123456789AbCdEf0123456789';

		$this->assertSame( $challenge, $method->invoke( null, $challenge, $challenge ) );
		$this->assertSame( '', $method->invoke( null, $challenge, strtolower( $challenge ) ) );
		$this->assertSame( '', $method->invoke( null, 'sitemap-php-path', 'sitemap-php-path' ) );

		$source = $this->request_pipeline_source();
		$this->assertStringContainsString( 'HTTP_X_CYBERMAPS_DIAGNOSTIC_CHALLENGE', $source );
		$this->assertStringContainsString( 'cybermaps_php_path_probe', $source );
		$this->assertStringContainsString( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', $source );
	}

	private function method_source( string $method_name ): string {
		$method = new \ReflectionMethod( Orchestrator::class, $method_name );
		$lines  = file( (string) $method->getFileName() );
		if ( false === $lines ) {
			return '';
		}

		return implode(
			'',
			array_slice(
				$lines,
				$method->getStartLine() - 1,
				$method->getEndLine() - $method->getStartLine() + 1
			)
		);
	}

	private function request_pipeline_source(): string {
		$source = '';
		foreach (
			array(
				'handle_sitemap_request',
				'resolve_request_provider',
				'validate_network_request',
				'validate_sitemap_page',
				'validate_index_page',
				'child_page_count',
				'manifest_page_count',
				'prepare_sitemap_response',
				'cached_sitemap_response',
				'preloaded_child_urls',
				'begin_sitemap_response',
				'render_sitemap_response',
				'serve_sitemap_xml',
			) as $method_name
		) {
			$source .= $this->method_source( $method_name );
		}

		return $source;
	}
}
