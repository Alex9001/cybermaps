<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\CacheManager;
use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Discovery\LLMSTLDR\LLMSTLDRGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves Cybermaps' opt-in experimental budgeted site briefing.
 */
class LLMSTLDR {
	public const CACHE_KEY                 = 'cybermaps_tldr_cache';
	public const CACHE_TTL                 = HOUR_IN_SECONDS;
	private const DATABASE_CACHE_MAX_BYTES = 512 * 1024;

	public function handle(): void {
		$path = (string) \Cybermaps\Core\URLManager::get_request_path();
		$lang = $this->get_request_language( $path );
		if ( null === $lang ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if (
			empty( $settings['enable_discovery_hub'] )
			|| empty( $settings['enable_llms_tldr'] )
			|| ( '' !== $lang && ! $this->localized_request_is_enabled( $lang, $settings ) )
		) {
			return;
		}
		PublicationRequestGuard::enforce_active_route();
		if ( '' !== $lang ) {
			$lang = (string) \Cybermaps\Core\TranslationHelper::resolve_active_language( $lang );
		}

		$original_language = '';
		if ( '' !== $lang ) {
			$original_language = (string) \Cybermaps\Core\TranslationHelper::get_current_language();
			\Cybermaps\Core\TranslationHelper::switch_to_language( $lang );
		}

		try {
			try {
				$output = $this->get_content( false, $lang );
			} finally {
				if ( '' !== $lang ) {
					\Cybermaps\Core\TranslationHelper::switch_to_language( $original_language );
				}
			}
		} catch ( BuildUnavailableException $error ) {
			PublicationRequestGuard::serve_unavailable( $error );
		}

		Integrity::send_headers( $output );
		header( 'Content-Type: text/plain; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text protocol body.
			echo $output;
		}
		exit;
	}

	/**
	 * Match the canonical or localized briefing route.
	 */
	protected function get_request_language( string $path ): ?string {
		if ( '/llms-tldr.txt' === $path ) {
			return '';
		}
		if ( 1 === preg_match( '/^\/([a-z0-9_-]{2,16})\/llms-tldr\.txt$/', $path, $matches ) ) {
			return (string) $matches[1];
		}

		return null;
	}

	/**
	 * Localized routes exist only for the enabled multilingual publication hub.
	 */
	protected function localized_request_is_enabled( string $language, array $settings ): bool {
		return ! empty( $settings['enable_multilingual_hub'] )
			&& \Cybermaps\Core\TranslationHelper::is_active_language( $language );
	}

	public static function invalidate_cache(): void {
		delete_transient( self::CACHE_KEY );
		CacheManager::clear_family( 'discovery' );
	}

	public function get_content( bool $skip_cache = false, string $language = '' ): string {
		$key = self::CACHE_KEY;
		if ( '' !== $language ) {
			$key .= ':' . sanitize_key( $language );
		}
		$producer = static function () use ( $language ): string {
			$settings = \Cybermaps\Core\ConfigurationStore::settings();
			$result   = ( new LLMSTLDRGenerator( new PublicationInventory( $settings, null, $language ) ) )->generate_publication(
				$settings,
				(string) get_bloginfo( 'name' )
			);
			return (string) $result['output'];
		};

		return (string) CacheManager::remember(
			$key,
			self::CACHE_TTL,
			'discovery',
			$producer,
			static fn ( $output ): bool =>
				\is_string( $output )
				&& '' !== $output
				&& \strlen( $output ) <= PublicationConstraints::BRIEFING_OUTPUT_MAX_BYTES
				&& (
					\strlen( $output ) <= self::DATABASE_CACHE_MAX_BYTES
					|| ( \function_exists( 'wp_using_ext_object_cache' ) && \wp_using_ext_object_cache() )
				),
			60,
			$skip_cache
		);
	}
}
