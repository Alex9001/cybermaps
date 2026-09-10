<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\ClientIPResolver;
use PHPUnit\Framework\TestCase;

final class ClientIPResolverTest extends TestCase {
	protected function tearDown(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		parent::tearDown();
	}

	public function test_direct_address_is_the_default_and_forwarding_headers_are_ignored(): void {
		$resolution = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'          => '203.0.113.25',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
			)
		);

		$this->assertSame(
			array(
				'ip'     => '203.0.113.25',
				'source' => ClientIPResolver::SOURCE_DIRECT,
			),
			$resolution
		);
	}

	public function test_explicitly_trusted_x_forwarded_for_uses_the_first_untrusted_hop(): void {
		update_option(
			'cybermaps_settings',
			array(
				'trusted_proxy_cidrs'  => "10.0.0.0/8\n192.0.2.0/24",
				'trusted_proxy_header' => 'x_forwarded_for',
			)
		);

		$resolution = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.2',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 192.0.2.22, 10.0.0.1',
			)
		);

		$this->assertSame( '198.51.100.9', $resolution['ip'] );
		$this->assertSame( ClientIPResolver::SOURCE_TRUSTED_PROXY, $resolution['source'] );
	}

	public function test_configured_forwarding_header_from_an_untrusted_peer_is_ignored(): void {
		update_option(
			'cybermaps_settings',
			array(
				'trusted_proxy_cidrs'  => '10.0.0.0/8',
				'trusted_proxy_header' => 'forwarded',
			)
		);

		$resolution = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'    => '203.0.113.25',
				'HTTP_FORWARDED' => 'for=198.51.100.9',
			)
		);

		$this->assertSame( '203.0.113.25', $resolution['ip'] );
		$this->assertSame( ClientIPResolver::SOURCE_DIRECT, $resolution['source'] );
	}

	public function test_spoofed_cloudflare_header_from_an_untrusted_peer_is_ignored(): void {
		$resolution = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'                 => '203.0.113.25',
				'HTTP_CF_CONNECTING_IP'       => '198.51.100.9',
				'HTTP_X_FORWARDED_FOR'        => '192.0.2.40',
			)
		);

		$this->assertSame( '203.0.113.25', $resolution['ip'] );
		$this->assertSame( ClientIPResolver::SOURCE_DIRECT, $resolution['source'] );
	}

	/**
	 * @dataProvider cloudflare_boundary_provider
	 */
	public function test_cloudflare_cidr_boundaries( string $proxy, bool $trusted ): void {
		$resolution = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'           => $proxy,
				'HTTP_CF_CONNECTING_IP' => '198.51.100.77',
			)
		);

		$this->assertSame( $trusted ? '198.51.100.77' : $proxy, $resolution['ip'] );
		$this->assertSame(
			$trusted ? ClientIPResolver::SOURCE_CLOUDFLARE : ClientIPResolver::SOURCE_DIRECT,
			$resolution['source']
		);
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function cloudflare_boundary_provider(): array {
		return array(
			'IPv4 lower boundary' => array( '173.245.48.0', true ),
			'IPv4 upper boundary' => array( '173.245.63.255', true ),
			'IPv4 immediately below' => array( '173.245.47.255', false ),
			'IPv4 immediately above' => array( '173.245.64.0', false ),
			'IPv6 lower boundary' => array( '2400:cb00::', true ),
			'IPv6 upper boundary' => array( '2400:cb00:ffff:ffff:ffff:ffff:ffff:ffff', true ),
			'IPv6 immediately below' => array( '2400:caff:ffff:ffff:ffff:ffff:ffff:ffff', false ),
			'IPv6 immediately above' => array( '2400:cb01::', false ),
		);
	}

	/**
	 * @dataProvider valid_forwarded_address_provider
	 */
	public function test_cloudflare_can_forward_ipv4_and_ipv6_clients( string $forwarded, string $expected ): void {
		$resolution = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'           => '2606:4700::1234',
				'HTTP_CF_CONNECTING_IP' => $forwarded,
			)
		);

		$this->assertSame( $expected, $resolution['ip'] );
		$this->assertSame( ClientIPResolver::SOURCE_CLOUDFLARE, $resolution['source'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function valid_forwarded_address_provider(): array {
		return array(
			'IPv4 client' => array( '198.51.100.9', '198.51.100.9' ),
			'IPv6 client' => array( '2001:0DB8:0000:0000:0000:0000:0000:0042', '2001:db8::42' ),
		);
	}

	/**
	 * @dataProvider invalid_forwarded_address_provider
	 *
	 * @param mixed $forwarded
	 */
	public function test_invalid_or_multi_value_cloudflare_headers_are_rejected( mixed $forwarded ): void {
		$resolution = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'           => '104.16.0.1',
				'HTTP_CF_CONNECTING_IP' => $forwarded,
			)
		);

		$this->assertSame( '104.16.0.1', $resolution['ip'] );
		$this->assertSame( ClientIPResolver::SOURCE_DIRECT, $resolution['source'] );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_forwarded_address_provider(): array {
		return array(
			'empty' => array( '' ),
			'malformed' => array( 'not-an-ip' ),
			'comma-separated' => array( '198.51.100.1, 198.51.100.2' ),
			'newline' => array( "198.51.100.1\r\nX-Test: injected" ),
			'address with port' => array( '198.51.100.1:443' ),
			'array' => array( array( '198.51.100.1' ) ),
		);
	}

	public function test_missing_or_invalid_remote_address_cannot_authorize_cloudflare_header(): void {
		$missing = ClientIPResolver::resolve(
			array(
				'HTTP_CF_CONNECTING_IP' => '198.51.100.9',
			)
		);
		$invalid = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'           => 'unknown',
				'HTTP_CF_CONNECTING_IP' => '198.51.100.9',
			)
		);

		$this->assertSame( array( 'ip' => '', 'source' => ClientIPResolver::SOURCE_DIRECT ), $missing );
		$this->assertSame( array( 'ip' => '', 'source' => ClientIPResolver::SOURCE_DIRECT ), $invalid );
	}

	public function test_ipv4_mapped_cloudflare_peer_is_normalized_and_trusted(): void {
		$resolution = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'           => '::ffff:173.245.48.10',
				'HTTP_CF_CONNECTING_IP' => '2001:db8::99',
			)
		);

		$this->assertSame( '2001:db8::99', $resolution['ip'] );
		$this->assertSame( ClientIPResolver::SOURCE_CLOUDFLARE, $resolution['source'] );
	}

	public function test_explicit_trusted_proxy_filter_can_supply_a_valid_resolution(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_client_ip_resolution'] = array(
			static function ( array $resolution, array $server ): array {
				unset( $resolution );
				return array(
					'ip'     => (string) $server['HTTP_X_TRUSTED_CLIENT_IP'],
					'source' => 'site-proxy',
				);
			},
		);

		$resolution = ClientIPResolver::resolve(
			array(
				'REMOTE_ADDR'                 => '192.0.2.10',
				'HTTP_X_TRUSTED_CLIENT_IP'    => '198.51.100.27',
			)
		);

		$this->assertSame( '198.51.100.27', $resolution['ip'] );
		$this->assertSame( 'site-proxy', $resolution['source'] );
	}

	/**
	 * @dataProvider invalid_filtered_resolution_provider
	 *
	 * @param mixed $filtered
	 */
	public function test_invalid_filtered_resolutions_cannot_override_the_safe_default( mixed $filtered ): void {
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_client_ip_resolution'] = array(
			static fn (): mixed => $filtered,
		);

		$this->assertSame(
			array(
				'ip'     => '203.0.113.25',
				'source' => ClientIPResolver::SOURCE_DIRECT,
			),
			ClientIPResolver::resolve( array( 'REMOTE_ADDR' => '203.0.113.25' ) )
		);
	}

	/**
	 * @return array<string,array{mixed}>
	 */
	public static function invalid_filtered_resolution_provider(): array {
		return array(
			'not an array'       => array( '198.51.100.1' ),
			'missing source'     => array( array( 'ip' => '198.51.100.1' ) ),
			'multiple addresses' => array(
				array(
					'ip'     => '198.51.100.1, 192.0.2.1',
					'source' => 'site-proxy',
				),
			),
			'invalid source'     => array(
				array(
					'ip'     => '198.51.100.1',
					'source' => '../proxy',
				),
			),
		);
	}
}
