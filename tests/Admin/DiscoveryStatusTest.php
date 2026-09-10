<?php
declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wp_safe_remote_get' ) ) {
		function wp_safe_remote_get( $url, $args = array() ) {
			$GLOBALS['cybermaps_mock_safe_remote_get_calls'][] = array(
				'url'  => $url,
				'args' => $args,
			);

			return $GLOBALS['cybermaps_mock_safe_remote_get_responses'][ $url ] ?? new \WP_Error();
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
		function wp_remote_retrieve_body( $response ) {
			return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
		function wp_remote_retrieve_header( $response, $header ) {
			if ( ! is_array( $response ) || ! is_array( $response['headers'] ?? null ) ) {
				return '';
			}

			foreach ( $response['headers'] as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, (string) $header ) ) {
					return $value;
				}
			}

			return '';
		}
	}
}

namespace Cybermaps\Tests\Admin {

	use Cybermaps\Admin\DiscoveryStatus;
	use Cybermaps\Core\EndpointRegistry;
	use Cybermaps\Core\URLManager;
	use Cybermaps\Discovery\APICatalog;
	use Cybermaps\Discovery\StaticBridge;
	use PHPUnit\Framework\TestCase;

	class DiscoveryStatusTest extends TestCase {

		/** @var array<string, mixed> */
		private array $prior_options = array();

		/** @var array<string, bool> */
		private array $prior_option_exists = array();

		/** @var array<string, mixed> */
		private array $prior_transients = array();

		protected function setUp(): void {
			parent::setUp();

			foreach (
				array(
					'cybermaps_settings',
					'cybermaps_static_hashes',
					'cybermaps_static_write_errors',
					'cybermaps_last_static_sync_report',
					'cybermaps_static_schedule_error',
					'cybermaps_last_static_sync_attempt',
					'cybermaps_last_static_sync',
					'cybermaps_static_generation',
					'cybermaps_static_ownership_revision',
				) as $option
			) {
				$sentinel                              = new \stdClass();
				$value                                 = get_option( $option, $sentinel );
				$this->prior_option_exists[ $option ]  = $sentinel !== $value;
				$this->prior_options[ $option ]        = $value;
			}
			$this->prior_transients = (array) ( $GLOBALS['cybermaps_mock_transients'] ?? array() );

			$GLOBALS['cybermaps_mock_safe_remote_get_calls']     = array();
			$GLOBALS['cybermaps_mock_safe_remote_get_responses'] = array();
			$GLOBALS['cybermaps_mock_safe_remote_head_calls']    = array();
			$GLOBALS['cybermaps_mock_transients']                = array();

			update_option(
				'cybermaps_settings',
				array(
					'enable_discovery_hub' => '1',
					'enable_llms_full'     => '1',
					'enable_llms_tldr'     => '1',
					'static_engine_mode'   => 'off',
				)
			);
			update_option( 'cybermaps_static_hashes', array() );
			update_option( 'cybermaps_static_write_errors', array() );
			delete_option( 'cybermaps_last_static_sync_report' );
			delete_option( 'cybermaps_static_schedule_error' );
			delete_option( 'cybermaps_last_static_sync_attempt' );
			delete_option( 'cybermaps_last_static_sync' );

			$this->install_valid_public_responses();
		}

		protected function tearDown(): void {
			foreach ( $this->prior_options as $option => $value ) {
				if ( $this->prior_option_exists[ $option ] ) {
					update_option( $option, $value );
				} else {
					delete_option( $option );
				}
			}

			$GLOBALS['cybermaps_mock_transients'] = $this->prior_transients;
			unset(
				$GLOBALS['cybermaps_mock_safe_remote_get_calls'],
				$GLOBALS['cybermaps_mock_safe_remote_get_responses'],
				$GLOBALS['cybermaps_mock_safe_remote_head_calls'],
				$GLOBALS['cybermaps_mock_safe_remote_head_response'],
				$GLOBALS['cybermaps_mock_get_option_observer']
			);

			parent::tearDown();
		}

