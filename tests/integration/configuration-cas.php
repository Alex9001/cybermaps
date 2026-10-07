<?php
declare(strict_types=1);

/**
 * Disposable real-WordPress fixture: wp eval-file tests/integration/configuration-cas.php
 * Requires CYBERMAPS_CONFIGURATION_FIXTURE=1. Uses two real database connections;
 * deterministic query/hook interleavings exercise the persistence boundary.
 */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'CYBERMAPS_CONFIGURATION_FIXTURE' ) ) {
	throw new RuntimeException( 'Run only in the disposable configuration fixture environment.' );
}

use Cybermaps\Admin\ConfigurationMutationStore;
use Cybermaps\Admin\MigrationHub;
use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\RawOptionStore;

global $wpdb;
$configuration_fixture_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$configuration_fixture_db->set_prefix( $wpdb->prefix );
$configuration_fixture_options = array( 'cybermaps_settings', 'cybermaps_discovery_center', 'cybermaps_robots_manager', 'cybermaps_identity_data', 'cybermaps_indexnow_key' );
$configuration_fixture_before = array();
$configuration_fixture_results = array();
$configuration_fixture_assert = static function ( bool $valid, string $name ) use ( &$configuration_fixture_results ): void {
	if ( ! $valid ) {
		throw new RuntimeException( $name );
	}
	$configuration_fixture_results[] = $name;
};
$configuration_fixture_write = static function ( string $option, mixed $value, bool $invalidate = true ) use ( $configuration_fixture_db ): void {
	$stored = $configuration_fixture_db->query( $configuration_fixture_db->prepare(
		'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)',
		$configuration_fixture_db->options, $option, maybe_serialize( $value ), 'off'
	) );
	if ( false === $stored ) {
		throw new RuntimeException( 'Fixture seed failed.' );
	}
	if ( $invalidate ) {
		RawOptionStore::invalidate( $option );
	}
};
$configuration_fixture_apply = static fn( array $targets ): array => ( new ReflectionMethod( MigrationHub::class, 'apply_targets' ) )->invoke( MigrationHub::get_instance(), $targets );

foreach ( $configuration_fixture_options as $configuration_fixture_option ) {
	$configuration_fixture_before[ $configuration_fixture_option ] = RawOptionStore::read( $wpdb, $configuration_fixture_option );
	if ( false === $configuration_fixture_before[ $configuration_fixture_option ] ) {
		throw new RuntimeException( 'Fixture could not snapshot configuration.' );
	}
}

