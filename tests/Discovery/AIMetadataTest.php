<?php
declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_Post', false ) ) {
		class WP_Post {
			public int $ID = 0;
			public string $post_type = 'post';
			public string $post_status = 'publish';
			public string $post_password = '';
			public string $post_title = '';
			public string $post_content = '';
			public string $post_excerpt = '';
			public string $post_date_gmt = '';
			public string $post_modified_gmt = '';

			public function __construct( array $properties = array() ) {
				foreach ( $properties as $name => $value ) {
					$this->{$name} = $value;
				}
			}
		}
	}

	if ( ! function_exists( 'get_the_modified_date' ) ) {
		function get_the_modified_date( $format = '', $post_id = 0 ) {
			unset( $format );
			$post = get_post( (int) $post_id );
			return is_object( $post ) ? gmdate( 'c', strtotime( (string) $post->post_modified_gmt . ' UTC' ) ) : '';
		}
	}
}

namespace Cybermaps\Tests\Discovery {
		use Cybermaps\Discovery\AIMetadata;
		use Cybermaps\Discovery\AIContentSelector;
		use Cybermaps\Discovery\AISitemap;
		use Cybermaps\Discovery\Chunker;
		use Cybermaps\Sitemap\MediaScanner;

	final class AIMetadataTest extends \WP_UnitTestCase {
		protected function setUp(): void {
			parent::setUp();
			\cybermaps_mock_reset_cache_runtime();
			$GLOBALS['cybermaps_mock_options']   = array(
				'cybermaps_settings' => array( 'enable_content_hints' => '1' ),
				'cybermaps_media_audit_generation' => 1,
			);
				$GLOBALS['cybermaps_mock_posts']     = array();
				$GLOBALS['cybermaps_mock_post_meta'] = array();
				$GLOBALS['cybermaps_mock_transients'] = array();
				$this->install_post();
				$GLOBALS['cybermaps_mock_post_meta'][15]['_cybermaps_media_audit_generation'] = 1;
				$GLOBALS['cybermaps_mock_post_meta'][15]['_cybermaps_media_audit_mode'] = 'advanced';
		}

		public function test_content_hint_configuration_is_part_of_cache_validity(): void {
			$enabled = AIMetadata::refresh( 15 );
			$this->assertSame( '1', $enabled['_snippet_enabled'] );
			$this->assertNotSame( '', $enabled['snippet'] );

			$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_content_hints'] = '0';
			$disabled = AIMetadata::refresh( 15 );

			$this->assertSame( '0', $disabled['_snippet_enabled'] );
			$this->assertSame( '', $disabled['snippet'] );
			$this->assertSame( $disabled, get_post_meta( 15, '_cybermaps_ai_meta', true ) );
		}

		public function test_refresh_indexes_the_next_time_relative_transition(): void {
			$created = time() - DAY_IN_SECONDS;
			$GLOBALS['cybermaps_mock_posts'][15]->post_date_gmt = gmdate( 'Y-m-d H:i:s', $created );
			$GLOBALS['cybermaps_mock_posts'][15]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', $created );

			AIMetadata::refresh( 15 );

			$this->assertSame(
				$created + ( 7 * DAY_IN_SECONDS ) + 1,
				(int) get_post_meta( 15, AIMetadata::TRANSITION_META_KEY, true )
			);
		}

		public function test_read_calculation_does_not_write_post_metadata(): void {
			$calculated = AIMetadata::calculate( 15 );

			$this->assertNotSame( '', $calculated['snippet'] );
			$this->assertSame( '', get_post_meta( 15, '_cybermaps_ai_meta', true ) );
			$this->assertSame( '', get_post_meta( 15, '_cybermaps_ai_meta_ts', true ) );
		}

		public function test_length_band_counts_non_ascii_words(): void {
			$GLOBALS['cybermaps_mock_posts'][15]->post_content = implode(
				' ',
				array_fill( 0, 301, 'киберкарта' )
			);

			$calculated = AIMetadata::calculate( 15 );

			$this->assertSame( 'medium', $calculated['length_band'] );
		}

			public function test_cached_content_metadata_recomputes_time_relative_freshness(): void {
			$post = $GLOBALS['cybermaps_mock_posts'][15];
			$post->post_date_gmt     = gmdate( 'Y-m-d H:i:s', time() - ( 120 * DAY_IN_SECONDS ) );
			$post->post_modified_gmt = gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) );
			$GLOBALS['cybermaps_mock_post_meta'][15]['_cybermaps_ai_meta'] = array(
				'content_type'     => 'Article',
				'length_band'      => 'short',
				'freshness'        => 'new',
				'snippet'          => 'Cached literal excerpt.',
				'_snippet_enabled' => '1',
			);
			$GLOBALS['cybermaps_mock_post_meta'][15]['_cybermaps_ai_meta_ts'] = time();

			$calculated = AIMetadata::calculate( 15 );