		public function test_inventory_covers_canonical_and_alternate_registered_publications(): void {
			$status = ( new DiscoveryStatus() )->get_status_data( true );
			$paths  = array_column( $status['endpoints'], 'path' );

			$this->assertContains( '/ai.json', $paths );
			$this->assertContains( '/ai-discovery', $paths );
			$this->assertContains( '/.well-known/api-catalog', $paths );
			$this->assertContains( '/api-catalog', $paths );
			$this->assertNotContains( '/.well-known/ai.json', $paths );
			$this->assertNotContains( '/.well-known/ai-plugin.json', $paths );
			$this->assertContains( '/.well-known/mcp/server-card.json', $paths );
			$this->assertContains( '/.well-known/agent-skills/index.json', $paths );
			$this->assertContains( '/.well-known/agent-skills/cybermaps-site-guide/SKILL.md', $paths );
			$this->assertContains( '/ai-discovery.json', $paths );
				$this->assertContains( '/updates.json', $paths );
				$this->assertContains( '/news/llms.txt', $paths );
				$this->assertContains( '/news/speakable.json', $paths );
				$this->assertContains( '/news/changelog.json', $paths );
				$this->assertContains( '/news/archive.jsonl', $paths );
			$this->assertContains( '/knowledge-graph.json', $paths );
			$this->assertContains( '/feed.json', $paths );
			$this->assertContains( '/ai-sitemap.xml', $paths );
			$this->assertSame( count( $paths ) - 4, $status['active_count'] );
			$this->assertSame( 0, $status['error_count'] );
			$this->assertNotEmpty( $GLOBALS['cybermaps_mock_safe_remote_get_calls'] );
			$this->assertSame(
				'1',
				$GLOBALS['cybermaps_mock_safe_remote_get_calls'][0]['args']['headers']['X-Cybermaps-Diagnostic']
			);
		}

		public function test_public_validation_reports_dynamic_delivery_and_accepts_text_xml(): void {
			$status  = ( new DiscoveryStatus() )->get_status_data( true );
			$sitemap = $this->find_path( $status['endpoints'], '/ai-sitemap.xml' );

			$this->assertSame( 'healthy', $sitemap['status'] );
			$this->assertSame( 'text/xml; charset=UTF-8', $sitemap['content_type'] );
			$this->assertTrue( $sitemap['content_type_valid'] );
			$this->assertTrue( $sitemap['body_valid'] );
			$this->assertSame( 'dynamic', $sitemap['delivery'] );
		}

		public function test_jsonl_validation_requires_object_records(): void {
			$validator = new \ReflectionMethod( DiscoveryStatus::class, 'validate_body' );
			$status    = new DiscoveryStatus();

			$valid = $validator->invoke( $status, "{\"id\":1}\n", 'jsonl', 'adp_news_archive' );
			$this->assertTrue( $valid['valid'] );

			foreach ( array( 'null', '"string"', '42', '[{"id":1}]' ) as $record ) {
				$result = $validator->invoke( $status, $record . "\n", 'jsonl', 'adp_news_archive' );
				$this->assertFalse( $result['valid'], $record );
				$this->assertSame( 'Each JSONL record must be a valid JSON object.', $result['message'] );
			}
		}

		public function test_wrong_static_server_media_type_is_a_confirmed_error(): void {
			$url = URLManager::get_home_url( '/skill.md' );
			$GLOBALS['cybermaps_mock_safe_remote_get_responses'][ $url ]['headers']['Content-Type'] = 'application/octet-stream';

			$status = ( new DiscoveryStatus() )->get_status_data( true );
			$skill  = $this->find_path( $status['endpoints'], '/skill.md' );

			$this->assertSame( 'error', $skill['status'] );
			$this->assertFalse( $skill['content_type_valid'] );
			$this->assertStringContainsString( 'text/markdown', $skill['message'] );
		}

		public function test_api_catalog_requires_profile_media_parameter(): void {
			$url = URLManager::get_home_url( '/.well-known/api-catalog' );
			$GLOBALS['cybermaps_mock_safe_remote_get_responses'][ $url ]['headers']['Content-Type'] = 'application/linkset+json';

			$status  = ( new DiscoveryStatus() )->get_status_data( true );
			$catalog = $this->find_path( $status['endpoints'], '/.well-known/api-catalog' );

			$this->assertSame( 'error', $catalog['status'] );
			$this->assertStringContainsString( 'profile parameter', $catalog['message'] );
		}

		public function test_api_catalog_requires_head_link_relation(): void {
			$GLOBALS['cybermaps_mock_safe_remote_head_response']['headers']['Link'] = '<https://example.com/llms.txt>; rel="discovery"';

			$status  = ( new DiscoveryStatus() )->get_status_data( true );
			$catalog = $this->find_path( $status['endpoints'], '/.well-known/api-catalog' );

			$this->assertSame( 'error', $catalog['status'] );
			$this->assertStringContainsString( 'rel="api-catalog"', $catalog['message'] );
		}

