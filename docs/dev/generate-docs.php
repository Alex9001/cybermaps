<?php
declare(strict_types=1);

/**
 * ============================================================================
 * Cybermaps Documentation Generator — AI AGENT REFERENCE
 * ============================================================================
 *
 * WHAT THIS IS:
 *   A standalone CLI script that introspects the plugin source code and
 *   outputs a structured JSON manifest of every feature, endpoint, setting,
 *   command, and source class. No WordPress bootstrap required — all WP
 *   functions are stubbed for standalone execution.
 *
 * HOW TO RUN (AI agent):
 *   cd /path/to/cybermaps && php docs/dev/generate-docs.php
 *
 *   The script outputs JSON to stdout. Pipe it to a file or parse it:
 *     php docs/dev/generate-docs.php > docs/dev/manifest.json
 *     php docs/dev/generate-docs.php --ai-schema > docs/dev/ai-configuration/schema.json
 *     php docs/dev/generate-docs.php --ai-catalog > docs/dev/ai-configuration/catalog.json
 *     php docs/dev/generate-docs.php | python3 -c "import json,sys; ..."
 *
 * WHAT TO UPDATE WHEN THE PLUGIN CHANGES:
 *   1. PLUGIN HEADERS: Name, version, minimum PHP, and minimum WordPress are
 *      automatically read from cybermaps.php. Do not duplicate them here.
 *   2. NEW ENDPOINTS: Register complete metadata in EndpointRegistry. The
 *      manifest derives fixed discovery paths, REST routes, and static targets
 *      from that registry. Add parameterized route metadata to
 *      extract_parameterized_discovery_endpoints().
 *   3. NEW STUB FUNCTIONS: If new source files reference WP functions not yet
 *      stubbed, add them to the stub block below (lines ~55-90).
 *   4. NEW SOURCE FILES: The script auto-discovers all PHP classes in src/
 *      via find_classes(). No manual registration needed.
 *
 * OUTPUT SCHEMA (top-level keys):
 *   plugin                — string: plugin display name
 *   version               — string: from cybermaps.php header
 *   php_min / wp_min      — string: minimum requirements
 *   generated_at          — ISO8601 timestamp
 *   discovery_endpoints[] — {id, path, canonical, parameterized, type, label,
 *                           desc, spec, delivery, maturity, adoption, group,
 *                           enabled_setting, advertised, throttle_tier,
 *                           static_bucket}
 *   discovery_endpoint_count — integer
 *   sitemap_features[]    — string list of sitemap capabilities
 *   shortcode_attributes[]— {name, default}
 *   shortcode_attribute_count — integer
 *   rest_api_routes[]     — {namespace, route, full}
 *   rest_api_route_count  — integer
 *   wp_cli_commands[]     — {name, full}
 *   wp_cli_command_count  — integer
 *   static_files[]        — string list of files written by StaticBridge
 *   static_file_count     — integer
 *   static_engine         — {default, modes[], target_counts} mode schema
 *   settings[]            — string list of cybermaps_settings option keys
 *   settings_count        — integer
 *   configuration_options[] — {option, storage_type, sanitizer,
 *                              top_level_fields, owning_workspace}
 *   configuration_option_count — integer
 *   standards[]           — {id, title, authority, spec_uri, version, maturity,
 *                            support, reviewed_at, conformance}
 *   standards_count       — integer
 *   ai_configuration      — {format_version, guide_path, public URLs,
 *                            schema_hash, catalog_hash, section_count,
 *                            field_count, artifacts}
 *   source_classes[]      — {namespace, class, fqcn, file, summary}
 *   class_count           — integer
 *
 * TROUBLESHOOTING:
 *   - "Namespace declaration statement has to be the very first statement":
 *     A source file has code between `declare(strict_types=1)` and `namespace`.
 *     The ABSPATH guard must come AFTER the namespace declaration, not before.
 *     Pattern: <?php → declare → namespace → ABSPATH guard.
 *   - "Class X not found": A required source file references a class that
 *     isn't loaded. Add a require_once for it in the "Load key source files"
 *     block below.
 *   - Fatal error on a WP function: Add a stub in the WordPress stubs block.
 *
 * ============================================================================
 */

// --- Bootstrap: paths and version detection ---

