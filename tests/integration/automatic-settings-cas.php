<?php
declare(strict_types=1);

/**
 * Required by configuration-cas.php inside its five-option snapshot/restore.
 * Native SQL, option caches and hooks; only translation-environment detection
 * is simulated when the disposable installation has no translation plugin.
 */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'CYBERMAPS_CONFIGURATION_FIXTURE' ) || ! isset( $configuration_fixture_db, $configuration_fixture_assert, $configuration_fixture_write ) ) {
	throw new RuntimeException( 'Run through the disposable configuration fixture.' );
}

use Cybermaps\Admin\ConfigurationMutationStore;
use Cybermaps\Admin\Settings;
use Cybermaps\Admin\Settings\SettingsRegistrar;
use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\Plugin;
use Cybermaps\Core\RawOptionStore;

// wp eval's normal require executes in a closure, not necessarily global scope.
global $wpdb;
$automatic_fixture_primary_connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
$automatic_fixture_competing_connection = (int) $configuration_fixture_db->get_var( 'SELECT CONNECTION_ID()' );
$configuration_fixture_assert( $automatic_fixture_primary_connection > 0 && $automatic_fixture_competing_connection > 0 && $automatic_fixture_primary_connection !== $automatic_fixture_competing_connection, 'Automatic settings use two distinct live database connections' );
$automatic_fixture_detector = 'native environment';
if ( ! Plugin::is_translation_environment() ) {
	define( 'ICL_SITEPRESS_VERSION', 'cybermaps-disposable-fixture' );
	$automatic_fixture_detector = 'fixture constant for environment detection only';
}
$configuration_fixture_assert( Plugin::is_translation_environment(), 'Automatic translation branch is reachable' );
$automatic_fixture_calls = array(
	'static' => static fn() => ( new ReflectionMethod( Settings::class, 'normalize_static_mode_setting' ) )->invoke( new Settings() ),
	'translation' => static fn() => ( new ReflectionMethod( SettingsRegistrar::class, 'maybe_enable_translation_integrations' ) )->invoke( null ),
);
$automatic_fixture_notifications = 0;
$automatic_fixture_observer = static function ( string $option ) use ( &$automatic_fixture_notifications ): void {
	if ( 'cybermaps_settings' === $option ) {
		++$automatic_fixture_notifications;
	}
};
add_action( 'updated_option', $automatic_fixture_observer );
add_action( 'added_option', $automatic_fixture_observer );

