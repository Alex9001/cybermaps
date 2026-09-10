<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap;

use Cybermaps\Core\AtomicOptionSequence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generation-scoped sitemap page occupancy manifest.
 */
final class PageOccupancyManifest {
	public const GENERATION_OPTION = 'cybermaps_sitemap_occupancy_generation';
	public const TOKEN_OPTION      = 'cybermaps_sitemap_occupancy_token';
	public const MANIFEST_OPTION   = 'cybermaps_sitemap_occupancy_manifest';
	public const MAX_RAW_PAGES     = 50000;
	private const MAX_PROVIDERS    = 10000;

	/**
	 * @return array<string, mixed>|null
	 */
	public static function load(): ?array {
		$manifest = \get_option( self::MANIFEST_OPTION, null );
		return \is_array( $manifest ) ? $manifest : null;
	}

	public static function current_generation(): int {
		return AtomicOptionSequence::current( self::GENERATION_OPTION );
	}

	public static function current_token(): string {
		global $wpdb;
		if (
			\is_object( $wpdb )
			&& isset( $wpdb->options )
			&& \is_string( $wpdb->options )
			&& \method_exists( $wpdb, 'prepare' )
			&& \method_exists( $wpdb, 'get_var' )
		) {
			$token = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
					$wpdb->options,
					self::TOKEN_OPTION
				)
			);
			if ( false === $token || ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) ) {
				return '';
			}
		} else {
			$token = \get_option( self::TOKEN_OPTION, '' );
		}
		return \is_scalar( $token ) ? (string) $token : '';
	}

	/**
	 * @param array<string, mixed>|null $manifest Loaded manifest.
	 */
	public static function is_usable( ?array $manifest = null ): bool {
		$manifest = $manifest ?? self::load();
		if ( ! \is_array( $manifest ) || empty( $manifest['complete'] ) ) {
			return false;
		}

		if ( ! self::has_current_fence( $manifest ) ) {
			return false;
		}

		return self::validate_provider_map( $manifest['providers'] ?? null );
	}

	/**
	 * @param array<string,mixed> $manifest Loaded manifest.
	 */
	private static function has_current_fence( array $manifest ): bool {
		$token = \is_scalar( $manifest['token'] ?? null ) ? (string) $manifest['token'] : '';
		return '' !== $token
			&& (int) ( $manifest['generation'] ?? 0 ) === self::current_generation()
			&& \hash_equals( self::current_token(), $token );
	}

	private static function validate_provider_map( mixed $providers ): bool {
		if ( ! \is_array( $providers ) || \count( $providers ) > self::MAX_PROVIDERS ) {
			return false;
		}

		$total_raw_pages = 0;
		foreach ( $providers as $provider_id => $record ) {
			if (
				! \is_string( $provider_id )
				|| '' === $provider_id
				|| \strlen( $provider_id ) > 191
				|| ! self::validate_provider_record( $record )
			) {
				return false;
			}
			$total_raw_pages += (int) $record['raw_page_count'];
			if ( $total_raw_pages > self::MAX_RAW_PAGES ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return the exact confirmed pages for one provider in a usable manifest.
	 *
	 * A missing provider in a complete manifest has no confirmed pages. Null is
	 * reserved for an unusable manifest so callers can fall back consistently.
	 *
	 * @param array<string,mixed>|null $manifest Loaded manifest.
	 * @return int[]|null
	 */
	public static function provider_pages( ?array $manifest, string $provider_id ): ?array {
		if ( ! self::is_usable( $manifest ) ) {
			return null;
		}

		$record = $manifest['providers'][ $provider_id ] ?? null;
		if ( null === $record ) {
			return array();
		}

		return \array_map( 'intval', $record['non_empty_pages'] );
	}

	/**
	 * @param array<string, mixed> $manifest Completed manifest.
	 * @return array<int, array{provider_id:string,provider_kind:string,provider_name:string,page:int,filename:string,loc:string,lastmod:string}>
	 */
	public static function entries_for_index( array $manifest, Orchestrator $orchestrator ): array {
		$providers = \is_array( $manifest['providers'] ?? null ) ? $manifest['providers'] : array();
		$routes    = PublicationRouteSlugs::resolve( \Cybermaps\Core\ConfigurationStore::settings() );
		$entries   = array();

		foreach ( $orchestrator->collect_weighted_providers() as $weighted_provider ) {
			$provider_id = (string) $weighted_provider['provider_id'];
			$entries     = \array_merge(
				$entries,
				self::entries_for_provider( $providers, $provider_id, $routes, $orchestrator )
			);
		}

		return $entries;
	}

	/**
	 * @param array<string,mixed>  $providers Provider records.
	 * @param array<string,string> $routes    Publication routes.
	 * @return array<int,array<string,mixed>>
	 */
	private static function entries_for_provider(
		array $providers,
		string $provider_id,
		array $routes,
		Orchestrator $orchestrator
	): array {
		if ( ! \is_array( $providers[ $provider_id ] ?? null ) ) {
			return array();
		}

		$record   = $providers[ $provider_id ];
		$provider = $orchestrator->get_provider( $provider_id );
		if ( ! $provider ) {
			return array();
		}

		$pages         = \is_array( $record['non_empty_pages'] ?? null ) ? $record['non_empty_pages'] : array();
		$page_lastmods = \is_array( $record['page_lastmod'] ?? null ) ? $record['page_lastmod'] : array();
		$entries       = array();
		foreach ( $pages as $page ) {
			$entry = self::page_entry( $provider_id, (int) $page, $routes, $page_lastmods, $provider );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @param array<string,string> $routes         Publication routes.
	 * @param array<mixed,mixed>   $page_lastmods Page modification times.
	 * @return array<string,mixed>|null
	 */
	private static function page_entry(
		string $provider_id,
		int $page,
		array $routes,
		array $page_lastmods,
		ProviderInterface $provider
	): ?array {
		if ( $page < 1 ) {
			return null;
		}
		$filename = ProviderIdentity::filename( $provider_id, $page, $routes );
		if ( '' === $filename ) {
			return null;
		}
		$lastmod = \is_scalar( $page_lastmods[ $page ] ?? null )
			? (string) $page_lastmods[ $page ]
			: $provider->get_lastmod();

		return array(
			'provider_id'   => $provider_id,
			'provider_kind' => ProviderIdentity::kind( $provider_id ),
			'provider_name' => ProviderIdentity::name( $provider_id ),
			'page'          => $page,
			'filename'      => $filename,
			'loc'           => \Cybermaps\Core\URLManager::get_home_url( '/' . $filename ),
			'lastmod'       => $lastmod,
		);
	}

	/**
	 * @param array<string, mixed> $record Provider occupancy record.
	 */
	public static function raw_page_count( array $record ): int {
		return max( 0, (int) ( $record['raw_page_count'] ?? 0 ) );
	}

	private static function validate_provider_record( mixed $record ): bool {
		if ( ! \is_array( $record ) || ! self::has_valid_record_shape( $record ) ) {
			return false;
		}

		$pages = self::validated_pages(
			$record['non_empty_pages'],
			(int) $record['raw_page_count']
		);
		if ( null === $pages ) {
			return false;
		}

		return self::validate_page_lastmods( $record['page_lastmod'], $pages );
	}

	/**
	 * @param array<string,mixed> $record Provider occupancy record.
	 */
	private static function has_valid_record_shape( array $record ): bool {
		return self::is_non_negative_integer( $record['raw_count'] ?? null )
			&& self::is_non_negative_integer( $record['raw_page_count'] ?? null )
			&& (int) $record['raw_page_count'] <= self::MAX_RAW_PAGES
			&& \is_array( $record['non_empty_pages'] ?? null )
			&& \array_is_list( $record['non_empty_pages'] )
			&& \is_array( $record['page_lastmod'] ?? null );
	}

	/**
	 * @param mixed[] $raw_pages Recorded non-empty pages.
	 * @return array<int,true>|null
	 */
	private static function validated_pages( array $raw_pages, int $raw_page_count ): ?array {
		$previous = 0;
		$pages    = array();
		foreach ( $raw_pages as $page ) {
			if ( ! self::is_non_negative_integer( $page ) ) {
				return null;
			}
			$page = (int) $page;
			if ( $page < 1 || $page > $raw_page_count || $page <= $previous ) {
				return null;
			}
			$pages[ $page ] = true;
			$previous       = $page;
		}

		return $pages;
	}

	/**
	 * @param array<mixed,mixed> $page_lastmods Modification times keyed by page.
	 * @param array<int,true>    $pages         Valid non-empty page set.
	 */
	private static function validate_page_lastmods( array $page_lastmods, array $pages ): bool {
		foreach ( $page_lastmods as $page => $lastmod ) {
			if (
				! self::is_non_negative_integer( $page )
				|| ! isset( $pages[ (int) $page ] )
				|| ! \is_string( $lastmod )
				|| \strlen( $lastmod ) > 64
				|| ( '' !== $lastmod && false === \strtotime( $lastmod ) )
			) {
				return false;
			}
		}

		return true;
	}

	private static function is_non_negative_integer( mixed $value ): bool {
		return ( \is_int( $value ) && $value >= 0 )
			|| ( \is_string( $value ) && 1 === \preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) );
	}
}
