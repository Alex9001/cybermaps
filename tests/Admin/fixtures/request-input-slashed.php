<?php
declare(strict_types=1);

namespace Cybermaps\Core {
	function wp_unslash( string $value ): string { ++$GLOBALS['request_unslash_calls']; return stripslashes( $value ); }
}
namespace {
	require dirname( __DIR__, 2 ) . '/bootstrap.php';
	use Cybermaps\Core\RequestInput;
	$GLOBALS['request_unslash_calls'] = 0;
	$uri = '/' . str_repeat( '"', 8192 - strlen( '/?version=3.1.2' ) ) . '?version=3.1.2';
	$_SERVER['REQUEST_URI'] = addslashes( $uri );
	$checks = array( 'escaped_max_uri' => strlen( $_SERVER['REQUEST_URI'] ) > 8192 && '3.1.2' === RequestInput::query_text( 'version', 16 ) );
	$_SERVER['REQUEST_URI'] = addslashes( 'p' . $uri );
	$checks['decoded_uri_over_limit'] = '' === RequestInput::query_text( 'version', 16 );
	$literal = str_repeat( '"', 4096 );
	$_SERVER['HTTP_IF_NONE_MATCH'] = addslashes( $literal );
	$checks['escaped_max_header'] = 8192 === strlen( $_SERVER['HTTP_IF_NONE_MATCH'] ) && $literal === RequestInput::header( 'if-none-match' );
	$_SERVER['HTTP_IF_NONE_MATCH'] = addslashes( 'x' . $literal );
	$checks['decoded_header_over_limit'] = '' === RequestInput::header( 'if-none-match' );
	$before = $GLOBALS['request_unslash_calls'];
	$_SERVER['REQUEST_URI'] = str_repeat( 'p', 16385 );
	$_SERVER['HTTP_IF_NONE_MATCH'] = str_repeat( 'x', 8193 );
	$checks['raw_rejected_before_unslash'] = '' === RequestInput::query_text( 'version' ) && '' === RequestInput::header( 'if-none-match' ) && $before === $GLOBALS['request_unslash_calls'];
	$literal = '  W/"opaque%25<tag>\\tail", "second"  ';
	$_SERVER['HTTP_IF_NONE_MATCH'] = addslashes( $literal );
	$checks['literal_validator_preserved'] = $literal === RequestInput::header( 'if-none-match' );
	echo json_encode( $checks, JSON_THROW_ON_ERROR );
}