try {
	foreach ( $automatic_fixture_calls as $automatic_fixture_path => $automatic_fixture_call ) {
		$automatic_fixture_base = array( 'enable_discovery_hub' => '0', 'site_name_override' => 'Before automatic change', 'api_secret' => 'fixture-old-secret' );
		$automatic_fixture_base += 'static' === $automatic_fixture_path ? array( 'enable_static_engine' => '1' ) : array( 'static_engine_mode' => 'off' );
		$automatic_fixture_competing = array( 'static_engine_mode' => 'off', 'enable_translation_integrations' => '0', 'enable_discovery_hub' => '0', 'site_name_override' => 'Concurrent authorized save', 'api_secret' => 'fixture-new-secret' );
		$automatic_fixture_label = $automatic_fixture_path . ' automatic settings: ';
		$configuration_fixture_write( 'cybermaps_settings', $automatic_fixture_base );
		get_option( 'cybermaps_settings' );
		ConfigurationStore::settings();
		$automatic_fixture_before_notifications = $automatic_fixture_notifications;
		$automatic_fixture_hits = 0;
		$automatic_fixture_interleave = static function ( string $sql ) use ( &$automatic_fixture_hits, $configuration_fixture_write, $automatic_fixture_competing ): string {
			if ( str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, "'cybermaps_settings'" ) && str_contains( $sql, 'BINARY option_value' ) ) {
				++$automatic_fixture_hits;
				$configuration_fixture_write( 'cybermaps_settings', $automatic_fixture_competing, false );
			}
			return $sql;
		};
		add_filter( 'query', $automatic_fixture_interleave );
		try {
			$automatic_fixture_call();
		} finally {
			remove_filter( 'query', $automatic_fixture_interleave );
		}
		$configuration_fixture_assert( 1 === $automatic_fixture_hits, $automatic_fixture_label . 'second connection interleaved exactly once, with no retry' );
		$configuration_fixture_assert( $automatic_fixture_competing === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'], $automatic_fixture_label . 'concurrent authorized values survive exact CAS' );
		$configuration_fixture_assert( $automatic_fixture_competing === get_option( 'cybermaps_settings' ) && $automatic_fixture_competing === ConfigurationStore::settings(), $automatic_fixture_label . 'conflict invalidates native option cache and configuration memo' );
		$configuration_fixture_assert( $automatic_fixture_before_notifications === $automatic_fixture_notifications, $automatic_fixture_label . 'conflict emits no option notification' );
		$automatic_fixture_call();
		$configuration_fixture_assert( $automatic_fixture_competing === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'] && $automatic_fixture_before_notifications === $automatic_fixture_notifications, $automatic_fixture_label . 'fresh normalization preserves explicit off choices without notifications' );

		// Missing row: prove the production insert cannot upsert another connection.
		$configuration_fixture_assert( false !== $configuration_fixture_db->delete( $configuration_fixture_db->options, array( 'option_name' => 'cybermaps_settings' ), array( '%s' ) ), $automatic_fixture_label . 'fixture deletes the settings row' );
		RawOptionStore::invalidate( 'cybermaps_settings' );
		get_option( 'cybermaps_settings' );
		ConfigurationStore::settings();
		$automatic_fixture_hits = 0;
		$automatic_fixture_insert_race = static function ( string $sql ) use ( &$automatic_fixture_hits, $configuration_fixture_write, $automatic_fixture_competing ): string {
			if ( str_starts_with( $sql, 'INSERT IGNORE ' ) && str_contains( $sql, "'cybermaps_settings'" ) ) {
				++$automatic_fixture_hits;
				$configuration_fixture_write( 'cybermaps_settings', $automatic_fixture_competing, false );
			}
			return $sql;
		};
		add_filter( 'query', $automatic_fixture_insert_race );
		try {
			$automatic_fixture_call();
		} finally {
			remove_filter( 'query', $automatic_fixture_insert_race );
		}
		$configuration_fixture_assert( 1 === $automatic_fixture_hits, $automatic_fixture_label . 'insert-only race is exercised once' );
		$configuration_fixture_assert( $automatic_fixture_competing === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'] && $automatic_fixture_competing === get_option( 'cybermaps_settings' ) && $automatic_fixture_competing === ConfigurationStore::settings(), $automatic_fixture_label . 'competing insertion survives and refreshes both caches' );
		$configuration_fixture_assert( $automatic_fixture_before_notifications === $automatic_fixture_notifications, $automatic_fixture_label . 'failed insertion emits no option notification' );

		// Fail the actual authoritative read and then the actual exact UPDATE.
		foreach ( array( 'read', 'write' ) as $automatic_fixture_failure ) {
			$configuration_fixture_write( 'cybermaps_settings', $automatic_fixture_base );
			$automatic_fixture_hits = 0;
			$automatic_fixture_error = static function ( string $sql ) use ( &$automatic_fixture_hits, $automatic_fixture_failure ): string {
				$matches = 'read' === $automatic_fixture_failure ? str_starts_with( $sql, 'SELECT option_value FROM ' ) : str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'BINARY option_value' );
				if ( $matches && str_contains( $sql, "'cybermaps_settings'" ) ) {
					++$automatic_fixture_hits;
					return 'SELECT missing_column FROM cybermaps_deliberately_missing_fixture_table';
				}
				return $sql;
			};
			$automatic_fixture_suppressed = $wpdb->suppress_errors( true );
			add_filter( 'query', $automatic_fixture_error );
			try {
				$automatic_fixture_call();
			} finally {
				remove_filter( 'query', $automatic_fixture_error );
				$wpdb->suppress_errors( $automatic_fixture_suppressed );
			}
			$configuration_fixture_assert( 1 === $automatic_fixture_hits, $automatic_fixture_label . $automatic_fixture_failure . ' error hit the real database boundary once' );
			$configuration_fixture_assert( $automatic_fixture_base === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'], $automatic_fixture_label . $automatic_fixture_failure . ' error preserves the original root' );
			$configuration_fixture_assert( $automatic_fixture_before_notifications === $automatic_fixture_notifications, $automatic_fixture_label . $automatic_fixture_failure . ' error emits no option notification' );
		}

		// A later request may retry from a new authoritative observation.
		$automatic_fixture_expected = $automatic_fixture_base;
		if ( 'static' === $automatic_fixture_path ) {
			unset( $automatic_fixture_expected['enable_static_engine'] );
			$automatic_fixture_expected['static_engine_mode'] = 'well_known';
		} else {
			$automatic_fixture_expected['enable_translation_integrations'] = '1';
		}
		get_option( 'cybermaps_settings' );
		ConfigurationStore::settings();
		$automatic_fixture_call();
		$configuration_fixture_assert( $automatic_fixture_expected === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'], $automatic_fixture_label . 'fresh retry succeeds and preserves unrelated fields' );
		$configuration_fixture_assert( $automatic_fixture_expected === get_option( 'cybermaps_settings' ) && $automatic_fixture_expected === ConfigurationStore::settings(), $automatic_fixture_label . 'successful write invalidates previously primed option cache and memo' );
		$configuration_fixture_assert( $automatic_fixture_before_notifications + 1 === $automatic_fixture_notifications, $automatic_fixture_label . 'successful write emits one native post-option notification' );
		$automatic_fixture_call();
		$configuration_fixture_assert( $automatic_fixture_before_notifications + 1 === $automatic_fixture_notifications, $automatic_fixture_label . 'settled normalization is a no-op' );

		foreach ( array( '', 'malformed root', array( 'list-item' ) ) as $automatic_fixture_malformed ) {
			$configuration_fixture_write( 'cybermaps_settings', $automatic_fixture_malformed );
			$automatic_fixture_raw = RawOptionStore::read( $wpdb, 'cybermaps_settings' );
			$automatic_fixture_call();
			$configuration_fixture_assert( $automatic_fixture_raw === RawOptionStore::read( $wpdb, 'cybermaps_settings' ), $automatic_fixture_label . 'malformed existing root is preserved byte-for-byte' );
		}
		$configuration_fixture_assert( $automatic_fixture_before_notifications + 1 === $automatic_fixture_notifications, $automatic_fixture_label . 'malformed roots emit no option notifications' );

		$configuration_fixture_assert( false !== $configuration_fixture_db->delete( $configuration_fixture_db->options, array( 'option_name' => 'cybermaps_settings' ), array( '%s' ) ), $automatic_fixture_label . 'fixture prepares successful absent-row insertion' );
		RawOptionStore::invalidate( 'cybermaps_settings' );
		get_option( 'cybermaps_settings' );
		ConfigurationStore::settings();
		$automatic_fixture_call();
		$automatic_fixture_initial = 'static' === $automatic_fixture_path ? array( 'static_engine_mode' => 'well_known' ) : array( 'enable_translation_integrations' => '1' );
		$configuration_fixture_assert( $automatic_fixture_initial === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'] && $automatic_fixture_initial === get_option( 'cybermaps_settings' ) && $automatic_fixture_initial === ConfigurationStore::settings(), $automatic_fixture_label . 'genuinely absent row initializes and clears negative caches' );
		$configuration_fixture_assert( $automatic_fixture_before_notifications + 2 === $automatic_fixture_notifications, $automatic_fixture_label . 'successful insertion emits one native add-option notification' );

		// A post-write observer is not a transaction: never undo its competing row.
		$configuration_fixture_write( 'cybermaps_settings', $automatic_fixture_base );
		$automatic_fixture_throwing_observer = static function () use ( $configuration_fixture_write, $automatic_fixture_competing ): void {
			$configuration_fixture_write( 'cybermaps_settings', $automatic_fixture_competing );
			throw new RuntimeException( 'Automatic-settings fixture observer failed.' );
		};
		add_action( 'update_option_cybermaps_settings', $automatic_fixture_throwing_observer, -1000 );
		try {
			$automatic_fixture_call();
			throw new LogicException( 'Automatic-settings observer failure was swallowed.' );
		} catch ( RuntimeException $error ) {
			$configuration_fixture_assert( 'Automatic-settings fixture observer failed.' === $error->getMessage(), $automatic_fixture_label . 'observer exception propagates' );
		} finally {
			remove_action( 'update_option_cybermaps_settings', $automatic_fixture_throwing_observer, -1000 );
		}
		$configuration_fixture_assert( $automatic_fixture_competing === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'], $automatic_fixture_label . 'observer failure does not roll back the competing value' );
	}
} finally {
	remove_action( 'updated_option', $automatic_fixture_observer );
	remove_action( 'added_option', $automatic_fixture_observer );
}
