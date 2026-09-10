<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\Updates;

final class UpdatesTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'ai_feed_limit'        => 7,
			),
		);
		$GLOBALS['cybermaps_mock_transients']       = array();
		$GLOBALS['cybermaps_mock_wp_query_args']    = array();
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn(): array => array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
		parent::tearDown();
	}

	public function test_update_stream_is_bounded_to_current_seven_day_window(): void {
		$data = ( new Updates() )->get_updates_data();

		$this->assertSame( '3.0', $data['version'] );
		$this->assertSame( '7d', $data['updateWindow'] );
		$this->assertSame( array(), $data['updates'] );
		$this->assertSame(
			'post_modified_gmt',
			$GLOBALS['cybermaps_mock_wp_query_args'][0]['date_query'][0]['column']
		);
	}
}