define( 'CYBERMAPS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'CYBERMAPS_PLUGIN_URL', '' );

// Detect release metadata from the main plugin file header.
$_cybermaps_main_file = CYBERMAPS_PLUGIN_DIR . 'cybermaps.php';
$_cybermaps_headers   = [
    'plugin'  => '',
    'version' => '0.0.0',
    'php_min' => '',
    'wp_min'  => '',
];
if ( file_exists( $_cybermaps_main_file ) ) {
    $_header = file_get_contents( $_cybermaps_main_file );
    if ( is_string( $_header ) && '' !== $_header ) {
        $_header_patterns = [
            'plugin'  => '/^[ \t*]*Plugin Name:\s*(.+?)\s*$/mi',
            'version' => '/^[ \t*]*Version:\s*([0-9.]+)\s*$/mi',
            'php_min' => '/^[ \t*]*Requires PHP:\s*([0-9.]+)\s*$/mi',
            'wp_min'  => '/^[ \t*]*Requires at least:\s*([0-9.]+)\s*$/mi',
        ];
        foreach ( $_header_patterns as $_key => $_pattern ) {
            if ( preg_match( $_pattern, $_header, $_match ) ) {
                $_cybermaps_headers[ $_key ] = trim( $_match[1] );
            }
        }
    }
}
define( 'CYBERMAPS_VERSION', $_cybermaps_headers['version'] );
define( 'CYBERMAPS_PLUGIN_BASENAME', 'cybermaps/cybermaps.php' );
define( 'ABSPATH', CYBERMAPS_PLUGIN_DIR . '../../../../' );

// ============================================================================
// WordPress function stubs — makes the script runnable without WP bootstrap.
// Add new stubs below when source files reference previously-unstubbed WP functions.
// ============================================================================

if ( ! function_exists( '__' ) ) {
    function __( string $s, string $td = '' ): string { return $s; }
    function esc_html__( string $s, string $td = '' ): string { return $s; }
    function esc_html( string $s ): string { return $s; }
    function esc_attr( string $s ): string { return $s; }
    function esc_url( string $s ): string { return $s; }
    function esc_xml( string $s ): string { return htmlspecialchars( $s ); }
    function esc_textarea( string $s ): string { return $s; }
    function esc_attr_e( string $s, string $td = '' ): void {}
    function checked( bool $v, bool $c ): void {}
    function wp_json_encode( mixed $d, int $f = 0 ): string|false { return json_encode( $d, $f ); }
    function get_option( string $k, mixed $d = false ): mixed { return $d; }
    function update_option( string $k, mixed $v, bool $a = false ): bool { return true; }
    function delete_option( string $k ): bool { return true; }
    function get_bloginfo( string $k ): string { return 'Example Site'; }
    function home_url( string $p = '' ): string { return 'https://example.com' . $p; }
    function sanitize_title( string $s ): string { return $s; }
    function sanitize_text_field( string $s ): string { return $s; }
    function sanitize_key( string $s ): string { return $s; }
    function sanitize_file_name( string $s ): string { return $s; }
    function sanitize_textarea_field( string $s ): string { return $s; }
    function wp_unslash( string $s ): string { return $s; }
    function absint( mixed $v ): int { return (int) $v; }
    function current_user_can( string $c ): bool { return true; }
    function wp_create_nonce( string $a ): string { return 'nonce'; }
    function wp_verify_nonce( string $n, string $a ): bool { return true; }
    function check_ajax_referer( string $a, string $q = '_wpnonce' ): bool { return true; }
    function admin_url( string $p = '' ): string { return '/wp-admin/' . $p; }
    function rest_url( string $p = '' ): string { return '/wp-json/' . $p; }
    function plugin_dir_path( string $f ): string { return CYBERMAPS_PLUGIN_DIR; }
    function plugin_dir_url( string $f ): string { return CYBERMAPS_PLUGIN_URL; }
    function plugin_basename( string $f ): string { return CYBERMAPS_PLUGIN_BASENAME; }
    function add_action( string $h, callable $c, int $p = 10 ): void {}
    function add_filter( string $h, callable $c, int $p = 10 ): void {}
    function add_shortcode( string $t, callable $c ): void {}
    function do_action( string $h ): void {}
    function apply_filters( string $h, mixed $v ): mixed { return $v; }
    function wp_parse_args( array $a, array $d ): array { return array_merge( $d, $a ); }
    function shortcode_atts( array $d, array $a ): array { return array_merge( $d, $a ); }
    function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); }
    function wp_kses( string $s, array $c ): string { return $s; }
    function wp_kses_post( string $s ): string { return $s; }
    function status_header( int $c ): void {}
    function nocache_headers(): void {}
    function wp_die( string $m = '' ): void { exit( 1 ); }
    function wp_send_json_success( mixed $d = null ): void { echo json_encode( [ 'success' => true, 'data' => $d ] ); exit; }
    function wp_send_json_error( mixed $d = null ): void { echo json_encode( [ 'success' => false, 'data' => $d ] ); exit; }
}

// ============================================================================
// Load minimal source files needed for class introspection.
// The scanner uses reflection via file_get_contents + regex, so full class
// loading is NOT required for most files. Only load files that other files
// depend on at the PHP level (interfaces, base classes referenced by `extends`
// or `implements` in other source files).
// ============================================================================

$_required_files = [
	'src/Autoloader.php',
    'src/Sitemap/ProviderInterface.php',
];

foreach ( $_required_files as $_rf ) {
    $_path = CYBERMAPS_PLUGIN_DIR . $_rf;
    if ( file_exists( $_path ) ) {
        require_once $_path;
    } else {
        fwrite( STDERR, "WARNING: Required file not found: $_rf — some class introspection may be incomplete.\n" );
    }
}

\Cybermaps\Autoloader::register();

// ============================================================================
// EXTRACTION FUNCTIONS — each scans a specific source file/directory and
// returns structured data. All functions return empty arrays on failure so
// the JSON output is always valid even if source files are missing.
// ============================================================================

/**
 * Extract the first prose line from a declaration docblock.
 */
function extract_docblock_summary( string $docblock ): string {
    $lines = preg_split( '/\R/', $docblock );
    if ( ! is_array( $lines ) ) return '';

    foreach ( $lines as $line ) {
        $line = trim( $line );
        $line = preg_replace( '/^\/?\*+\s?|\s*\*\/$/', '', $line );
        $line = is_string( $line ) ? trim( $line ) : '';
        if ( '' === $line || str_starts_with( $line, '@' ) ) continue;
        return $line;
    }

    return '';
}

/**
 * Find all PHP classes in a directory tree.
 * Scans recursively, extracts namespace + class name + docblock summary.
 * PHP tokens are used so prose such as "this class handles" in comments can
 * never be mistaken for a declaration.
 * Returns array of {namespace, class, fqcn, file, summary}.
 */
function find_classes( string $dir ): array {
    $classes = [];
    if ( ! is_dir( $dir ) ) return $classes;
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
    foreach ( $it as $file ) {
        if ( $file->getExtension() !== 'php' ) continue;
        $content = file_get_contents( $file->getPathname() );
        if ( ! $content ) continue;

        foreach ( extract_declared_classes( $content ) as $declaration ) {
            $classes[] = [
                'namespace' => $declaration['namespace'],
                'class'     => $declaration['class'],
                'fqcn'      => $declaration['namespace'] . '\\' . $declaration['class'],
                'file'      => str_replace( CYBERMAPS_PLUGIN_DIR, '', $file->getPathname() ),
                'summary'   => $declaration['summary'],
            ];
        }
    }

    usort(
        $classes,
        static fn ( array $left, array $right ): int => [
            $left['file'],
            $left['class'],
        ] <=> [
            $right['file'],
            $right['class'],
        ]
    );

    return $classes;
}

