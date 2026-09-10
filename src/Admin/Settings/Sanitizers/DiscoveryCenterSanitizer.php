<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Sanitizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiscoveryCenterSanitizer {

	private const ALLOWED_ARCHETYPES = array(
		'newspaper',
		'blog',
		'ecommerce',
		'knowledgebase',
		'corporate',
		'small-business',
		'medium-business',
	);

	private const ALLOWED_TYPE_INTENTS = array(
		'informational',
		'transactional',
	);

	public static function sanitize( $input ) {
		if ( \Cybermaps\Admin\MigrationHub::is_applying_prepared_import() ) {
			return self::import_value( $input );
		}

		$data = self::decode_submission( $input );
		if ( is_string( $data ) ) {
			return $data;
		}

		$overrides = self::sanitize_priority_map( $data['overrides'] ?? array() );
		$disabled  = self::sanitize_disabled_map( $data['disabled'] ?? array() );
		self::migrate_disabled_overrides( $overrides, $disabled );

		$sanitized = array(
			'archetype'    => self::sanitize_archetype( $data['archetype'] ?? '' ),
			'overrides'    => $overrides,
			'type_intents' => self::sanitize_type_intents( $data['type_intents'] ?? array() ),
			'disabled'     => $disabled,
		);

		return self::encode( $sanitized );
	}

	/**
	 * Preserve the prepared-import transport contract.
	 *
	 * @param mixed $input Raw option value.
	 */
	private static function import_value( $input ): string {
		return is_string( $input ) ? $input : '';
	}

	/**
	 * Decode an active submission or return the final value immediately.
	 *
	 * WordPress passes null for a registered option that was absent from an
	 * options.php request. The settings UI intentionally submits only the active
	 * tab's controls, so an absent strategy payload preserves the existing option.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string,mixed>|string
	 */
	private static function decode_submission( $input ) {
		if ( SettingsSanitizer::is_incomplete_main_submission() ) {
			return self::current_value();
		}
		if ( ! is_string( $input ) ) {
			return self::current_value();
		}
		if ( '' === trim( $input ) ) {
			return '';
		}

		$data = json_decode( $input, true );
		return is_array( $data ) ? $data : '';
	}

	/**
	 * Convert historical zero-priority exclusions to the canonical disabled map.
	 *
	 * @param array<string,float> $overrides Priority overrides, updated in place.
	 * @param array<string,bool>  $disabled  Disabled map, updated in place.
	 */
	private static function migrate_disabled_overrides( array &$overrides, array &$disabled ): void {
		foreach ( $overrides as $type => $priority ) {
			if ( $priority > 0 ) {
				continue;
			}

			$disabled[ $type ] = true;
			unset( $overrides[ $type ] );
		}
	}

	/**
	 * Sanitize the selected strategy archetype.
	 *
	 * @param mixed $archetype Raw archetype.
	 */
	private static function sanitize_archetype( $archetype ): string {
		$clean = is_scalar( $archetype ) ? sanitize_key( (string) $archetype ) : '';
		return in_array( $clean, self::ALLOWED_ARCHETYPES, true ) ? $clean : 'medium-business';
	}

	/**
	 * Encode the canonical strategy payload.
	 *
	 * @param array<string,mixed> $value Canonical strategy data.
	 */
	private static function encode( array $value ): string {
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Sanitize current per-content-type priority overrides.
	 *
	 * @param mixed $overrides Raw override map.
	 * @return array<string,float>
	 */
	private static function sanitize_priority_map( $overrides ): array {
		if ( ! is_array( $overrides ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( self::identity_first_entries( $overrides ) as $entry ) {
			if ( ! is_numeric( $entry['value'] ) ) {
				continue;
			}
			foreach ( self::normalize_type_keys( $entry['type'] ) as $clean_type ) {
				if ( ! array_key_exists( $clean_type, $sanitized ) ) {
					$sanitized[ $clean_type ] = self::clamp_priority( (float) $entry['value'] );
					if ( count( $sanitized ) >= \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX ) {
						return $sanitized;
					}
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize current per-content-type intent selections.
	 *
	 * @param mixed $intents Raw intent map.
	 * @return array<string,string>
	 */
	private static function sanitize_type_intents( $intents ): array {
		if ( ! is_array( $intents ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( self::identity_first_entries( $intents ) as $entry ) {
			$clean_intent = is_scalar( $entry['value'] )
				? sanitize_key( (string) $entry['value'] )
				: '';
			foreach ( self::normalize_type_keys( $entry['type'] ) as $clean_type ) {
				if ( ! array_key_exists( $clean_type, $sanitized ) ) {
					$sanitized[ $clean_type ] = self::normalize_intent( $clean_intent );
					if ( count( $sanitized ) >= \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX ) {
						return $sanitized;
					}
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize the sparse map of content groups excluded from publication.
	 *
	 * @param mixed $disabled Raw disabled map.
	 * @return array<string,bool>
	 */
	private static function sanitize_disabled_map( $disabled ): array {
		if ( ! is_array( $disabled ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( self::identity_first_entries( $disabled ) as $entry ) {
			$is_disabled = filter_var( $entry['value'], FILTER_VALIDATE_BOOLEAN );
			if ( ! $is_disabled ) {
				continue;
			}
			foreach ( self::normalize_type_keys( $entry['type'] ) as $clean_type ) {
				if ( ! array_key_exists( $clean_type, $sanitized ) ) {
					$sanitized[ $clean_type ] = true;
					if ( count( $sanitized ) >= \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX ) {
						return $sanitized;
					}
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Preserve historical array-valued options without erasing them when a
	 * different settings tab submits the shared form.
	 */
	private static function current_value(): string {
		$current = get_option( 'cybermaps_discovery_center', '' );
		if ( is_string( $current ) ) {
			return $current;
		}
		if ( ! is_array( $current ) ) {
			return '';
		}

		$encoded = wp_json_encode( $current );
		return is_string( $encoded ) ? $encoded : '';
	}

	private static function clamp_priority( float $priority ): float {
		// Exact zero remains the historical publication-off marker and is
		// migrated to the independent disabled map by sanitize(). Every
		// positive value must match the 0.1–1.0 scale exposed by the UI.
		if ( $priority <= 0.0 ) {
			return 0.0;
		}

		return max( 0.1, min( 1.0, $priority ) );
	}

	/**
	 * Put explicit kind-aware keys before raw legacy fallbacks so an old raw
	 * slug can fill only the object kind that lacks an explicit value.
	 *
	 * @param array<int|string,mixed> $values
	 * @return array<int,array{type:string,value:mixed}>
	 */
	private static function identity_first_entries( array $values ): array {
		$explicit = array();
		$legacy   = array();
		foreach ( $values as $type => $value ) {
			$entry = array(
				'type'  => (string) $type,
				'value' => $value,
			);
			$kind  = \Cybermaps\Sitemap\ProviderIdentity::kind( (string) $type );
			if ( in_array( $kind, array( 'post_type', 'taxonomy' ), true ) ) {
				$explicit[] = $entry;
			} else {
				$legacy[] = $entry;
			}
		}

		return array_merge( $explicit, $legacy );
	}

	/**
	 * Expand a legacy raw slug to its registered public object kind(s).
	 *
	 * Ambiguous legacy keys deterministically seed both identities. Unknown raw
	 * slugs stay intact so a late-registering companion plugin can still use
	 * the saved value. Malformed pseudo-identities are rejected.
	 *
	 * @return string[]
	 */
	private static function normalize_type_keys( string $type ): array {
		$kind = \Cybermaps\Sitemap\ProviderIdentity::kind( $type );
		if ( in_array( $kind, array( 'post_type', 'taxonomy' ), true ) ) {
			return array( $type );
		}
		if ( str_contains( $type, ':' ) ) {
			return array();
		}

		$type = sanitize_key( $type );
		$type = 'tag' === $type ? 'post_tag' : $type;
		if ( '' === $type ) {
			return array();
		}

		$identities = \Cybermaps\Sitemap\ProviderIdentity::public_object_identities( $type );
		return ! empty( $identities ) ? $identities : array( $type );
	}

	/**
	 * Keep the stored strategy aligned with the two intent choices exposed by
	 * the editor and matrix. Legacy four-intent values are folded into their
	 * equivalent current bucket instead of leaking unsupported labels.
	 */
	private static function normalize_intent( string $intent ): string {
		if ( 'commercial' === $intent ) {
			return 'transactional';
		}
		if ( 'navigational' === $intent ) {
			return 'informational';
		}

		return in_array( $intent, self::ALLOWED_TYPE_INTENTS, true )
			? $intent
			: 'informational';
	}
}
