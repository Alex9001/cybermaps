<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a request's client address without trusting arbitrary proxy headers.
 *
 * Core recognizes Cloudflare because its proxy networks are publicly documented.
 * Other proxies require explicit trusted CIDRs and a chosen forwarding header;
 * deployments can still supply a validated resolution through the public filter.
 */
final class ClientIPResolver {
	public const SOURCE_DIRECT        = 'direct';
	public const SOURCE_CLOUDFLARE    = 'cloudflare';
	public const SOURCE_TRUSTED_PROXY = 'trusted_proxy';

	/**
	 * Official Cloudflare proxy networks.
	 *
	 * Source:
	 * - https://www.cloudflare.com/ips-v4/
	 * - https://www.cloudflare.com/ips-v6/
	 *
	 * @var list<string>
	 */
	private const CLOUDFLARE_CIDRS = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Resolve the effective client IP and how it was obtained.
	 *
	 * CF-Connecting-IP is accepted only when the immediate peer is inside an
	 * official Cloudflare network. Other forwarding headers are accepted only
	 * from explicitly configured proxy CIDRs.
	 *
	 * The final result is filterable for installations with another explicitly
	 * trusted proxy. Filter callbacks receive the unmodified server array so
	 * they can implement and document their own trust boundary. A callback must
	 * return an array containing a single valid `ip` and a stable `source` slug;
	 * malformed filtered values are ignored.
	 *
	 * @param array<string, mixed>|null $server Server variables, or null for $_SERVER.
	 * @return array{ip: string, source: string}
	 */
	public static function resolve( ?array $server = null ): array {
		$server     = null === $server ? $_SERVER : $server;
		$direct     = self::normalize_ip( $server['REMOTE_ADDR'] ?? null );
		$resolution = self::cloudflare_resolution( $direct, $server ) ?? self::trusted_proxy_resolution( $direct, $server ) ?? array(
			'ip'     => $direct ?? '',
			'source' => self::SOURCE_DIRECT,
		);

		if ( ! \function_exists( 'apply_filters' ) ) {
			return $resolution;
		}

		/**
		 * Filters the trusted client IP resolution.
		 *
		 * This is an integration point for a site owner who has explicitly
		 * configured another trusted reverse proxy. Do not return an
		 * X-Forwarded-For value without first verifying REMOTE_ADDR against that
		 * proxy's authoritative network list.
		 *
		 * @param array{ip: string, source: string} $resolution Core resolution.
		 * @param array<string, mixed>               $server     Server variables.
		 */
		$filtered = \apply_filters( 'cybermaps_client_ip_resolution', $resolution, $server );

		return self::validate_filtered_resolution( $filtered ) ?? $resolution;
	}

	/**
	 * Resolve only the effective client address.
	 *
	 * @param array<string, mixed>|null $server Server variables, or null for $_SERVER.
	 */
	public static function get_ip( ?array $server = null ): string {
		return self::resolve( $server )['ip'];
	}

