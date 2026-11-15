<?php
declare(strict_types=1);

require dirname( __DIR__, 2 ) . '/bootstrap.php';
require dirname( __DIR__ ) . '/AuditRunRepositoryTest.php';

$GLOBALS['wpdb'] = new Cybermaps\Tests\Audit\AuditRunRepositoryWpdbStub();
$batch = array_fill( 0, 5, array( 80, array(
	'key' => 'test', 'severity' => 'review', 'summary' => 'Café',
	'evidence' => array(), 'recommendation' => 'Action',
) ) );
( new ReflectionMethod( Cybermaps\Audit\AuditRunRepository::class, 'insert_finding_batch' ) )->invoke( new Cybermaps\Audit\AuditRunRepository(), 8, $batch );
echo json_encode( array(
	'mb_available' => function_exists( 'mb_strlen' ),
	'findings' => count( $GLOBALS['wpdb']->saved_findings ),
	'summary' => $GLOBALS['wpdb']->saved_findings[0]['summary'] ?? null,
	'queries' => $GLOBALS['wpdb']->queries,
) );