/**
 * Extract named class declarations from PHP source.
 *
 * Interfaces, traits, and enums are intentionally excluded because the
 * manifest field is `source_classes`. Anonymous classes are excluded as they
 * do not form part of the public class inventory.
 *
 * @return array<int, array{namespace: string, class: string, summary: string}>
 */
function extract_declared_classes( string $content ): array {
    $tokens      = token_get_all( $content );
    $namespace   = '';
    $declarations = [];
    $token_count = count( $tokens );

    for ( $index = 0; $index < $token_count; $index++ ) {
        $token = $tokens[ $index ];
        if ( ! is_array( $token ) ) continue;

        if ( T_NAMESPACE === $token[0] ) {
            $namespace = '';
            for ( $cursor = $index + 1; $cursor < $token_count; $cursor++ ) {
                $part = $tokens[ $cursor ];
                if ( is_string( $part ) && ( ';' === $part || '{' === $part ) ) {
                    break;
                }
                if (
                    is_array( $part )
                    && in_array( $part[0], [ T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR ], true )
                ) {
                    $namespace .= $part[1];
                }
            }
            continue;
        }

        if ( T_CLASS !== $token[0] ) continue;

        // A named declaration has a T_STRING immediately after optional
        // whitespace/comments. This excludes both `new class {}` and
        // class-name constants such as `SomeClass::class`.
        for ( $cursor = $index + 1; $cursor < $token_count; $cursor++ ) {
            $candidate = $tokens[ $cursor ];
            if (
                is_array( $candidate )
                && in_array( $candidate[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true )
            ) {
                continue;
            }
            if ( is_array( $candidate ) && T_STRING === $candidate[0] ) {
                if ( '' !== $namespace ) {
                    $summary = '';
                    for ( $back = $index - 1; $back >= 0; $back-- ) {
                        $preceding = $tokens[ $back ];
                        if ( is_array( $preceding ) && T_DOC_COMMENT === $preceding[0] ) {
                            $summary = extract_docblock_summary( $preceding[1] );
                            break;
                        }
                        if (
                            is_array( $preceding )
                            && in_array(
                                $preceding[0],
                                [ T_WHITESPACE, T_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY ],
                                true
                            )
                        ) {
                            continue;
                        }
                        break;
                    }

                    $declarations[] = [
                        'namespace' => $namespace,
                        'class'     => $candidate[1],
                        'summary'   => $summary,
                    ];
                }
            }
            break;
        }
    }

    return $declarations;
}

/**
 * Extract shortcode attributes from ShortcodeHandler.php.
 * Parses the first shortcode_atts() argument with PHP tokens so both long
 * `array()` syntax and short `[]` syntax survive automated formatting.
 * Returns array of {name, default}.
 */
function extract_shortcode_atts(): array {
    $file = CYBERMAPS_PLUGIN_DIR . 'src/Sitemap/ShortcodeHandler.php';
    if ( ! file_exists( $file ) ) return [];
    $content = file_get_contents( $file );
    if ( ! $content ) return [];

    $tokens = token_get_all( $content );
    $token_count = count( $tokens );
    for ( $index = 0; $index < $token_count; ++$index ) {
        $token = $tokens[ $index ];
        if ( ! is_array( $token ) || T_STRING !== $token[0] || 'shortcode_atts' !== strtolower( $token[1] ) ) {
            continue;
        }

        $call_open = next_significant_php_token( $tokens, $index + 1 );
        if ( null === $call_open || '(' !== $tokens[ $call_open ] ) {
            continue;
        }

        $array_start = next_significant_php_token( $tokens, $call_open + 1 );
        if ( null === $array_start ) {
            continue;
        }

        $array_token = $tokens[ $array_start ];
        if ( is_array( $array_token ) && T_ARRAY === $array_token[0] ) {
            $array_start = next_significant_php_token( $tokens, $array_start + 1 );
            if ( null === $array_start || '(' !== $tokens[ $array_start ] ) {
                continue;
            }
        } elseif ( '[' !== $array_token ) {
            continue;
        }

        $array_tokens = balanced_php_token_contents( $tokens, $array_start );
        if ( null === $array_tokens ) {
            continue;
        }

        return parse_shortcode_attribute_tokens( $array_tokens );
    }

    return [];
}

/**
 * Find the next PHP token that is not whitespace or a comment.
 */
function next_significant_php_token( array $tokens, int $offset ): ?int {
    $token_count = count( $tokens );
    for ( $index = $offset; $index < $token_count; ++$index ) {
        $token = $tokens[ $index ];
        if (
            is_array( $token )
            && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true )
        ) {
            continue;
        }

        return $index;
    }

    return null;
}

/**
 * Return tokens inside one balanced (), [], or {} expression.
 */
function balanced_php_token_contents( array $tokens, int $open_index ): ?array {
    $opening = $tokens[ $open_index ] ?? null;
    $pairs = [ '(' => ')', '[' => ']', '{' => '}' ];
    if ( ! is_string( $opening ) || ! isset( $pairs[ $opening ] ) ) {
        return null;
    }

    $stack = [ $opening ];
    $contents = [];
    $token_count = count( $tokens );
    for ( $index = $open_index + 1; $index < $token_count; ++$index ) {
        $token = $tokens[ $index ];
        if ( is_string( $token ) && isset( $pairs[ $token ] ) ) {
            $stack[] = $token;
            $contents[] = $token;
            continue;
        }
        if ( is_string( $token ) && in_array( $token, array_values( $pairs ), true ) ) {
            $expected = $pairs[ end( $stack ) ];
            if ( $token !== $expected ) {
                return null;
            }
            array_pop( $stack );
            if ( [] === $stack ) {
                return $contents;
            }
            $contents[] = $token;
            continue;
        }

        $contents[] = $token;
    }

    return null;
}

/**
 * Parse top-level scalar key/default pairs from a tokenized PHP array.
 */
