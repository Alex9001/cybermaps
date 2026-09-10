<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\URLManager;
use PHPUnit\Framework\TestCase;

final class URLManagerTest extends TestCase {

	private mixed $original_home_url;
	private array $original_options;
	private bool $had_request_uri;
	private string $original_request_uri;

	protected function setUp(): void {
		parent::setUp();

		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$this->original_home_url    = $GLOBALS['cybermaps_mock_home_url'] ?? null;
		$this->original_options     = $cybermaps_mock_options;
		$this->had_request_uri      = isset( $_SERVER['REQUEST_URI'] );
		$this->original_request_uri = $this->had_request_uri ? (string) $_SERVER['REQUEST_URI'] : '';
		$cybermaps_mock_home_url = 'https://example.com';
		$cybermaps_mock_options['cybermaps_settings'] = array();
		$_SERVER['REQUEST_URI'] = '/';
	}

	protected function tearDown(): void {
		$GLOBALS['cybermaps_mock_options'] = $this->original_options;
		if ( null === $this->original_home_url ) {
			unset( $GLOBALS['cybermaps_mock_home_url'] );
		} else {
			$GLOBALS['cybermaps_mock_home_url'] = $this->original_home_url;
		}
		if ( $this->had_request_uri ) {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		} else {
			unset( $_SERVER['REQUEST_URI'] );
		}
		parent::tearDown();
	}

	public function test_well_known_urls_are_always_rooted_at_the_origin(): void {
		global $cybermaps_mock_options;
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example:9443/app',
		);

