<?php
declare(strict_types=1);

/**
 * Fail-closed source checks for WordPress.org review-sensitive patterns.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "ERROR: WordPress.org source validation is CLI-only.\n" );
	exit( 1 );
}

$project_dir = dirname( __DIR__ );
$fixture_dir = null;
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( str_starts_with( $argument, '--fixture=' ) ) {
		$fixture_dir = substr( $argument, strlen( '--fixture=' ) );
	}
}
$scan_root   = null === $fixture_dir ? $project_dir : (string) realpath( $fixture_dir );
$config_path = $project_dir . '/docs/dev/wporg-source-allowlist.json';

/** @var string[] $failures */
$failures = array();

/** Record one stable rule failure. */
function cybermaps_wporg_source_error( string $rule, string $detail ): void {
	global $failures;
	$failures[] = "[{$rule}] {$detail}";
}

/** @return array<string,mixed> */
function cybermaps_wporg_source_json( string $path ): array {
	$contents = file_get_contents( $path );
	if ( ! is_string( $contents ) ) {
		throw new RuntimeException( "Could not read {$path}." );
	}
	$decoded = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $decoded ) ) {
		throw new RuntimeException( "{$path} must contain a JSON object." );
	}
	return $decoded;
}

/** @return array<string,string> Relative path => source. */
function cybermaps_wporg_source_files( string $root, bool $fixture ): array {
	$paths = array();
	if ( ! $fixture ) {
		foreach ( array( 'cybermaps.php', 'uninstall.php' ) as $entry ) {
			if ( is_file( $root . '/' . $entry ) ) {
				$paths[] = $root . '/' . $entry;
			}
		}
		$search_root = $root . '/src';
	} else {
		$search_root = $root;
	}
	if ( is_dir( $search_root ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $search_root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file instanceof SplFileInfo && $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$paths[] = $file->getPathname();
			}
		}
	}

	$files = array();
	foreach ( $paths as $path ) {
		$source = file_get_contents( $path );
		if ( ! is_string( $source ) ) {
			cybermaps_wporg_source_error( 'read', "Could not read {$path}." );
			continue;
		}
		$relative           = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
		$files[ $relative ] = $source;
	}
	ksort( $files, SORT_STRING );
	return $files;
}

/** @param string[] $entries @return array{count:int,sha256:string} */
function cybermaps_wporg_source_fingerprint( array $entries ): array {
	sort( $entries, SORT_STRING );
	return array(
		'count'  => count( $entries ),
		'sha256' => hash( 'sha256', implode( "\n", $entries ) ),
	);
}