function parse_shortcode_attribute_tokens( array $tokens ): array {
    $entries = split_top_level_php_tokens( $tokens, ',' );
    $attributes = [];
    foreach ( $entries as $entry ) {
        $parts = split_top_level_php_tokens( $entry, T_DOUBLE_ARROW, 2 );
        if ( 2 !== count( $parts ) ) {
            continue;
        }

        $name = parse_php_literal( trim( php_tokens_to_source( $parts[0] ) ) );
        if ( ! is_string( $name ) || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
            continue;
        }

        $attributes[] = [
            'name'    => $name,
            'default' => parse_php_literal( trim( php_tokens_to_source( $parts[1] ) ) ),
        ];
    }

    return $attributes;
}

/**
 * Split a token list at a delimiter encountered outside nested expressions.
 */
function split_top_level_php_tokens( array $tokens, string|int $delimiter, int $limit = PHP_INT_MAX ): array {
    $pairs = [ '(' => ')', '[' => ']', '{' => '}' ];
    $stack = [];
    $parts = [];
    $current = [];

    foreach ( $tokens as $token ) {
        $is_delimiter = is_int( $delimiter )
            ? is_array( $token ) && $delimiter === $token[0]
            : $delimiter === $token;
        if ( $is_delimiter && [] === $stack && count( $parts ) + 1 < $limit ) {
            $parts[] = $current;
            $current = [];
            continue;
        }

        if ( is_string( $token ) && isset( $pairs[ $token ] ) ) {
            $stack[] = $token;
        } elseif ( is_string( $token ) && in_array( $token, array_values( $pairs ), true ) ) {
            if ( [] === $stack || $pairs[ end( $stack ) ] !== $token ) {
                return [];
            }
            array_pop( $stack );
        }

        $current[] = $token;
    }

    if ( [] !== $current || [] !== $parts ) {
        $parts[] = $current;
    }

    return $parts;
}

/**
 * Reassemble significant token text for scalar literal parsing.
 */
function php_tokens_to_source( array $tokens ): string {
    $source = '';
    foreach ( $tokens as $token ) {
        if (
            is_array( $token )
            && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true )
        ) {
            continue;
        }
        $source .= is_array( $token ) ? $token[1] : $token;
    }

    return $source;
}

/**
 * Convert a scalar PHP source literal into the value represented in JSON.
 */
function parse_php_literal( string $literal ): mixed {
    if ( preg_match( "/^'(.*)'$/s", $literal, $match ) ) {
        return str_replace( [ "\\\\", "\\'" ], [ "\\", "'" ], $match[1] );
    }
    if ( preg_match( '/^"(.*)"$/s', $literal, $match ) ) {
        return stripcslashes( $match[1] );
    }
    if ( preg_match( '/^-?[0-9]+$/', $literal ) ) {
        return (int) $literal;
    }
    if ( preg_match( '/^-?(?:[0-9]+\.[0-9]*|[0-9]*\.[0-9]+)$/', $literal ) ) {
        return (float) $literal;
    }

    return match ( strtolower( $literal ) ) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $literal,
    };
}

/**
 * Extract REST API routes from the canonical EndpointRegistry.
 * Returns array of {namespace, route, full}.
 */
function extract_rest_routes(): array {
    $file = CYBERMAPS_PLUGIN_DIR . 'src/Core/EndpointRegistry.php';
    if ( ! file_exists( $file ) ) return [];

    require_once $file;

    $routes = [];
    foreach ( \Cybermaps\Core\EndpointRegistry::get_instance()->all() as $endpoint ) {
        if ( 'rest' !== ( $endpoint['kind'] ?? '' ) ) continue;

        $namespace = (string) ( $endpoint['namespace'] ?? '' );
        $route     = (string) ( $endpoint['route'] ?? '' );
        if ( '' === $namespace || '' === $route ) continue;

        $routes[] = [
            'namespace' => $namespace,
            'route'     => $route,
            'full'      => '/wp-json/' . $namespace . $route,
        ];
    }

    return $routes;
}

/**
 * Extract WP-CLI commands from Command.php.
 * Finds all public methods (excluding __construct).
 * Returns array of {name, full}.
 */
function extract_cli_commands(): array {
    $file = CYBERMAPS_PLUGIN_DIR . 'src/CLI/Command.php';
    if ( ! file_exists( $file ) ) return [];
    $content = file_get_contents( $file );
    if ( ! $content ) return [];
    $commands = [];
    preg_match_all( "/public function (\w+)\s*\(/", $content, $m );
    foreach ( $m[1] as $cmd ) {
        if ( '__construct' === $cmd ) continue;
        $commands[] = [ 'name' => $cmd, 'full' => 'wp cybermaps ' . $cmd ];
    }
    return $commands;
}

/**
 * Extract fixed discovery publications from the canonical endpoint registry.
 *
 * Canonical and alternate paths are both emitted so documentation, routing,
 * analytics, status checks, and static publication cannot silently drift.
 *
 * Returns endpoint metadata with delivery, maturity, adoption, group, and
 * optional enablement setting kept as independent dimensions.
 */
function extract_discovery_endpoints(): array {
    $registry_file = CYBERMAPS_PLUGIN_DIR . 'src/Core/EndpointRegistry.php';
    if ( ! file_exists( $registry_file ) ) return [];
    require_once $registry_file;

    $endpoints = [];
    $registry  = \Cybermaps\Core\EndpointRegistry::get_instance();

    foreach ( $registry->get_path_publications() as $id => $publication ) {
        $canonical_path = (string) ( $publication['path'] ?? '' );
        $paths = array_merge(
            [ $canonical_path ],
            (array) ( $publication['aliases'] ?? [] )
        );

        foreach ( $paths as $path ) {
            if ( ! is_string( $path ) || '' === $path ) continue;
            $canonical = $path === $canonical_path;
            $static_bucket = '';
            foreach ( (array) ( $publication['static_targets'] ?? [] ) as $target ) {
                if ( $path === (string) ( $target['path'] ?? '' ) ) {
                    $static_bucket = (string) ( $target['bucket'] ?? '' );
                    break;
                }
            }

            $endpoints[] = discovery_manifest_row(
                (string) $id,
                $path,
                $canonical,
                false,
                $publication,
                $static_bucket
            );
        }
    }

    foreach ( [ 'rest_root', 'rest_llms_tldr', 'rest_search' ] as $id ) {
        $publication = $registry->get( $id );
        if ( null === $publication || 'rest' !== ( $publication['kind'] ?? '' ) ) continue;

        $route = $registry->get_rest_route( $id );
        if ( null === $route ) continue;

        $publication['type']        = (string) ( $publication['type'] ?? 'application/json' );
        $publication['spec']        = (string) ( $publication['spec'] ?? 'WordPress REST API' );
        $endpoints[] = discovery_manifest_row(
            (string) $id,
            '/wp-json/' . $route['namespace'] . $route['route'],
            true,
            false,
            $publication
        );
    }

    return array_merge( $endpoints, extract_parameterized_discovery_endpoints() );
}