	/**
	 * Determine whether an address is an official Cloudflare proxy address.
	 */
	public static function is_cloudflare_ip( string $ip ): bool {
		$ip = self::normalize_ip( $ip ) ?? '';
		if ( '' === $ip ) {
			return false;
		}

		foreach ( self::CLOUDFLARE_CIDRS as $cidr ) {
			if ( self::is_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a single IPv4 or IPv6 address.
	 *
	 * @param mixed $value Candidate server value.
	 */
	private static function normalize_ip( mixed $value ): ?string {
		if ( ! \is_string( $value ) || '' === $value ) {
			return null;
		}

		// Reject combined headers and control characters before trimming OWS.
		if ( \str_contains( $value, ',' ) || 1 === \preg_match( '/[\x00-\x08\x0A-\x1F\x7F]/', $value ) ) {
			return null;
		}

		$value = \trim( $value, " \t" );
		if ( '' === $value || 1 === \preg_match( '/\s/', $value ) ) {
			return null;
		}

		if ( false === \filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = \inet_pton( $value );
		if ( false === $packed ) {
			return null;
		}

		// Some web-server stacks expose IPv4 peers as IPv4-mapped IPv6.
		if ( 16 === \strlen( $packed ) && \str_repeat( "\0", 10 ) . "\xff\xff" === \substr( $packed, 0, 12 ) ) {
			$packed = \substr( $packed, 12 );
		}

		$normalized = \inet_ntop( $packed );

		return false === $normalized ? null : $normalized;
	}

	/**
	 * Validate a resolution returned through the public filter.
	 *
	 * @param mixed $value Filtered value.
	 * @return array{ip: string, source: string}|null
	 */
	private static function validate_filtered_resolution( mixed $value ): ?array {
		if ( ! \is_array( $value ) || ! \array_key_exists( 'ip', $value ) || ! \array_key_exists( 'source', $value ) ) {
			return null;
		}

		$ip     = self::normalize_ip( $value['ip'] );
		$source = $value['source'];
		if ( null === $ip || ! \is_string( $source ) || 1 !== \preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $source ) ) {
			return null;
		}

		return array(
			'ip'     => $ip,
			'source' => $source,
		);
	}

	/**
	 * Read trusted-proxy settings without turning proxy headers into origin or
	 * scheme configuration. Settings sanitization/UI are intentionally owned by
	 * the central settings component.
	 *
	 * @return array{cidrs: list<string>, header: string}
	 */
	private static function trusted_proxy_configuration(): array {
		$settings  = \get_option( 'cybermaps_settings', array() );
		$settings  = \is_array( $settings ) ? $settings : array();
		$raw_cidrs = $settings['trusted_proxy_cidrs'] ?? '';
		$header    = $settings['trusted_proxy_header'] ?? 'off';
		$values    = \is_array( $raw_cidrs ) ? $raw_cidrs : \preg_split( '/[\r\n,]+/', (string) $raw_cidrs );
		$cidrs     = self::valid_cidrs( $values );

		$configuration = array(
			'cidrs'  => array_values( array_unique( $cidrs ) ),
			'header' => \is_string( $header ) && \in_array( $header, array( 'off', 'forwarded', 'x_forwarded_for', 'x_real_ip' ), true ) ? $header : 'off',
		);

		if ( \function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the explicit trusted-proxy configuration.
			 *
			 * @param array{cidrs: list<string>, header: string} $configuration Validated configuration.
			 */
			$configuration = \apply_filters( 'cybermaps_trusted_proxy_configuration', $configuration );
		}

		return self::normalized_proxy_configuration( $configuration );
	}

	private static function is_configured_trusted_proxy( string $ip ): bool {
		$configuration = self::trusted_proxy_configuration();
		if ( 'off' === $configuration['header'] ) {
			return false;
		}
		foreach ( $configuration['cidrs'] as $cidr ) {
			if ( self::is_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the first non-proxy hop in the configured forwarding chain.
	 *
	 * @param array<string, mixed> $server Server variables.
	 */
	private static function trusted_proxy_client_ip( array $server ): ?string {
		$configuration = self::trusted_proxy_configuration();
		$header        = $configuration['header'];
		if ( 'off' === $header ) {
			return null;
		}
		if ( 'x_real_ip' === $header ) {
			return self::normalize_ip( $server['HTTP_X_REAL_IP'] ?? null );
		}

		$raw = 'forwarded' === $header
			? ( $server['HTTP_FORWARDED'] ?? null )
			: ( $server['HTTP_X_FORWARDED_FOR'] ?? null );
		if ( ! \is_string( $raw ) || \strlen( $raw ) > 4096 || 1 === \preg_match( '/[\x00-\x08\x0A-\x1F\x7F]/', $raw ) ) {
			return null;
		}

		$chain = 'forwarded' === $header ? self::parse_forwarded_chain( $raw ) : self::parse_x_forwarded_for_chain( $raw );
		return self::first_untrusted_hop( $chain, $configuration['cidrs'] );
	}

	/** @return array{ip:string,source:string}|null */
	private static function cloudflare_resolution( ?string $direct, array $server ): ?array {
		if ( null === $direct || ! self::is_cloudflare_ip( $direct ) ) {
			return null;
		}
		$forwarded = self::normalize_ip( $server['HTTP_CF_CONNECTING_IP'] ?? null );
		return null === $forwarded ? null : array(
			'ip'     => $forwarded,
			'source' => self::SOURCE_CLOUDFLARE,
		);
	}

	/** @return array{ip:string,source:string}|null */
	private static function trusted_proxy_resolution( ?string $direct, array $server ): ?array {
		if ( null === $direct || self::is_cloudflare_ip( $direct ) || ! self::is_configured_trusted_proxy( $direct ) ) {
			return null;
		}
		$forwarded = self::trusted_proxy_client_ip( $server );
		return null === $forwarded ? null : array(
			'ip'     => $forwarded,
			'source' => self::SOURCE_TRUSTED_PROXY,
		);
	}

	/** @param mixed $values @return list<string> */
	private static function valid_cidrs( mixed $values ): array {
		$valid = array();
		foreach ( \array_slice( \is_array( $values ) ? $values : array(), 0, 64 ) as $cidr ) {
			if ( \is_string( $cidr ) && self::is_valid_cidr( \trim( $cidr ) ) ) {
				$valid[] = \trim( $cidr );
			}
		}
		return array_values( array_unique( $valid ) );
	}

	/** @return array{cidrs:list<string>,header:string} */
	private static function normalized_proxy_configuration( mixed $configuration ): array {
		if ( ! \is_array( $configuration ) ) {
			return array(
				'cidrs'  => array(),
				'header' => 'off',
			);
		}
		$header = $configuration['header'] ?? 'off';
		$cidrs  = $configuration['cidrs'] ?? array();
		if ( ! \is_string( $header ) || ! \in_array( $header, array( 'off', 'forwarded', 'x_forwarded_for', 'x_real_ip' ), true ) || ! \is_array( $cidrs ) ) {
			return array(
				'cidrs'  => array(),
				'header' => 'off',
			);
		}
		return array(
			'cidrs'  => self::valid_cidrs( $cidrs ),
			'header' => $header,
		);
	}

	/** @param list<string> $chain @param list<string> $cidrs */
	private static function first_untrusted_hop( array $chain, array $cidrs ): ?string {
		for ( $index = \count( $chain ) - 1; $index >= 0; --$index ) {
			if ( ! self::is_trusted_hop( $chain[ $index ], $cidrs ) ) {
				return $chain[ $index ];
			}
		}
		return null;
	}

	/** @param list<string> $cidrs */
	private static function is_trusted_hop( string $ip, array $cidrs ): bool {
		foreach ( $cidrs as $cidr ) {
			if ( self::is_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return list<string> */
	private static function parse_x_forwarded_for_chain( string $raw ): array {
		$values = array();
		foreach ( \array_slice( \explode( ',', $raw ), 0, 32 ) as $candidate ) {
			$ip = self::normalize_ip( $candidate );
			if ( null === $ip ) {
				return array();
			}
			$values[] = $ip;
		}

		return $values;
	}

	/** @return list<string> */
	private static function parse_forwarded_chain( string $raw ): array {
		$values = array();
		foreach ( \array_slice( \explode( ',', $raw ), 0, 32 ) as $element ) {
			$matched = \preg_match( '/(?:^|;)\\s*for=(?:"\\[([^\\]]+)\\]"|"?([^;",\\s]+)"?)/i', $element, $match );
			if ( 1 !== $matched ) {
				return array();
			}
			$candidate = '' !== ( $match[1] ?? '' ) ? $match[1] : ( $match[2] ?? '' );
			$ip        = self::normalize_ip( $candidate );
			if ( null === $ip ) {
				return array();
			}
			$values[] = $ip;
		}

		return $values;
	}

	private static function is_valid_cidr( string $cidr ): bool {
		$parts = \explode( '/', $cidr, 2 );
		if ( 2 !== \count( $parts ) || ! \ctype_digit( $parts[1] ) ) {
			return false;
		}
		$network = self::normalize_ip( $parts[0] );
		if ( null === $network ) {
			return false;
		}
		$prefix = (int) $parts[1];

		return $prefix >= 0 && $prefix <= ( false === \strpos( $network, ':' ) ? 32 : 128 );
	}

	/**
	 * Determine whether an IP falls within a CIDR network.
	 */
	private static function is_in_cidr( string $ip, string $cidr ): bool {
		$parts = \explode( '/', $cidr, 2 );
		if ( 2 !== \count( $parts ) || ! \ctype_digit( $parts[1] ) ) {
			return false;
		}

		$address = \inet_pton( $ip );
		$network = \inet_pton( $parts[0] );
		if ( false === $address || false === $network || \strlen( $address ) !== \strlen( $network ) ) {
			return false;
		}

		$prefix      = (int) $parts[1];
		$maximum     = \strlen( $address ) * 8;
		$whole_bytes = \intdiv( $prefix, 8 );
		$remaining   = $prefix % 8;
		if ( $prefix < 0 || $prefix > $maximum ) {
			return false;
		}

		if ( $whole_bytes > 0 && \substr( $address, 0, $whole_bytes ) !== \substr( $network, 0, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $remaining ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $remaining ) ) & 0xff;

		return ( \ord( $address[ $whole_bytes ] ) & $mask ) === ( \ord( $network[ $whole_bytes ] ) & $mask );
	}
}