/** @param array<string,string> $files */
function cybermaps_wporg_source_static_checks( array $files, array $config, bool $fixture ): void {
	$request_boundaries = array_fill_keys( (array) ( $config['request_boundary_files'] ?? array() ), true );
	$high_risk          = array();
	$protocol_ignores   = array();

	foreach ( $files as $path => $source ) {
		$lines = preg_split( '/\R/', $source ) ?: array();
		if ( preg_match( '/\$_(?:GET|POST|REQUEST|SERVER|COOKIE|FILES)\s*(?:;|:|,|\))/', $source ) ) {
			cybermaps_wporg_source_error( 'server-bag', "{$path} copies or forwards the complete server bag instead of reading bounded fields." );
		}
		if ( preg_match( '/<(?:script|style)(?:\s|>)/i', $source ) ) {
			cybermaps_wporg_source_error( 'inline-asset', "{$path} contains a literal script or style block." );
		}
		if ( preg_match( '/\$_(?:GET|POST|REQUEST|SERVER|COOKIE|FILES)\b/', $source ) && $fixture ) {
			cybermaps_wporg_source_error( 'request-boundary', "{$path} reads a raw request superglobal outside the designated boundary list." );
		}
		if (
			preg_match( '/(?:get|add|update|delete)_option\s*\([^;]*[\'\"]_transient_/is', $source )
			|| preg_match( '/option_name[^;\n]*_transient_/i', $source )
		) {
			cybermaps_wporg_source_error( 'reserved-transient', "{$path} manually accesses WordPress transient option names." );
		}
		if ( preg_match_all( '/\bIN\s*\(\s*(?:\{\s*)?\$([a-zA-Z_][a-zA-Z0-9_]*)/i', $source, $list_matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $list_matches[1] as $index => $variable_match ) {
				$variable   = (string) $variable_match[0];
				$position   = (int) $list_matches[0][ $index ][1];
				$definition = '/\$' . preg_quote( $variable, '/' ) . '\s*=\s*[^;]*array_fill\s*\([^;]*[\'\"]%[ds][\'\"]/s';
				$window     = substr( $source, max( 0, $position - 1500 ), 3000 );
				if ( ! preg_match( $definition, $source ) || ! str_contains( $window, 'prepare(' ) ) {
					cybermaps_wporg_source_error( 'sql-list', "{$path} contains an unsafe dynamic SQL IN list in \${$variable}." );
				}
			}
		}
		if (
			( $fixture || str_starts_with( $path, 'src/Discovery/' ) || str_starts_with( $path, 'src/MCP/' ) || str_starts_with( $path, 'src/Sitemap/' ) || str_starts_with( $path, 'src/Core/' ) || str_starts_with( $path, 'src/Integration/' ) )
			&& preg_match( '/JSON_(?:PRETTY_PRINT|UNESCAPED_SLASHES|UNESCAPED_UNICODE)/', $source )
		) {
			cybermaps_wporg_source_error( 'json-flags', "{$path} uses presentation flags in public protocol code." );
		}
		if ( preg_match( '/(?:wp-load\.php|wp-blog-header\.php|wp-config\.php)/i', $source ) ) {
			cybermaps_wporg_source_error( 'core-bootstrap', "{$path} includes a forbidden WordPress bootstrap file." );
		}
		if ( preg_match( '~wp-admin/includes/(?!(?:file|misc|upgrade)\.php)[^\'\"]+\.php~i', $source ) ) {
			cybermaps_wporg_source_error( 'core-bootstrap', "{$path} includes a non-allowlisted WordPress core file." );
		}

		foreach ( $lines as $offset => $line ) {
			$line_number = $offset + 1;
			if (
				preg_match( '/phpcs:(?:ignore|disable).*?(?:NonceVerification|EscapeOutput|ValidatedSanitizedInput|PreparedSQL|DirectDatabaseQuery)/', $line )
			) {
				$entry       = $path . ':' . $line_number . ':' . trim( $line );
				$high_risk[] = $entry;
				if ( str_contains( $line, 'EscapeOutput.OutputNotEscaped' ) && str_contains( $line, 'wporg-source-allowlist.json' ) ) {
					$protocol_ignores[] = array(
						'path'   => $path,
						'source' => 'WordPress.Security.EscapeOutput.OutputNotEscaped',
						'line'   => $line_number,
						'sha256' => hash( 'sha256', trim( $line ) ),
					);
				}
			}
			if ( ! preg_match( '~require_once\s+ABSPATH\s*\.\s*[\'\"]wp-admin/includes/(file|misc|upgrade)\.php[\'\"]~', $line, $match ) ) {
				continue;
			}
			$window   = implode( "\n", array_slice( $lines, $offset + 1, 6 ) );
			$required = array(
				'file'    => 'WP_Filesystem(',
				'misc'    => 'get_home_path(',
				'upgrade' => 'dbDelta(',
			)[ $match[1] ];
			if ( ! str_contains( $window, $required ) ) {
				cybermaps_wporg_source_error( 'core-include-order', "{$path}:{$line_number} does not use {$required} immediately after its permitted include." );
			}
		}
	}

	if ( $fixture ) {
		if ( array() !== $high_risk ) {
			cybermaps_wporg_source_error( 'phpcs-suppression', 'Fixture contains a high-risk PHPCS suppression.' );
		}
		return;
	}

	if ( $protocol_ignores !== ( $config['protocol_output_exceptions'] ?? array() ) ) {
		cybermaps_wporg_source_error( 'protocol-output', 'The exact raw protocol output exception allowlist changed.' );
	}
}


try {
	if ( ! is_string( $scan_root ) || '' === $scan_root || ! is_dir( $scan_root ) ) {
		throw new RuntimeException( 'The source scan root does not exist.' );
	}
	$config = null === $fixture_dir ? cybermaps_wporg_source_json( $config_path ) : array();
	if ( null === $fixture_dir ) {
		$readme = (string) file_get_contents( $project_dir . '/readme.txt' );
		if ( ! str_contains( $readme, '== Source Code ==' ) || ! str_contains( $readme, 'https://github.com/Alex9001/cybermaps' ) || ! str_contains( $readme, 'bash bin/package-candidate.sh' ) ) {
			cybermaps_wporg_source_error( 'asset-source', 'The shipped readme must identify editable sources and build instructions.' );
		}
	}
	$files  = cybermaps_wporg_source_files( $scan_root, null !== $fixture_dir );
	cybermaps_wporg_source_static_checks( $files, $config, null !== $fixture_dir );
	if ( null === $fixture_dir ) {
		passthru( 'python3 -B ' . escapeshellarg( $project_dir . '/bin/check-security-review.py' ), $audit_status );
		if ( 0 !== $audit_status ) {
			cybermaps_wporg_source_error( 'annotation-blind-phpcs', 'Security review failed; inspect exact changed function scopes.' );
		}
	}
} catch ( Throwable $error ) {
	cybermaps_wporg_source_error( 'tooling', $error->getMessage() );
}

if ( array() !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "ERROR: {$failure}\n" );
	}
	exit( 1 );
}

echo null === $fixture_dir
	? "WordPress.org source checks passed.\n"
	: "WordPress.org fixture accepted.\n";
