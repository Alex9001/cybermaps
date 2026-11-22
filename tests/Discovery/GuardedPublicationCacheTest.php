<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\ADP;
use Cybermaps\Discovery\GuardedPublicationCache;
use Cybermaps\Discovery\Manager;
use Cybermaps\Discovery\Robots;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Discovery\StaticOwnershipStore;
use PHPUnit\Framework\Attributes\DataProvider;

final class GuardedPublicationCacheTest extends \WP_UnitTestCase {
	private StaticBridge $bridge;

	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'static_engine_mode' => 'all', 'enable_discovery_hub' => '1', 'enable_llms_full' => '1', 'enable_rag_chunks' => '1' ),
		);
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
		$GLOBALS['cybermaps_mock_scheduled'] = array();
		\cybermaps_mock_enable_static_ownership_database( true );
		$this->bridge = StaticBridge::get_instance();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback'] );
		\cybermaps_mock_disable_static_ownership_database();
		parent::tearDown();
	}

	public static function visibility_changes(): array {
		return array( 'marking private' => array( 'private' ), 'adding a password' => array( 'password' ) );
	}

	#[DataProvider( 'visibility_changes' )]
	public function test_origin_never_reopens_the_old_generation_while_waiting_for_a_successful_rebuild( string $change ): void {
		GuardedPublicationCache::retire_public_files();
		$post = (object) array( 'ID' => 31, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '' );
		$manager = new Manager( new ADP(), new Robots() );
		$files = array( 'llms-full.txt', 'de/llms-full.txt', 'discovery/chunks/31.json', 'news/archive.jsonl', 'sitemap-rss.xml' );
		foreach ( $files as $file ) {
			self::assertTrue( $this->bridge->write_file( $file, 'first publication' ), $file );
			self::assertSame( 'first publication', $this->bridge->read_current_publication( $file ) );
			self::assertFileDoesNotExist( ABSPATH . $file );
		}
		$previous = $this->bridge->get_file_path( 'llms-full.txt' );
		if ( 'private' === $change ) {
			$post->post_status = 'private';
			$manager->on_post_status_transition( 'private', 'publish', $post );
		} else {
			$post->post_password = 'fixture';
			$manager->on_post_saved( $post->ID, $post );
		}

		self::assertNotFalse( wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
		self::assertFileExists( $previous, 'The old body can still exist in private storage.' );
		foreach ( $files as $file ) {
			self::assertNull( $this->bridge->read_current_publication( $file ) );
		}
		$GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback'] = static fn(): bool => false;
		self::assertFalse( $this->bridge->write_file( 'llms-full.txt', 'current publication' ) );
		self::assertNull( $this->bridge->read_current_publication( 'llms-full.txt' ) );
		unset( $GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback'] );
		self::assertTrue( $this->bridge->write_file( 'llms-full.txt', 'current publication' ) );
		self::assertSame( 'current publication', $this->bridge->read_current_publication( 'llms-full.txt' ) );
		self::assertFileDoesNotExist( ABSPATH . 'llms-full.txt' );
	}

	public function test_a_family_change_alone_makes_the_old_file_ineligible(): void {
		self::assertTrue( $this->bridge->write_file( 'llms-full.txt', 'first' ) );
		self::assertSame( 'first', $this->bridge->read_current_publication( 'llms-full.txt' ) );
		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
		self::assertNull( $this->bridge->read_current_publication( 'llms-full.txt' ) );
	}

	public function test_upgrade_retires_verified_old_public_files_and_reports_a_changed_copy(): void {
		$store = new StaticOwnershipStore();
		$files = array( 'llms-full.txt', 'fr/llms-full.txt', 'discovery/chunks/31.json', 'llms-tldr.txt' );
		try {
			foreach ( $files as $file ) {
				wp_mkdir_p( dirname( ABSPATH . $file ) );
				file_put_contents( ABSPATH . $file, 'previous version' );
				self::assertTrue( $store->set_hash( $file, md5( 'previous version' ), 0, true ) );
			}
			file_put_contents( ABSPATH . 'llms-tldr.txt', 'owner edited' );
			GuardedPublicationCache::retire_public_files();
			foreach ( array_slice( $files, 0, 3 ) as $file ) {
				self::assertFileDoesNotExist( ABSPATH . $file );
			}
			self::assertSame( 'owner edited', file_get_contents( ABSPATH . 'llms-tldr.txt' ) );
			self::assertArrayHasKey( 'llms-tldr.txt', get_option( 'cybermaps_static_write_errors' ) );
			self::assertNotSame( 'complete', get_option( GuardedPublicationCache::MIGRATION_KEY ) );
			wp_delete_file( ABSPATH . 'llms-tldr.txt' );
			GuardedPublicationCache::retire_public_files();
			self::assertSame( 'complete', get_option( GuardedPublicationCache::MIGRATION_KEY ) );
		} finally {
			foreach ( $files as $file ) {
				wp_delete_file( ABSPATH . $file );
			}
		}
	}
}