/**
 * Normalize one fixed, REST, or parameterized publication manifest row.
 *
 * @param array<string, mixed> $publication Endpoint metadata.
 * @return array<string, mixed>
 */
function discovery_manifest_row(
    string $id,
    string $path,
    bool $canonical,
    bool $parameterized,
    array $publication,
    string $static_bucket = ''
): array {
    $label = (string) ( $publication['label'] ?? $id );
    if ( ! $canonical ) {
        $label .= ' (alternate)';
    }

    return [
        'id'              => $id,
        'path'            => $path,
        'canonical'       => $canonical,
        'parameterized'   => $parameterized,
        'type'            => (string) ( $publication['type'] ?? 'application/octet-stream' ),
        'label'           => $label,
        'desc'            => (string) ( $publication['description'] ?? '' ),
        'spec'            => (string) ( $publication['spec'] ?? '' ),
        'delivery'        => (string) ( $publication['delivery'] ?? ( $parameterized ? 'parameterized-path' : 'fixed-path' ) ),
        'maturity'        => (string) ( $publication['maturity'] ?? 'vendor-extension' ),
        'adoption'        => (string) ( $publication['adoption'] ?? 'reference-only' ),
        'group'           => (string) ( $publication['group'] ?? 'essential' ),
        'enabled_setting' => (string) ( $publication['enabled_setting'] ?? '' ),
        'advertised'      => ! empty( $publication['advertise'] ),
        'throttle_tier'   => (string) ( $publication['throttle_tier'] ?? 'cheap' ),
        'static_bucket'   => $static_bucket,
    ];
}

/**
 * Describe public parameterized routes that intentionally cannot live in the
 * literal EndpointRegistry.
 *
 * @return array<int, array<string, mixed>>
 */
function extract_parameterized_discovery_endpoints(): array {
    $definitions = [
		'localized_llms' => [
			'path'            => '/{language}/llms.txt',
			'type'            => 'text/markdown',
            'label'           => 'Localized LLMS Summary',
            'description'     => 'Language-specific llms.txt publication.',
            'spec'            => 'llms.txt',
            'maturity'        => 'community-convention',
            'adoption'        => 'independent-producers',
            'enabled_setting' => 'enable_multilingual_hub',
            'throttle_tier'   => 'medium',
            'static_bucket'   => 'all',
        ],
		'localized_llms_full' => [
			'path'            => '/{language}/llms-full.txt',
			'type'            => 'text/markdown',
            'label'           => 'Localized LLMS Full',
            'description'     => 'Language-specific complete literal publication of eligible stored content.',
            'spec'            => 'Cybermaps Literal Full Corpus 1.0',
            'enabled_setting' => 'enable_llms_full',
            'throttle_tier'   => 'medium',
            'static_bucket'   => 'all',
        ],
        'localized_llms_tldr' => [
            'path'            => '/{language}/llms-tldr.txt',
            'type'            => 'text/plain',
            'label'           => 'Localized Budgeted Site Briefing',
            'description'     => 'Language-specific experimental literal briefing.',
            'spec'            => 'Cybermaps Budgeted Site Briefing 0.2-draft',
            'maturity'        => 'experimental-proposal',
            'group'           => 'experimental',
            'enabled_setting' => 'enable_llms_tldr',
            'throttle_tier'   => 'expensive',
            'static_bucket'   => 'all',
        ],
		'rag_chunk' => [
            'path'            => '/discovery/chunks/{post_id}.json',
            'type'            => 'application/json',
            'label'           => 'RAG Chunk',
            'description'     => 'Per-resource chunk publication for eligible stored content.',
            'spec'            => 'Cybermaps RAG chunk',
            'enabled_setting' => 'enable_rag_chunks',
            'throttle_tier'   => 'expensive',
            'static_bucket'   => 'all',
			'advertise'       => true,
		],
		'markdown_alternate' => [
			'path'            => '/{permalink}/index.md',
			'type'            => 'text/markdown',
			'label'           => 'Per-resource Markdown Alternate',
			'description'     => 'Literal Markdown representation of an eligible singular WordPress resource.',
			'spec'            => 'llms.txt page-alternate proposal',
			'maturity'        => 'community-convention',
			'adoption'        => 'independent-producers',
			'throttle_tier'   => 'medium',
			'static_bucket'   => '',
		],
    ];

    $endpoints = [];
    foreach ( $definitions as $id => $definition ) {
        $static_bucket = (string) ( $definition['static_bucket'] ?? '' );
        unset( $definition['static_bucket'] );
        $definition['delivery'] = 'parameterized-path';
        $endpoints[] = discovery_manifest_row(
            $id,
            (string) $definition['path'],
            true,
            true,
            $definition,
            $static_bucket
        );
    }

    return $endpoints;
}

/**
 * Extract registry-owned static targets plus variable sitemap/chunk output.
 * Returns array of filename strings.
 */
function extract_static_files(): array {
    $files = array_column(
        \Cybermaps\Core\EndpointRegistry::get_instance()->get_static_targets( 'all', [], true ),
        'filename'
    );

    // Variable inventories that cannot be represented by a literal call.
    $files = array_merge(
        [
            '{base}.xml',
            '{index_child}.xml',
            '{news_base}.xml',
            '{rss_base}.xml',
            '{language}/llms.txt',
            '{language}/llms-full.txt',
            '{language}/llms-tldr.txt',
            'discovery/chunks/{post_id}.json',
        ],
        $files
    );

    return array_values( array_unique( $files ) );
}

