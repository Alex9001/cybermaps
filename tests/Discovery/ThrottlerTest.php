<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\Throttler;
use PHPUnit\Framework\TestCase;

final class ThrottlerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_discovery_hub'  => '1',
				'enable_llms_tldr'      => '1',
				'enable_llms_full'      => '1',
				'enable_rag_chunks'     => '1',
				'enable_multilingual_hub' => '1',
				'enable_markdown_negotiation' => '1',
			),
			'cybermaps_robots_manager' => array(
				'overrides' => array(
					'gptbot' => array( 'tpm' => 10 ),
				),
			),
		);
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_object_cache'] = array();
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = false;
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 compatible; GPTBot/1.0';
		$_SERVER['REQUEST_URI']     = '/llms-tldr.txt';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.10';
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ACCEPT'] );
		unset(
			$GLOBALS['cybermaps_mock_is_multisite'],
			$GLOBALS['cybermaps_mock_is_main_site'],
			$GLOBALS['cybermaps_mock_site_options']
		);
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = false;
		$GLOBALS['cybermaps_mock_object_cache'] = array();

		parent::tearDown();
	}

	public function test_per_bot_override_uses_the_current_request_path(): void {
		( new Throttler() )->check_throttle();

		$keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn ( string $key ): bool => str_starts_with( $key, 'cm_tpm_gptbot_expensive_' )
			)
		);

		$this->assertCount( 1, $keys );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_transients'][ $keys[0] ] );
	}

	public function test_aliases_use_the_registry_throttle_tier(): void {
		$_SERVER['REQUEST_URI'] = '/ai.json';

		( new Throttler() )->check_throttle();

		$keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn ( string $key ): bool => str_starts_with( $key, 'cm_tpm_gptbot_cheap_' )
			)
		);
		$this->assertCount( 1, $keys );
	}

	public function test_negotiated_canonical_page_uses_the_medium_tier(): void {
		$_SERVER['REQUEST_URI'] = '/guide/';
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown, text/html;q=0.8';

		( new Throttler() )->check_throttle();

		$keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn ( string $key ): bool => str_starts_with( $key, 'cm_tpm_gptbot_medium_' )
			)
		);
		$this->assertCount( 1, $keys );
	}

	public function test_localized_tldr_fallback_uses_the_expensive_tier(): void {
		$_SERVER['REQUEST_URI'] = '/es/llms-tldr.txt';

		( new Throttler() )->check_throttle();

		$keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn ( string $key ): bool => str_starts_with( $key, 'cm_tpm_gptbot_expensive_' )
			)
		);
		$this->assertCount( 1, $keys );
	}

	public function test_cybermaps_rest_requests_are_throttled_before_dispatch(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager']['overrides']['gptbot']['tpm'] = 1;
		$request = new class() {
			public function get_route(): string {
				return '/cybermaps/v1/discovery';
			}
		};
		$throttler = new Throttler();

		$this->assertNull( $throttler->check_rest_throttle( null, null, $request ) );
		$this->assertInstanceOf(
			\WP_Error::class,
			$throttler->check_rest_throttle( null, null, $request )
		);
	}

	public function test_external_object_cache_uses_atomic_add_and_increment(): void {
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		$GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager']['overrides']['gptbot']['tpm'] = 1;
		$request = new class() {
			public function get_route(): string {
				return '/cybermaps/v1/discovery';
			}
		};
		$throttler = new Throttler();

		$this->assertNull( $throttler->check_rest_throttle( null, null, $request ) );
		$this->assertInstanceOf( \WP_Error::class, $throttler->check_rest_throttle( null, null, $request ) );

		$counts = array_values(
			array_filter(
				$GLOBALS['cybermaps_mock_object_cache'],
				static fn( mixed $value, string $key ): bool => str_starts_with( $key, 'cybermaps_atomic_counter:cm_tpm_' ),
				ARRAY_FILTER_USE_BOTH
			)
		);
		$this->assertSame( array( 2 ), $counts );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_transients'] );
	}

	public function test_localized_full_fallback_uses_the_expensive_tier(): void {
		$_SERVER['REQUEST_URI'] = '/fr/llms-full.txt';
		( new Throttler() )->check_throttle();

		$this->assertNotEmpty(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn ( string $key ): bool => str_starts_with( $key, 'cm_tpm_gptbot_expensive_' )
			)
		);
	}

	public function test_disabled_localized_full_does_not_consume_a_counter(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_llms_full'] = '0';
		$_SERVER['REQUEST_URI'] = '/fr/llms-full.txt';

		( new Throttler() )->check_throttle();

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_transients'] );
	}

	public function test_rest_requests_use_their_registry_throttle_tier(): void {
		$request = new class() {
			public function get_route(): string {
				return '/cybermaps/v1/llms-tldr';
			}
		};

		( new Throttler() )->check_rest_throttle( null, null, $request );

		$keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn( string $key ): bool => str_starts_with( $key, 'cm_tpm_gptbot_expensive_' )
			)
		);
		$this->assertCount( 1, $keys );
	}

	public function test_same_bot_from_different_clients_has_independent_counter(): void {
		$throttler = new Throttler();
		$throttler->check_throttle();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		$throttler->check_throttle();

		$keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn( string $key ): bool => str_starts_with( $key, 'cm_tpm_gptbot_expensive_' )
			)
		);
		$this->assertCount( 2, $keys );
		$this->assertSame( array( 1, 1 ), array_values( array_intersect_key( $GLOBALS['cybermaps_mock_transients'], array_flip( $keys ) ) ) );
	}

	public function test_unidentified_and_empty_user_agents_still_receive_cost_protection(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'ExampleClient/1.0';
		$throttler = new Throttler();
		$throttler->check_throttle();

		$_SERVER['HTTP_USER_AGENT'] = '';
		$throttler->check_throttle();

		$keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn( string $key ): bool => str_starts_with( $key, 'cm_tpm_unidentified_expensive_' )
			)
		);
		$this->assertCount( 1, $keys );
		$this->assertSame( 2, $GLOBALS['cybermaps_mock_transients'][ $keys[0] ] );
	}

	public function test_malformed_user_agent_and_override_shapes_fail_closed_without_warnings(): void {
		$_SERVER['HTTP_USER_AGENT'] = array( 'GPTBot/1.0' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager']['overrides'] = array(
			'gptbot' => new \stdClass(),
		);

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			( new Throttler() )->check_throttle();
		} finally {
			restore_error_handler();
		}

		$keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn ( string $key ): bool => str_starts_with(
					$key,
					'cm_tpm_unidentified_expensive_'
				)
			)
		);
		$this->assertCount( 1, $keys );
	}

	public function test_disabled_publications_do_not_consume_throttle_counters(): void {
		$throttler = new Throttler();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '0';
		$_SERVER['REQUEST_URI'] = '/ai.json';
		$throttler->check_throttle();

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '1';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_llms_tldr'] = '0';
		$_SERVER['REQUEST_URI'] = '/llms-tldr.txt';
		$throttler->check_throttle();

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_rag_chunks'] = '0';
		$_SERVER['REQUEST_URI'] = '/discovery/chunks/15.json';
		$throttler->check_throttle();

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_transients'] );
	}

	public function test_unsupported_fixed_path_method_does_not_consume_a_counter(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		( new Throttler() )->check_throttle();

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_transients'] );
	}

	public function test_disabled_public_rest_route_does_not_consume_a_counter(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '0';
		$request = new class() {
			public function get_route(): string {
				return '/cybermaps/v1/discovery';
			}
		};

		$this->assertNull( ( new Throttler() )->check_rest_throttle( null, null, $request ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_transients'] );
	}

	public function test_unsupported_public_rest_method_does_not_consume_a_counter(): void {
		$request = new class() {
			public function get_route(): string {
				return '/cybermaps/v1/discovery';
			}

			public function get_method(): string {
				return 'POST';
			}
		};

		$this->assertNull( ( new Throttler() )->check_rest_throttle( null, null, $request ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_transients'] );
	}

	public function test_every_canonical_sitemap_route_kind_is_throttled_but_the_retired_ambiguous_route_is_not(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'sitemap_url_base'      => 'machine-map',
			'news_sitemap_url_base' => 'press-map',
			'rss_sitemap_url_base'  => 'machine-feed',
			'enable_google_news'    => '1',
			'enable_rss_sitemap'    => '1',
		);
		$GLOBALS['cybermaps_mock_is_multisite'] = true;
		$GLOBALS['cybermaps_mock_is_main_site'] = true;
		$GLOBALS['cybermaps_mock_site_options']['cybermaps_network_settings'] = array(
			'enable_master_index' => '1',
		);
		$throttler = new Throttler();

		foreach (
			array(
				'/machine-map.xml',
				'/press-map.xml',
				'/machine-feed.xml',
				'/sitemap-network.xml',
				'/machine-map-misc.xml',
				'/machine-map-authors-1.xml',
				'/machine-map-posts-shared-1.xml',
				'/machine-map-taxonomies-shared-1.xml',
			) as $path
		) {
			$_SERVER['REQUEST_URI'] = $path;
			$throttler->check_throttle();
		}

		$keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_transients'] ),
				static fn( string $key ): bool => str_starts_with( $key, 'cm_tpm_gptbot_medium_' )
			)
		);
		$this->assertCount( 1, $keys );
		$this->assertSame( 8, $GLOBALS['cybermaps_mock_transients'][ $keys[0] ] );

		$_SERVER['REQUEST_URI'] = '/machine-map-shared-1.xml';
		$throttler->check_throttle();
		$this->assertSame( 8, $GLOBALS['cybermaps_mock_transients'][ $keys[0] ] );
	}
}