		$this->assertSame(
			'https://frontend.example:9443/.well-known/ai.json?format=full#manifest',
			URLManager::get_home_url( '/.well-known/ai.json?format=full#manifest' )
		);
		$this->assertSame(
			'https://frontend.example:9443/app/llms.txt',
			URLManager::get_home_url( '/llms.txt' )
		);
	}

	public function test_headless_rewrite_replaces_a_subdirectory_base_and_preserves_url_components(): void {
		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$cybermaps_mock_home_url = 'https://backend.example:8443/blog';
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example:9443/app/',
		);

		$this->assertSame(
			'https://frontend.example:9443/app/articles/launch?preview=0&lang=en#details',
			URLManager::rewrite_url(
				'https://backend.example:8443/blog/articles/launch?preview=0&lang=en#details'
			)
		);
		$this->assertSame(
			'https://frontend.example:9443/app/',
			URLManager::rewrite_url( 'https://backend.example:8443/blog/' )
		);
	}

	public function test_headless_rewrite_requires_an_origin_and_path_boundary_match(): void {
		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$cybermaps_mock_home_url = 'https://example.com/blog';
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example/app',
		);

		$unchanged = array(
			'https://example.com/blogger/article',
			'https://example.com/other/article',
			'https://example.com.evil.test/blog/article',
			'https://evil.test/?next=https://example.com/blog/article',
			'http://example.com/blog/article',
			'https://example.com:8443/blog/article',
		);

		foreach ( $unchanged as $url ) {
			$this->assertSame( $url, URLManager::rewrite_url( $url ) );
		}
	}

	public function test_explicit_default_port_matches_the_same_origin(): void {
		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$cybermaps_mock_home_url = 'https://example.com:443';
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example',
		);

		$this->assertSame(
			'https://frontend.example/article',
			URLManager::rewrite_url( 'https://example.com/article' )
		);
	}

	public function test_headless_rewrite_is_idempotent_when_bases_share_an_origin(): void {
		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$cybermaps_mock_home_url = 'https://example.com';
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://example.com/app',
		);

		$public_url = URLManager::rewrite_url( 'https://example.com/article' );

		$this->assertSame( 'https://example.com/app/article', $public_url );
		$this->assertSame( $public_url, URLManager::rewrite_url( $public_url ) );
	}

	public function test_media_cdn_rewrites_backend_and_headless_same_site_urls(): void {
		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$cybermaps_mock_home_url = 'https://backend.example:8443/blog';
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example:9443/app',
			'cdn_enabled'       => '1',
			'cdn_base_url'      => 'https://cdn.example:7443/media/',
		);

		$this->assertSame(
			'https://cdn.example:7443/media/uploads/image.jpg?width=1200#asset',
			URLManager::rewrite_media_url(
				'https://backend.example:8443/blog/uploads/image.jpg?width=1200#asset'
			)
		);
		$this->assertSame(
			'https://cdn.example:7443/media/uploads/video.mp4',
			URLManager::rewrite_media_url(
				'https://frontend.example:9443/app/uploads/video.mp4'
			)
		);
	}

	public function test_media_cdn_prefers_the_most_specific_overlapping_public_base(): void {
		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$cybermaps_mock_home_url = 'https://example.com';
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://example.com/app',
			'cdn_enabled'       => '1',
			'cdn_base_url'      => 'https://example.com/media',
		);

		$cdn_url = URLManager::rewrite_media_url(
			'https://example.com/app/uploads/image.jpg'
		);

		$this->assertSame( 'https://example.com/media/uploads/image.jpg', $cdn_url );
		$this->assertSame( $cdn_url, URLManager::rewrite_media_url( $cdn_url ) );
	}

	public function test_media_cdn_does_not_rewrite_external_or_out_of_scope_urls(): void {
		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$cybermaps_mock_home_url = 'https://example.com/blog';
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example/app',
			'cdn_enabled'       => '1',
			'cdn_base_url'      => 'https://cdn.example',
		);

		$unchanged = array(
			'https://example.com/blogger/image.jpg',
			'https://example.com.evil.test/blog/image.jpg',
			'https://media.example/image.jpg?source=https://example.com/blog/image.jpg',
			'https://frontend.example/application/image.jpg',
			'https://third-party.example/video.mp4',
		);

		foreach ( $unchanged as $url ) {
			$this->assertSame( $url, URLManager::rewrite_media_url( $url ) );
		}
	}

	public function test_invalid_public_bases_do_not_generate_unsafe_urls(): void {
		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$cybermaps_mock_home_url = 'https://backend.example/blog';
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://user:secret@frontend.example/app',
			'cdn_enabled'       => '1',
			'cdn_base_url'      => 'javascript:alert(1)',
		);

		$this->assertSame(
			'https://backend.example/blog/llms.txt',
			URLManager::get_home_url( '/llms.txt' )
		);
		$this->assertSame(
			'https://backend.example/blog/article',
			URLManager::rewrite_url( 'https://backend.example/blog/article' )
		);
		$this->assertSame(
			'https://backend.example/blog/image.jpg',
			URLManager::rewrite_media_url( 'https://backend.example/blog/image.jpg' )
		);
	}

	public function test_public_url_and_configured_base_validation_share_http_safety_rules(): void {
		$this->assertSame(
			'https://example.com/path?view=full#details',
			URLManager::sanitize_http_url( 'https://example.com/path?view=full#details' )
		);
		$this->assertSame( '', URLManager::sanitize_http_url( 'mailto:editor@example.com' ) );
		$this->assertSame( '', URLManager::sanitize_http_url( '/relative/path' ) );
		$this->assertSame( '', URLManager::sanitize_http_url( 'https://user:secret@example.com/path' ) );
		$this->assertSame( '', URLManager::sanitize_http_url( array( 'https://example.com' ) ) );

		$this->assertSame(
			'https://frontend.example:9443/app',
			URLManager::normalize_configured_base_url( 'HTTPS://FRONTEND.EXAMPLE:9443/app/' )
		);
		$this->assertSame( '', URLManager::normalize_configured_base_url( 'https://example.com/app?preview=1' ) );
		$this->assertSame( '', URLManager::normalize_configured_base_url( 'https://example.com/app#fragment' ) );
		$this->assertSame( '', URLManager::normalize_configured_base_url( 'https://user@example.com/app' ) );
	}

	public function test_query_templates_support_pretty_plain_and_fragment_urls(): void {
		$this->assertSame(
			'https://example.com/wp-json/cybermaps/v1/search?q={query}',
			URLManager::append_query_template(
				'https://example.com/wp-json/cybermaps/v1/search',
				'q={query}'
			)
		);
		$this->assertSame(
			'https://example.com/?rest_route=%2Fcybermaps%2Fv1%2Fsearch&q={query}',
			URLManager::append_query_template(
				'https://example.com/?rest_route=%2Fcybermaps%2Fv1%2Fsearch',
				'?q={query}'
			)
		);
		$this->assertSame(
			'https://example.com/search?existing=1&q={query}#api',
			URLManager::append_query_template(
				'https://example.com/search?existing=1#api',
				'q={query}'
			)
		);
	}

	public function test_request_path_uses_the_wordpress_origin_not_headless_frontend(): void {
		global $cybermaps_mock_home_url, $cybermaps_mock_options;
		$cybermaps_mock_home_url = 'https://backend.example/blog';
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example/app',
		);
		$_SERVER['REQUEST_URI'] = '/blog/llms.txt?source=test';

		$this->assertSame( '/llms.txt', URLManager::get_request_path() );
	}

	public function test_malformed_request_uri_shape_returns_root_without_a_php_warning(): void {
		$prior = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = array( '/ai.json' );

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$this->assertSame( '/', URLManager::get_request_path() );
		} finally {
			restore_error_handler();
			if ( null === $prior ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $prior;
			}
		}
	}

	public function test_configured_frontend_host_is_allowed_for_safe_sitemap_redirects(): void {
		global $cybermaps_mock_options;
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example:9443/app',
		);

		$this->assertSame(
			array( 'backend.example', 'frontend.example' ),
			URLManager::allow_configured_public_host( array( 'backend.example' ) )
		);

		$cybermaps_mock_options['cybermaps_settings']['frontend_base_url'] =
			'https://user:secret@untrusted.example/app';
		$this->assertSame(
			array( 'backend.example' ),
			URLManager::allow_configured_public_host( array( 'backend.example', '', 123 ) )
		);
	}

	public function test_site_path_is_removed_only_at_a_segment_boundary(): void {
		global $cybermaps_mock_home_url;
		$cybermaps_mock_home_url = 'https://example.com/blog';
		$_SERVER['REQUEST_URI'] = '/blogger/ai.json';

		$this->assertSame( '/blogger/ai.json', URLManager::get_request_path() );
	}
}
