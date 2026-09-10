<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Sanitizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsSitemapNormalizer {
	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @param array<string, mixed> $old_options
	 */
	public static function normalize( array $input, array $sanitized, array $old_options, string $active_tab ): array {
		$sanitized = self::normalize_sitemap_flags( $input, $sanitized, $active_tab );
		$sanitized = self::normalize_news_settings( $input, $sanitized );
		$sanitized = self::normalize_rss_settings( $input, $sanitized, $old_options, $active_tab );
		$sanitized = self::normalize_sitemap_routes( $input, $sanitized );
		return self::normalize_sitemap_exclusions( $input, $sanitized, $old_options );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_sitemap_flags( array $input, array $sanitized, string $active_tab ): array {
		$keys = array(
			'include_homepage',
			'include_authors',
			'include_archives',
			'update_comment_post',
			'update_comment_page',
			'include_empty_terms',
			'inject_robots',
			'enable_caching',
			'enable_translation_integrations',
			'enable_indexnow',
			'enable_websub',
			'enable_google_news',
			'redirect_wp_sitemap',
			'redirect_default_sitemap',
			'redirect_news_sitemap',
			'enable_video_schema',
			'enable_rss_sitemap',
		);
		foreach ( $keys as $key ) {
			if ( SettingsSanitizer::should_process_checkbox( $input, $key, 'sitemaps', $active_tab ) ) {
				$sanitized[ $key ] = ! empty( $input[ $key ] ) ? '1' : '0';
			}
		}
		if ( array_key_exists( 'websub_hubs', $input ) ) {
			$sanitized['websub_hubs'] = implode( "\n", \Cybermaps\Discovery\WebSub::normalize_hubs( SettingsSanitizer::scalar_string( $input['websub_hubs'] ) ) );
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_news_settings( array $input, array $sanitized ): array {
		if ( array_key_exists( 'news_publication_name', $input ) ) {
			$sanitized['news_publication_name'] = \Cybermaps\Discovery\PublicationConstraints::bounded_text(
				sanitize_text_field( SettingsSanitizer::scalar_string( $input['news_publication_name'] ) ),
				\Cybermaps\Discovery\PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH
			);
		}
		foreach ( array( 'news_sitemap_url_base', 'sitemap_url_base', 'rss_sitemap_url_base' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$sanitized[ $key ] = is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
			}
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @param array<string, mixed> $old_options
	 * @return array<string, mixed>
	 */
	private static function normalize_rss_settings( array $input, array $sanitized, array $old_options, string $active_tab ): array {
		if ( array_key_exists( 'rss_sitemap_limit', $input ) ) {
			$limit                          = SettingsSanitizer::value_or_default( $input['rss_sitemap_limit'], 100 );
			$sanitized['rss_sitemap_limit'] = max( 1, min( 1000, absint( $limit ) ) );
		}
		if ( array_key_exists( 'rss_sitemap_types', $input ) || 'sitemaps' === $active_tab ) {
			$sanitized['rss_sitemap_types'] = SettingsSanitizer::sanitize_key_list( $input['rss_sitemap_types'] ?? array(), \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX );
		} elseif ( ! isset( $sanitized['rss_sitemap_types'] ) ) {
			$sanitized = self::migrate_legacy_rss_types( $input, $sanitized, $old_options );
		}
		unset( $sanitized['rss_sitemap_post_types'] );
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @param array<string, mixed> $old_options
	 * @return array<string, mixed>
	 */
	private static function migrate_legacy_rss_types( array $input, array $sanitized, array $old_options ): array {
		$legacy = $input['rss_sitemap_post_types'] ?? ( $old_options['rss_sitemap_post_types'] ?? null );
		if ( null === $legacy ) {
			return $sanitized;
		}
		$legacy                         = is_array( $legacy ) ? $legacy : explode( ',', SettingsSanitizer::scalar_string( $legacy ) );
		$sanitized['rss_sitemap_types'] = SettingsSanitizer::sanitize_key_list( $legacy, \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX );
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_sitemap_routes( array $input, array $sanitized ): array {
		foreach ( array( 'external_sitemaps', 'external_pages' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$raw               = sanitize_textarea_field( SettingsSanitizer::scalar_string( $input[ $key ] ) );
				$sanitized[ $key ] = 'external_pages' === $key
					? \Cybermaps\Sitemap\ExternalSitemapValidator::filter_page_textarea( $raw )
					: \Cybermaps\Sitemap\ExternalSitemapValidator::filter_textarea( $raw );
			}
		}
		if ( array_key_exists( 'exclude_post_ids', $input ) ) {
			$sanitized['exclude_post_ids'] = SettingsSanitizer::sanitize_id_list( $input['exclude_post_ids'] );
		}
		if ( array_key_exists( 'exclude_categories', $input ) ) {
			$sanitized['exclude_categories'] = SettingsSanitizer::sanitize_term_list( $input['exclude_categories'] );
		}
		if ( array_key_exists( 'site_language', $input ) ) {
			$sanitized['site_language'] = self::normalize_site_language( $input['site_language'] );
		}
		return $sanitized;
	}

	private static function normalize_site_language( $value ): string {
		$language = \Cybermaps\Core\TranslationHelper::normalize_hreflang( SettingsSanitizer::scalar_string( $value ) );
		if ( '' === $language ) {
			$language = \Cybermaps\Core\TranslationHelper::normalize_hreflang( (string) get_bloginfo( 'language' ) );
		}
		return '' !== $language ? $language : 'en';
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_sitemap_exclusions( array $input, array $sanitized, array $old_options ): array {
		unset( $sanitized['enable_static_engine'] );
		$valid_modes = array( 'off', 'well_known', 'all' );
		if ( array_key_exists( 'static_engine_mode', $input ) && in_array( $input['static_engine_mode'], $valid_modes, true ) ) {
			$sanitized['static_engine_mode'] = $input['static_engine_mode'];
		} elseif ( isset( $old_options['static_engine_mode'] ) && in_array( $old_options['static_engine_mode'], $valid_modes, true ) ) {
			$sanitized['static_engine_mode'] = $old_options['static_engine_mode'];
		} else {
			$sanitized['static_engine_mode'] = 'well_known';
		}
		return $sanitized;
	}
}
