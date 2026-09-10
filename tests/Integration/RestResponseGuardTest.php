<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Integration;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Integration\RestResponseGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RestResponseGuardTest extends \WP_UnitTestCase {

	private RestResponseGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new RestResponseGuard( EndpointRegistry::get_instance() );
	}

	public function test_resolves_pretty_and_query_parameter_rest_routes(): void {
		$this->assertSame(
			'rest_status',
			$this->guard->endpoint_id_from_request(
				'/subdirectory/wp-json/cybermaps/v1/status?context=view'
			)
		);
		$this->assertSame(
			'rest_audit_run',
			$this->guard->endpoint_id_from_request(
				'/index.php?rest_route=%2Fcybermaps%2Fv1%2Faudit-run',
				'/cybermaps/v1/audit-run'
			)
		);
		$this->assertSame(
			'',
			$this->guard->endpoint_id_from_request( '/wp-json/another/v1/status' )
		);
		$this->assertSame(
			'',
			$this->guard->endpoint_id_from_request( '/ordinary-page/' )
		);
	}

	public function test_private_route_inventory_excludes_public_rest_routes(): void {
		foreach ( array( 'urls', 'status', 'audit-latest', 'audit-run', 'purge' ) as $route ) {
			$this->assertTrue( $this->guard->is_private_rest_path( '/cybermaps/v1/' . $route ) );
		}

		foreach ( array( 'discovery', 'search', 'llms-tldr' ) as $route ) {
			$this->assertFalse( $this->guard->is_private_rest_path( '/cybermaps/v1/' . $route ) );
		}
	}

	public function test_mai_cache_headers_are_disabled_for_all_machine_requests(): void {
		$original_uri = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/wp-json/cybermaps/v1/status';

		$this->assertSame(
			array( 'cache_headers' => false, 'move_scripts' => true ),
			$this->guard->filter_mai_settings(
				array( 'cache_headers' => true, 'move_scripts' => true )
			)
		);

		$_SERVER['REQUEST_URI'] = '/wp-json/cybermaps/v1/discovery';
		$this->assertSame(
			array( 'cache_headers' => false ),
			$this->guard->filter_mai_settings( array( 'cache_headers' => true ) )
		);

		$_SERVER['REQUEST_URI'] = '/feed.json';
		$this->assertSame(
			array( 'cache_headers' => false, 'move_scripts' => true ),
			$this->guard->filter_mai_settings(
				array( 'cache_headers' => true, 'move_scripts' => true )
			)
		);

		$_SERVER['REQUEST_URI'] = '/ordinary-page/';
		$this->assertSame(
			array( 'cache_headers' => true ),
			$this->guard->filter_mai_settings( array( 'cache_headers' => true ) )
		);

		if ( null === $original_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $original_uri;
		}
	}

	public function test_machine_inventory_includes_dynamic_and_configured_routes(): void {
		$original_uri = $_SERVER['REQUEST_URI'] ?? null;
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'sitemap_url_base' => 'machine-map',
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_indexnow_key'] = 'verification-key';

		foreach (
			array(
				'/feed.json',
				'/robots.txt',
				'/machine-map.xml',
				'/machine-map-posts-post-2.xml',
				'/discovery/chunks/4.json',
				'/fr/llms.txt',
				'/verification-key.txt',
				'/wp-json/cybermaps/v1/discovery',
			)
			as $path
		) {
			$_SERVER['REQUEST_URI'] = $path;
			$this->assertTrue( $this->guard->is_current_machine_request(), $path );
		}

		$_SERVER['REQUEST_URI'] = '/ordinary-page/';
		$this->assertFalse( $this->guard->is_current_machine_request() );

		if ( null === $original_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $original_uri;
		}
	}

	public function test_post_dispatch_reasserts_private_headers(): void {
		$response = new GuardResponseStub();
		$request  = new GuardRequestStub( '/cybermaps/v1/status' );

		$this->assertSame(
			$response,
			$this->guard->enforce_private_headers( $response, null, $request )
		);
		$this->assertSame(
			'no-cache, no-store, must-revalidate, private',
			$response->headers['Cache-Control']
		);
		$this->assertSame( 'no-cache', $response->headers['Pragma'] );
		$this->assertSame( '0', $response->headers['Expires'] );
	}

	public function test_post_dispatch_leaves_public_response_untouched(): void {
		$response = new GuardResponseStub();
		$request  = new GuardRequestStub( '/cybermaps/v1/discovery' );

		$this->guard->enforce_private_headers( $response, null, $request );

		$this->assertSame( array(), $response->headers );
	}

	public function test_malformed_server_and_request_values_fail_closed_without_warnings(): void {
		$original_uri    = $_SERVER['REQUEST_URI'] ?? null;
		$original_accept = $_SERVER['HTTP_ACCEPT'] ?? null;
		$_SERVER['REQUEST_URI'] = array( '/wp-json/cybermaps/v1/status' );
		$_SERVER['HTTP_ACCEPT'] = array( 'text/html' );

		$request = new class() {
			public function get_route(): array {
				return array( '/cybermaps/v1/status' );
			}
		};
		$response = new GuardResponseStub();

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$this->assertFalse( $this->guard->is_current_machine_request() );
			$this->assertSame(
				$response,
				$this->guard->enforce_private_headers( $response, null, $request )
			);
			$this->assertSame( array(), $response->headers );
		} finally {
			restore_error_handler();
			if ( null === $original_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $original_uri;
			}
			if ( null === $original_accept ) {
				unset( $_SERVER['HTTP_ACCEPT'] );
			} else {
				$_SERVER['HTTP_ACCEPT'] = $original_accept;
			}
		}
	}

	public function test_malformed_json_negotiation_headers_never_reach_wordpress_parser(): void {
		$original_accept       = $_SERVER['HTTP_ACCEPT'] ?? null;
		$original_content_type = $_SERVER['CONTENT_TYPE'] ?? null;
		$_SERVER['HTTP_ACCEPT'] = array( 'application/json' );
		$_SERVER['CONTENT_TYPE'] = array( 'application/json' );
		$method = new \ReflectionMethod( RestResponseGuard::class, 'current_request_is_json' );

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$this->assertFalse( $method->invoke( $this->guard ) );
		} finally {
			restore_error_handler();
			if ( null === $original_accept ) {
				unset( $_SERVER['HTTP_ACCEPT'] );
			} else {
				$_SERVER['HTTP_ACCEPT'] = $original_accept;
			}
			if ( null === $original_content_type ) {
				unset( $_SERVER['CONTENT_TYPE'] );
			} else {
				$_SERVER['CONTENT_TYPE'] = $original_content_type;
			}
		}
	}
}

final class GuardResponseStub {
	/** @var array<string, string> */
	public array $headers = array();

	public function header( string $name, string $value ): void {
		$this->headers[ $name ] = $value;
	}
}

final class GuardRequestStub {
	public function __construct(
		private readonly string $route
	) {}

	public function get_route(): string {
		return $this->route;
	}
}
