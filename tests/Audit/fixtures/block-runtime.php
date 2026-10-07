<?php
declare(strict_types=1);

// Deliberately loaded only inside isolated PHPUnit processes. Each result models
// the WordPress block API contract; this is not a substitute for the native parser.
function parse_blocks( string $content ): array {
	$GLOBALS['cybermaps_audit_parse_calls'][] = $content;
	return $GLOBALS['cybermaps_audit_parsed'][ $content ] ?? array();
}

function get_block_templates( array $query = array(), string $type = 'wp_template' ): array {
	throw new RuntimeException( 'Hydrating/rendering unified templates is forbidden in the stored audit.' );
}

function get_block_template( string $id, string $type = 'wp_template' ): ?object {
	throw new RuntimeException( 'Hydrating/rendering unified template parts is forbidden in the stored audit.' );
}

function get_stylesheet(): string { return 'fixture-theme'; }
function get_template(): string { return 'fixture-theme'; }
function get_stylesheet_directory(): string { return __DIR__ . '/theme'; }
function get_template_directory(): string { return __DIR__ . '/theme'; }
function get_block_theme_folders( string $theme ): array { return array( 'wp_template' => 'templates', 'wp_template_part' => 'parts' ); }

final class CybermapsAuditFilesystem {
	public string $method = 'direct';
	public array $reads = array();
	public ?int $reported_size = null;
	public function is_dir( string $path ): bool { return is_dir( $path ); }
	public function is_file( string $path ): bool { return is_file( $path ); }
	public function is_readable( string $path ): bool { return is_readable( $path ); }
	public function size( string $path ): int|false { return $this->reported_size ?? filesize( $path ); }
	public function get_contents( string $path ): string|false {
		$this->reads[] = $path;
		return file_get_contents( $path );
	}
}

// The stored source resolves the current theme's raw taxonomy identity first.
$GLOBALS['cybermaps_mock_terms']['wp_theme'] = array( (object) array( 'term_id' => 71, 'term_taxonomy_id' => 171, 'taxonomy' => 'wp_theme', 'name' => 'fixture-theme' ) );
