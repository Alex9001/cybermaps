<?php
declare(strict_types=1);

/**
 * Enforce the declared WordPress baseline and its independent product floor.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "ERROR: Runtime baseline validation is a command-line operation.\n" );
	exit( 1 );
}

/** Stop with a release-tooling error. */
function cybermaps_runtime_fail( string $message ): never {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

/** Read one required text file. */
function cybermaps_runtime_read( string $root, string $relative_path ): string {
	$path     = $root . '/' . $relative_path;
	$contents = file_get_contents( $path );
	if ( ! is_string( $contents ) ) {
		cybermaps_runtime_fail( "Could not read {$relative_path}." );
	}

	return $contents;
}

/** Extract one version from a required metadata source. */
function cybermaps_runtime_capture( string $contents, string $pattern, string $label ): string {
	if ( 1 !== preg_match( $pattern, $contents, $matches ) ) {
		cybermaps_runtime_fail( "Could not read the WordPress baseline from {$label}." );
	}

	return (string) $matches[1];
}

$project_dir = dirname( __DIR__ );
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( str_starts_with( $argument, '--root=' ) ) {
		$project_dir = substr( $argument, 7 );
		continue;
	}
	cybermaps_runtime_fail( "Unknown argument: {$argument}" );
}
$project_dir = rtrim( $project_dir, '/\\' );

$contract_json = cybermaps_runtime_read( $project_dir, 'docs/dev/runtime-baseline.json' );
try {
	$contract = json_decode( $contract_json, true, 32, JSON_THROW_ON_ERROR );
} catch ( JsonException $error ) {
	cybermaps_runtime_fail( 'Runtime baseline contract is invalid JSON: ' . $error->getMessage() );
}
if ( ! is_array( $contract ) || 1 !== ( $contract['schema_version'] ?? null ) ) {
	cybermaps_runtime_fail( 'Runtime baseline contract must use schema_version 1.' );
}

$floor   = $contract['minimum_floor_wordpress'] ?? null;
$runtime = $contract['release_runtime_wordpress'] ?? null;
$basis   = $contract['basis'] ?? null;
foreach ( array( 'minimum_floor_wordpress' => $floor, 'release_runtime_wordpress' => $runtime ) as $key => $value ) {
	if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]+\.[0-9]+$/', $value ) ) {
		cybermaps_runtime_fail( "Runtime baseline contract has an invalid {$key}." );
	}
}
if ( ! is_string( $basis ) || ! str_contains( $basis, 'Abilities API' ) ) {
	cybermaps_runtime_fail( 'Runtime baseline contract must document the native Abilities API basis.' );
}

$main     = cybermaps_runtime_read( $project_dir, 'cybermaps.php' );
$declared = cybermaps_runtime_capture(
	$main,
	'/^[ \t*]*Requires at least:[ \t]*([0-9]+(?:\.[0-9]+){1,2})[ \t]*$/mi',
	'cybermaps.php'
);
if ( version_compare( $declared, (string) $floor, '<' ) ) {
	cybermaps_runtime_fail(
		"Declared WordPress minimum {$declared} is below the independent {$floor} product floor required by the native Abilities API contract."
	);
}

$manifest_json = cybermaps_runtime_read( $project_dir, 'docs/dev/manifest.json' );
try {
	$manifest = json_decode( $manifest_json, true, 512, JSON_THROW_ON_ERROR );
} catch ( JsonException $error ) {
	cybermaps_runtime_fail( 'Developer manifest is invalid JSON: ' . $error->getMessage() );
}
if ( ! is_array( $manifest ) || ! is_string( $manifest['wp_min'] ?? null ) ) {
	cybermaps_runtime_fail( 'Developer manifest does not declare wp_min.' );
}

$metadata_versions = array(
	'cybermaps.php'                                   => $declared,
	'readme.txt'                                      => cybermaps_runtime_capture(
		cybermaps_runtime_read( $project_dir, 'readme.txt' ),
		'/^Requires at least:[ \t]*([0-9]+(?:\.[0-9]+){1,2})[ \t]*$/mi',
		'readme.txt'
	),
	'docs/dev/manifest.json'                          => $manifest['wp_min'],
	'docs/documentation.md'                           => cybermaps_runtime_capture(
		cybermaps_runtime_read( $project_dir, 'docs/documentation.md' ),
		'/^> Version [0-9]+(?:\.[0-9]+){2}[ \t]*·[ \t]*PHP [0-9.]+[ \t]*·[ \t]*WordPress ([0-9]+(?:\.[0-9]+){1,2})[ \t]*$/m',
		'docs/documentation.md'
	),
	'docs/features.md'                                => cybermaps_runtime_capture(
		cybermaps_runtime_read( $project_dir, 'docs/features.md' ),
		'/^> Standalone WordPress\.org plugin.*WordPress ([0-9]+(?:\.[0-9]+){1,2})\+/m',
		'docs/features.md'
	),
	'docs/comparison.md'                              => cybermaps_runtime_capture(
		cybermaps_runtime_read( $project_dir, 'docs/comparison.md' ),
		'/requires PHP [0-9.]+\+ and WordPress ([0-9]+(?:\.[0-9]+){1,2})\+\./',
		'docs/comparison.md'
	),
	'CONTRIBUTING.md'                                 => cybermaps_runtime_capture(
		cybermaps_runtime_read( $project_dir, 'CONTRIBUTING.md' ),
		'/Cybermaps requires PHP [0-9.]+\+ and WordPress ([0-9]+(?:\.[0-9]+){1,2})\+\./',
		'CONTRIBUTING.md'
	),
	'AGENTS.md'                                       => cybermaps_runtime_capture(
		cybermaps_runtime_read( $project_dir, 'AGENTS.md' ),
		'/Language\/runtime:.*WordPress ([0-9]+(?:\.[0-9]+){1,2})\+/',
		'AGENTS.md'
	),
	'phpcs.xml.dist'                                  => cybermaps_runtime_capture(
		cybermaps_runtime_read( $project_dir, 'phpcs.xml.dist' ),
		'/<config name="minimum_wp_version" value="([0-9]+(?:\.[0-9]+){1,2})"\/>/',
		'phpcs.xml.dist'
	),
);

foreach ( $metadata_versions as $source => $version ) {
	if ( $declared !== $version ) {
		cybermaps_runtime_fail(
			"WordPress baseline drift: cybermaps.php declares {$declared}, but {$source} declares {$version}."
		);
	}
}
if ( $declared !== $runtime ) {
	cybermaps_runtime_fail(
		"Release runtime {$runtime} must exactly match the declared WordPress minimum {$declared}."
	);
}

echo "Runtime baseline is consistent: WordPress {$declared} (independent floor {$floor}).\n";