		public function test_exact_owned_public_body_is_observed_as_static(): void {
			update_option(
				'cybermaps_settings',
				array(
					'enable_discovery_hub' => '1',
					'static_engine_mode'   => 'well_known',
				)
			);

			$body = '{"ok":true}';
			$path = StaticBridge::get_instance()->get_file_path( 'ai.json' );
			wp_mkdir_p( dirname( $path ) );
			WP_Filesystem();
			global $wp_filesystem;
			$wp_filesystem->put_contents( $path, $body );
			update_option( 'cybermaps_static_hashes', array( 'ai.json' => md5( $body ) ) );

			$url = URLManager::get_home_url( '/ai.json' );
			unset( $GLOBALS['cybermaps_mock_safe_remote_get_responses'][ $url ]['headers']['X-Cybermaps-Version'] );

			try {
				$status   = ( new DiscoveryStatus() )->get_status_data( true );
				$manifest = $this->find_path( $status['endpoints'], '/ai.json' );

				$this->assertSame( 'healthy', $manifest['status'] );
				$this->assertSame( 'static', $manifest['intended_delivery'] );
				$this->assertTrue( $manifest['on_disk'] );
				$this->assertSame( 'static', $manifest['delivery'] );
			} finally {
				wp_delete_file( $path );
				update_option( 'cybermaps_static_hashes', array() );
			}
		}

		public function test_static_header_conformance_is_reported_separately_from_a_valid_body(): void {
			update_option(
				'cybermaps_settings',
				array(
					'enable_discovery_hub' => '1',
					'static_engine_mode'   => 'well_known',
				)
			);

			$status   = ( new DiscoveryStatus() )->get_status_data( true );
			$manifest = $this->find_path( $status['endpoints'], '/ai.json' );

			$this->assertSame( 'healthy', $manifest['status'] );
			$this->assertSame( 'error', $manifest['header_status'] );
		$this->assertStringContainsString( 'Cache-Control', $manifest['header_message'] );
			$this->assertArrayHasKey( 'snippets', $status['header_manifest'] );
		}

		public function test_loopback_error_is_unverified_instead_of_broken(): void {
			$url = URLManager::get_home_url( '/llms.txt' );
			$GLOBALS['cybermaps_mock_safe_remote_get_responses'][ $url ] = new \WP_Error();

			$status = ( new DiscoveryStatus() )->get_status_data( true );
			$llms   = $this->find_path( $status['endpoints'], '/llms.txt' );

			$this->assertSame( 'unverified', $llms['status'] );
			$this->assertSame( 'unverified', $llms['delivery'] );
			$this->assertStringContainsString( 'not proven broken', $llms['message'] );
			$this->assertFalse( $status['healthy'] );
			$this->assertSame( 'unverified', $status['overall_status'] );
		}

		public function test_environment_notice_describes_the_current_mime_safe_delivery_model(): void {
			$method  = new \ReflectionMethod( DiscoveryStatus::class, 'get_environment_notices' );
			$notices = $method->invoke( new DiscoveryStatus(), array(), 0 );
			$message = implode( ' ', array_column( $notices, 'message' ) );

			$this->assertStringContainsString( 'canonical discovery fallback bodies', $message );
			$this->assertStringContainsString( 'optional edge rules report or repair headers', $message );
			$this->assertStringNotContainsString( 'extensionless static files require', $message );
		}

		public function test_repeated_loopback_transport_failures_open_a_bounded_circuit(): void {
			foreach ( $GLOBALS['cybermaps_mock_safe_remote_get_responses'] as $url => $response ) {
				unset( $response );
				$GLOBALS['cybermaps_mock_safe_remote_get_responses'][ $url ] = new \WP_Error();
			}

			$status = ( new DiscoveryStatus() )->get_status_data( true );
			$active = array_filter(
				$status['endpoints'],
				static fn ( array $endpoint ): bool => ! empty( $endpoint['enabled'] )
			);
			$skipped = array_filter(
				$active,
				static fn ( array $endpoint ): bool =>
					str_contains( (string) ( $endpoint['message'] ?? '' ), 'was skipped' )
			);

			$this->assertCount( 2, $GLOBALS['cybermaps_mock_safe_remote_get_calls'] );
			$this->assertCount( count( $active ) - 2, $skipped );
			$this->assertSame( count( $active ), $status['unverified_count'] );
			$this->assertSame( 'unverified', $status['overall_status'] );
			$this->assertSame( array(), $GLOBALS['cybermaps_mock_safe_remote_head_calls'] );
		}

		public function test_cached_status_does_not_read_the_large_ownership_inventory(): void {
			$hashes = array();
			for ( $index = 1; $index <= 5000; ++$index ) {
				$hashes[ 'discovery/chunks/' . $index . '.json' ] = md5( (string) $index );
			}
			update_option( 'cybermaps_static_hashes', $hashes );
			update_option( 'cybermaps_static_ownership_revision', 42 );

			$expected = ( new DiscoveryStatus() )->get_status_data( true );
			$hash_option_reads = 0;
			$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$hash_option_reads ): void {
				if ( 'cybermaps_static_hashes' === $option ) {
					++$hash_option_reads;
				}
			};

			$cached = ( new DiscoveryStatus() )->get_status_data();

			$this->assertSame( $expected, $cached );
			$this->assertSame( 0, $hash_option_reads );
		}

