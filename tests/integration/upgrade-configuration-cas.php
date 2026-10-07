<?php
declare(strict_types=1);

/** Load by normal require inside WP-CLI eval in a disposable WordPress site. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'CYBERMAPS_CONFIGURATION_FIXTURE' ) ) {
	throw new RuntimeException( 'Disposable configuration fixture opt-in is required.' );
}

use Cybermaps\Admin\ConfigurationMutationStore;
use Cybermaps\Core\OptionLeaseLock;
use Cybermaps\Core\RawOptionStore;
use Cybermaps\Core\Upgrade;

global $wpdb;
$upgrade_fixture_lock_option = 'cybermaps_upgrade_lock';
if ( null !== RawOptionStore::read( $wpdb, $upgrade_fixture_lock_option ) ) {
	throw new RuntimeException( 'An upgrade lease exists; do not run beside an upgrade worker.' );
}
$upgrade_fixture_connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$upgrade_fixture_connection->set_prefix( $wpdb->prefix );
$upgrade_fixture_snapshots = array();
$upgrade_fixture_results = array();
$upgrade_fixture_assert = static function ( bool $condition, string $name ) use ( &$upgrade_fixture_results ): void {
	if ( ! $condition ) { throw new RuntimeException( $name ); }
	$upgrade_fixture_results[] = $name;
};
$upgrade_fixture_seed = static function ( string $option, mixed $value ) use ( $upgrade_fixture_connection ): void {
	$result = $upgrade_fixture_connection->query( $upgrade_fixture_connection->prepare(
		'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)',
		$upgrade_fixture_connection->options, $option, maybe_serialize( $value ), 'off'
	) );
	if ( false === $result ) { throw new RuntimeException( 'Fixture seed failed.' ); }
};
foreach ( array( 'cybermaps_settings', 'cybermaps_discovery_center' ) as $upgrade_fixture_option ) {
	$upgrade_fixture_raw = RawOptionStore::read( $wpdb, $upgrade_fixture_option );
	if ( false === $upgrade_fixture_raw ) { throw new RuntimeException( 'Cannot snapshot upgrade configuration.' ); }
	$upgrade_fixture_snapshots[ $upgrade_fixture_option ] = $upgrade_fixture_raw;
}
$upgrade_fixture_lock = new OptionLeaseLock( $upgrade_fixture_lock_option, 300, 60 );
$upgrade_fixture_property = new ReflectionProperty( Upgrade::class, 'upgrade_lock' );
$upgrade_fixture_previous_lock = $upgrade_fixture_property->getValue();
$upgrade_fixture_foreign_raw = null;
try {
	$upgrade_fixture_assert( $upgrade_fixture_lock->acquire(), 'Fixture acquires its own upgrade lease' );
	$upgrade_fixture_property->setValue( null, $upgrade_fixture_lock );
	$upgrade_fixture_cases = array(
		'cybermaps_settings' => array( 'method' => 'normalize_settings', 'old' => array( 'static_engine_mode' => 'off', 'agency_name' => 'Old A' ), 'new' => array( 'static_engine_mode' => 'off', 'agency_name' => 'Concurrent B' ) ),
		'cybermaps_discovery_center' => array( 'method' => 'normalize_discovery_center', 'old' => '{"overrides":{"post":0}}', 'new' => '{"overrides":{"post":0.9}}' ),
	);
	foreach ( $upgrade_fixture_cases as $upgrade_fixture_option => $upgrade_fixture_case ) {
		$upgrade_fixture_seed( $upgrade_fixture_option, $upgrade_fixture_case['old'] );
		RawOptionStore::invalidate( $upgrade_fixture_option );
		get_option( $upgrade_fixture_option );
		$upgrade_fixture_fired = false;
		$upgrade_fixture_interleave = static function ( string $sql ) use ( &$upgrade_fixture_fired, $upgrade_fixture_option, $upgrade_fixture_case, $upgrade_fixture_seed ): string {
			if ( ! $upgrade_fixture_fired && str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, ' AS target INNER JOIN ' ) && str_contains( $sql, "'{$upgrade_fixture_option}'" ) ) {
				$upgrade_fixture_fired = true;
				$upgrade_fixture_seed( $upgrade_fixture_option, $upgrade_fixture_case['new'] );
			}
			return $sql;
		};
		$upgrade_fixture_method = new ReflectionMethod( Upgrade::class, $upgrade_fixture_case['method'] );
		add_filter( 'query', $upgrade_fixture_interleave );
		try {
			$upgrade_fixture_assert( false === $upgrade_fixture_method->invoke( null ), $upgrade_fixture_option . ': stale migration is rejected' );
		} finally {
			remove_filter( 'query', $upgrade_fixture_interleave );
		}
		$upgrade_fixture_assert( $upgrade_fixture_fired, $upgrade_fixture_option . ': second connection writes immediately before guarded UPDATE' );
		$upgrade_fixture_assert( $upgrade_fixture_case['new'] === ConfigurationMutationStore::read( $upgrade_fixture_option )['value'], $upgrade_fixture_option . ': competing bytes survive' );
		$upgrade_fixture_assert( $upgrade_fixture_case['new'] === get_option( $upgrade_fixture_option ), $upgrade_fixture_option . ': cache sees surviving value' );
		$upgrade_fixture_assert( true === $upgrade_fixture_method->invoke( null ), $upgrade_fixture_option . ': fresh retry succeeds' );
	}
	$upgrade_fixture_assert( 'Concurrent B' === get_option( 'cybermaps_settings' )['agency_name'], 'Retry keeps concurrent settings' );
	$upgrade_fixture_assert( 0.9 === json_decode( get_option( 'cybermaps_discovery_center' ), true )['overrides']['post_type:post'], 'Retry keeps concurrent discovery strategy' );

	$upgrade_fixture_old = array( 'static_engine_mode' => 'off', 'agency_name' => 'Lease guarded A' );
	$upgrade_fixture_seed( 'cybermaps_settings', $upgrade_fixture_old );
	$upgrade_fixture_foreign_raw = (string) maybe_serialize( array( 'token' => 'fixture-successor-' . wp_generate_uuid4(), 'time' => time() ) );
	$upgrade_fixture_takeover = static function ( string $sql ) use ( &$upgrade_fixture_fired, $upgrade_fixture_seed, $upgrade_fixture_foreign_raw ): string {
		if ( ! $upgrade_fixture_fired && str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, ' AS target INNER JOIN ' ) && str_contains( $sql, "'cybermaps_settings'" ) ) {
			$upgrade_fixture_fired = true;
			$upgrade_fixture_seed( 'cybermaps_upgrade_lock', maybe_unserialize( $upgrade_fixture_foreign_raw ) );
		}
		return $sql;
	};
	$upgrade_fixture_fired = false;
	add_filter( 'query', $upgrade_fixture_takeover );
	try {
		$upgrade_fixture_assert( false === ( new ReflectionMethod( Upgrade::class, 'normalize_settings' ) )->invoke( null ), 'Lease takeover fences the migration SQL' );
	} finally {
		remove_filter( 'query', $upgrade_fixture_takeover );
	}
	$upgrade_fixture_assert( $upgrade_fixture_fired && $upgrade_fixture_old === ConfigurationMutationStore::read( 'cybermaps_settings' )['value'], 'Lost owner preserves configuration bytes' );
} finally {
	$upgrade_fixture_lock->release();
	if ( is_string( $upgrade_fixture_foreign_raw ) ) {
		RawOptionStore::remove( $wpdb, $upgrade_fixture_lock_option, $upgrade_fixture_foreign_raw );
		RawOptionStore::invalidate( $upgrade_fixture_lock_option );
	}
	$upgrade_fixture_property->setValue( null, $upgrade_fixture_previous_lock );
	foreach ( $upgrade_fixture_snapshots as $upgrade_fixture_option => $upgrade_fixture_raw ) {
		if ( null === $upgrade_fixture_raw ) {
			$upgrade_fixture_connection->delete( $upgrade_fixture_connection->options, array( 'option_name' => $upgrade_fixture_option ), array( '%s' ) );
		} else {
			$upgrade_fixture_connection->query( $upgrade_fixture_connection->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $upgrade_fixture_connection->options, $upgrade_fixture_option, $upgrade_fixture_raw, 'off' ) );
		}
		RawOptionStore::invalidate( $upgrade_fixture_option );
	}
	$upgrade_fixture_connection->close();
}
echo wp_json_encode( array( 'passed' => count( $upgrade_fixture_results ), 'checks' => $upgrade_fixture_results, 'external_object_cache' => wp_using_ext_object_cache() ) ) . "\n";