/**
 * Extract Static File Engine modes from the sanitizer and the default from
 * StaticBridge::get_mode(). Registry target counts remain derived data.
 */
function extract_static_engine_modes(): array {
    $mode_values = extract_static_mode_values();
    $bridge_file = CYBERMAPS_PLUGIN_DIR . 'src/Discovery/StaticBridge.php';
    if ( file_exists( $bridge_file ) ) {
        require_once $bridge_file;
    }

    $default = class_exists( \Cybermaps\Discovery\StaticBridge::class )
        ? \Cybermaps\Discovery\StaticBridge::get_mode( [] )
        : 'well_known';
    $registry = \Cybermaps\Core\EndpointRegistry::get_instance();
    $target_counts = [
        'off'        => 0,
        'well_known' => count( $registry->get_static_targets( 'well_known', [], true ) ),
        'all'        => count( $registry->get_static_targets( 'all', [], true ) ),
    ];
    $modes = [];
    foreach ( $mode_values as $mode ) {
        $label = match ( $mode ) {
            'off' => 'Publishes no files; supported requests use WordPress when the server routes them to PHP.',
            'well_known' => sprintf(
				'Publishes the %d registered core compatibility files; all other supported routes remain dynamic. (Default)',
                $target_counts['well_known']
            ),
            'all' => 'Publishes the eligible sitemap, protocol-safe discovery, localized, and RAG inventory; protocol/MIME-sensitive routes and robots.txt remain dynamic.',
            default => 'Static publication mode.',
        };
        $modes[] = [
            'mode'  => $mode,
            'label' => $label,
        ];
    }

    return [
        'default'       => $default,
        'modes'         => $modes,
        'target_counts' => $target_counts,
        'multisite'     => 'Dynamic delivery only; physical root files are disabled because sites share a web root.',
    ];
}

/**
 * Concatenate sanitizer sources used for settings-key and static-mode extraction.
 */
function extract_sanitizer_source_content(): string {
    $dir = CYBERMAPS_PLUGIN_DIR . 'src/Admin/Settings/Sanitizers/';
    $files = [
        $dir . 'SettingsSanitizer.php',
        $dir . 'SettingsSitemapNormalizer.php',
        $dir . 'SettingsDiscoveryNormalizer.php',
        $dir . 'SettingsAnalyticsNormalizer.php',
        $dir . 'SettingsAdvancedNormalizer.php',
    ];
    $content = '';
    foreach ( $files as $file ) {
        if ( ! file_exists( $file ) ) {
            continue;
        }
        $chunk = file_get_contents( $file );
        if ( is_string( $chunk ) && '' !== $chunk ) {
            $content .= $chunk . "\n";
        }
    }

    return $content;
}

/**
 * Read the sanitizer's accepted Static File Engine mode values.
 *
 * @return string[]
 */
function extract_static_mode_values(): array {
	$bridge_file = CYBERMAPS_PLUGIN_DIR . 'src/Discovery/StaticBridge.php';
	if ( file_exists( $bridge_file ) ) {
		require_once $bridge_file;
	}

	if (
		class_exists( \Cybermaps\Discovery\StaticBridge::class )
		&& method_exists( \Cybermaps\Discovery\StaticBridge::class, 'get_supported_modes' )
	) {
		return \Cybermaps\Discovery\StaticBridge::get_supported_modes();
	}

	$content = extract_sanitizer_source_content();
    if ( '' === $content ) {
        return [];
    }

    if (
        ! preg_match(
            '/\$valid_static_modes\s*=\s*array\s*\((.*?)\)\s*;/s',
            $content,
            $match
        )
    ) {
        return [];
    }

    preg_match_all( "/'([^']+)'/", $match[1], $values );
    return array_values( array_unique( $values[1] ?? [] ) );
}

/**
 * Extract the canonical settings keys from SettingsSanitizer.
 * Scans every $sanitized['key'] assignment so the manifest always reflects the
 * authoritative option schema without manual maintenance.
 * Returns a sorted array of setting key strings stored in `cybermaps_settings`.
 */
function extract_settings(): array {
	$registry_file = CYBERMAPS_PLUGIN_DIR . 'src/Admin/AIConfigurationRegistry.php';
	if ( file_exists( $registry_file ) ) {
		require_once $registry_file;
	}

	if ( class_exists( \Cybermaps\Admin\AIConfigurationRegistry::class ) ) {
		$keys = array_fill_keys( \Cybermaps\Admin\AIConfigurationRegistry::FORBIDDEN_FIELDS, true );
		foreach ( \Cybermaps\Admin\AIConfigurationRegistry::get_fields() as $key => $field ) {
			if ( 'cybermaps_settings' === ( $field['option'] ?? '' ) ) {
				$keys[ (string) $key ] = true;
			}
		}

		$keys = array_keys( $keys );
		sort( $keys );
		return $keys;
	}

	$content = extract_sanitizer_source_content();
    if ( '' === $content ) {
        return [];
    }

    $keys = [];
    preg_match_all( "/\\\$sanitized\[\s*'([^']+)'\s*\]\s*=/", $content, $m );
    foreach ( $m[1] as $k ) {
        $keys[ $k ] = true;
    }
    foreach ( extract_dynamic_sanitized_keys( $content ) as $k ) {
        $keys[ $k ] = true;
    }
    $keys = array_keys( $keys );
    sort( $keys );
    return $keys;
}

/**
 * Extract literal schema keys from foreach loops that assign
 * `$sanitized[$key]`. This covers bounded policy maps without treating retired
 * unset keys or nested payload fields as top-level settings.
 *
 * @return string[]
 */