		public function test_unresolved_headless_static_path_is_not_reported_as_absent(): void {
			update_option(
				'cybermaps_settings',
				array(
					'enable_discovery_hub' => '1',
					'static_engine_mode'   => 'well_known',
					'frontend_base_url'    => 'https://frontend.example/app',
				)
			);
			$this->install_valid_public_responses();

			$status   = ( new DiscoveryStatus() )->get_status_data( true );
			$manifest = $this->find_path( $status['endpoints'], '/ai.json' );

			$this->assertSame( 'static', $manifest['intended_delivery'] );
			$this->assertNull( $manifest['on_disk'] );
			$this->assertSame( 'pending', $status['sync_status'] );
		}

		public function test_missing_intended_files_override_an_old_complete_report(): void {
			update_option(
				'cybermaps_settings',
				array(
					'enable_discovery_hub' => '1',
					'static_engine_mode'   => 'well_known',
				)
			);
			update_option(
				'cybermaps_last_static_sync_report',
				array(
					'status'  => 'complete',
					'success' => true,
					'mode'    => 'well_known',
					'counts'  => array(),
				)
			);

			$status = ( new DiscoveryStatus() )->get_status_data( true );

			$this->assertSame( 'missing', $status['sync_status'] );
			$this->assertSame( 'complete', $status['sync_report']['status'] );
		}

		public function test_schedule_failure_is_exposed_from_structured_diagnostic(): void {
			update_option(
				'cybermaps_settings',
				array(
					'enable_discovery_hub' => '1',
					'static_engine_mode'   => 'well_known',
				)
			);
			update_option(
				'cybermaps_static_schedule_error',
				array(
					'status'  => 'error',
					'code'    => 'schedule_failed',
					'message' => 'Cron rejected the event.',
				)
			);

			$status = ( new DiscoveryStatus() )->get_status_data( true );

			$this->assertSame( 'schedule_error', $status['sync_status'] );
			$this->assertSame( 'Cron rejected the event.', $status['schedule_error']['message'] );
		}

		public function test_report_from_an_older_settings_generation_is_stale(): void {
			update_option( 'cybermaps_static_generation', 8 );

			$method = new \ReflectionMethod( DiscoveryStatus::class, 'get_sync_status' );
			$status = $method->invoke(
				new DiscoveryStatus(),
				true,
				'well_known',
				array(
					'status'     => 'complete',
					'success'    => true,
					'mode'       => 'well_known',
					'generation' => 7,
				),
				array(),
				array()
			);

			$this->assertSame( 'stale', $status );
		}

		/**
		 * Install a valid response for every path and alias in the registry.
		 */
		private function install_valid_public_responses(): void {
			foreach ( EndpointRegistry::get_instance()->get_path_publications() as $id => $definition ) {
				$paths = array_merge(
					array( (string) $definition['path'] ),
					(array) ( $definition['aliases'] ?? array() )
				);

				foreach ( $paths as $path ) {
					$format       = (string) ( $definition['format'] ?? 'text' );
					$content_type = (string) ( $definition['type'] ?? 'text/plain' ) . '; charset=UTF-8';
					$body         = 'Healthy publication';

						if ( 'json' === $format ) {
							$body = '{"ok":true}';
						} elseif ( 'jsonl' === $format ) {
							$body = "{\"ok\":true}\n";
						} elseif ( 'xml' === $format ) {
						$body         = '<?xml version="1.0"?><urlset></urlset>';
						$content_type = 'text/xml; charset=UTF-8';
					}

					if ( 'api_catalog' === $id ) {
						$content_type = APICatalog::get_media_type();
						$body         = '{"linkset":[{"anchor":"https://example.com/.well-known/api-catalog","item":[{"href":"https://example.com/wp-json/cybermaps/v1/discovery"}]}]}';
					}

					$GLOBALS['cybermaps_mock_safe_remote_get_responses'][ URLManager::get_home_url( $path ) ] = array(
						'response' => array( 'code' => 200 ),
						'headers'  => array(
							'Content-Type'        => $content_type,
							'X-Cybermaps-Version' => CYBERMAPS_VERSION,
						),
						'body'     => $body,
					);
				}
			}

			$GLOBALS['cybermaps_mock_safe_remote_head_response'] = array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(
					'Link' => '<https://example.com/.well-known/api-catalog>; rel="api-catalog"',
				),
			);
		}

		/**
		 * @param array<int, array<string, mixed>> $endpoints Endpoint status rows.
		 * @return array<string, mixed>
		 */
		private function find_path( array $endpoints, string $path ): array {
			foreach ( $endpoints as $endpoint ) {
				if ( ( $endpoint['path'] ?? '' ) === $path ) {
					return $endpoint;
				}
			}

			$this->fail( 'Missing endpoint status for ' . $path );
		}
	}
}
