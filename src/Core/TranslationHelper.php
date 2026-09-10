<?php
/**
 * Multilingual Translation Helper
 *
 * @package Cybermaps\Core
 */

declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TranslationHelper {

	/**
	 * Get current language code.
	 *
	 * @return string
	 */
	public static function get_current_language() {
		// 1. Detect Polylang
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
			if ( \is_scalar( $lang ) && '' !== (string) $lang ) {
				return self::normalize_language_code( (string) $lang );
			}
		}

			// 2. Detect WPML
		if ( function_exists( 'apply_filters' ) ) {
			$lang = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML owns this documented third-party hook.
			if ( is_string( $lang ) && '' !== $lang ) {
				return self::normalize_language_code( $lang );
			}
		}
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return self::normalize_language_code( (string) ICL_LANGUAGE_CODE );
		}

		// 3. Fallback to site locale
		$locale = self::normalize_language_code( (string) get_locale() );
		return (string) ( preg_split( '/[-_]/', $locale, 2 )[0] ?? '' );
	}

	/**
	 * Get all active language codes.
	 *
	 * @return array
	 */
	public static function get_active_languages() {
		$languages = array();

		// 1. Polylang
		if ( function_exists( 'pll_languages_list' ) ) {
			$languages = (array) pll_languages_list();
		}

		// 2. WPML
		if ( empty( $languages ) && function_exists( 'apply_filters' ) ) {
			$wpml_languages = apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML owns this documented third-party hook.
				'wpml_active_languages',
				null,
				array( 'skip_missing' => 0 )
			);
			if ( is_array( $wpml_languages ) ) {
				$languages = array_keys( $wpml_languages );
			}
		}
		if ( empty( $languages ) && function_exists( 'icl_get_languages' ) ) {
			$langs = icl_get_languages( 'skip_missing=0' );
			if ( is_array( $langs ) ) {
				$languages = array_keys( $langs );
			}
		}

		// 3. Default
		if ( empty( $languages ) ) {
			$languages = array( self::get_current_language() );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $language ): string => \is_scalar( $language )
							? self::normalize_language_code( (string) $language )
							: '',
						$languages
					)
				)
			)
		);
	}

	/**
	 * Determine whether a route language is currently published by WordPress.
	 */
	public static function is_active_language( string $lang ): bool {
		return '' !== self::resolve_active_language( $lang );
	}

	/**
	 * Resolve a route spelling to the exact language slug exposed by the
	 * translation plugin.
	 *
	 * Hyphens and underscores are equivalent for matching, but the original
	 * active slug is returned because Polylang and WPML queries expect their own
	 * registered value rather than an invented normalized variant.
	 */
	public static function resolve_active_language( string $lang ): string {
		$comparison = self::language_comparison_key( $lang );
		if ( '' === $comparison ) {
			return '';
		}

		foreach ( self::get_active_languages() as $active_language ) {
			if ( self::language_comparison_key( $active_language ) === $comparison ) {
				return $active_language;
			}
		}

		return '';
	}

	/**
	 * Switch to a specific language context.
	 *
	 * @param string $lang Language code.
	 */
	public static function switch_to_language( $lang ) {
		$lang = \is_scalar( $lang )
			? self::normalize_language_code( (string) $lang )
			: '';
		if ( '' === $lang ) {
			return;
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'ICL_LANGUAGE_CODE' ) || class_exists( 'SitePress' ) ) {
			global $sitepress;
			if ( is_object( $sitepress ) && method_exists( $sitepress, 'switch_lang' ) ) {
				$sitepress->switch_lang( $lang );
				return;
			}

			if ( function_exists( 'do_action' ) ) {
				do_action( 'wpml_switch_language', $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML owns this documented third-party hook.
			}
		}
	}

	/**
	 * Normalize one language tag for an HTML or XML hreflang value.
	 *
	 * WordPress, WPML, and Polylang may expose locale separators differently.
	 * Hreflang uses BCP 47-style hyphenated tags; accepting only bounded
	 * alphanumeric subtags prevents malformed stored rows from reaching XML.
	 */
	public static function normalize_hreflang( mixed $language ): string {
		if ( ! \is_scalar( $language ) ) {
			return '';
		}

		$language = \str_replace( '_', '-', \trim( (string) $language ) );
		if ( '' === $language || \strlen( $language ) > 35 ) {
			return '';
		}

		$comparison = \strtolower( $language );
		if ( 'x-default' === $comparison ) {
			return 'x-default';
		}
		if ( 1 !== \preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/i', $language ) ) {
			return '';
		}

		$parts = \explode( '-', $comparison );
		foreach ( $parts as $index => $part ) {
			if ( 0 === $index ) {
				continue;
			}
			if ( 4 === \strlen( $part ) && \ctype_alpha( $part ) ) {
				$parts[ $index ] = \ucfirst( $part );
			} elseif (
				( 2 === \strlen( $part ) && \ctype_alpha( $part ) )
				|| ( 3 === \strlen( $part ) && \ctype_digit( $part ) )
			) {
				$parts[ $index ] = \strtoupper( $part );
			}
		}

		return \implode( '-', $parts );
	}

	/**
	 * Normalize locale-style and route-style language identifiers.
	 */
	private static function normalize_language_code( string $lang ): string {
		$lang = strtolower( trim( $lang ) );
		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9_-]{0,14}[a-z0-9])$/', $lang ) ? $lang : '';
	}

	/**
	 * Build a separator-insensitive language comparison key.
	 */
	private static function language_comparison_key( string $lang ): string {
		$lang = self::normalize_language_code( $lang );
		return str_replace( '_', '-', $lang );
	}
}
