<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\MediaScanner;

final class MediaScannerTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_home_url']  = 'https://example.com';
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_children'] = array();
		$GLOBALS['cybermaps_mock_attachment_urls'] = array();
		$GLOBALS['cybermaps_mock_posts']     = array(
			41 => (object) array(
				'ID'           => 41,
				'post_title'   => 'Embedded video examples',
				'post_content' => implode(
					"\n",
					array(
						'<video src="/uploads/walkthrough.mp4" poster="/uploads/walkthrough.jpg"></video>',
						'<video poster="/uploads/stream.jpg"><source src="/uploads/stream.m3u8"></video>',
						'<video src="/uploads/no-poster.mp4"></video>',
						'<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
						'<a href="youtube.com/watch?v=abcdefghijk">Video</a>',
						'<iframe src="https://player.vimeo.com/video/123456789"></iframe>',
						'<a href="vimeo.com/987654321?autoplay=1">Vimeo</a>',
					)
				),
			),
		);
	}

	public function test_advanced_scan_assigns_truthful_content_and_player_urls(): void {
		$videos = array_values(
			array_filter(
				( new MediaScanner() )->scan_advanced( 41 ),
				static fn( array $item ): bool => 'video' === (string) ( $item['type'] ?? '' )
			)
		);
		$by_url = array_column( $videos, null, 'url' );

		$this->assertSame(
			'content',
			$by_url['https://example.com/uploads/walkthrough.mp4']['video_url_type']
		);
		$this->assertSame(
			'https://example.com/uploads/walkthrough.jpg',
			$by_url['https://example.com/uploads/walkthrough.mp4']['thumbnail_loc']
		);
		$this->assertSame(
			'https://example.com/uploads/stream.jpg',
			$by_url['https://example.com/uploads/stream.m3u8']['thumbnail_loc']
		);
		$this->assertSame(
			'',
			$by_url['https://example.com/uploads/no-poster.mp4']['thumbnail_loc']
		);
		foreach (
			array(
				'https://www.youtube.com/embed/dQw4w9WgXcQ',
				'https://www.youtube.com/embed/abcdefghijk',
				'https://player.vimeo.com/video/123456789',
				'https://player.vimeo.com/video/987654321',
			) as $url
		) {
			$this->assertArrayHasKey( $url, $by_url );
			$this->assertSame( 'player', $by_url[ $url ]['video_url_type'] );
		}
		foreach ( array_keys( $by_url ) as $url ) {
			$this->assertMatchesRegularExpression( '~^https://~', $url );
		}
	}

	public function test_legacy_hosted_video_urls_resolve_to_canonical_players(): void {
		$this->assertSame(
			array(
				'url'  => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
				'type' => 'player',
			),
			MediaScanner::resolve_video_publication(
				array(
					'url'            => 'youtube.com/watch?v=dQw4w9WgXcQ',
					'video_url_type' => 'content',
				)
			)
		);
		$this->assertSame(
			array(
				'url'  => 'https://player.vimeo.com/video/123456789',
				'type' => 'player',
			),
			MediaScanner::resolve_video_publication(
				array( 'url' => 'https://vimeo.com/123456789' )
			)
		);
		$this->assertSame(
			array(
				'url'  => 'https://example.com/video.mp4',
				'type' => 'content',
			),
			MediaScanner::resolve_video_publication(
				array( 'url' => 'https://example.com/video.mp4' )
			)
		);
		$this->assertSame(
			array(
				'url'  => 'https://notyoutube.com/embed/dQw4w9WgXcQ',
				'type' => 'content',
			),
			MediaScanner::resolve_video_publication(
				array( 'url' => 'https://notyoutube.com/embed/dQw4w9WgXcQ' )
			)
		);
	}

	public function test_legacy_domain_only_urls_are_normalized_for_consumers(): void {
		$this->assertSame(
			'https://youtube.com/watch?v=dQw4w9WgXcQ',
			MediaScanner::normalize_media_url( 'youtube.com/watch?v=dQw4w9WgXcQ' )
		);
		$this->assertSame(
			'https://vimeo.com/123456789',
			MediaScanner::normalize_media_url( 'vimeo.com/123456789' )
		);
		$this->assertSame(
			'https://example.com/uploads/video.mp4',
			MediaScanner::normalize_media_url( 'uploads/video.mp4' )
		);
		$this->assertSame(
			'',
			MediaScanner::normalize_media_url( 'https://user:secret@example.com/private.mp4' )
		);
		$this->assertSame( '', MediaScanner::normalize_media_url( 'javascript:alert(1)' ) );
	}

	public function test_invalid_featured_url_does_not_discard_valid_attached_images(): void {
		$GLOBALS['cybermaps_mock_post_meta'][41]['_thumbnail_id'] = 501;
		$GLOBALS['cybermaps_mock_attachment_urls'][501] = 'https://user:secret@example.com/featured.jpg';
		$GLOBALS['cybermaps_mock_attachment_urls'][502] = 'https://example.com/uploads/attached.jpg';
		$GLOBALS['cybermaps_mock_children'] = array(
			502 => (object) array(
				'ID'         => 502,
				'post_title' => 'Attached image',
			),
		);

		$media = ( new MediaScanner() )->scan_standard( 41 );

		$this->assertCount( 1, $media );
		$this->assertSame( 'https://example.com/uploads/attached.jpg', $media[0]['url'] );
		$this->assertSame( 'Attached image', $media[0]['title'] );
	}

	public function test_advanced_match_collection_stops_at_the_publication_budget(): void {
		$content = '';
		for ( $index = 0; $index < 250; ++$index ) {
			$content .= '<img src="/uploads/image-' . $index . '.jpg">';
		}
		$method = new \ReflectionMethod( MediaScanner::class, 'bounded_content_matches' );
		$matches = $method->invoke(
			null,
			'/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i',
			$content,
			MediaScanner::MAX_MEDIA_ITEMS_PER_POST
		);

		$this->assertCount( MediaScanner::MAX_MEDIA_ITEMS_PER_POST, $matches );
		$this->assertSame( '/uploads/image-0.jpg', $matches[0][1] );
		$this->assertSame( '/uploads/image-99.jpg', $matches[99][1] );
	}

	public function test_malformed_optional_media_fields_are_normalized_without_warnings(): void {
		$media = array(
			array(
				'type'            => 'video',
				'url'             => 'https://example.com/video.mp4',
				'title'           => array( 'bad' ),
				'thumbnail_loc'   => new \stdClass(),
				'multimodal_desc' => array( 'bad' ),
				'video_url_type'  => array( 'content' ),
				'visual_weight'   => array( 1 ),
			),
		);

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$bounded = MediaScanner::limit_audit_items( $media );
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $bounded );
		$this->assertSame( '', $bounded[0]['title'] );
		$this->assertSame( '', $bounded[0]['thumbnail_loc'] );
		$this->assertSame( '', $bounded[0]['multimodal_desc'] );
		$this->assertSame( '', $bounded[0]['video_url_type'] );
		$this->assertArrayNotHasKey( 'visual_weight', $bounded[0] );
	}

	public function test_missing_post_returns_no_standard_media_without_warnings(): void {
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$this->assertSame( array(), ( new MediaScanner() )->scan_standard( 999 ) );
		} finally {
			restore_error_handler();
		}
	}

	public function test_standard_scan_does_not_claim_content_embeds_but_advanced_does(): void {
		$scanner = new MediaScanner();

		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$scanner->scan_standard( 41 ),
					static fn( array $item ): bool => 'video' === (string) ( $item['type'] ?? '' )
				)
			)
		);
		$this->assertNotEmpty(
			array_filter(
				$scanner->scan_advanced( 41 ),
				static fn( array $item ): bool => 'video' === (string) ( $item['type'] ?? '' )
			)
		);
	}

	public function test_saved_media_inventory_is_bounded_by_type_and_total(): void {
		$media = array();
		for ( $index = 1; $index <= 150; ++$index ) {
			$media[] = array(
				'type' => 'image',
				'url'  => 'https://example.com/image-' . $index . '.jpg',
			);
		}
		for ( $index = 1; $index <= 50; ++$index ) {
			$media[] = array(
				'type'          => 'video',
				'url'           => 'https://example.com/video-' . $index . '.mp4',
				'thumbnail_loc' => 'https://example.com/video-' . $index . '.jpg',
			);
		}
		$media[] = array( 'type' => 'document', 'url' => 'https://example.com/file.pdf' );
		$media[] = array( 'type' => 'video', 'url' => 'javascript:alert(1)' );

		$bounded = MediaScanner::limit_audit_items( $media );
		$videos  = array_filter(
			$bounded,
			static fn( array $item ): bool => 'video' === (string) ( $item['type'] ?? '' )
		);

		$this->assertCount( MediaScanner::MAX_MEDIA_ITEMS_PER_POST, $bounded );
		$this->assertCount( MediaScanner::MAX_VIDEO_ITEMS_PER_POST, $videos );
		$this->assertSame(
			MediaScanner::MAX_MEDIA_ITEMS_PER_POST - MediaScanner::MAX_VIDEO_ITEMS_PER_POST,
			count( $bounded ) - count( $videos )
		);
	}

	public function test_audit_provenance_fails_closed_across_mode_generations(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_media_audit_generation'] = 7;
		$GLOBALS['cybermaps_mock_post_meta'][41]['_cybermaps_media_audit'] = array(
			array( 'type' => 'image', 'url' => 'https://example.com/current.jpg' ),
		);

		// Legacy rows without provenance are never reinterpreted.
		$this->assertSame( array(), MediaScanner::current_audit_items( 41, 'standard' ) );

		$GLOBALS['cybermaps_mock_post_meta'][41]['_cybermaps_media_audit_mode'] = 'standard';
		$GLOBALS['cybermaps_mock_post_meta'][41]['_cybermaps_media_audit_generation'] = 7;
		$this->assertCount( 1, MediaScanner::current_audit_items( 41, 'standard' ) );
		$this->assertSame( array(), MediaScanner::current_audit_items( 41, 'advanced' ) );
		$this->assertSame( array(), MediaScanner::current_audit_items( 41, 'none' ) );

		$this->assertSame( 8, MediaScanner::invalidate_audits() );
		$this->assertArrayHasKey(
			'_cybermaps_media_audit',
			$GLOBALS['cybermaps_mock_post_meta'][41]
		);
		$this->assertSame( array(), MediaScanner::current_audit_items( 41, 'standard' ) );

		$GLOBALS['cybermaps_mock_post_meta'][41]['_cybermaps_media_audit_mode'] = 'advanced';
		$GLOBALS['cybermaps_mock_post_meta'][41]['_cybermaps_media_audit_generation'] = 8;
		$this->assertCount( 1, MediaScanner::current_audit_items( 41, 'advanced' ) );
		$this->assertSame( array(), MediaScanner::current_audit_items( 41, array( 'advanced' ) ) );
	}
}
