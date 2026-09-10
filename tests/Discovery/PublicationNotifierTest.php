<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\IndexNow;
use Cybermaps\Discovery\PublicationNotifier;
use Cybermaps\Discovery\WebSub;

final class PublicationNotifierTest extends \WP_UnitTestCase {
	private NotifierIndexNowStub $indexnow;
	private NotifierWebSubStub $websub;
	private PublicationNotifier $notifier;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_hooks'] = array();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_indexnow'       => '1',
				'enable_websub'         => '1',
				'enable_discovery_hub'  => '1',
				'static_engine_mode'    => 'off',
				'frontend_base_url'     => 'https://frontend.example',
			),
		);
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post'      => (object) array( 'name' => 'post', 'public' => true ),
			'portfolio' => (object) array( 'name' => 'portfolio', 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_posts']     = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();

		$this->indexnow = new NotifierIndexNowStub();
		$this->websub   = new NotifierWebSubStub();
		$this->notifier = new PublicationNotifier( $this->indexnow, $this->websub );
	}

	public function test_hook_contract_captures_pre_write_state_and_flushes_at_shutdown(): void {
		$this->notifier->register_hooks();

		$this->assertHook( 'transition_post_status', 'action', 3 );
		$this->assertHook( 'before_delete_post', 'action', 2 );
		$this->assertHook( 'add_post_metadata', 'filter', 5 );
		$this->assertHook( 'update_post_metadata', 'filter', 5 );
		$this->assertHook( 'delete_post_metadata', 'filter', 5 );
		$this->assertHook( 'add_term_relationship', 'action', 3 );
		$this->assertHook( 'delete_term_relationships', 'action', 3 );
		$this->assertHook( 'shutdown', 'action', 1 );
	}

	public function test_publish_update_uses_final_meta_for_included_to_excluded_change(): void {
		$post = $this->post( 41, 'post', 'publish' );
		$this->notifier->queue_transition( 'publish', 'publish', $post );

		$GLOBALS['cybermaps_mock_post_meta'][41]['_cybermaps_exclude_sitemap'] = '1';
		$GLOBALS['cybermaps_mock_post_meta'][41]['_cybermaps_exclude_ai']      = '1';
		$this->notifier->flush();

		$this->assertSame( array( 'https://frontend.example/?p=41' ), $this->indexnow->urls );
		$this->assertSame( 1, $this->websub->notifications );
	}

	public function test_publish_update_uses_final_meta_for_excluded_to_included_change(): void {
		$post = $this->post( 42, 'post', 'publish' );
		$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_exclude_sitemap'] = '1';
		$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_exclude_ai']      = '1';
		$this->notifier->queue_transition( 'publish', 'publish', $post );

		unset(
			$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_exclude_sitemap'],
			$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_exclude_ai']
		);
		$this->notifier->flush();

		$this->assertSame( array( 'https://frontend.example/?p=42' ), $this->indexnow->urls );
		$this->assertSame( 1, $this->websub->notifications );
	}

	public function test_newly_published_but_finally_excluded_post_is_not_announced(): void {
		$post = $this->post( 43, 'post', 'publish' );
		$this->notifier->queue_transition( 'publish', 'draft', $post );
		$GLOBALS['cybermaps_mock_post_meta'][43]['_cybermaps_exclude_sitemap'] = '1';
		$GLOBALS['cybermaps_mock_post_meta'][43]['_cybermaps_exclude_ai']      = '1';

		$this->notifier->flush();

		$this->assertSame( array(), $this->indexnow->urls );
		$this->assertSame( 0, $this->websub->notifications );
	}

	public function test_direct_meta_write_captures_the_pre_write_inventory(): void {
		$this->post( 44, 'post', 'publish' );

		$result = $this->notifier->capture_before_meta_write(
			null,
			44,
			'_cybermaps_exclude_sitemap',
			'1',
			null
		);
		$GLOBALS['cybermaps_mock_post_meta'][44]['_cybermaps_exclude_sitemap'] = '1';
		$GLOBALS['cybermaps_mock_post_meta'][44]['_cybermaps_exclude_ai']      = '1';
		$this->notifier->flush();

		$this->assertNull( $result );
		$this->assertSame( array( 'https://frontend.example/?p=44' ), $this->indexnow->urls );
		$this->assertSame( 1, $this->websub->notifications );
	}

	public function test_non_feed_cpt_notifies_indexnow_but_not_websub(): void {
		$post = $this->post( 45, 'portfolio', 'publish' );
		$this->notifier->queue_transition( 'publish', 'draft', $post );

		$this->notifier->flush();

		$this->assertSame( array( 'https://frontend.example/?p=45' ), $this->indexnow->urls );
		$this->assertSame( 0, $this->websub->notifications );
	}

	public function test_taxonomy_relationship_change_uses_pre_write_inventory(): void {
		$this->post( 47, 'post', 'publish' );

		$this->notifier->capture_before_term_write( 47, array( 9 ), 'category' );
		$GLOBALS['cybermaps_mock_post_meta'][47]['_cybermaps_exclude_ai'] = '1';
		$this->notifier->flush();

		$this->assertSame( array( 'https://frontend.example/?p=47' ), $this->indexnow->urls );
		$this->assertSame( 1, $this->websub->notifications );
	}

	public function test_force_delete_is_delivered_after_the_row_is_removed(): void {
		$post = $this->post( 46, 'post', 'publish' );
		$this->notifier->queue_deletion( $post->ID, $post );
		unset( $GLOBALS['cybermaps_mock_posts'][46] );

		$this->notifier->flush();

		$this->assertSame( array( 'https://frontend.example/?p=46' ), $this->indexnow->urls );
		$this->assertSame( 1, $this->websub->notifications );
	}

	private function post( int $id, string $post_type, string $status ): object {
		$post = (object) array(
			'ID'            => $id,
			'post_type'     => $post_type,
			'post_status'   => $status,
			'post_password' => '',
		);
		$GLOBALS['cybermaps_mock_posts'][ $id ] = $post;
		return $post;
	}

	private function assertHook( string $hook, string $type, int $accepted_args ): void {
		$matches = array_values(
			array_filter(
				$GLOBALS['wp_hooks'],
				static fn ( array $record ): bool => $hook === $record['hook']
					&& $type === $record['type']
			)
		);
		$this->assertCount( 1, $matches, $hook );
		$this->assertSame( $accepted_args, $matches[0]['accepted_args'], $hook );
	}
}

final class NotifierIndexNowStub extends IndexNow {
	/** @var string[] */
	public array $urls = array();

	public function __construct() {}

	public function notify_url( string $url ): void {
		$this->urls[] = $url;
	}
}

final class NotifierWebSubStub extends WebSub {
	public int $notifications = 0;

	public function __construct() {}

	public function notify_change(): void {
		++$this->notifications;
	}
}