			$this->assertSame( 'established', $calculated['freshness'] );
			$this->assertSame( 'Cached literal excerpt.', $calculated['snippet'] );
			$this->assertSame(
				'new',
				$GLOBALS['cybermaps_mock_post_meta'][15]['_cybermaps_ai_meta']['freshness'],
				'Read-only calculation must not rewrite save-time metadata.'
				);
			}

			public function test_malformed_or_unbounded_cached_snippet_is_recalculated_safely(): void {
				foreach ( array( array( 'malformed' ), str_repeat( 'X', 2048 ) ) as $cached_snippet ) {
					$GLOBALS['cybermaps_mock_post_meta'][15]['_cybermaps_ai_meta'] = array(
						'content_type'     => 'Article',
						'length_band'      => 'short',
						'freshness'        => 'new',
						'snippet'          => $cached_snippet,
						'_snippet_enabled' => '1',
					);
					$GLOBALS['cybermaps_mock_post_meta'][15]['_cybermaps_ai_meta_ts'] = time();

					set_error_handler(
						static function ( int $severity, string $message, string $file, int $line ): never {
							throw new \ErrorException( $message, 0, $severity, $file, $line );
						}
					);
					try {
						$calculated = AIMetadata::calculate( 15 );
					} finally {
						restore_error_handler();
					}

					$this->assertIsString( $calculated['snippet'] );
					$this->assertLessThanOrEqual( 1024, strlen( $calculated['snippet'] ) );
					$this->assertNotSame( $cached_snippet, $calculated['snippet'] );
				}
			}

			public function test_freshness_boundaries_and_next_transition_are_exact(): void {
				$created = strtotime( '2026-01-01 00:00:00 UTC' );
				$GLOBALS['cybermaps_mock_posts'][15]->post_date_gmt = gmdate( 'Y-m-d H:i:s', $created );
				$GLOBALS['cybermaps_mock_posts'][15]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', $created );

				$this->assertSame( 'new', AIMetadata::freshness_at( 15, $created + ( 7 * DAY_IN_SECONDS ) ) );
				$this->assertSame( 'established', AIMetadata::freshness_at( 15, $created + ( 7 * DAY_IN_SECONDS ) + 1 ) );
				$this->assertSame(
					$created + ( 7 * DAY_IN_SECONDS ) + 1,
					AIMetadata::next_freshness_transition( 15, $created )
				);
			}

			public function test_old_recently_modified_content_has_a_bounded_recent_label(): void {
				$created  = strtotime( '2026-01-01 00:00:00 UTC' );
				$modified = $created + ( 100 * DAY_IN_SECONDS );
				$GLOBALS['cybermaps_mock_posts'][15]->post_date_gmt = gmdate( 'Y-m-d H:i:s', $created );
				$GLOBALS['cybermaps_mock_posts'][15]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', $modified );

				$this->assertSame( 'recently_updated', AIMetadata::freshness_at( 15, $modified ) );
				$this->assertSame( 'recently_updated', AIMetadata::freshness_at( 15, $modified + ( 30 * DAY_IN_SECONDS ) ) );
				$this->assertSame( 'established', AIMetadata::freshness_at( 15, $modified + ( 30 * DAY_IN_SECONDS ) + 1 ) );
				$this->assertSame(
					$modified + ( 30 * DAY_IN_SECONDS ) + 1,
					AIMetadata::next_freshness_transition( 15, $modified )
				);
			}

			public function test_chunk_cache_identity_changes_with_time_relative_freshness(): void {
				$post = $GLOBALS['cybermaps_mock_posts'][15];
				$post->post_date_gmt     = gmdate( 'Y-m-d H:i:s', time() - ( 6 * DAY_IN_SECONDS ) );
				$post->post_modified_gmt = '2026-01-01 00:00:00';
				$chunker = new Chunker();
				$first = $chunker->get_chunks( 15 );

				$post->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() - ( 8 * DAY_IN_SECONDS ) );
				$second = $chunker->get_chunks( 15 );

				$this->assertNotSame( $first['metadata']['freshness'], $second['metadata']['freshness'] );
			}

		public function test_enabled_content_hint_is_emitted_in_ai_sitemap(): void {
			$selector = new class( $GLOBALS['cybermaps_mock_posts'][15] ) extends AIContentSelector {
				public function __construct( private object $fixture ) {}

				public function get_posts(): array {
					return array( $this->fixture );
				}

				public function get_weight( string $post_type ): float {
					unset( $post_type );
					return 0.8;
				}
			};

			$xml = ( new AISitemap( $selector ) )->get_content();

			$this->assertStringContainsString( '<ai:snippet>', $xml );
			$this->assertStringContainsString( 'Stored content', $xml );
		}

		public function test_ai_sitemap_uses_xml_safe_entities_in_generated_text(): void {
			$post = $GLOBALS['cybermaps_mock_posts'][15];
			$post->post_content = 'Stored content ending with enough words to produce a trimmed excerpt & more words here now.';
			$post->post_title   = 'Metadata & XML Test';

			$selector = new class( $post ) extends AIContentSelector {
				public function __construct( private object $fixture ) {}

				public function get_posts(): array {
					return array( $this->fixture );
				}

				public function get_weight( string $post_type ): float {
					unset( $post_type );
					return 0.8;
				}
			};

			$xml = ( new AISitemap( $selector ) )->get_content();

			$this->assertStringNotContainsString( '&hellip;', $xml );
			$this->assertStringNotContainsString( '&nbsp;', $xml );
			$this->assertStringContainsString( '&amp;', $xml );

			$document = new \DOMDocument();
			$this->assertTrue( $document->loadXML( $xml ) );
		}

		public function test_ai_sitemap_normalizes_and_rewrites_only_supported_media_urls(): void {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
				'enable_content_hints'          => '1',
				'enable_multimodal_discovery'   => '1',
				'media_discovery_intensity'     => 'advanced',
				'cdn_enabled'                   => '1',
				'cdn_base_url'                  => 'https://cdn.example.com/assets',
			);
			$GLOBALS['cybermaps_mock_options']['home'] = 'https://example.com';
			$GLOBALS['cybermaps_mock_post_meta'][15]['_cybermaps_media_audit'] = array(
				array(
					'type' => 'image',
					'url'  => '/uploads/diagram.png',
				),
				array(
					'type' => 'video',
					'url'  => 'javascript:alert(1)',
				),
				array(
					'type' => 'document',
					'url'  => 'https://example.com/uploads/brief.pdf',
				),
			);

			$xml = ( new AISitemap( $this->selector_for_post() ) )->get_content();

			$this->assertStringContainsString(
				'<ai:loc>https://cdn.example.com/assets/uploads/diagram.png</ai:loc>',
				$xml
			);
			$this->assertStringNotContainsString( 'javascript:', $xml );
			$this->assertStringNotContainsString( 'brief.pdf', $xml );
		}

		public function test_ai_sitemap_applies_shared_media_publication_bounds(): void {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
				'enable_multimodal_discovery' => '1',
				'media_discovery_intensity'   => 'advanced',
			);
			$media = array();
			for ( $index = 1; $index <= 150; ++$index ) {
				$media[] = array(
					'type' => 'image',
					'url'  => 'https://example.com/image-' . $index . '.jpg',
				);
			}
			for ( $index = 1; $index <= 50; ++$index ) {
				$media[] = array(
					'type' => 'video',
					'url'  => 'https://example.com/video-' . $index . '.mp4',
				);
			}
			$GLOBALS['cybermaps_mock_post_meta'][15]['_cybermaps_media_audit'] = $media;

			$xml = ( new AISitemap( $this->selector_for_post() ) )->get_content();

			$this->assertSame(
				MediaScanner::MAX_MEDIA_ITEMS_PER_POST - MediaScanner::MAX_VIDEO_ITEMS_PER_POST,
				substr_count( $xml, '<ai:image>' )
			);
			$this->assertSame(
				MediaScanner::MAX_VIDEO_ITEMS_PER_POST,
				substr_count( $xml, '<ai:video>' )
			);
		}

		public function test_ai_sitemap_omits_non_http_custom_links(): void {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['ai_sitemap_custom_links'] = array(
				array(
					'url'      => 'https://external.example/resource',
					'priority' => 0.7,
				),
				array(
					'url'      => 'mailto:editor@example.com',
					'priority' => 0.5,
				),
				array(
					'url'      => '/relative-resource',
					'priority' => 0.5,
				),
				array(
					'url'      => 'https://user:secret@example.com/private',
					'priority' => 0.5,
				),
			);

			$xml = ( new AISitemap( $this->selector_for_post() ) )->get_content();

			$this->assertStringContainsString( '<loc>https://external.example/resource</loc>', $xml );
			$this->assertStringNotContainsString( 'mailto:', $xml );
			$this->assertStringNotContainsString( '/relative-resource', $xml );
			$this->assertStringNotContainsString( 'user:secret', $xml );
		}

		private function selector_for_post(): AIContentSelector {
			return new class( $GLOBALS['cybermaps_mock_posts'][15] ) extends AIContentSelector {
				public function __construct( private object $fixture ) {}

				public function get_posts(): array {
					return array( $this->fixture );
				}

				public function get_weight( string $post_type ): float {
					unset( $post_type );
					return 0.8;
				}
			};
		}

		private function install_post(): void {
			$GLOBALS['cybermaps_mock_posts'][15] = new \WP_Post(
				array(
					'ID'                => 15,
					'post_type'         => 'post',
					'post_status'       => 'publish',
					'post_password'     => '',
					'post_title'        => 'Metadata Test',
					'post_content'      => 'Stored content includes Cybermaps and 42 concrete examples for readers.',
					'post_date_gmt'     => '2026-06-01 00:00:00',
					'post_modified_gmt' => '2026-07-01 00:00:00',
				)
			);
		}
	}
}