function extract_dynamic_sanitized_keys( string $content ): array {
    $tokens      = token_get_all( $content );
    $token_count = count( $tokens );
    $keys        = [];

    for ( $index = 0; $index < $token_count; $index++ ) {
        $token = $tokens[ $index ];
        if ( ! is_array( $token ) || T_FOREACH !== $token[0] ) continue;

        $open = next_token_text_index( $tokens, $index + 1, '(' );
        if ( null === $open ) continue;

        $depth = 0;
        $close = null;
        $as_index = null;
        for ( $cursor = $open; $cursor < $token_count; $cursor++ ) {
            $part = $tokens[ $cursor ];
            $text = token_text( $part );
            if ( '(' === $text ) {
                ++$depth;
            } elseif ( ')' === $text ) {
                --$depth;
                if ( 0 === $depth ) {
                    $close = $cursor;
                    break;
                }
            } elseif ( 1 === $depth && is_array( $part ) && T_AS === $part[0] ) {
                $as_index = $cursor;
            }
        }
        if ( null === $close || null === $as_index ) continue;

        $key_variable = null;
        for ( $cursor = $as_index + 1; $cursor < $close; $cursor++ ) {
            $part = $tokens[ $cursor ];
            if ( is_array( $part ) && T_VARIABLE === $part[0] ) {
                $next = next_significant_token_index( $tokens, $cursor + 1, $close );
                if (
                    null !== $next
                    && is_array( $tokens[ $next ] )
                    && T_DOUBLE_ARROW === $tokens[ $next ][0]
                ) {
                    $key_variable = $part[1];
                }
                break;
            }
        }
        if ( null === $key_variable ) continue;

        $body_open = next_token_text_index( $tokens, $close + 1, '{' );
        if ( null === $body_open ) continue;
        $body_depth = 0;
        $body_close = null;
        for ( $cursor = $body_open; $cursor < $token_count; $cursor++ ) {
            $text = token_text( $tokens[ $cursor ] );
            if ( '{' === $text ) {
                ++$body_depth;
            } elseif ( '}' === $text ) {
                --$body_depth;
                if ( 0 === $body_depth ) {
                    $body_close = $cursor;
                    break;
                }
            }
        }
        if ( null === $body_close ) continue;

        $body = '';
        for ( $cursor = $body_open; $cursor <= $body_close; $cursor++ ) {
            $body .= token_text( $tokens[ $cursor ] );
        }
        if (
            ! preg_match(
                '/\$sanitized\s*\[\s*' . preg_quote( $key_variable, '/' ) . '\s*\]\s*=/',
                $body
            )
        ) {
            continue;
        }

        for ( $cursor = $open + 1; $cursor < $as_index; $cursor++ ) {
            $part = $tokens[ $cursor ];
            if ( ! is_array( $part ) || T_CONSTANT_ENCAPSED_STRING !== $part[0] ) continue;
            $next = next_significant_token_index( $tokens, $cursor + 1, $as_index );
            if (
                null === $next
                || ! is_array( $tokens[ $next ] )
                || T_DOUBLE_ARROW !== $tokens[ $next ][0]
            ) {
                continue;
            }

            $value = parse_php_literal( $part[1] );
            if ( is_string( $value ) && '' !== $value ) {
                $keys[ $value ] = true;
            }
        }
    }

    return array_keys( $keys );
}

/**
 * Get a token's literal source text.
 */
function token_text( array|string $token ): string {
    return is_array( $token ) ? $token[1] : $token;
}

/**
 * Find the next non-whitespace/comment token before a boundary.
 *
 * @param array<int, array|string> $tokens
 */
function next_significant_token_index( array $tokens, int $start, int $limit ): ?int {
    for ( $index = $start; $index < $limit; $index++ ) {
        $token = $tokens[ $index ];
        if (
            is_array( $token )
            && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true )
        ) {
            continue;
        }
        return $index;
    }

    return null;
}

/**
 * Find the next literal punctuation token.
 *
 * @param array<int, array|string> $tokens
 */
function next_token_text_index( array $tokens, int $start, string $expected ): ?int {
    $token_count = count( $tokens );
    for ( $index = $start; $index < $token_count; $index++ ) {
        if ( $expected === token_text( $tokens[ $index ] ) ) {
            return $index;
        }
        $token = $tokens[ $index ];
        if (
            is_array( $token )
            && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true )
        ) {
            continue;
        }
        break;
    }

    return null;
}

/**
 * Describe every registered Core configuration root.
 *
 * The general settings keys remain source-derived. Structured roots use small,
 * stable top-level schemas; nested rows are governed by their dedicated
 * sanitizers.
 *
 * @param string[] $settings General cybermaps_settings keys.
 * @return array<int,array<string,mixed>>
 */
function extract_configuration_options( array $settings ): array {
    return [
        [
            'option'           => 'cybermaps_settings',
            'storage_type'     => 'array',
            'sanitizer'        => 'Cybermaps\\Admin\\Settings\\Sanitizers\\SettingsSanitizer::sanitize',
            'top_level_fields' => $settings,
            'owning_workspace' => 'General settings across XML Sitemaps, HTML Sitemap, AI Publishing, Reports, and Advanced',
        ],
        [
            'option'           => 'cybermaps_discovery_center',
            'storage_type'     => 'json_string',
            'sanitizer'        => 'Cybermaps\\Admin\\Settings\\Sanitizers\\DiscoveryCenterSanitizer::sanitize',
            'top_level_fields' => [ 'archetype', 'overrides', 'type_intents', 'disabled' ],
            'owning_workspace' => 'XML Sitemaps — Content Discovery Strategy',
        ],
        [
            'option'           => 'cybermaps_robots_manager',
            'storage_type'     => 'array',
            'sanitizer'        => 'Cybermaps\\Admin\\Settings\\Sanitizers\\RobotsManagerSanitizer::sanitize',
            'top_level_fields' => [ 'takeover_enabled', 'overrides', 'manual_directives', 'content_signals' ],
            'owning_workspace' => 'AI Publishing — Crawler Policy',
        ],
        [
            'option'           => 'cybermaps_identity_data',
            'storage_type'     => 'array',
            'sanitizer'        => 'Cybermaps\\Admin\\IdentityHub::sanitize_identity_data',
            'top_level_fields' => [
                'type',
                'precise_type',
                'name',
                'description',
                'image_id',
                'address_country',
                'address',
                'city',
                'address_region',
                'postal_code',
                'phone',
                'email',
                'latitude',
                'longitude',
                'hours',
                'catalogs',
                'social_profiles',
                'contact_points',
            ],
            'owning_workspace' => 'Schema — Site Identity',
        ],
    ];
}

