<?php
declare(strict_types=1);

/** Disposable release fixture. Never packaged or installed on a user's site. */
if ( ! defined( 'CYBERMAPS_NATIVE_UPGRADE_FIXTURE' ) || true !== CYBERMAPS_NATIVE_UPGRADE_FIXTURE ) {
	throw new RuntimeException( 'Disposable native upgrade fixture required.' );
}

// A foreign rewrite rule must survive the update alongside Cybermaps rules.
add_action( 'init', static function (): void {
	add_rewrite_rule( '^native-upgrade-foreign/?$', 'index.php?native_upgrade_foreign=1', 'top' );
} );

/** Passive transport observer; never supplies or modifies HTTP responses. */
add_action( 'http_api_debug', static function ( $response, $context, $transport, $request, $url ): void {
	$update_url = 'https://api.wordpress.org/plugins/update-check/1.1/';
	$package_url = 'https://downloads.wordpress.org/plugin/cybermaps.' . CYBERMAPS_NATIVE_UPGRADE_VERSION . '.zip';
	if ( 'response' !== $context || ! in_array( $url, array( $update_url, $package_url ), true ) ) {
		return;
	}
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	$directory = '/evidence/http-observations/';
	if ( ! $wp_filesystem->is_dir( $directory ) && ! $wp_filesystem->mkdir( $directory ) ) {
		throw new RuntimeException( 'Could not create HTTP provenance directory.' );
	}
	$id = wp_generate_uuid4();
	$record = array(
		'url' => $url, 'kind' => $url === $update_url ? 'update-check' : 'download',
		'context' => $context, 'transport' => $transport, 'method' => $request['method'] ?? '',
		'sslverify' => $request['sslverify'] ?? false, 'response_code' => wp_remote_retrieve_response_code( $response ),
		'error' => is_wp_error( $response ) ? $response->get_error_code() : null,
		'checked_at' => gmdate( 'c' ),
	);
	if ( $url === $update_url ) {
		$request_body = wp_json_encode( $request['body'] ?? array() );
		$response_body = wp_remote_retrieve_body( $response );
		foreach ( array( 'request' => $request_body, 'response' => $response_body ) as $type => $body ) {
			$name = $id . '-' . $type . '.json';
			if ( ! $wp_filesystem->put_contents( $directory . $name, $body ) ) {
				throw new RuntimeException( 'Could not retain update-check HTTP bytes.' );
			}
			$record[ $type . '_file' ] = $name;
			$record[ $type . '_sha256' ] = hash( 'sha256', $body );
		}
	} elseif ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$filename = $request['filename'] ?? '';
		if ( ! is_string( $filename ) || ! is_file( $filename ) ) {
			throw new RuntimeException( 'WordPress did not stream its download to a file.' );
		}
		$record['zip_sha256'] = hash_file( 'sha256', $filename );
		$record['zip_file'] = $id . '-download.zip';
		if ( ! $wp_filesystem->copy( $filename, $directory . $record['zip_file'], true ) ) {
			throw new RuntimeException( 'Could not retain the actual WordPress.org download.' );
		}
	}
	if ( ! $wp_filesystem->put_contents( $directory . $id . '-observation.json', wp_json_encode( $record ) ) ) {
		throw new RuntimeException( 'Could not retain HTTP provenance.' );
	}
}, 10, 5 );
