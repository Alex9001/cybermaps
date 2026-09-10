<?php
declare(strict_types=1);

/**
 * Verify that the committed AI Configuration Brief schema and catalog match
 * the canonical PHP registry exactly and that the publishable guide documents
 * the generated contract.
 */

$project_dir   = dirname( __DIR__ );
$generator     = $project_dir . '/docs/dev/generate-docs.php';
$artifact_root = $project_dir . '/docs/dev/ai-configuration';
$guide_path    = $project_dir . '/docs/ai-configuration.md';
$artifacts     = array(
	'--ai-schema'  => $artifact_root . '/schema.json',
	'--ai-catalog' => $artifact_root . '/catalog.json',
);
$committed_artifacts = array();

function cybermaps_ai_contract_fail( string $message ): never {
	fwrite( STDERR, "AI configuration contract check failed: {$message}\n" );
	exit( 1 );
}

/**
 * @return array<string,mixed>
 */
function cybermaps_ai_contract_decode( string $contents, string $label ): array {
	try {
		$decoded = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	} catch ( JsonException $error ) {
		cybermaps_ai_contract_fail( "{$label} is invalid JSON: {$error->getMessage()}" );
	}
	if ( ! is_array( $decoded ) || ( ! empty( $decoded ) && array_is_list( $decoded ) ) ) {
		cybermaps_ai_contract_fail( "{$label} must contain a JSON object." );
	}

	return $decoded;
}

if ( ! is_file( $generator ) ) {
	cybermaps_ai_contract_fail( "generator does not exist: {$generator}" );
}

foreach ( $artifacts as $mode => $artifact_path ) {
	if ( ! is_file( $artifact_path ) ) {
		cybermaps_ai_contract_fail( "committed artifact does not exist: {$artifact_path}" );
	}

	$command = array( PHP_BINARY, $generator, $mode );
	$spec    = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$pipes   = array();
	$process = proc_open( $command, $spec, $pipes, $project_dir );
	if ( ! is_resource( $process ) ) {
		cybermaps_ai_contract_fail( "could not start generator for {$mode}." );
	}
	fclose( $pipes[0] );
	$generated_json = stream_get_contents( $pipes[1] );
	$diagnostics    = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	if ( 0 !== $status ) {
		cybermaps_ai_contract_fail( "generator failed for {$mode}: " . trim( (string) $diagnostics ) );
	}
	if ( '' !== trim( (string) $diagnostics ) ) {
		cybermaps_ai_contract_fail( "generator emitted diagnostics for {$mode}: " . trim( (string) $diagnostics ) );
	}
	if ( ! is_string( $generated_json ) || '' === trim( $generated_json ) ) {
		cybermaps_ai_contract_fail( "generator returned no JSON for {$mode}." );
	}

	$committed_json = file_get_contents( $artifact_path );
	if ( ! is_string( $committed_json ) ) {
		cybermaps_ai_contract_fail( "could not read committed artifact: {$artifact_path}" );
	}
	$generated = cybermaps_ai_contract_decode( $generated_json, "Generated {$mode}" );
	$committed = cybermaps_ai_contract_decode( $committed_json, "Committed {$mode}" );
	if ( $generated !== $committed ) {
		cybermaps_ai_contract_fail(
			"{$artifact_path} is stale. Regenerate it with php docs/dev/generate-docs.php {$mode} > {$artifact_path}."
		);
	}
	$committed_artifacts[ $mode ] = $committed;
}

if ( ! is_file( $guide_path ) ) {
	cybermaps_ai_contract_fail( "publishable guide does not exist: {$guide_path}" );
}
$guide = file_get_contents( $guide_path );
if ( ! is_string( $guide ) || '' === trim( $guide ) ) {
	cybermaps_ai_contract_fail( "publishable guide could not be read: {$guide_path}" );
}
$searchable_guide = preg_replace( '/\s+/u', ' ', $guide );
if ( ! is_string( $searchable_guide ) ) {
	cybermaps_ai_contract_fail( "publishable guide could not be normalized: {$guide_path}" );
}

$catalog = $committed_artifacts['--ai-catalog'] ?? array();
$version = isset( $catalog['plugin_version'] ) && is_string( $catalog['plugin_version'] )
	? $catalog['plugin_version']
	: '';
$sections = isset( $catalog['sections'] ) && is_array( $catalog['sections'] )
	? $catalog['sections']
	: array();
$fields = isset( $catalog['fields'] ) && is_array( $catalog['fields'] )
	? $catalog['fields']
	: array();
if ( '' === $version || empty( $sections ) || empty( $fields ) ) {
	cybermaps_ai_contract_fail( 'committed catalog is missing version, section, or field metadata.' );
}

$spec_base = 'https://cybermaps.dev/specs/ai-configuration/' . rawurlencode( $version );
$required_guide_fragments = array(
	'Cybermaps ' . $version,
	'https://cybermaps.dev/docs/ai-configuration/',
	$spec_base . '/schema.json',
	$spec_base . '/catalog.json',
	'cybermaps-ai-configuration-changes',
	'CYBERMAPS-CHANGES-BEGIN',
	'CYBERMAPS-CHANGES-END',
	'all ' . count( $fields ) . ' editable fields',
	'null',
	'Smart Merge',
	'Preview Changes',
	'Apply Reviewed Changes',
	'private Cybermaps REST API secret',
	'IndexNow key',
	'docs/dev/ai-configuration/schema.json',
	'docs/dev/ai-configuration/catalog.json',
);
foreach ( $sections as $section ) {
	if ( is_array( $section ) && isset( $section['id'] ) && is_string( $section['id'] ) ) {
		$required_guide_fragments[] = '`' . $section['id'] . '`';
	}
}

foreach ( array_unique( $required_guide_fragments ) as $fragment ) {
	$normalized_fragment = preg_replace( '/\s+/u', ' ', $fragment );
	if ( ! is_string( $normalized_fragment ) || ! str_contains( $searchable_guide, $normalized_fragment ) ) {
		cybermaps_ai_contract_fail( "{$guide_path} is missing required contract text: {$fragment}" );
	}
}

echo "AI configuration schema, catalog, and guide are current.\n";
