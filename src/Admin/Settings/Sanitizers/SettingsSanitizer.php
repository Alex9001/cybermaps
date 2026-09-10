<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Sanitizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsSanitizer {
	/**
	 * Explicit base used by configuration imports.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $base_override = null;

	/**
	 * Active-tab override used by configuration imports.
	 */
	private static ?string $tab_override = null;

	/**
	 * Sanitize the shared Cybermaps settings option without erasing fields from
	 * tabs that were not being edited.
	 *
	 * @param mixed $input Submitted option value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		if ( \Cybermaps\Admin\MigrationHub::is_applying_prepared_import() ) {
			return is_array( $input ) ? $input : array();
		}

		$old_options = null !== self::$base_override
			? self::$base_override
			: get_option( 'cybermaps_settings', array() );
		$old_options = is_array( $old_options ) ? $old_options : array();
		if ( self::is_incomplete_main_submission( true ) ) {
			return $old_options;
		}

		$input      = is_array( $input ) ? $input : array();
		$sanitized  = $old_options;
		$active_tab = self::get_active_tab();

		$sanitized = SettingsSitemapNormalizer::normalize( $input, $sanitized, $old_options, $active_tab );
		$sanitized = SettingsDiscoveryNormalizer::normalize( $input, $sanitized, $old_options, $active_tab );
		$sanitized = SettingsAnalyticsNormalizer::normalize( $input, $sanitized, $old_options, $active_tab );
		$sanitized = SettingsAdvancedNormalizer::normalize( $input, $sanitized, $old_options, $active_tab );

		$new_routes = \Cybermaps\Sitemap\PublicationRouteSlugs::resolve( $sanitized );

		$sanitized['sitemap_url_base']      = $new_routes['sitemap_url_base'];
		$sanitized['news_sitemap_url_base'] = $new_routes['news_sitemap_url_base'];
		$sanitized['rss_sitemap_url_base']  = $new_routes['rss_sitemap_url_base'];

		return $sanitized;
	}

	/**
	 * Detect a Settings API request truncated before the form's final marker.
	 *
	 * Direct sanitizer calls, CLI/import operations, and the narrow analytics
	 * form do not carry the main option-page identifier and are unaffected.
	 * Structured-option sanitizers use this same guard so a partially parsed
	 * nested array cannot replace the complete stored value.
	 */
	public static function is_incomplete_main_submission( bool $report = false ): bool {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- The Settings API verifies the option-group nonce before invoking registered sanitizers; these values only select fail-closed control flow.
		if ( ! isset( $_POST['option_page'] ) || ! is_scalar( $_POST['option_page'] ) ) {
			return false;
		}

		$option_page = sanitize_key( wp_unslash( (string) $_POST['option_page'] ) );
		if ( 'cybermaps_options_group' !== $option_page ) {
			return false;
		}

		$complete = isset( $_POST['cybermaps_form_complete'] )
			&& is_scalar( $_POST['cybermaps_form_complete'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_POST['cybermaps_form_complete'] ) );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $complete ) {
			return false;
		}

		if ( $report && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'cybermaps_settings',
				'cybermaps_input_truncated',
				__( 'PHP truncated the Cybermaps settings request before it was complete. No Cybermaps settings were changed; reduce unusually large repeater lists or ask your host to raise max_input_vars, then try again.', 'cybermaps' ),
				'error'
			);
		}

		return true;
	}

	/**
	 * Sanitize an imported settings payload against an explicit merge base.
	 *
	 * Imports have no active settings tab, so absent checkboxes are preserved in
	 * merge mode and omitted in overwrite mode instead of being guessed from
	 * request-global POST state.
	 *
	 * @param array<string, mixed> $input Imported values.
	 * @param array<string, mixed> $base  Existing values for merge, or an empty array for overwrite.
	 * @return array<string, mixed>
	 */
	public static function sanitize_import( array $input, array $base ): array {
		self::$base_override = $base;
		self::$tab_override  = '';

		try {
			return self::sanitize( $input );
		} finally {
			self::$base_override = null;
			self::$tab_override  = null;
		}
	}

	/**
	 * Read the tab that submitted the shared settings form.
	 */
	private static function get_active_tab(): string {
		if ( null !== self::$tab_override ) {
			return self::$tab_override;
		}

		// The Settings API verifies the option-group nonce before this callback runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST['cybermaps_active_tab'] ) && is_scalar( $_POST['cybermaps_active_tab'] )
			? sanitize_key( wp_unslash( (string) $_POST['cybermaps_active_tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
	}

	/**
	 * Process a checkbox when it was submitted or belongs to the active tab.
	 *
	 * Missing checkboxes on the active tab mean "unchecked"; missing checkboxes
	 * on every other tab mean "preserve the stored value".
	 *
	 * @param array<string, mixed> $input Submitted settings.
	 */
	public static function should_process_checkbox( array $input, string $key, string $tab, string $active_tab ): bool {
		return array_key_exists( $key, $input ) || $tab === $active_tab;
	}

	/**
	 * Use a runtime default for blank or non-scalar form values.
	 *
	 * @param mixed $value   Submitted value.
	 * @param mixed $fallback Canonical fallback.
	 * @return mixed
	 */
	public static function value_or_default( $value, $fallback ) {
		return is_scalar( $value ) && '' !== trim( (string) $value )
			? $value
			: $fallback;
	}

	/**
	 * @param mixed $values Candidate list.
	 * @return array<int, string>
	 */
	public static function sanitize_key_list( $values, int $limit = PHP_INT_MAX ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$values = array_slice( $values, 0, max( 0, $limit ) );

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( $value ): string => sanitize_key( self::scalar_string( $value ) ),
						$values
					)
				)
			)
		);
	}

	/**
	 * Normalize the optional comma-separated LLMS taxonomy inventory.
	 *
	 * WordPress taxonomy keys are at most 32 bytes. Limiting both the raw input
	 * and retained key count prevents an imported value from creating an
	 * unbounded tax_query while preserving far more entries than a normal site
	 * can reasonably register.
	 *
	 * @param mixed $value Candidate taxonomy list.
	 */
	public static function sanitize_taxonomy_filter( $value ): string {
		$raw        = \Cybermaps\Discovery\PublicationConstraints::bounded_text(
			self::scalar_string( $value ),
			\Cybermaps\Discovery\PublicationConstraints::TAXONOMY_FILTER_MAX_LENGTH
		);
		$taxonomies = array();

		foreach ( explode( ',', $raw ) as $candidate ) {
			if ( count( $taxonomies ) >= \Cybermaps\Discovery\PublicationConstraints::TAXONOMY_FILTER_ITEMS_MAX ) {
				break;
			}

			$taxonomy = sanitize_key( trim( $candidate ) );
			if (
				'' === $taxonomy
				|| strlen( $taxonomy ) > \Cybermaps\Discovery\PublicationConstraints::TAXONOMY_NAME_MAX_LENGTH
				|| in_array( $taxonomy, $taxonomies, true )
			) {
				continue;
			}

			$taxonomies[] = $taxonomy;
		}

		return implode( ', ', $taxonomies );
	}

	/**
	 * @param mixed $values Candidate list.
	 * @return array<int, string>
	 */
	public static function sanitize_text_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static fn ( $value ): string => sanitize_text_field( self::scalar_string( $value ) ),
					$values
				)
			)
		);
	}

	/**
	 * Normalize a comma-separated positive-ID list.
	 *
	 * @param mixed $value Candidate list.
	 */
	public static function sanitize_id_list(
		$value,
		int $limit = \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX
	): string {
		return \Cybermaps\Core\PositiveIdList::to_csv(
			$value,
			$limit,
			\Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES
		);
	}

	/**
	 * Collapse the retired AI-sitemap exclusion alias into the canonical
	 * global AI exclusion before any setting-specific sanitization runs.
	 *
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $sanitized
	 */
	public static function canonicalize_ai_exclusion_ids( array &$input, array &$sanitized ): void {
		$legacy_input = $input['ai_sitemap_exclude_ids'] ?? null;
		$legacy_saved = $sanitized['ai_sitemap_exclude_ids'] ?? null;
		$canonical    = $input['llms_exclude_ids'] ?? ( $sanitized['llms_exclude_ids'] ?? null );

		if ( null !== $legacy_input || null !== $legacy_saved ) {
			$input['llms_exclude_ids'] = \Cybermaps\Core\PositiveIdList::to_csv(
				array_merge(
					\Cybermaps\Core\PositiveIdList::parse( $canonical ),
					\Cybermaps\Core\PositiveIdList::parse( null !== $legacy_input ? $legacy_input : $legacy_saved )
				),
				\Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX,
				\Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES
			);
		}

		unset( $input['ai_sitemap_exclude_ids'], $sanitized['ai_sitemap_exclude_ids'] );
	}

	/**
	 * Normalize a comma-separated global AI term list.
	 *
	 * Numeric term IDs remain numeric; labels and slugs become stable slugs so
	 * every AI publication can apply the same comparison.
	 *
	 * @param mixed $value Candidate list.
	 */
	public static function sanitize_term_list(
		$value,
		int $limit = \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX
	): string {
		return \Cybermaps\Core\TermExclusionList::to_csv(
			$value,
			$limit,
			\Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES
		);
	}

	/**
	 * Convert only PHP scalar values to text.
	 *
	 * Malformed nested POST values must fail closed instead of emitting
	 * "Array to string conversion" warnings in WordPress's sanitize callback.
	 */
	public static function scalar_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
