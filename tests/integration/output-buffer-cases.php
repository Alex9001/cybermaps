<?php
declare(strict_types=1);

/** Test-only bounded subprocess runner, shared by unit and real WordPress checks. */
function cybermaps_buffer_cases( string $bootstrap, string $origin ): array {
	$cases = array();
	foreach ( array( 'uncached', 'cached', 'not_modified', 'early_404', 'late_404', 'unavailable_render', 'unavailable_headers', 'unrelated' ) as $scenario ) {
		foreach ( array( 'single', 'nested', 'fixed' ) as $mode ) {
			foreach ( array( 'GET', 'HEAD' ) as $method ) {
				$command = array( PHP_BINARY, __DIR__ . '/output-buffer-worker.php', $bootstrap, $scenario, $mode, $method, $origin );
				$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
				if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start buffer worker.' ); }
				foreach ( $pipes as $pipe ) { stream_set_blocking( $pipe, false ); }
				$stdout = ''; $stderr = ''; $deadline = microtime( true ) + 15;
				do {
					$stdout .= stream_get_contents( $pipes[1] ); $stderr .= stream_get_contents( $pipes[2] );
					$status = proc_get_status( $process );
					if ( ! $status['running'] ) { break; }
					if ( microtime( true ) > $deadline ) { proc_terminate( $process, 9 ); break; }
					usleep( 10000 );
				} while ( true );
				$stdout .= stream_get_contents( $pipes[1] ); $stderr .= stream_get_contents( $pipes[2] );
				foreach ( $pipes as $pipe ) { fclose( $pipe ); }
				proc_close( $process );
				if ( $status['running'] || 0 !== $status['exitcode'] || '' !== $stdout ) {
					throw new RuntimeException( "Buffer worker failed or timed out: {$scenario}/{$mode}/{$method}: " . substr( $stdout . $stderr, 0, 1500 ) );
				}
				$result = json_decode( $stderr, true, 512, JSON_THROW_ON_ERROR );
				cybermaps_buffer_assert_case( $result, $scenario, $mode, $method );
				$result['passed'] = true;
				$cases[] = $result;
			}
		}
	}
	return $cases;
}

function cybermaps_buffer_assert_case( array $result, string $scenario, string $mode, string $method ): void {
	$expected = match ( $scenario ) {
		'not_modified' => 304,
		'early_404', 'late_404' => 404,
		'unavailable_render', 'unavailable_headers' => 503,
		default => 200,
	};
	if ( $result['before'] !== $result['after'] || true !== $result['prefix_preserved'] || array() !== $result['callback_phases_before_shutdown'] || $expected !== $result['status'] ) {
		throw new RuntimeException( "Buffer/status ownership changed: {$scenario}/{$mode}/{$method}: " . json_encode( $result ) );
	}
	$body = $result['body'];
	if ( 'HEAD' === $method || in_array( $scenario, array( 'not_modified', 'unrelated' ), true ) ) {
		if ( '' !== $body ) { throw new RuntimeException( 'Bodyless response emitted bytes.' ); }
	} elseif ( 200 === $expected ) {
		$xml = new DOMDocument();
		if ( ! $xml->loadXML( $body, LIBXML_NONET ) || 1 !== $xml->getElementsByTagName( 'url' )->length ) { throw new RuntimeException( 'Incomplete sitemap body.' ); }
		$marker = 'cached' === $scenario ? '/cached-fixture/' : '/buffer-fixture/';
		if ( ! str_contains( $body, $marker ) ) { throw new RuntimeException( 'Wrong cached/uncached body.' ); }
	} elseif ( '' === trim( $body ) || str_contains( $body, '<url' ) ) {
		throw new RuntimeException( 'Error response contained missing or partial protocol bytes.' );
	}
}
