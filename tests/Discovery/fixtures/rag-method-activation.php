<?php
declare(strict_types=1);
require dirname( __DIR__, 2 ) . '/bootstrap.php';
cybermaps_mock_reset_cache_runtime();
$GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'enable_rag_chunks' => 'enabled' === ( $argv[1] ?? '' ) ? '1' : '0' ) );
$GLOBALS['cybermaps_mock_status_headers'] = array();
$_SERVER['REQUEST_URI'] = '/discovery/chunks/7.json';
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
register_shutdown_function( static function (): void {
	$body = ob_get_clean();
	echo json_encode( array( 'status' => $GLOBALS['cybermaps_mock_status_headers'], 'body' => $body ) );
} );
( new \Cybermaps\Discovery\RAGChunk() )->handle();
echo 'fell_through';