/**
 * Extract the local, reviewed standards registry. No network calls are made.
 *
 * @return array<int, array<string, mixed>>
 */
function extract_standards_registry(): array {
	$registry = new \Cybermaps\Core\StandardsRegistry();
	return $registry->all();
}

// ============================================================================
// BUILD MANIFEST — assemble all extraction results into the final JSON object.
// ============================================================================

$all_classes = find_classes( CYBERMAPS_PLUGIN_DIR . 'src' );
$discovery_endpoints = extract_discovery_endpoints();
$shortcode_attributes = extract_shortcode_atts();
$rest_api_routes = extract_rest_routes();
$wp_cli_commands = extract_cli_commands();
$static_files = extract_static_files();
$settings = extract_settings();
$configuration_options = extract_configuration_options( $settings );
$standards_registry = extract_standards_registry();
$ai_configuration_catalog = \Cybermaps\Admin\AIConfigurationRegistry::get_catalog();
$ai_configuration_schema  = \Cybermaps\Admin\AIConfigurationRegistry::get_json_schema();

$manifest = [
    'plugin'        => $_cybermaps_headers['plugin'],
    'version'       => CYBERMAPS_VERSION,
    'php_min'       => $_cybermaps_headers['php_min'],
    'wp_min'        => $_cybermaps_headers['wp_min'],
    'generated_at'  => gmdate( 'c' ),

    'discovery_endpoints'      => $discovery_endpoints,
    'discovery_endpoint_count' => count( $discovery_endpoints ),

    'sitemap_features' => [
        'XML sitemaps with configurable base URL slug',
        'Automatic 2,000 URL children with collision-safe post-type and taxonomy route namespaces',
        'Post types, taxonomies, authors, archives, and Google News sitemaps',
        'RSS 2.0 sitemap with configurable post types and item limit',
        'Image and video sitemap extensions with configurable media discovery intensity',
        'Optional transient caching and ETag-based 304 Not Modified responses',
        'XSL stylesheet for a human-readable browser preview',
        'Content Discovery Strategy with practical profile baselines, independent Publish status, Informational/Commercial discovery intent, publication weight, and manual per-resource overrides',
        'Per-post changefreq override (always, hourly, daily, weekly, monthly, yearly, never)',
        'Per-post exclude from sitemap toggle (Gutenberg sidebar)',
        'Shared publication eligibility honoring WordPress visibility, Genesis/Mai, and supported Yoast, Rank Math, and AIOSEO signals',
        'HTML sitemap via [cybermap] shortcode with interactive builder (list, columns, bare layouts)',
        'Sitemap URL listing page with coverage statistics',
        'External sitemap URL inclusion in sitemap index',
    ],

    'shortcode_attributes'      => $shortcode_attributes,
    'shortcode_attribute_count' => count( $shortcode_attributes ),

    'rest_api_routes'     => $rest_api_routes,
    'rest_api_route_count' => count( $rest_api_routes ),
    'wp_cli_commands'     => $wp_cli_commands,
    'wp_cli_command_count' => count( $wp_cli_commands ),

    'static_files'      => $static_files,
    'static_file_count' => count( $static_files ),
    'static_engine'     => extract_static_engine_modes(),

    'settings'       => $settings,
    'settings_count' => count( $settings ),
    'configuration_options'      => $configuration_options,
    'configuration_option_count' => count( $configuration_options ),
	'standards'       => $standards_registry,
	'standards_count' => count( $standards_registry ),
	'ai_configuration' => [
		'format'         => \Cybermaps\Admin\AIConfigurationRegistry::CHANGES_FORMAT,
		'format_version' => \Cybermaps\Admin\AIConfigurationRegistry::FORMAT_VERSION,
		'section_count'  => count( $ai_configuration_catalog['sections'] ),
		'field_count'    => count( $ai_configuration_catalog['fields'] ),
		'forbidden_fields' => \Cybermaps\Admin\AIConfigurationRegistry::FORBIDDEN_FIELDS,
		'catalog_hash'   => \Cybermaps\Admin\AIConfigurationRegistry::get_catalog_hash(),
		'schema_hash'    => \Cybermaps\Admin\AIConfigurationRegistry::get_schema_hash(),
		'guide_path'     => 'docs/ai-configuration.md',
		'guide_url'      => 'https://cybermaps.dev/docs/ai-configuration/',
		'schema_url'     => 'https://cybermaps.dev/specs/ai-configuration/' . rawurlencode( CYBERMAPS_VERSION ) . '/schema.json',
		'catalog_url'    => 'https://cybermaps.dev/specs/ai-configuration/' . rawurlencode( CYBERMAPS_VERSION ) . '/catalog.json',
		'compatibility'  => [
			'format_version' => 'exact',
			'plugin_version' => 'warning_on_mismatch',
			'runtime_authority' => 'installed_plugin_registry_and_sanitizers',
		],
		'hash_scope'     => 'sha256_of_canonical_registry_json',
		'artifacts'      => [
			'docs/dev/ai-configuration/catalog.json',
			'docs/dev/ai-configuration/schema.json',
		],
	],

    'source_classes'       => $all_classes,
    'class_count'          => count( $all_classes ),
];

// ============================================================================
// OUTPUT — JSON to stdout. AI agents consuming this should parse with:
//   php docs/dev/generate-docs.php | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['version'])"
// ============================================================================

$output_mode = $argv[1] ?? '--manifest';
$output = match ( $output_mode ) {
	'--manifest'    => $manifest,
	'--ai-schema'   => $ai_configuration_schema,
	'--ai-catalog'  => $ai_configuration_catalog,
	default         => null,
};
if ( null === $output ) {
	fwrite( STDERR, "Unknown output mode. Use --manifest, --ai-schema, or --ai-catalog.\n" );
	exit( 2 );
}

echo json_encode(
	$output,
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
) . "\n";
