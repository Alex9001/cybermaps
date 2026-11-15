<?php
declare(strict_types=1);

/** Run only with the candidate plugin loaded in a disposable WP-CLI database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'CYBERMAPS_AUDIT_FIXTURE_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Set CYBERMAPS_AUDIT_FIXTURE_DISPOSABLE=1 in a disposable WP-CLI runtime.' );
}

global $wpdb;
$original_prefix = $wpdb->prefix;
$fixture_prefix = 'cm_audit_fixture_' . bin2hex( random_bytes( 6 ) ) . '_';
$tables = array( 'cybermaps_audit_runs', 'cybermaps_audit_resources', 'cybermaps_audit_findings' );
$created = array();
$receipt = null;
$old_suppression = $wpdb->suppress_errors( true );
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
try {
	foreach ( $tables as $suffix ) {
		$table = $fixture_prefix . $suffix;
		$assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $table, $original_prefix . $suffix ) ), 'Could not create isolated audit table: ' . $wpdb->last_error );
		$created[] = $table;
	}
	$wpdb->prefix = $fixture_prefix;
	$repository = new Cybermaps\Audit\AuditRunRepository();
	$policy = Cybermaps\Audit\AuditPolicy::from_settings( array() );
	$legacy = $repository->begin( $policy );
	$batched = $repository->begin( $policy );
	$results = array();
	for ( $i = 1; $i <= 3; ++$i ) {
		$resource = array(
			'resource_key' => 'post:1:' . $i, 'object_type' => 'post', 'object_id' => $i,
			'post_type' => 'page', 'title' => "L'été café '" . $i, 'url' => 'https://example.test/' . $i,
			'modified_gmt' => '', 'word_count' => 100, 'age_days' => null, 'has_media' => false,
			'indexable' => true, 'indexability' => array( 'indexable' => true ),
			'measurement' => array( 'text_complete' => true ), 'content_hash' => str_repeat( 'a', 64 ),
		);
		$findings = array();
		for ( $j = 1; $j <= 2; ++$j ) {
			$findings[] = array(
				'key' => 'fixture_' . $j, 'severity' => 'review',
				'summary' => "Quoted 'café' \\ path " . $i . ':' . $j,
				'evidence' => array( 'id' => $i, 'unicode' => '東京', 'quote' => "'\\" ),
				'recommendation' => 'Review café ' . $j,
			);
		}
		$results[] = compact( 'resource', 'findings' );
		$repository->add_resource( $legacy, $resource, $findings );
	}
	$repository->add_resources( $batched, $results );
	$resource_rows = static function ( int $run_id ) use ( $wpdb, $fixture_prefix ): array {
		return $wpdb->get_results( $wpdb->prepare( 'SELECT resource_key, object_type, object_id, post_type, title, url, modified_gmt, word_count, age_days, has_media, indexable, indexability_json, measurement_json, content_hash FROM %i WHERE run_id = %d ORDER BY resource_key', $fixture_prefix . 'cybermaps_audit_resources', $run_id ), ARRAY_A );
	};
	$finding_rows = static function ( int $run_id ) use ( $wpdb, $fixture_prefix ): array {
		return $wpdb->get_results( $wpdb->prepare( 'SELECT r.resource_key, f.finding_key, f.severity, f.summary, f.evidence_json, f.recommendation FROM %i f INNER JOIN %i r ON r.id = f.resource_id WHERE f.run_id = %d ORDER BY r.resource_key, f.finding_key', $fixture_prefix . 'cybermaps_audit_findings', $fixture_prefix . 'cybermaps_audit_resources', $run_id ), ARRAY_A );
	};
	$legacy_resources = $resource_rows( $legacy );
	$batched_resources = $resource_rows( $batched );
	$assert( 3 === count( $legacy_resources ) && $legacy_resources === $batched_resources, 'Resource values differ between legacy and batch storage.' );
	$assert( null === $legacy_resources[0]['modified_gmt'] && null === $legacy_resources[0]['age_days'], 'Nullable values did not survive.' );
	$legacy_findings = $finding_rows( $legacy );
	$batched_findings = $finding_rows( $batched );
	$assert( 6 === count( $legacy_findings ) && $legacy_findings === $batched_findings, 'Finding values or resource identities differ.' );
	$rejections = array();
	foreach ( array( 'length', 'invalid_utf8' ) as $case ) {
		foreach ( array( 'legacy', 'batch' ) as $method ) {
			$run = $repository->begin( $policy );
			$bad = $results[0];
			$bad['resource']['resource_key'] .= ':' . $case . ':' . $method;
			$bad['findings'] = array();
			for ( $i = 0; $i < 5; ++$i ) {
				$finding = $results[0]['findings'][0];
				$finding['key'] = 'failure_' . $i;
				if ( 2 === $i ) {
					if ( 'length' === $case ) { $finding['key'] = str_repeat( 'x', 65 ); }
					else { $finding['summary'] = "Invalid \xC3\x28"; }
				}
				$bad['findings'][] = $finding;
			}
			$failed = false;
			try {
				if ( 'legacy' === $method ) { $repository->add_resource( $run, $bad['resource'], $bad['findings'] ); }
				else { $repository->add_resources( $run, array( $bad ) ); }
			} catch ( RuntimeException $error ) {
				$failed = true;
				$rejections[ $case ][ $method ] = array( 'rejected' => true, 'exception' => get_class( $error ) );
			}
			$assert( $failed, $method . ' accepted ' . $case . ' finding data.' );
		}
	}
	$receipt = array(
		'schema_version' => 1,
		'database' => $wpdb->db_version(),
		'resources' => array( 'legacy' => $legacy_resources, 'batch' => $batched_resources ),
		'findings' => array( 'legacy' => $legacy_findings, 'batch' => $batched_findings ),
		'rejections' => $rejections,
	);
} finally {
	$wpdb->prefix = $original_prefix;
	try {
		foreach ( array_reverse( $created ) as $table ) {
			$assert( false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ), 'Could not clean up isolated audit table.' );
			$assert( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'Isolated audit table remains after cleanup.' );
		}
	} finally {
		$wpdb->suppress_errors( $old_suppression );
	}
}
$receipt['cleanup_passed'] = count( $created ) === 3 && $wpdb->prefix === $original_prefix && $wpdb->suppress_errors === $old_suppression;
$receipt['audit_batching_passed'] = true;
echo wp_json_encode( $receipt ) . "\n";