try {
	$configuration_fixture_write( 'cybermaps_indexnow_key', '' );
	$configuration_fixture_empty = ConfigurationMutationStore::read( 'cybermaps_indexnow_key' );
	$configuration_fixture_assert( array( 'exists' => true, 'value' => '', 'raw' => '' ) === $configuration_fixture_empty, 'Native empty option remains an existing row' );
	$configuration_fixture_next = ConfigurationMutationStore::target( 'empty-row-replacement' );
	$configuration_fixture_assert( ConfigurationMutationStore::write( 'cybermaps_indexnow_key', $configuration_fixture_empty, $configuration_fixture_next ), 'Existing empty option uses successful exact update' );
	$configuration_fixture_assert( ConfigurationMutationStore::restore( 'cybermaps_indexnow_key', $configuration_fixture_next, $configuration_fixture_empty ), 'Owned rollback restores empty option bytes' );
	$configuration_fixture_assert( $configuration_fixture_empty === ConfigurationMutationStore::read( 'cybermaps_indexnow_key' ), 'Rollback preserves empty row existence' );
	// Exact bytes, not the options table's usual case-insensitive collation.
	$configuration_fixture_write( 'cybermaps_indexnow_key', 'Case ' );
	$configuration_fixture_assert( 0 === RawOptionStore::replace( $wpdb, 'cybermaps_indexnow_key', 'case ', 'wrong' ), 'CAS rejects case mismatch' );
	$configuration_fixture_assert( 0 === RawOptionStore::replace( $wpdb, 'cybermaps_indexnow_key', 'Case', 'wrong' ), 'CAS rejects trailing-space mismatch' );

	$configuration_fixture_base = array( 'static_engine_mode' => 'off', 'agency_name' => 'Before', 'enable_discovery_hub' => '1', 'api_secret' => 'fixture-secret' );
	$configuration_fixture_write( 'cybermaps_settings', $configuration_fixture_base );
	// Prime both caches and request-local configuration before the competitor.
	get_option( 'cybermaps_settings' );
	ConfigurationStore::settings();
	$configuration_fixture_fired = false;
	$configuration_fixture_interleave = static function ( string $sql ) use ( &$configuration_fixture_fired, $configuration_fixture_write, $configuration_fixture_base ): string {
		if ( ! $configuration_fixture_fired && str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, "'cybermaps_settings'" ) && str_contains( $sql, 'BINARY option_value' ) ) {
			$configuration_fixture_fired = true;
			$configuration_fixture_write( 'cybermaps_settings', array_replace( $configuration_fixture_base, array( 'enable_discovery_hub' => '0' ) ), false );
		}
		return $sql;
	};
	add_filter( 'query', $configuration_fixture_interleave );
	try {
		$configuration_fixture_apply( array( 'cybermaps_settings' => array_replace( $configuration_fixture_base, array( 'agency_name' => 'Imported' ) ) ) );
		throw new LogicException( 'Stale root was incorrectly accepted.' );
	} catch ( RuntimeException $error ) {
		$configuration_fixture_assert( $configuration_fixture_fired, 'Second connection ran between observation and CAS' );
		$configuration_fixture_assert( str_contains( $error->getMessage(), 'before any configuration values were written' ), 'Conflict before first write is reported' );
	} finally {
		remove_filter( 'query', $configuration_fixture_interleave );
	}
	$configuration_fixture_assert( '0' === ConfigurationMutationStore::read( 'cybermaps_settings' )['value']['enable_discovery_hub'], 'Unreviewed opt-out survives CAS conflict' );
	$configuration_fixture_assert( '0' === get_option( 'cybermaps_settings' )['enable_discovery_hub'] && '0' === ConfigurationStore::settings()['enable_discovery_hub'], 'Option and configuration caches reflect surviving value' );

	// A nonparticipating database writer remains outside the import, but its row is protected.
	$configuration_fixture_write( 'cybermaps_settings', $configuration_fixture_base );
	$configuration_fixture_write( 'cybermaps_identity_data', array( 'name' => 'Before identity' ) );
	$configuration_fixture_observer = static function () use ( $configuration_fixture_write ): void {
		$configuration_fixture_write( 'cybermaps_identity_data', array( 'name' => 'Competing identity' ) );
		throw new RuntimeException( 'Fixture observer failure after a competing write.' );
	};
	add_action( 'update_option_cybermaps_identity_data', $configuration_fixture_observer );
	try {
		$configuration_fixture_apply( array( 'cybermaps_settings' => $configuration_fixture_base + array( 'analytics_retention_days' => '30' ), 'cybermaps_identity_data' => array( 'name' => 'Imported identity' ) ) );
		throw new LogicException( 'Observer failure was ignored.' );
	} catch ( RuntimeException $error ) {
		$configuration_fixture_assert( str_contains( $error->getMessage(), 'Conflicting values were preserved' ), 'Partial rollback reports retained conflict' );
	} finally {
		remove_action( 'update_option_cybermaps_identity_data', $configuration_fixture_observer );
	}
	$configuration_fixture_assert( $configuration_fixture_base === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'], 'Owned earlier root rolls back' );
	$configuration_fixture_assert( 'Competing identity' === ConfigurationMutationStore::read( 'cybermaps_identity_data' )['value']['name'], 'Rollback retains second connection write' );

	// Missing-row insertion races and deletion of an owned inserted row.
	delete_option( 'cybermaps_identity_data' );
	$configuration_fixture_absent = ConfigurationMutationStore::read( 'cybermaps_identity_data' );
	$configuration_fixture_write( 'cybermaps_identity_data', array( 'name' => 'Competing insert' ) );
	$configuration_fixture_assert( ! ConfigurationMutationStore::write( 'cybermaps_identity_data', $configuration_fixture_absent, ConfigurationMutationStore::target( array( 'name' => 'Import' ) ) ), 'Absent-row CAS does not upsert competitor' );
	delete_option( 'cybermaps_identity_data' );
	$configuration_fixture_target = ConfigurationMutationStore::target( array( 'name' => 'Owned insert' ) );
	$configuration_fixture_assert( ConfigurationMutationStore::write( 'cybermaps_identity_data', $configuration_fixture_absent, $configuration_fixture_target ), 'Absent-row owned insertion succeeds' );
	$configuration_fixture_assert( ConfigurationMutationStore::restore( 'cybermaps_identity_data', $configuration_fixture_target, $configuration_fixture_absent ) && ! ConfigurationMutationStore::read( 'cybermaps_identity_data' )['exists'], 'Owned inserted row is removed during rollback' );

	// Real storage error on a later root must restore the first root.
	$configuration_fixture_write( 'cybermaps_identity_data', array( 'name' => 'Before identity' ) );
	$configuration_fixture_fail = static function ( string $sql ): string {
		return str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, "'cybermaps_identity_data'" ) && str_contains( $sql, 'BINARY option_value' )
			? 'UPDATE cybermaps_deliberately_missing_fixture_table SET missing_column = 1'
			: $sql;
	};
	$configuration_fixture_suppress = $wpdb->suppress_errors( true );
	add_filter( 'query', $configuration_fixture_fail );
	try {
		$configuration_fixture_apply( array( 'cybermaps_settings' => $configuration_fixture_base + array( 'agency_extra' => 'temporary' ), 'cybermaps_identity_data' => array( 'name' => 'Import' ) ) );
		throw new LogicException( 'Database error was ignored.' );
	} catch ( RuntimeException $error ) {
		$configuration_fixture_assert( str_contains( $error->getMessage(), 'restored the previous configuration' ), 'Later database error rolls back proven writes' );
	} finally {
		remove_filter( 'query', $configuration_fixture_fail );
		$wpdb->suppress_errors( $configuration_fixture_suppress );
	}
	$configuration_fixture_assert( $configuration_fixture_base === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'], 'Earlier settings bytes restored after database failure' );

	// Shares this fixture's two connections and existing option snapshot/restore.
	require __DIR__ . '/automatic-settings-cas.php';
} finally {
	$configuration_fixture_restore_failures = array();
	try {
		foreach ( $configuration_fixture_before as $configuration_fixture_option => $configuration_fixture_raw ) {
			if ( null === $configuration_fixture_raw ) {
				$configuration_fixture_restored = $configuration_fixture_db->delete( $configuration_fixture_db->options, array( 'option_name' => $configuration_fixture_option ), array( '%s' ) );
			} else {
				$configuration_fixture_restored = $configuration_fixture_db->query( $configuration_fixture_db->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $configuration_fixture_db->options, $configuration_fixture_option, $configuration_fixture_raw, 'off' ) );
			}
			RawOptionStore::invalidate( $configuration_fixture_option );
			if ( false === $configuration_fixture_restored || $configuration_fixture_raw !== RawOptionStore::read( $configuration_fixture_db, $configuration_fixture_option ) ) {
				$configuration_fixture_restore_failures[] = $configuration_fixture_option;
			}
		}
	} finally {
		$configuration_fixture_db->close();
	}
	$configuration_fixture_assert( array() === $configuration_fixture_restore_failures, 'All five configuration roots restored to exact original bytes' );
}

echo wp_json_encode( array( 'passed' => count( $configuration_fixture_results ), 'checks' => $configuration_fixture_results, 'external_object_cache' => wp_using_ext_object_cache(), 'translation_detector' => $automatic_fixture_detector ) ) . "\n";
