<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Discovery\ADPNews;
use Cybermaps\Discovery\DiscoveryPublicationGenerator;

final class ADPNewsTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'llms_included_types' => array() ),
		);
	}

	public function test_level_three_news_bodies_are_nonempty_bounded_and_parseable(): void {
		$news = new ADPNews();
		$llms = $news->get_content( 'adp_news_llms' );
		$this->assertStringContainsString( '# Mock Site News', $llms );
		$this->assertStringContainsString( '/news/archive.jsonl', $llms );
		$speakable = json_decode( $news->get_content( 'adp_news_speakable' ), true );
		$this->assertSame( 'ItemList', $speakable['@type'] ?? null );
		$this->assertSame( 0, $speakable['numberOfItems'] ?? null );
		$changelog = json_decode( $news->get_content( 'adp_news_changelog' ), true );
		$this->assertSame( '3.0', $changelog['version'] ?? null );
		$this->assertSame( CYBERMAPS_VERSION, $changelog['currentVersion'] ?? null );
		$archive = trim( $news->get_content( 'adp_news_archive' ) );
		$this->assertNotSame( '', $archive );
		foreach ( preg_split( '/\R/', $archive ) ?: array() as $line ) {
			$this->assertIsArray( json_decode( $line, true ) );
		}
		$this->assertLessThanOrEqual( 512 * 1024, strlen( $llms ) );
		$this->assertLessThanOrEqual( 512 * 1024, strlen( $archive ) );
	}

	public function test_registry_and_static_generator_cover_every_news_publication(): void {
		$registry   = EndpointRegistry::get_instance();
		$generator  = new DiscoveryPublicationGenerator();
		$well_known = array_column( $registry->get_static_targets( 'well_known' ), 'path' );
		$all         = array_column( $registry->get_static_targets( 'all' ), 'path' );
		$expected    = array(
			'adp_news_llms' => '/news/llms.txt', 'adp_news_speakable' => '/news/speakable.json',
			'adp_news_changelog' => '/news/changelog.json', 'adp_news_archive' => '/news/archive.jsonl',
		);
		$this->assertCount( 8, $well_known );
		$this->assertContains( '/ai-discovery', $well_known );
		$this->assertContains( '/.well-known/api-catalog', $well_known );
		$this->assertContains( '/.well-known/agent-skills/index.json', $well_known );
		foreach ( $expected as $id => $path ) {
			$this->assertNotContains( $path, $well_known );
			$this->assertContains( $path, $all );
			$this->assertSame( $id, $registry->match_path( $path )['id'] ?? null );
			$this->assertNotSame( '', $generator->generate( $id, array( 'path' => $path ), (array) get_option( 'cybermaps_settings', array() ) ) );
		}
	}
}
