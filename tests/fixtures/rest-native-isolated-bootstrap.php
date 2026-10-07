<?php
/** Load native REST serving code with isolated site dependencies; no DB or network. */
declare(strict_types=1);
$native = rtrim( (string) getenv( 'CYBERMAPS_NATIVE_WP_ROOT' ), '/' );
if ( ! is_file( $native . '/wp-includes/rest-api/class-wp-rest-server.php' ) ) { throw new RuntimeException( 'Set CYBERMAPS_NATIVE_WP_ROOT to a local WordPress source tree.' ); }
define( 'ABSPATH', $native . '/' );
define( 'WPINC', 'wp-includes' );
define( 'CYBERMAPS_VERSION', '8.0.2' );
foreach ( array( 'plugin.php', 'class-wp-error.php', 'class-wp-http-response.php', 'rest-api/class-wp-rest-response.php', 'rest-api/class-wp-rest-request.php', 'rest-api/class-wp-rest-server.php', 'rest-api.php' ) as $file ) { require $native . '/wp-includes/' . $file; }
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
function __( $text, $domain = '' ) { return $text; }
function get_option( $name, $default = false ) { return array( 'blog_charset' => 'UTF-8', 'permalink_structure' => '/%postname%/', 'home' => 'https://example.com' )[ $name ] ?? $default; }
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function is_ssl() { return false; }
function is_user_logged_in() { return false; }
function home_url( $path = '' ) { return 'https://example.com/' . ltrim( $path, '/' ); }
function get_home_url( $blog = null, $path = '', $scheme = null ) { return home_url( $path ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function wp_json_encode( $value, $options = 0, $depth = 512 ) { return json_encode( $value, $options, $depth ); }
function sanitize_url( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_parse_list( $input ) { return is_array( $input ) ? $input : preg_split( '/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY ); }
function wp_is_numeric_array( $data ) { return is_array( $data ) && array_is_list( $data ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_check_jsonp_callback( $callback ) { return 'cybermapsFixture' === $callback; }
function _deprecated_hook( ...$args ) {}
function _doing_it_wrong( ...$args ) { throw new RuntimeException( 'Unexpected native REST misuse.' ); }
$GLOBALS['wp_rewrite'] = new class { public function using_index_permalinks() { return false; } };
function is_admin() { return false; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
