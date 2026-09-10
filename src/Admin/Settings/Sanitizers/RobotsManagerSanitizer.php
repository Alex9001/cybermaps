<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Sanitizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RobotsManagerSanitizer {
	public static function sanitize( $input ) {
		if ( \Cybermaps\Admin\MigrationHub::is_applying_prepared_import() ) {
			return is_array( $input ) ? $input : array();
		}
		if ( SettingsSanitizer::is_incomplete_main_submission() ) {
			return self::stored_policy();
		}
		$input = self::decode_input( $input );
		if ( ! is_array( $input ) ) {
			return self::stored_policy();
		}
		$sanitized                     = self::default_policy();
		$sanitized['takeover_enabled'] = ! empty( $input['takeover_enabled'] );
		$sanitized['overrides']        = self::sanitize_crawler_overrides( $input );
		if ( isset( $input['manual_directives'] ) ) {
			$sanitized['manual_directives'] = self::sanitize_manual_directives( $input['manual_directives'] );
		}
		$sanitized['content_signals']         = self::sanitize_content_signals( $input['content_signals'] ?? array() );
		$sanitized['content_usage_enabled']   = ! empty( $input['content_usage_enabled'] );
		$sanitized['content_usage_overrides'] = self::sanitize_content_usage_overrides( $input['content_usage_overrides'] ?? array() );
		return $sanitized;
	}

	private static function stored_policy(): array {
		$current = get_option( 'cybermaps_robots_manager', array() );
		return is_array( $current ) ? $current : array();
	}

	private static function decode_input( $input ) {
		if ( ! is_string( $input ) ) {
			return $input;
		}
		$decoded = json_decode( $input, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/** @return array<string, mixed> */
	private static function default_policy(): array {
		return array(
			'takeover_enabled'        => false,
			'overrides'               => array(),
			'manual_directives'       => '',
			'content_signals'         => array(),
			'content_usage_enabled'   => false,
			'content_usage_overrides' => array(),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, array{robots:bool,llm:bool,tpm:int}>
	 */
	private static function sanitize_crawler_overrides( array $input ): array {
		if ( ! empty( $input['reset_overrides'] ) || ! isset( $input['overrides'] ) || ! is_array( $input['overrides'] ) ) {
			return array();
		}
		$registered = \Cybermaps\Core\CrawlerRegistry::get_policy_bots();
		$normalized = \Cybermaps\Core\CrawlerRegistry::normalize_overrides( $input['overrides'] );
		$overrides  = array();
		foreach ( $normalized as $bot_id => $perms ) {
			$bot_id = \Cybermaps\Core\CrawlerRegistry::canonicalize_id( sanitize_key( $bot_id ) );
			if ( ! isset( $registered[ $bot_id ] ) || ! is_array( $perms ) ) {
				continue;
			}
			$policy   = self::crawler_policy( $registered[ $bot_id ], $perms );
			$defaults = self::crawler_defaults( $registered[ $bot_id ] );
			if ( $policy !== $defaults ) {
				$overrides[ $bot_id ] = $policy;
			}
		}
		return $overrides;
	}

	/** @return array{robots:bool,llm:bool,tpm:int} */
	private static function crawler_policy( object $crawler, array $perms ): array {
		$supports_manifest = \Cybermaps\Core\CrawlerRegistry::supports_manifest_target( $crawler );
		return array(
			'robots' => ! empty( $perms['robots'] ),
			'llm'    => $supports_manifest && ! empty( $perms['llm'] ),
			'tpm'    => isset( $perms['tpm'] ) ? min( 10000, absint( $perms['tpm'] ) ) : 0,
		);
	}

	/** @return array{robots:bool,llm:bool,tpm:int} */
	private static function crawler_defaults( object $crawler ): array {
		$supports_manifest = \Cybermaps\Core\CrawlerRegistry::supports_manifest_target( $crawler );
		return array(
			'robots' => ! empty( $crawler->default['robots'] ),
			'llm'    => $supports_manifest && ! empty( $crawler->default['llm'] ),
			'tpm'    => 0,
		);
	}

	private static function sanitize_manual_directives( $value ): string {
		$manual = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
		return \Cybermaps\Discovery\PublicationConstraints::bounded_text( $manual, \Cybermaps\Discovery\Robots::MAX_MANUAL_DIRECTIVES_BYTES );
	}

	/**
	 * @param mixed $input
	 * @return array<string, string>
	 */
	private static function sanitize_content_signals( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$allowed = array_fill_keys( array( 'ai-train', 'search', 'ai-input' ), true );
		$aliases = array(
			'training' => 'ai-train',
			'ai_train' => 'ai-train',
			'ai_input' => 'ai-input',
		);
		$signals = array();
		foreach ( $input as $key => $value ) {
			$key = $aliases[ sanitize_key( $key ) ] ?? sanitize_key( $key );
			if ( isset( $allowed[ $key ] ) && in_array( $value, array( 'yes', 'no' ), true ) ) {
				$signals[ $key ] = $value;
			}
		}
		return $signals;
	}

	/**
	 * @param mixed $input
	 * @return array<string, array<string, string>>
	 */
	private static function sanitize_content_usage_overrides( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$overrides = array();
		foreach ( $input as $key => $raw_override ) {
			$path = is_numeric( $key ) && is_array( $raw_override ) ? ( $raw_override['path'] ?? '' ) : $key;
			$path = \Cybermaps\Discovery\Robots::normalize_content_usage_path( $path );
			if ( '' === $path || ! is_array( $raw_override ) ) {
				continue;
			}
			$preferences = self::content_usage_preferences( $raw_override );
			if ( array() === $preferences ) {
				continue;
			}
			$overrides[ $path ] = $preferences;
			if ( count( $overrides ) >= \Cybermaps\Discovery\Robots::MAX_CONTENT_USAGE_OVERRIDES ) {
				break;
			}
		}
		ksort( $overrides, SORT_STRING );
		return $overrides;
	}

	/**
	 * @param array<mixed> $input
	 * @return array<string, string>
	 */
	private static function content_usage_preferences( array $input ): array {
		$source      = self::content_usage_source( $input );
		$preferences = array();
		foreach ( $source as $key => $value ) {
			$normalized = self::normalize_content_usage_value( $key, $value );
			if ( null !== $normalized ) {
				$preferences[ $normalized[0] ] = $normalized[1];
			}
		}
		return self::order_content_usage_preferences( $preferences );
	}

	/**
	 * @param array<mixed> $input
	 * @return array<mixed>
	 */
	private static function content_usage_source( array $input ): array {
		if ( isset( $input['content_usage'] ) && is_array( $input['content_usage'] ) ) {
			return $input['content_usage'];
		}
		return isset( $input['signals'] ) && is_array( $input['signals'] ) ? $input['signals'] : $input;
	}

	/**
	 * @param mixed $key
	 * @param mixed $value
	 * @return array{string,string}|null
	 */
	private static function normalize_content_usage_value( $key, $value ): ?array {
		$aliases = array(
			'ai-train' => 'ai-train',
			'train-ai' => 'ai-train',
			'ai_train' => 'ai-train',
			'search'   => 'search',
		);
		$key     = $aliases[ sanitize_key( (string) $key ) ] ?? '';
		if ( '' === $key || ! is_scalar( $value ) ) {
			return null;
		}
		$value = strtolower( trim( (string) $value ) );
		$value = 'y' === $value ? 'yes' : ( 'n' === $value ? 'no' : $value );
		return in_array( $value, array( 'yes', 'no' ), true ) ? array( $key, $value ) : null;
	}

	/**
	 * @param array<string, string> $preferences
	 * @return array<string, string>
	 */
	private static function order_content_usage_preferences( array $preferences ): array {
		$ordered = array();
		foreach ( array( 'ai-train', 'search' ) as $key ) {
			if ( isset( $preferences[ $key ] ) ) {
				$ordered[ $key ] = $preferences[ $key ];
			}
		}
		return $ordered;
	}
}
