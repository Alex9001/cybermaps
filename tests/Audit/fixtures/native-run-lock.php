<?php
declare(strict_types=1);

/**
 * Bounded disposable WordPress fixture; load with normal require via WP-CLI eval.
 * CYBERMAPS_AUDIT_LOCK_FIXTURE=1 is mandatory. No reports or content are created.
 * The query filter pauses A immediately before its insert while B acquires using
 * a separate real DB connection; both have first observed the absent row.
 */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'CYBERMAPS_AUDIT_LOCK_FIXTURE' ) ) {
	throw new RuntimeException( 'Use only the disposable audit lock fixture environment.' );
}

use Cybermaps\Audit\AuditRunRepository;
use Cybermaps\Core\RawOptionStore;

global $wpdb;
$audit_fixture_primary = $wpdb;
$audit_fixture_secondary = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$audit_fixture_secondary->set_prefix( $wpdb->prefix );
$audit_fixture_option = AuditRunRepository::RUN_LOCK_OPTION;
$audit_fixture_before = RawOptionStore::read( $wpdb, $audit_fixture_option );
if ( null !== $audit_fixture_before ) {
	throw new RuntimeException( 'Fixture refuses to replace any existing report lease.' );
}
$audit_fixture_results = array();
$audit_fixture_assert = static function ( bool $valid, string $name ) use ( &$audit_fixture_results ): void {
	if ( ! $valid ) {
		throw new RuntimeException( $name );
	}
	$audit_fixture_results[] = $name;
};
$audit_fixture_winner = null;
$audit_fixture_expired = null;
$audit_fixture_fired = false;
$audit_fixture_interleave = static function ( string $sql ) use ( &$audit_fixture_fired, &$audit_fixture_winner, $audit_fixture_secondary, $audit_fixture_primary, $audit_fixture_option ): string {
	if ( ! $audit_fixture_fired && str_starts_with( $sql, 'INSERT IGNORE INTO ' ) && str_contains( $sql, "'" . $audit_fixture_option . "'" ) ) {
		$audit_fixture_fired = true;
		$GLOBALS['wpdb'] = $audit_fixture_secondary;
		try {
			$audit_fixture_winner = ( new AuditRunRepository() )->acquire_run_lock( 120 );
		} finally {
			$GLOBALS['wpdb'] = $audit_fixture_primary;
		}
	}
	return $sql;
};
try {
	$audit_fixture_assert( null === RawOptionStore::read( $audit_fixture_secondary, $audit_fixture_option ), 'Both DB connections observe absence' );
	get_option( $audit_fixture_option ); // Prime the native negative cache before either insert.
	add_filter( 'query', $audit_fixture_interleave );
	try {
		$audit_fixture_loser = ( new AuditRunRepository() )->acquire_run_lock( 120 );
	} finally {
		remove_filter( 'query', $audit_fixture_interleave );
	}
	$audit_fixture_assert( $audit_fixture_fired && is_string( $audit_fixture_winner ) && null === $audit_fixture_loser, 'Exactly one initial acquisition succeeds' );
	$audit_fixture_raw = RawOptionStore::read( $audit_fixture_primary, $audit_fixture_option );
	$audit_fixture_assert( is_string( $audit_fixture_raw ) && $audit_fixture_winner === json_decode( $audit_fixture_raw, true )['token'], 'Loser never overwrites winner at DB boundary' );
	$audit_fixture_assert( $audit_fixture_raw === get_option( $audit_fixture_option ), 'Native option cache observes surviving lease' );
	$audit_fixture_repository = new AuditRunRepository();
	$audit_fixture_assert( false === $audit_fixture_repository->refresh_run_lock( 'not-the-owner', 300 ), 'Foreign renewal rejected' );
	$audit_fixture_repository->release_run_lock( 'not-the-owner' );
	$audit_fixture_assert( $audit_fixture_raw === RawOptionStore::read( $audit_fixture_secondary, $audit_fixture_option ), 'Foreign release preserves winner' );
	$audit_fixture_assert( $audit_fixture_repository->refresh_run_lock( $audit_fixture_winner, 300 ), 'Exact owner renews' );
	$audit_fixture_repository->release_run_lock( $audit_fixture_winner );
	$audit_fixture_assert( null === RawOptionStore::read( $audit_fixture_secondary, $audit_fixture_option ), 'Exact owner releases' );
	$audit_fixture_assert( 1 === RawOptionStore::insert( $audit_fixture_secondary, $audit_fixture_option, '' ), 'Empty malformed lease seed inserted' );
	$audit_fixture_expired = '';
	$audit_fixture_winner = $audit_fixture_repository->acquire_run_lock( 120 );
	$audit_fixture_assert( is_string( $audit_fixture_winner ), 'Native empty lease is recovered instead of remaining busy' );
	$audit_fixture_repository->release_run_lock( $audit_fixture_winner );
	$audit_fixture_assert( null === RawOptionStore::read( $audit_fixture_secondary, $audit_fixture_option ), 'Recovered empty lease releases normally' );
	$audit_fixture_winner = null;
	$audit_fixture_expired = wp_json_encode( array( 'token' => 'fixture-expired-owner', 'expires' => time() - 60 ) );
	$audit_fixture_assert( 1 === RawOptionStore::insert( $audit_fixture_secondary, $audit_fixture_option, $audit_fixture_expired ), 'Expired lease seed inserted' );
	$audit_fixture_winner = $audit_fixture_repository->acquire_run_lock( 120 );
	$audit_fixture_assert( is_string( $audit_fixture_winner ), 'Expired lease takeover succeeds' );
	$audit_fixture_repository->release_run_lock( 'fixture-expired-owner' );
	$audit_fixture_assert( false === $audit_fixture_repository->refresh_run_lock( 'fixture-expired-owner', 300 ), 'Former owner cannot renew successor' );
	$audit_fixture_assert( $audit_fixture_winner === json_decode( RawOptionStore::read( $audit_fixture_secondary, $audit_fixture_option ), true )['token'], 'Former owner cannot delete successor' );
	WP_CLI::line( wp_json_encode( array( 'status' => 'passed', 'checks' => $audit_fixture_results, 'external_object_cache' => wp_using_ext_object_cache() ) ) );
} finally {
	remove_filter( 'query', $audit_fixture_interleave );
	$GLOBALS['wpdb'] = $audit_fixture_primary;
	if ( is_string( $audit_fixture_winner ) ) {
		( new AuditRunRepository() )->release_run_lock( $audit_fixture_winner );
	}
	if ( is_string( $audit_fixture_expired ) ) {
		RawOptionStore::remove( $audit_fixture_secondary, $audit_fixture_option, $audit_fixture_expired );
	}
	RawOptionStore::invalidate( $audit_fixture_option );
	$audit_fixture_secondary->close();
}
