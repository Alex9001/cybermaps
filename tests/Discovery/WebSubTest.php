<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\WebSub;
use PHPUnit\Framework\TestCase;

class WebSubTest extends TestCase {
	/**
	 * @var array<string, mixed>
	 */
	private array $original_options;

	protected function setUp(): void {
		parent::setUp();

		global $cybermaps_mock_options;
		$this->original_options = $cybermaps_mock_options;

		$GLOBALS['cybermaps_mock_http_url_validation']     = array();
		$GLOBALS['cybermaps_mock_safe_remote_post_calls']  = array();
		$GLOBALS['cybermaps_mock_safe_remote_post_response'] = array(
			'response' => array( 'code' => 202 ),
		);
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array( 'public' => true );
	}

	protected function tearDown(): void {
		global $cybermaps_mock_options;
		$cybermaps_mock_options = $this->original_options;

		unset(
			$GLOBALS['cybermaps_mock_http_url_validation'],
			$GLOBALS['cybermaps_mock_safe_remote_post_calls'],
			$GLOBALS['cybermaps_mock_safe_remote_post_response']
		);

		parent::tearDown();
	}

	public function test_get_hubs_keeps_only_unique_safe_https_urls(): void {
		global $cybermaps_mock_options;

		$private_url = 'http://127.0.0.1/hub';
		$GLOBALS['cybermaps_mock_http_url_validation'][ $private_url ] = false;
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'enable_websub' => '1',
			'websub_hubs'   => implode(
				"\n",
				array(
					'https://hub.example.com/',
					$private_url,
					'http://public.example.com/hub',
					'javascript:alert(1)',
					'https://hub.example.com/',
					'https://second.example.net/websub',
				)
			),
		);

		$this->assertSame(
			array(
				'https://hub.example.com/',
				'https://second.example.net/websub',
			),
			( new WebSub() )->get_hubs()
		);
	}

	public function test_bulk_ping_uses_safe_transport_for_the_canonical_feed_topic(): void {
		global $cybermaps_mock_options;

		$cybermaps_mock_options['cybermaps_settings'] = array(
			'enable_websub'       => '1',
			'enable_discovery_hub'=> '1',
			'websub_hubs'         => 'https://hub.example.com/',
		);

		( new WebSub() )->notify_change();

		$calls = $GLOBALS['cybermaps_mock_safe_remote_post_calls'];
		$this->assertCount( 1, $calls );
		$this->assertSame(
			array(
				'https://example.com/feed.json',
			),
			array_map(
				static fn ( array $call ): string => $call['args']['body']['hub.url'],
				$calls
			)
		);
	}

	public function test_bulk_ping_does_not_advertise_a_disabled_feed(): void {
		global $cybermaps_mock_options;

		$cybermaps_mock_options['cybermaps_settings'] = array(
			'enable_websub'        => '1',
			'enable_discovery_hub' => '0',
			'websub_hubs'          => 'https://hub.example.com/',
		);

		( new WebSub() )->notify_change();

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_safe_remote_post_calls'] );
	}

	public function test_notify_change_pings_the_dynamic_feed_in_every_static_mode(): void {
		global $cybermaps_mock_options;

		foreach ( array( 'off', 'well_known', 'all' ) as $mode ) {
			$cybermaps_mock_options['cybermaps_settings'] = array(
				'enable_websub'        => '1',
				'enable_discovery_hub' => '1',
				'websub_hubs'          => 'https://hub.example.com/',
				'static_engine_mode'   => $mode,
			);
			$GLOBALS['cybermaps_mock_safe_remote_post_calls'] = array();

			( new WebSub() )->notify_change();

			$this->assertCount( 1, $GLOBALS['cybermaps_mock_safe_remote_post_calls'], $mode );
		}
	}

}
