<?php
declare(strict_types=1);

/**
 * Verify that the committed developer manifest exactly matches a fresh run.
 *
 * The timestamp is intentionally excluded from comparison; every other value,
 * key, ordering decision, and type must remain identical.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "ERROR: Manifest validation is a command-line operation.\n" );
	exit( 1 );
}

$project_dir    = dirname( __DIR__ );
$manifest_path  = $argv[1] ?? $project_dir . '/docs/dev/manifest.json';
$generator_path = $project_dir . '/docs/dev/generate-docs.php';

/**
 * Stop with a release-tooling error.
 */
function cybermaps_manifest_fail( string $message ): never {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

/**
 * Decode one required JSON object.
 *
 * @return array<string, mixed>
 */
function cybermaps_manifest_decode( string $contents, string $label ): array {
	try {
		$decoded = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	} catch ( JsonException $error ) {
		cybermaps_manifest_fail( "{$label} is invalid JSON: {$error->getMessage()}" );
	}

	if ( ! is_array( $decoded ) ) {
		cybermaps_manifest_fail( "{$label} must contain a JSON object." );
	}

	return $decoded;
}

if ( ! is_file( $manifest_path ) ) {
	cybermaps_manifest_fail( "Committed manifest does not exist: {$manifest_path}" );
}
if ( ! is_file( $generator_path ) ) {
	cybermaps_manifest_fail( "Manifest generator does not exist: {$generator_path}" );
}

$descriptors = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);
$pipes       = array();
$process     = proc_open(
	array( PHP_BINARY, $generator_path ),
	$descriptors,
	$pipes,
	$project_dir
);

if ( ! is_resource( $process ) ) {
	cybermaps_manifest_fail( 'Could not start the manifest generator.' );
}

fclose( $pipes[0] );
$generated_json  = stream_get_contents( $pipes[1] );
$generator_error = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
$generator_status = proc_close( $process );
$generator_detail = trim( is_string( $generator_error ) ? $generator_error : '' );

if ( 0 !== $generator_status ) {
	cybermaps_manifest_fail(
		'Manifest generation failed.'
		. ( '' !== $generator_detail ? " {$generator_detail}" : '' )
	);
}
if ( '' !== $generator_detail ) {
	cybermaps_manifest_fail( "Manifest generation emitted diagnostics. {$generator_detail}" );
}
if ( ! is_string( $generated_json ) || '' === trim( $generated_json ) ) {
	cybermaps_manifest_fail( 'Manifest generator returned no JSON.' );
}

$committed_json = file_get_contents( $manifest_path );
if ( ! is_string( $committed_json ) ) {
	cybermaps_manifest_fail( "Could not read committed manifest: {$manifest_path}" );
}

$committed = cybermaps_manifest_decode( $committed_json, 'Committed manifest' );
$generated = cybermaps_manifest_decode( $generated_json, 'Generated manifest' );

if (
	! array_key_exists( 'generated_at', $committed )
	|| ! array_key_exists( 'generated_at', $generated )
) {
	cybermaps_manifest_fail( 'Both manifests must contain generated_at metadata.' );
}

unset( $committed['generated_at'], $generated['generated_at'] );
if ( $committed !== $generated ) {
	$changed_keys = array();
	$all_keys     = array_values(
		array_unique(
			array_merge( array_keys( $committed ), array_keys( $generated ) )
		)
	);
	foreach ( $all_keys as $key ) {
		if (
			! array_key_exists( $key, $committed )
			|| ! array_key_exists( $key, $generated )
			|| $committed[ $key ] !== $generated[ $key ]
		) {
			$changed_keys[] = $key;
		}
	}

	$detail = '' !== implode( ', ', $changed_keys )
		? ' Changed top-level keys: ' . implode( ', ', $changed_keys ) . '.'
		: '';
	cybermaps_manifest_fail(
		"docs/dev/manifest.json is stale. Run php docs/dev/generate-docs.php > docs/dev/manifest.json.{$detail}"
	);
}

echo "Developer manifest is current: {$manifest_path}\n";
