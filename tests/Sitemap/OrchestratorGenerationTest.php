<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Core\CacheManager;
use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\ProviderInterface;
use Cybermaps\Sitemap\ProviderIdentity;

final class OrchestratorGenerationTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_settings' => array( 'enable_caching' => '1' ) );
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'public' => true ) );
	}
	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_get_transient_observer'] );
	}
	public function test_dynamic_and_static_xml_reject_generation_changed_during_provider_selection(): void {
		foreach ( array( '0', '1' ) as $cache_enabled ) {
			\cybermaps_mock_reset_cache_runtime();
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_caching'] = $cache_enabled;
			$orchestrator = new Orchestrator();
			( new \ReflectionMethod( Orchestrator::class, 'begin_publication' ) )->invoke( $orchestrator );
			$provider = new class implements ProviderInterface {
				public function get_count(): int { return 1; }
				public function get_lastmod(): string { return ''; }
				public function get_urls( int $page ): array {
					CacheManager::clear_family( 'sitemap' );
					return array( array( 'loc' => 'https://example.com/private-after-selection/' ) );
				}
			};
			( new \ReflectionProperty( Orchestrator::class, 'providers' ) )->setValue( $orchestrator, array( ProviderIdentity::post_type( 'post' ) => $provider ) );
			$this->assertUnavailable( static fn() => $orchestrator->generate_xml( ProviderIdentity::post_type( 'post' ), 1 ) );
		}
	}
	public function test_cached_response_is_rejected_after_generation_changes_during_read(): void {
		$orchestrator = new Orchestrator();
		$begin = new \ReflectionMethod( Orchestrator::class, 'begin_publication' );
		$generation = $begin->invoke( $orchestrator );
		$key = ( new \ReflectionMethod( Orchestrator::class, 'get_response_cache_key' ) )->invoke( $orchestrator, 'index', 1 );
		$xml = '<sitemapindex><sitemap><loc>https://example.com/old.xml</loc></sitemap></sitemapindex>';
		CacheManager::set_if_current( $key, $xml, HOUR_IN_SECONDS, 'sitemap', $generation );
		$GLOBALS['cybermaps_mock_get_transient_observer'] = static function ( string $backend_key ) use ( $xml ): void {
			if ( ( $GLOBALS['cybermaps_mock_transients'][ $backend_key ]['value'] ?? null ) === $xml ) {
				unset( $GLOBALS['cybermaps_mock_get_transient_observer'] );
				CacheManager::clear_family( 'sitemap' );
			}
		};
		$prepare = new \ReflectionMethod( Orchestrator::class, 'prepare_sitemap_response' );
		$this->assertUnavailable( static fn() => $prepare->invoke( $orchestrator, 'index', '', null, 1 ) );
	}
	private function assertUnavailable( callable $read ): void {
		try { $read(); $this->fail( 'Changed sitemap generation must not return bytes.' ); }
		catch ( BuildUnavailableException ) { $this->addToAssertionCount( 1 ); }
	}
}
