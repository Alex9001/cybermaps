<?php
/**
 * Mock WordPress for testing
 *
 * @package Cybermaps
 */

declare(strict_types=1);

// phpcs:ignoreFile

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CYBERMAPS_MOCK_ABSPATH', __DIR__ . '/' );
define( 'CYBERMAPS_MOCK_PLUGIN_DIR', __DIR__ . '/' );
define( 'CYBERMAPS_MOCK_DAY_IN_SECONDS', 86400 );
define( 'CYBERMAPS_MOCK_HOUR_IN_SECONDS', 3600 );

/**
 * Mock plugin_dir_path
 */
function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

/**
 * Mock get_option
 */
function get_option( $option, $default = false ) {
	global $cybermaps_mock_current_blog_id, $cybermaps_mock_options, $cybermaps_mock_options_by_blog;
	$observer = $GLOBALS['cybermaps_mock_get_option_observer'] ?? null;
	if ( is_callable( $observer ) ) {
		$observer( (string) $option );
	}
	foreach ( $GLOBALS['wp_hooks'] ?? array() as $registered ) {
		if (
			'filter' === ( $registered['type'] ?? '' )
			&& 'pre_option_' . $option === ( $registered['hook'] ?? '' )
			&& is_callable( $registered['callback'] ?? null )
		) {
			$pre = call_user_func( $registered['callback'], false );
			if ( false !== $pre ) {
				return $pre;
			}
		}
	}
	if (
		isset( $cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ] )
		&& array_key_exists( $option, $cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ] )
	) {
		return $cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ][ $option ];
	}

	return isset( $cybermaps_mock_options[ $option ] ) ? $cybermaps_mock_options[ $option ] : $default;
}

/**
 * Mock settings-error collection.
 */
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['cybermaps_mock_settings_errors'][] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

/**
 * Mock current_time
 */
function current_time( $type, $gmt = 0 ) {
	unset( $gmt );
	if ( 'mysql' === $type ) {
		return $GLOBALS['cybermaps_mock_current_time_mysql'] ?? '2026-07-26 12:00:00';
	}
	if ( 'timestamp' === $type || 'U' === $type ) {
		return strtotime( $GLOBALS['cybermaps_mock_current_time_mysql'] ?? '2026-07-26 12:00:00' );
	}
	return gmdate( (string) $type, strtotime( $GLOBALS['cybermaps_mock_current_time_mysql'] ?? '2026-07-26 12:00:00' ) );
}

/**
 * Mock update_option
 */
function update_option( $option, $value ) {
	global $cybermaps_mock_current_blog_id, $cybermaps_mock_options, $cybermaps_mock_options_by_blog;
	$behavior = $GLOBALS['cybermaps_mock_update_option_behavior'] ?? null;
	if ( is_callable( $behavior ) ) {
		$behavior( $option, $value, 'before' );
	}
	if ( isset( $cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ] ) ) {
		$cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ][ $option ] = $value;
		if ( is_callable( $behavior ) ) {
			$behavior( $option, $value, 'after' );
		}
		return true;
	}

	$cybermaps_mock_options[ $option ] = $value;
	if ( is_callable( $behavior ) ) {
		$behavior( $option, $value, 'after' );
	}
	return true;
}

/**
 * Mock add_option with WordPress's insert-only semantics.
 */
function add_option( $option, $value = '', $deprecated = '', $autoload = null ) {
	unset( $deprecated, $autoload );
	global $cybermaps_mock_current_blog_id, $cybermaps_mock_options, $cybermaps_mock_options_by_blog;
	$behavior = $GLOBALS['cybermaps_mock_add_option_behavior'] ?? null;
	if ( is_callable( $behavior ) ) {
		$behavior( $option, $value, 'before' );
	}

	if ( isset( $cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ] ) ) {
		if ( array_key_exists( $option, $cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ] ) ) {
			return false;
		}
		$cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ][ $option ] = $value;
		if ( is_callable( $behavior ) ) {
			$behavior( $option, $value, 'after' );
		}
		return true;
	}

	if ( array_key_exists( $option, $cybermaps_mock_options ) ) {
		return false;
	}
	$cybermaps_mock_options[ $option ] = $value;
	if ( is_callable( $behavior ) ) {
		$behavior( $option, $value, 'after' );
	}
	return true;
}

/**
 * Mock delete_option
 */
function delete_option( $option ) {
	global $cybermaps_mock_current_blog_id, $cybermaps_mock_options, $cybermaps_mock_options_by_blog;
	if ( isset( $cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ] ) ) {
		unset( $cybermaps_mock_options_by_blog[ $cybermaps_mock_current_blog_id ][ $option ] );
		return true;
	}

	unset( $cybermaps_mock_options[ $option ] );
	return true;
}

function wp_set_option_autoload_values( $options ) {
	$GLOBALS['cybermaps_mock_option_autoload_values'] = array_merge(
		$GLOBALS['cybermaps_mock_option_autoload_values'] ?? array(),
		(array) $options
	);
	return array_fill_keys( array_keys( (array) $options ), true );
}

/**
 * Mock external object-cache primitives used for atomic request limiting.
 */
function wp_using_ext_object_cache() {
	return ! empty( $GLOBALS['cybermaps_mock_using_ext_object_cache'] );
}

function cybermaps_mock_cache_key( $key, $group = '' ) {
	$blog_id = (int) ( $GLOBALS['cybermaps_mock_current_blog_id'] ?? 1 );
	return (string) $group . ':' . (string) $key . ':blog' . max( 1, $blog_id );
}

function wp_cache_add( $key, $value, $group = '', $expire = 0 ) {
	$cache_key = cybermaps_mock_cache_key( $key, $group );
	if (
		isset( $GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ] )
		&& $GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ] <= time()
	) {
		unset(
			$GLOBALS['cybermaps_mock_object_cache'][ $cache_key ],
			$GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ]
		);
	}
	if ( array_key_exists( $cache_key, $GLOBALS['cybermaps_mock_object_cache'] ?? array() ) ) {
		return false;
	}
	$GLOBALS['cybermaps_mock_object_cache'][ $cache_key ] = $value;
	if ( $expire > 0 ) {
		$GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ] = time() + (int) $expire;
	}
	return true;
}

function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
	$serialized = serialize( $value );
	$maximum    = (int) ( $GLOBALS['cybermaps_mock_object_cache_max_bytes'] ?? 0 );
	if ( $maximum > 0 && strlen( $serialized ) > $maximum ) {
		return false;
	}
	$observer = $GLOBALS['cybermaps_mock_wp_cache_set_observer'] ?? null;
	if ( is_callable( $observer ) && false === $observer( $key, $value, $group, $expire ) ) {
		return false;
	}
	$cache_key = cybermaps_mock_cache_key( $key, $group );
	$GLOBALS['cybermaps_mock_object_cache'][ $cache_key ] = $value;
	if ( $expire > 0 ) {
		$GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ] = time() + (int) $expire;
	} else {
		unset( $GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ] );
	}
	return true;
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	unset( $force );
	$observer = $GLOBALS['cybermaps_mock_wp_cache_get_observer'] ?? null;
	if ( is_callable( $observer ) ) {
		$observer( $key, $group );
	}
	$cache_key = cybermaps_mock_cache_key( $key, $group );
	if (
		isset( $GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ] )
		&& $GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ] <= time()
	) {
		unset(
			$GLOBALS['cybermaps_mock_object_cache'][ $cache_key ],
			$GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ]
		);
	}
	$found = array_key_exists( $cache_key, $GLOBALS['cybermaps_mock_object_cache'] ?? array() );
	return $found ? $GLOBALS['cybermaps_mock_object_cache'][ $cache_key ] : false;
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	$cache_key = cybermaps_mock_cache_key( $key, $group );
	if ( ! array_key_exists( $cache_key, $GLOBALS['cybermaps_mock_object_cache'] ?? array() ) ) {
		return false;
	}
	$GLOBALS['cybermaps_mock_object_cache'][ $cache_key ] = (int) $GLOBALS['cybermaps_mock_object_cache'][ $cache_key ] + (int) $offset;
	return $GLOBALS['cybermaps_mock_object_cache'][ $cache_key ];
}

function wp_cache_delete( $key, $group = '' ) {
	$cache_key = cybermaps_mock_cache_key( $key, $group );
	unset(
		$GLOBALS['cybermaps_mock_object_cache'][ $cache_key ],
		$GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ]
	);
	return true;
}

function wp_cache_supports( $feature ) {
	return in_array( (string) $feature, (array) ( $GLOBALS['cybermaps_mock_cache_capabilities'] ?? array() ), true );
}

if ( ! function_exists( 'apcu_enabled' ) ) {
	function apcu_enabled() {
		return ! empty( $GLOBALS['cybermaps_mock_apcu_enabled'] );
	}
}

if ( ! function_exists( 'apcu_store' ) ) {
	function apcu_store( $key, $value, $ttl = 0 ) {
		$GLOBALS['cybermaps_mock_apcu'][ (string) $key ] = $value;
		if ( $ttl > 0 ) {
			$GLOBALS['cybermaps_mock_apcu_expirations'][ (string) $key ] = time() + (int) $ttl;
		}
		return true;
	}
}

if ( ! function_exists( 'apcu_fetch' ) ) {
	function apcu_fetch( $key, &$success = null ) {
		$key = (string) $key;
		if (
			isset( $GLOBALS['cybermaps_mock_apcu_expirations'][ $key ] )
			&& $GLOBALS['cybermaps_mock_apcu_expirations'][ $key ] <= time()
		) {
			unset(
				$GLOBALS['cybermaps_mock_apcu'][ $key ],
				$GLOBALS['cybermaps_mock_apcu_expirations'][ $key ]
			);
		}
		$success = array_key_exists( $key, $GLOBALS['cybermaps_mock_apcu'] ?? array() );
		return $success ? $GLOBALS['cybermaps_mock_apcu'][ $key ] : false;
	}
}

/**
 * Start a fresh simulated WordPress request for cache-sensitive tests.
 */
function cybermaps_mock_reset_cache_runtime() {
	$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
	$GLOBALS['cybermaps_mock_using_ext_object_cache'] = false;
	$GLOBALS['cybermaps_mock_object_cache'] = array();
	$GLOBALS['cybermaps_mock_object_cache_expirations'] = array();
	$GLOBALS['cybermaps_mock_cache_capabilities'] = array();
	$GLOBALS['cybermaps_mock_apcu_enabled'] = false;
	$GLOBALS['cybermaps_mock_apcu'] = array();
	$GLOBALS['cybermaps_mock_apcu_expirations'] = array();
	$GLOBALS['cybermaps_mock_transient_expirations'] = array();
	unset(
		$GLOBALS['cybermaps_mock_options_by_blog'],
		$GLOBALS['cybermaps_mock_object_cache_max_bytes'],
		$GLOBALS['cybermaps_mock_wp_cache_set_observer'],
		$GLOBALS['cybermaps_mock_wp_cache_get_observer'],
		$GLOBALS['cybermaps_mock_get_transient_observer'],
		$GLOBALS['cybermaps_mock_set_transient_observer'],
		$GLOBALS['cybermaps_mock_add_option_behavior']
	);
	if ( class_exists( '\\Cybermaps\\Core\\CacheManager' ) ) {
		\Cybermaps\Core\CacheManager::reset_runtime();
	}
}

/**
 * Mock wp_json_encode
 */
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
    return json_encode( $data, $options, $depth );
}

/**
 * Mock maybe_unserialize.
 */
function maybe_unserialize( $data ) {
	if ( ! is_string( $data ) ) {
		return $data;
	}

	$serialized   = trim( $data );
	$unserialized = @unserialize( $serialized, array( 'allowed_classes' => false ) );
	return false === $unserialized && 'b:0;' !== $serialized
		? $data
		: $unserialized;
}

/**
 * Mock get_bloginfo
 */
function get_bloginfo( $show ) {
	if ( 'name' === $show ) {
		return 'Mock Site';
	}
	if ( 'language' === $show ) {
		return 'en-US';
	}
	if ( 'version' === $show ) {
		return $GLOBALS['cybermaps_mock_wp_version'] ?? '7.1';
	}
	return '';
}

function mysql2date( $format, $date, $translate = true ) {
	unset( $translate );
	$timestamp = strtotime( (string) $date . ' UTC' );
	return false === $timestamp ? false : gmdate( (string) $format, $timestamp );
}

/**
 * Mock get_permalink
 */
function get_permalink( $id ) {
	$id = is_object( $id ) ? (int) $id->ID : (int) $id;
	if (
		isset( $GLOBALS['cybermaps_mock_permalinks'] )
		&& array_key_exists( (int) $id, $GLOBALS['cybermaps_mock_permalinks'] )
	) {
		return $GLOBALS['cybermaps_mock_permalinks'][ (int) $id ];
	}

	return "https://example.com/?p=$id";
}

/**
 * Mock URL-to-post resolution for parameterized Markdown routes.
 */
function url_to_postid( $url ) {
	return (int) ( $GLOBALS['cybermaps_mock_url_to_postid'][ (string) $url ] ?? 0 );
}

/**
 * Mock home_url
 */
function home_url( $path = '' ) {
	global $cybermaps_mock_home_url;
	$base = isset( $cybermaps_mock_home_url ) ? rtrim( (string) $cybermaps_mock_home_url, '/' ) : 'https://example.com';
	return $base . $path;
}

/**
 * Mock untrailingslashit.
 */
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

/**
 * Mock esc_url
 */
function esc_url( $url ) {
	return str_replace( '&', '&#038;', (string) $url );
}

/**
 * Mock esc_html
 */
function esc_html( $text ) {
	return $text;
}

/**
 * Mock esc_attr
 */
function esc_attr( $text ) {
	return $text;
}

/**
 * Mock esc_textarea
 */
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Mock checked
 */
function checked( $checked, $current = true, $display = true ) {
	$result = (string) $checked === (string) $current ? 'checked="checked"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

/**
 * Mock selected
 */
function selected( $selected, $current = true, $display = true ) {
	$result = (string) $selected === (string) $current ? 'selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

/**
 * Mock wp_add_inline_script
 */
function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	$GLOBALS['cybermaps_mock_added_inline_scripts'][ $handle ][ $position ][] = $data;
	return true;
}

function esc_xml( $text ) {
	return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

function esc_html_e( $text, $domain = 'default' ) {
	unset( $domain );
	echo esc_html( $text );
}

function esc_html__( $text, $domain = 'default' ) {
	unset( $domain );
	return esc_html( $text );
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	unset( $domain );
	return 1 === (int) $number ? (string) $single : (string) $plural;
}

function esc_attr_e( $text, $domain = 'default' ) {
	unset( $domain );
	echo esc_attr( $text );
}

function esc_attr__( $text, $domain = 'default' ) {
	unset( $domain );
	return esc_attr( $text );
}

function wp_kses_post( $text ) {
	return (string) $text;
}

function wp_kses( $text, $allowed_html = array(), $allowed_protocols = array() ) {
	unset( $allowed_html, $allowed_protocols );
	return (string) $text;
}

/**
 * Mock the native informational toggletip helper available on WordPress 7.1+.
 */
function wp_get_toggletip( $content, $args = array() ) {
	static $sequence = 0;
	++$sequence;
	$id          = isset( $args['id'] ) ? (string) $args['id'] : 'wp-tooltip-' . $sequence;
	$label       = isset( $args['label'] ) ? (string) $args['label'] : 'Help';
	$close_label = isset( $args['close_label'] ) ? (string) $args['close_label'] : 'Close';
	$icon        = isset( $args['icon'] ) ? (string) $args['icon'] : 'dashicons-editor-help';
	$class       = isset( $args['class'] ) ? ' ' . (string) $args['class'] : '';

	return '<span class="wp-tooltip wp-is-toggletip' . esc_attr( $class ) . '">'
		. '<button aria-haspopup="dialog" class="wp-tooltip__toggle" popovertarget="' . esc_attr( $id ) . '" type="button" aria-label="' . esc_attr( $label ) . '">'
		. '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span></button>'
		. '<span popover="auto" id="' . esc_attr( $id ) . '" class="wp-tooltip__bubble" role="dialog" aria-label="' . esc_attr( $label ) . '" tabindex="-1">'
		. '<span id="' . esc_attr( $id ) . '-text" class="wp-tooltip__text">' . esc_html( $content ) . '</span>'
		. '<button type="button" class="wp-tooltip__close" popovertarget="' . esc_attr( $id ) . '" popovertargetaction="hide" aria-label="' . esc_attr( $close_label ) . '">'
		. '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></span></span>';
}

/**
 * Mock WordPress's request-scoped unique ID helper.
 */
function wp_unique_id( $prefix = '' ) {
	static $sequence = 0;
	++$sequence;
	return (string) $prefix . $sequence;
}

/**
 * Mock sanitize_title
 */
function sanitize_title( $title ) {
	return strtolower( str_replace( ' ', '-', $title ) );
}

/**
 * Mock absint
 */
function absint( $val ) {
	return abs( intval( $val ) );
}

/**
 * Mock capability checks with an opt-in capability list.
 */
function current_user_can( $capability, ...$args ) {
	unset( $args );
	return in_array(
		(string) $capability,
		(array) ( $GLOBALS['cybermaps_mock_current_user_capabilities'] ?? array() ),
		true
	);
}

/** Mock current administrator identity. */
function get_current_user_id() {
	return (int) ( $GLOBALS['cybermaps_mock_user_id'] ?? $GLOBALS['cybermaps_mock_current_user_id'] ?? 1 );
}

/**
 * Mock WordPress's installation-salted one-way hash helper.
 */
function wp_hash( $data, $scheme = 'auth', $algo = 'md5' ) {
	return hash_hmac( (string) $algo, (string) $data, 'cybermaps-test-' . (string) $scheme . '-salt' );
}

/**
 * Mock get_post
 */
function get_post( $id ) {
	if ( is_object( $id ) ) {
		return $id;
	}
	global $cybermaps_mock_posts;
	return isset( $cybermaps_mock_posts[ $id ] ) ? $cybermaps_mock_posts[ $id ] : null;
}

/**
 * Mock revision/autosave predicates.
 */
function wp_is_post_revision( $post_id ) {
	return false;
}

function wp_is_post_autosave( $post_id ) {
	return false;
}

/**
 * Mock get_comment
 */
function get_comment( $id ) {
	return $GLOBALS['cybermaps_mock_comments'][ (int) $id ] ?? null;
}

/**
 * Mock clean_post_cache
 */
function clean_post_cache( $post_id ) {
	$GLOBALS['cybermaps_mock_cleaned_post_ids'][] = (int) $post_id;
}

/**
 * Mock complete post queries used by literal publication inventory.
 */
function get_posts( $args = array() ) {
	global $cybermaps_mock_posts;
	$GLOBALS['cybermaps_mock_get_posts_args'][] = $args;
	$callback = $GLOBALS['cybermaps_mock_get_posts_callback'] ?? null;
	if ( is_callable( $callback ) ) {
		$result = $callback( $args );
		if ( is_array( $result ) ) {
			return $result;
		}
	}
	$posts      = array_values( (array) $cybermaps_mock_posts );
	$post_types = isset( $args['post_type'] ) ? (array) $args['post_type'] : array();
	$status     = isset( $args['post_status'] ) ? (array) $args['post_status'] : array();
	$excluded   = array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) );
	$included   = array_map( 'intval', (array) ( $args['post__in'] ?? array() ) );

	$posts = array_values(
		array_filter(
			$posts,
			static function ( $post ) use ( $post_types, $status, $excluded, $included ) {
				if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
					return false;
				}
				if ( ! empty( $post_types ) && ! in_array( (string) ( $post->post_type ?? 'post' ), $post_types, true ) ) {
					return false;
				}
				if ( ! empty( $status ) && ! in_array( (string) ( $post->post_status ?? 'publish' ), $status, true ) ) {
					return false;
				}
				if ( ! empty( $included ) && ! in_array( (int) $post->ID, $included, true ) ) {
					return false;
				}
				return ! in_array( (int) $post->ID, $excluded, true );
			}
		)
	);

	$snapshot_id = (int) ( $args['cybermaps_snapshot_id'] ?? 0 );
	$after       = (string) ( $args['cybermaps_after_modified'] ?? '' );
	$after_id    = (int) ( $args['cybermaps_after_id'] ?? 0 );
	if ( $snapshot_id > 0 ) {
		$posts = array_values(
			array_filter(
				$posts,
				static function ( object $post ) use ( $snapshot_id, $after, $after_id ): bool {
					if ( (int) $post->ID > $snapshot_id ) {
						return false;
					}
					if ( '' === $after ) {
						return true;
					}
					$modified = (string) ( $post->post_modified_gmt ?? $post->post_date_gmt ?? '' );
					return $modified < $after || ( $modified === $after && (int) $post->ID > $after_id );
				}
			)
		);
	}

	if ( 'post__in' === ( $args['orderby'] ?? '' ) && ! empty( $included ) ) {
		$order = array_flip( $included );
		usort(
			$posts,
			static fn( object $left, object $right ): int => ( $order[ (int) $left->ID ] ?? PHP_INT_MAX )
				<=> ( $order[ (int) $right->ID ] ?? PHP_INT_MAX )
		);
	}
	if ( 'ID' === ( $args['orderby'] ?? '' ) ) {
		usort(
			$posts,
			static fn( object $left, object $right ): int =>
				'DESC' === strtoupper( (string) ( $args['order'] ?? 'ASC' ) )
					? (int) $right->ID <=> (int) $left->ID
					: (int) $left->ID <=> (int) $right->ID
		);
	}
	if ( is_array( $args['orderby'] ?? null ) ) {
		usort(
			$posts,
			static function ( object $left, object $right ): int {
				$modified_order = strcmp(
					(string) ( $right->post_modified_gmt ?? $right->post_date_gmt ?? '' ),
					(string) ( $left->post_modified_gmt ?? $left->post_date_gmt ?? '' )
				);
				return 0 !== $modified_order
					? $modified_order
					: (int) $left->ID <=> (int) $right->ID;
			}
		);
	}

	$per_page = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 5;
	if ( $per_page > -1 ) {
		$page   = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset = isset( $args['offset'] )
			? max( 0, (int) $args['offset'] )
			: ( $page - 1 ) * $per_page;
		$posts  = array_slice( $posts, $offset, $per_page );
	}

	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return array_map(
			static fn( object $post ): int => (int) $post->ID,
			$posts
		);
	}

	return $posts;
}

/**
 * Mock published post counts for upper-bound inventory estimates.
 */
function wp_count_posts( $post_type = 'post', $perm = '' ) {
	unset( $perm );
	global $cybermaps_mock_posts, $cybermaps_mock_wp_count_posts_calls;
	if ( isset( $cybermaps_mock_wp_count_posts_calls ) ) {
		++$cybermaps_mock_wp_count_posts_calls;
	}
	$publish = 0;
	foreach ( (array) $cybermaps_mock_posts as $post ) {
		if (
			is_object( $post )
			&& (string) ( $post->post_type ?? 'post' ) === (string) $post_type
			&& 'publish' === (string) ( $post->post_status ?? 'publish' )
		) {
			++$publish;
		}
	}

	return (object) array(
		'publish' => $publish,
	);
}

function get_post_type( $post = null ) {
	$object = is_object( $post ) ? $post : get_post( (int) $post );
	return is_object( $object ) ? (string) ( $object->post_type ?? 'post' ) : false;
}

/**
 * Mock public object registries used by sitemap inventory tests.
 */
function get_post_types( $args = array(), $output = 'names' ) {
	unset( $args, $output );
	return $GLOBALS['cybermaps_mock_post_types'] ?? array();
}

function post_type_exists( $post_type ) {
	$post_type = (string) $post_type;
	return in_array( $post_type, (array) ( $GLOBALS['cybermaps_mock_post_types'] ?? array() ), true )
		|| isset( $GLOBALS['cybermaps_mock_post_type_objects'][ $post_type ] );
}

function get_taxonomies( $args = array(), $output = 'names' ) {
	unset( $args, $output );
	return $GLOBALS['cybermaps_mock_taxonomies'] ?? array();
}

function taxonomy_exists( $taxonomy ) {
	$taxonomy = (string) $taxonomy;
	return in_array( $taxonomy, (array) ( $GLOBALS['cybermaps_mock_taxonomies'] ?? array() ), true )
		|| isset( $GLOBALS['cybermaps_mock_taxonomy_objects'][ $taxonomy ] );
}

function get_post_type_object( $post_type ) {
	return isset( $GLOBALS['cybermaps_mock_post_type_objects'][ $post_type ] )
		? $GLOBALS['cybermaps_mock_post_type_objects'][ $post_type ]
		: null;
}

function post_type_supports( $post_type, $feature ) {
	return ! empty( $GLOBALS['cybermaps_mock_post_type_supports'][ $post_type ][ $feature ] );
}

function add_post_type_support( $post_type, $feature, ...$args ) {
	$GLOBALS['cybermaps_mock_post_type_supports'][ $post_type ][ $feature ] = empty( $args )
		? true
		: $args;
}

function register_post_meta( $post_type, $meta_key, $args ) {
	$GLOBALS['cybermaps_mock_registered_post_meta'][ $post_type ][ $meta_key ] = $args;
	return true;
}

function get_object_taxonomies( $post_type, $output = 'names' ) {
	unset( $output );
	$post_type = (string) $post_type;

	return $GLOBALS['cybermaps_mock_object_taxonomies'][ $post_type ]
		?? ( $GLOBALS['cybermaps_mock_taxonomies'] ?? array() );
}

function wp_get_object_terms( $post_ids, $taxonomies, $args = array() ) {
	unset( $taxonomies );
	$post_id = is_array( $post_ids ) ? (int) reset( $post_ids ) : (int) $post_ids;
	$terms   = (array) ( $GLOBALS['cybermaps_mock_object_terms'][ $post_id ] ?? array() );

	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return array_values(
			array_filter(
				array_map(
					static fn ( $term ): int => is_object( $term )
						? (int) ( $term->term_id ?? 0 )
						: (int) $term,
					$terms
				)
			)
		);
	}

	return $terms;
}

function get_taxonomy( $taxonomy ) {
	return isset( $GLOBALS['cybermaps_mock_taxonomy_objects'][ $taxonomy ] )
		? $GLOBALS['cybermaps_mock_taxonomy_objects'][ $taxonomy ]
		: null;
}

function get_term( $term, $taxonomy = '' ) {
	if ( is_object( $term ) ) {
		return $term;
	}

	$term_id = (int) $term;
	$taxonomies = '' !== (string) $taxonomy
		? array( (string) $taxonomy )
		: array_keys( (array) ( $GLOBALS['cybermaps_mock_terms'] ?? array() ) );
	foreach ( $taxonomies as $candidate_taxonomy ) {
		foreach ( (array) ( $GLOBALS['cybermaps_mock_terms'][ $candidate_taxonomy ] ?? array() ) as $candidate ) {
			if ( is_object( $candidate ) && (int) ( $candidate->term_id ?? 0 ) === $term_id ) {
				return $candidate;
			}
		}
	}

	return null;
}

function get_terms( $args = array() ) {
	$taxonomy = (string) ( $args['taxonomy'] ?? '' );
	$GLOBALS['cybermaps_mock_get_terms_args'][] = $args;
	return $GLOBALS['cybermaps_mock_terms'][ $taxonomy ] ?? array();
}

function wp_count_terms( $args = array(), $deprecated = '' ) {
	if ( is_array( $args ) ) {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
	} else {
		$taxonomy = (string) $args;
		$args     = is_array( $deprecated ) ? $deprecated : array();
	}
	$terms    = (array) ( $GLOBALS['cybermaps_mock_terms'][ $taxonomy ] ?? array() );
	if ( ! empty( $args['hide_empty'] ) ) {
		$terms = array_filter(
			$terms,
			static fn ( $term ): bool => ! is_object( $term )
				|| ! isset( $term->count )
				|| (int) $term->count > 0
		);
	}
	return count( $terms );
}

/**
 * Mock public archive URLs.
 */
function get_term_link( $term, $taxonomy = '' ) {
	$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : (int) $term;
	return sprintf( 'https://example.com/%s/%d/', (string) $taxonomy, $term_id );
}

function get_author_posts_url( $user_id ) {
	return 'https://example.com/author/' . (int) $user_id . '/';
}

function get_month_link( $year, $month ) {
	return sprintf( 'https://example.com/%04d/%02d/', (int) $year, (int) $month );
}

function get_post_type_archive_link( $post_type ) {
	if (
		isset( $GLOBALS['cybermaps_mock_post_type_archive_links'] )
		&& array_key_exists( (string) $post_type, $GLOBALS['cybermaps_mock_post_type_archive_links'] )
	) {
		return $GLOBALS['cybermaps_mock_post_type_archive_links'][ (string) $post_type ];
	}

	return 'https://example.com/' . trim( (string) $post_type, '/' ) . '/';
}

/**
 * Mock direct child-page queries used by automated identity catalogs.
 */
function get_pages( $args = array() ) {
	$GLOBALS['cybermaps_mock_get_pages_args'][] = $args;
	$parent_id = (int) ( $args['parent'] ?? 0 );

	return array_values(
		array_filter(
			(array) ( $GLOBALS['cybermaps_mock_pages'] ?? array() ),
			static function ( $page ) use ( $parent_id ): bool {
				return is_object( $page )
					&& $parent_id === (int) ( $page->post_parent ?? 0 );
			}
		)
	);
}

function wp_dropdown_pages( $args = array() ) {
	$id    = (string) ( $args['id'] ?? $args['name'] ?? '' );
	$name  = (string) ( $args['name'] ?? '' );
	$class = (string) ( $args['class'] ?? '' );
	echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="' . esc_attr( $class ) . '"></select>';
}

/**
 * Mock get_post_meta
 */
function get_post_meta( $id, $key, $single = false ) {
	if ( isset( $GLOBALS['cybermaps_mock_post_meta_observer'] ) ) {
		( $GLOBALS['cybermaps_mock_post_meta_observer'] )( $id, $key );
	}
	global $cybermaps_mock_post_meta;
	if ( isset( $cybermaps_mock_post_meta[ $id ][ $key ] ) ) {
		return $single ? $cybermaps_mock_post_meta[ $id ][ $key ] : array( $cybermaps_mock_post_meta[ $id ][ $key ] );
	}
	return $single ? '' : array();
}

function update_postmeta_cache( $post_ids ) {
	if ( isset( $GLOBALS['cybermaps_mock_prime_post_meta_observer'] ) ) {
		( $GLOBALS['cybermaps_mock_prime_post_meta_observer'] )( $post_ids );
	}
	unset( $post_ids );
}

function update_object_term_cache( $object_ids, $object_type ) {
	unset( $object_ids, $object_type );
}

/**
 * Mock term metadata.
 */
function get_term_meta( $id, $key, $single = false ) {
	global $cybermaps_mock_term_meta;
	if ( isset( $cybermaps_mock_term_meta[ $id ][ $key ] ) ) {
		return $single ? $cybermaps_mock_term_meta[ $id ][ $key ] : array( $cybermaps_mock_term_meta[ $id ][ $key ] );
	}
	return $single ? '' : array();
}

/**
 * Genesis/Mai SEO compatibility fixtures.
 */
function genesis_seo_active() {
	return (bool) ( $GLOBALS['cybermaps_mock_genesis_seo_active'] ?? false );
}

function genesis_get_custom_field( $key, $post_id = null ) {
	return get_post_meta( (int) $post_id, (string) $key, true );
}

function genesis_get_seo_option( $key, $use_cache = true ) {
	unset( $use_cache );
	return $GLOBALS['cybermaps_mock_genesis_seo_options'][ $key ] ?? '';
}

function genesis_has_post_type_archive_support( $post_type = '' ) {
	return in_array( (string) $post_type, $GLOBALS['cybermaps_mock_genesis_archive_support'] ?? array(), true );
}

function genesis_get_cpt_option( $key, $post_type = '', $use_cache = true ) {
	unset( $use_cache );
	return $GLOBALS['cybermaps_mock_genesis_cpt_options'][ $post_type ][ $key ] ?? '';
}

/**
 * Mock update_post_meta
 */
function update_post_meta( $id, $key, $value ) {
	global $cybermaps_mock_post_meta;
	$cybermaps_mock_post_meta[ $id ][ $key ] = $value;
}

/**
 * Mock delete_post_meta
 */
function delete_post_meta( $id, $key ) {
	global $cybermaps_mock_post_meta;
	unset( $cybermaps_mock_post_meta[ $id ][ $key ] );
}

/**
 * Mock delete_post_meta_by_key
 */
function delete_post_meta_by_key( $key ) {
	global $cybermaps_mock_post_meta, $cybermaps_mock_deleted_post_meta_keys;
	$cybermaps_mock_deleted_post_meta_keys[] = $key;

	foreach ( (array) $cybermaps_mock_post_meta as $post_id => $metadata ) {
		unset( $cybermaps_mock_post_meta[ $post_id ][ $key ] );
	}

	return true;
}

/**
 * Mock update_user_meta
 */
function update_user_meta( $user_id, $key, $value ) {
	global $cybermaps_mock_user_meta;
	$cybermaps_mock_user_meta[ $user_id ][ $key ] = $value;
	return true;
}

function get_user_by( $field, $value ) {
	if ( 'email' !== (string) $field ) {
		return false;
	}

	return $GLOBALS['cybermaps_mock_users_by_email'][ (string) $value ] ?? false;
}

/**
 * Mock get_user_meta
 */
function get_user_meta( $user_id, $key, $single = false ) {
	global $cybermaps_mock_user_meta;
	if ( isset( $cybermaps_mock_user_meta[ $user_id ][ $key ] ) ) {
		return $single
			? $cybermaps_mock_user_meta[ $user_id ][ $key ]
			: array( $cybermaps_mock_user_meta[ $user_id ][ $key ] );
	}

	return $single ? '' : array();
}

function get_the_author_meta( $field = '', $user_id = false ) {
	$user = $GLOBALS['cybermaps_mock_users'][ (int) $user_id ] ?? null;
	return is_object( $user ) && isset( $user->{$field} )
		? (string) $user->{$field}
		: '';
}

/**
 * Mock delete_metadata
 */
function delete_metadata( $meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
	unset( $object_id, $meta_value );

	if ( 'user' !== $meta_type || ! $delete_all ) {
		return false;
	}

	global $cybermaps_mock_user_meta, $cybermaps_mock_deleted_user_meta_keys;
	$cybermaps_mock_deleted_user_meta_keys[] = $meta_key;

	foreach ( (array) $cybermaps_mock_user_meta as $user_id => $metadata ) {
		unset( $cybermaps_mock_user_meta[ $user_id ][ $meta_key ] );
	}

	return true;
}

/**
 * Mock get_the_title
 */
function get_the_title( $id ) {
	$post = get_post( $id );
	return $post ? (string) ( $post->post_title ?? '' ) : '';
}

/**
 * Mock singular/front-end request context.
 */
function is_front_page() {
	return (bool) ( $GLOBALS['cybermaps_mock_is_front_page'] ?? false );
}

function is_singular() {
	return (bool) ( $GLOBALS['cybermaps_mock_is_singular'] ?? false );
}

function get_the_ID() {
	return (int) ( $GLOBALS['cybermaps_mock_current_post_id'] ?? 0 );
}

function get_queried_object_id() {
	return (int) ( $GLOBALS['cybermaps_mock_queried_object_id'] ?? get_the_ID() );
}

function setup_postdata( $post ) {
	$GLOBALS['cybermaps_mock_current_post_id'] = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
	return true;
}

function wp_reset_postdata() {
	$GLOBALS['cybermaps_mock_current_post_id'] = 0;
}

function get_post_time( $format = 'U', $gmt = false, $post = null ) {
	unset( $gmt );
	$object = is_object( $post ) ? $post : get_post( (int) $post );
	$date   = is_object( $object ) && ! empty( $object->post_date_gmt )
		? (string) $object->post_date_gmt
		: '2026-07-29 12:00:00';
	$time   = strtotime( $date . ' UTC' );
	return false === $time ? false : gmdate( (string) $format, $time );
}

function has_excerpt( $post = 0 ) {
	$object = is_object( $post ) ? $post : get_post( (int) $post );
	return is_object( $object ) && '' !== trim( (string) ( $object->post_excerpt ?? '' ) );
}

function get_the_content( $more_link_text = null, $strip_teaser = false, $post = null ) {
	unset( $more_link_text, $strip_teaser );
	$object = is_object( $post ) ? $post : get_post( (int) $post );
	return is_object( $object ) ? (string) ( $object->post_content ?? '' ) : '';
}

function get_the_date( $format = '', $post = null ) {
	unset( $format, $post );
	return (string) ( $GLOBALS['cybermaps_mock_the_date'] ?? '2026-07-29T12:00:00+00:00' );
}

function get_the_modified_date( $format = '', $post = null ) {
	unset( $format );
	$object = is_object( $post ) ? $post : get_post( (int) $post );
	if ( is_object( $object ) && ! empty( $object->post_modified_gmt ) ) {
		return gmdate( DATE_ATOM, strtotime( (string) $object->post_modified_gmt . ' UTC' ) );
	}
	return (string) ( $GLOBALS['cybermaps_mock_the_modified_date'] ?? '2026-07-29T12:00:00+00:00' );
}

/**
 * Capture inline scripts while preserving the observable print behavior.
 */
function wp_print_inline_script_tag( $data, $attributes = array() ) {
	$GLOBALS['cybermaps_mock_inline_scripts'][] = array(
		'data'       => (string) $data,
		'attributes' => (array) $attributes,
	);

	$type = isset( $attributes['type'] ) ? ' type="' . esc_attr( $attributes['type'] ) . '"' : '';
	echo '<script' . $type . '>' . $data . '</script>';
}

/**
 * Mock wp_get_attachment_url
 */
function wp_get_attachment_url( $id ) {
	if (
		isset( $GLOBALS['cybermaps_mock_attachment_urls'] )
		&& array_key_exists( (int) $id, $GLOBALS['cybermaps_mock_attachment_urls'] )
	) {
		return $GLOBALS['cybermaps_mock_attachment_urls'][ (int) $id ];
	}

	return "https://example.com/wp-content/uploads/attachment-$id.jpg";
}

function wp_attachment_is_image( $id ) {
	if (
		isset( $GLOBALS['cybermaps_mock_attachment_images'] )
		&& array_key_exists( (int) $id, $GLOBALS['cybermaps_mock_attachment_images'] )
	) {
		return (bool) $GLOBALS['cybermaps_mock_attachment_images'][ (int) $id ];
	}

	return true;
}

/**
 * Mock get_children
 */
function get_children( $args ) {
	unset( $args );
	return $GLOBALS['cybermaps_mock_children'] ?? array();
}

/**
 * Mock get_site_icon_url
 */
function get_site_icon_url() {
	return 'https://example.com/favicon.ico';
}

/**
 * Mock includes_url
 */
function includes_url( $path ) {
	return 'https://example.com/wp-includes/' . $path;
}

/**
 * Mock is_ssl
 */
function is_ssl() {
	return true;
}

/**
 * Mock WordPress administration context.
 */
function is_admin() {
	return (bool) ( $GLOBALS['cybermaps_mock_is_admin'] ?? false );
}

/**
 * Mock status_header
 */
function status_header( $code ) {
	$GLOBALS['cybermaps_mock_status_headers'][] = (int) $code;
}

/**
 * Mock nocache_headers
 */
function nocache_headers() {}

/**
 * Mock get_404_template
 */
function get_404_template() {
	return '404.php';
}

/**
 * Hook call tracker
 */
$GLOBALS['wp_hooks'] = array();

/**
 * Mock add_action
 */
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wp_hooks'][] = array(
		'type'          => 'action',
		'hook'          => $hook,
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

/**
 * Mock add_filter
 */
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wp_hooks'][] = array(
		'type'          => 'filter',
		'hook'          => $hook,
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function has_filter( $hook, $callback = false ) {
	$mock_callbacks = (array) ( $GLOBALS['cybermaps_mock_filter_callbacks'][ $hook ] ?? array() );
	if ( false === $callback ) {
		if ( ! empty( $mock_callbacks ) ) {
			return 10;
		}
	} else {
		foreach ( $mock_callbacks as $mock_callback ) {
			if ( $mock_callback === $callback ) {
				return 10;
			}
		}
	}

	$matches = array_values(
		array_filter(
			$GLOBALS['wp_hooks'] ?? array(),
			static function ( array $registered ) use ( $hook, $callback ): bool {
				if ( 'filter' !== ( $registered['type'] ?? '' ) || $hook !== ( $registered['hook'] ?? '' ) ) {
					return false;
				}
				if ( false === $callback ) {
					return true;
				}
				return ( $registered['callback'] ?? null ) === $callback;
			}
		)
	);
	if ( false === $callback ) {
		return empty( $matches ) ? false : 10;
	}
	return empty( $matches ) ? false : 10;
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	unset( $callback, $priority );
	$GLOBALS['wp_hooks'] = array_values(
		array_filter(
			$GLOBALS['wp_hooks'],
			static fn( array $registered ): bool => ! (
				'filter' === $registered['type']
				&& $hook === $registered['hook']
			)
		)
	);
	return true;
}

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	unset( $shortcode );
	return array_merge(
		(array) $pairs,
		array_intersect_key( (array) $atts, (array) $pairs )
	);
}

/**
 * Mock add_submenu_page
 */
function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
	global $cybermaps_mock_admin_pages;
	$cybermaps_mock_admin_pages[] = array(
		'parent_slug' => $parent_slug,
		'page_title'  => $page_title,
		'menu_title'  => $menu_title,
		'capability'  => $capability,
		'menu_slug'   => $menu_slug,
		'callback'    => $callback,
	);

	// WordPress derives a top-level submenu hook from the sanitized parent menu
	// title ("CYBERMAPS"), not from its menu slug ("cybermaps-settings").
	$page_type = 'cybermaps-settings' === $parent_slug ? 'cybermaps' : $parent_slug;
	return $page_type . '_page_' . $menu_slug;
}

/**
 * Mock wp_enqueue_style
 */
function wp_enqueue_style( $handle, $src = '', $deps = array(), $version = false, $media = 'all' ) {
	$GLOBALS['cybermaps_mock_enqueued_styles'][ $handle ] = array(
		'src'     => $src,
		'deps'    => $deps,
		'version' => $version,
		'media'   => $media,
	);
}

/**
 * Mock wp_style_add_data
 */
function wp_style_add_data( $handle, $key, $value ) {
	$GLOBALS['cybermaps_mock_style_data'][ $handle ][ $key ] = $value;
}

/**
 * Mock wp_enqueue_script
 */
function wp_enqueue_script( $handle, $src = '', $deps = array(), $version = false, $args = array() ) {
	$GLOBALS['cybermaps_mock_enqueued_scripts'][ $handle ] = array(
		'src'     => $src,
		'deps'    => $deps,
		'version' => $version,
		'args'    => $args,
	);
}

function wp_script_is( $handle, $status = 'enqueued' ) {
	if ( 'registered' === $status ) {
		return ! empty( $GLOBALS['cybermaps_mock_registered_scripts'][ $handle ] );
	}
	return isset( $GLOBALS['cybermaps_mock_enqueued_scripts'][ $handle ] );
}

function wp_localize_script( $handle, $object_name, $data ) {
	$GLOBALS['cybermaps_mock_localized_scripts'][ $handle ][ $object_name ] = $data;
	return true;
}

function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
	$GLOBALS['cybermaps_mock_script_translations'][ $handle ] = array(
		'domain' => $domain,
		'path'   => $path,
	);
	return true;
}

function get_current_screen() {
	return $GLOBALS['cybermaps_mock_current_screen'] ?? null;
}

/**
 * Mock add_rewrite_rule
 */
function add_rewrite_rule( $regex, $query, $after ) {
	$GLOBALS['cybermaps_mock_rewrite_rules'][] = array(
		'regex' => $regex,
		'query' => $query,
		'after' => $after,
	);
}

/**
 * Mock wp_parse_url
 */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

/**
 * Mock wp_unslash
 */
function wp_unslash( $value ) {
	return $value;
}

/**
 * Mock nonce generation for rendered admin controls.
 */
function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . md5( (string) $action );
}

/**
 * Mock nonce verification with the same action-bound token generated above.
 */
function wp_verify_nonce( $nonce, $action = -1 ) {
	return hash_equals( wp_create_nonce( $action ), (string) $nonce );
}

if ( ! class_exists( 'WP_Post', false ) ) {
	/**
	 * Minimal post value object for admin save-handler tests.
	 */
	#[\AllowDynamicProperties]
	class WP_Post {
		public function __construct( array $properties = array() ) {
			foreach ( $properties as $name => $value ) {
				$this->{$name} = $value;
			}
		}
	}
}

/**
 * Mock sanitize_text_field
 */
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

/**
 * Mock sanitize_key
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * Mock sanitize_textarea_field
 */
function sanitize_textarea_field( $value ) {
	return trim( (string) $value );
}

/**
 * Mock sanitize_email
 */
function sanitize_email( $email ) {
	return filter_var( trim( (string) $email ), FILTER_VALIDATE_EMAIL ) ?: '';
}

/**
 * Mock sanitize_file_name
 */
function sanitize_file_name( $filename ) {
	return preg_replace( '/[^a-zA-Z0-9._\-]/', '', (string) $filename );
}

/**
 * Mock esc_url_raw
 */
function esc_url_raw( $url, $protocols = null ) {
	unset( $protocols );
	return trim( (string) $url );
}

function admin_url( $path = '', $scheme = 'admin' ) {
	unset( $scheme );
	return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' );
}

/**
 * Mock add_query_arg with fragment preservation and replacement semantics.
 */
function add_query_arg( $key, $value = null, $url = null ) {
	if ( is_array( $key ) ) {
		$args = $key;
		$url  = (string) $value;
	} else {
		$args = array( (string) $key => $value );
		$url  = (string) $url;
	}

	$fragment = '';
	$hash_at  = strpos( $url, '#' );
	if ( false !== $hash_at ) {
		$fragment = substr( $url, $hash_at );
		$url      = substr( $url, 0, $hash_at );
	}

	$query_at = strpos( $url, '?' );
	$base     = false === $query_at ? $url : substr( $url, 0, $query_at );
	$query    = false === $query_at ? '' : substr( $url, $query_at + 1 );
	$current  = array();
	parse_str( $query, $current );

	foreach ( $args as $name => $candidate ) {
		if ( false === $candidate ) {
			unset( $current[ (string) $name ] );
			continue;
		}
		$current[ (string) $name ] = (string) $candidate;
	}

	$encoded = http_build_query( $current, '', '&', PHP_QUERY_RFC3986 );
	return $base . ( '' !== $encoded ? '?' . $encoded : '' ) . $fragment;
}

/**
 * Mock wp_http_validate_url with optional per-URL overrides.
 */
function wp_http_validate_url( $url ) {
	global $cybermaps_mock_http_url_validation;

	$url = (string) $url;
	if (
		is_array( $cybermaps_mock_http_url_validation )
		&& array_key_exists( $url, $cybermaps_mock_http_url_validation )
	) {
		return $cybermaps_mock_http_url_validation[ $url ] ? $url : false;
	}

	$parts = parse_url( $url );
	if (
		! is_array( $parts )
		|| empty( $parts['host'] )
		|| empty( $parts['scheme'] )
		|| ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true )
	) {
		return false;
	}

	if ( 'localhost' === strtolower( (string) $parts['host'] ) ) {
		return false;
	}

	if (
		filter_var( $parts['host'], FILTER_VALIDATE_IP )
		&& ! filter_var(
			$parts['host'],
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		)
	) {
		return false;
	}

	return $url;
}

/**
 * Mock a safe remote POST and retain the request for assertions.
 */
function wp_safe_remote_post( $url, $args = array() ) {
	global $cybermaps_mock_safe_remote_post_calls, $cybermaps_mock_safe_remote_post_response;

	$cybermaps_mock_safe_remote_post_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);

	return isset( $cybermaps_mock_safe_remote_post_response )
		? $cybermaps_mock_safe_remote_post_response
		: array( 'response' => array( 'code' => 200 ) );
}

/**
 * Mock a safe remote HEAD and retain the request for assertions.
 */
function wp_safe_remote_head( $url, $args = array() ) {
	global $cybermaps_mock_safe_remote_head_calls, $cybermaps_mock_safe_remote_head_response;

	$cybermaps_mock_safe_remote_head_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);

	return isset( $cybermaps_mock_safe_remote_head_response )
		? $cybermaps_mock_safe_remote_head_response
		: array( 'response' => array( 'code' => 200 ) );
}

/**
 * Mock a safe remote GET and retain the request for assertions.
 */
function wp_safe_remote_get( $url, $args = array() ) {
	global $cybermaps_mock_safe_remote_get_calls, $cybermaps_mock_safe_remote_get_response, $cybermaps_mock_safe_remote_get_responses;

	$cybermaps_mock_safe_remote_get_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);

	if (
		is_array( $cybermaps_mock_safe_remote_get_responses )
		&& array_key_exists( (string) $url, $cybermaps_mock_safe_remote_get_responses )
	) {
		return $cybermaps_mock_safe_remote_get_responses[ (string) $url ];
	}

	return isset( $cybermaps_mock_safe_remote_get_response )
		? $cybermaps_mock_safe_remote_get_response
		: array( 'response' => array( 'code' => 200 ) );
}

/**
 * Mock WordPress HTTP response code extraction.
 */
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] )
		? (int) $response['response']['code']
		: 0;
}

/** Mock WordPress HTTP response body extraction. */
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && is_scalar( $response['body'] ?? null ) ? (string) $response['body'] : '';
}

/**
 * Mock case-insensitive WordPress HTTP response-header retrieval.
 */
function wp_remote_retrieve_header( $response, $header ) {
	if ( ! is_array( $response ) || ! is_array( $response['headers'] ?? null ) ) {
		return '';
	}

	$header = strtolower( (string) $header );
	foreach ( $response['headers'] as $name => $value ) {
		if ( $header === strtolower( (string) $name ) ) {
			return $value;
		}
	}

	return '';
}

if ( ! class_exists( 'WP_Error', false ) ) {
	/**
	 * Minimal WordPress error object for HTTP tests.
	 */
	class WP_Error {
	}
}

/**
 * Mock WordPress error detection.
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Mock wp_generate_password
 */
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return str_repeat( 'x', (int) $length );
}

/**
 * Mock delete_transient
 */
function delete_transient( $transient ) {
	global $cybermaps_mock_transients;
	unset( $cybermaps_mock_transients[ $transient ] );
	unset( $GLOBALS['cybermaps_mock_transient_expirations'][ $transient ] );
}

/**
 * Mock get_transient
 */
function get_transient( $transient ) {
	global $cybermaps_mock_transients;
	if (
		isset( $GLOBALS['cybermaps_mock_transient_expirations'][ $transient ] )
		&& $GLOBALS['cybermaps_mock_transient_expirations'][ $transient ] <= time()
	) {
		unset(
			$cybermaps_mock_transients[ $transient ],
			$GLOBALS['cybermaps_mock_transient_expirations'][ $transient ]
		);
		return false;
	}
	$observer = $GLOBALS['cybermaps_mock_get_transient_observer'] ?? null;
	if ( is_callable( $observer ) ) {
		$observer( (string) $transient );
	}
	return isset( $cybermaps_mock_transients[ $transient ] ) ? $cybermaps_mock_transients[ $transient ] : false;
}

/**
 * Mock set_transient
 */
function set_transient( $transient, $value, $expiration = 0 ) {
	global $cybermaps_mock_transients;
	$observer = $GLOBALS['cybermaps_mock_set_transient_observer'] ?? null;
	if ( is_callable( $observer ) ) {
		$observer( (string) $transient, $value, (int) $expiration, 'before' );
	}
	$cybermaps_mock_transients[ $transient ] = $value;
	if ( $expiration > 0 ) {
		$GLOBALS['cybermaps_mock_transient_expirations'][ $transient ] = time() + (int) $expiration;
	} else {
		unset( $GLOBALS['cybermaps_mock_transient_expirations'][ $transient ] );
	}
	if ( is_callable( $observer ) ) {
		$observer( (string) $transient, $value, (int) $expiration, 'after' );
	}
	return true;
}

/**
 * Mock wp_schedule_event
 */
function wp_schedule_event( $timestamp, $recurrence, $hook, $wp_error = false ) {
	unset( $recurrence, $wp_error );
	global $cybermaps_mock_scheduled;
	if ( array_key_exists( 'cybermaps_mock_schedule_event_result', $GLOBALS ) ) {
		$result = $GLOBALS['cybermaps_mock_schedule_event_result'];
		if ( true !== $result ) {
			return $result;
		}
	}
	$cybermaps_mock_scheduled[ $hook ] = $timestamp;
	return true;
}

/**
 * Mock wp_clear_scheduled_hook
 */
function wp_clear_scheduled_hook( $hook ) {
	global $cybermaps_mock_scheduled;
	unset( $cybermaps_mock_scheduled[ $hook ] );
	return 1;
}

/**
 * Mock flush_rewrite_rules
 */
function flush_rewrite_rules( $hard = true ) {
	global $cybermaps_mock_flush_count, $cybermaps_mock_flushes;
	++$cybermaps_mock_flush_count;
	$cybermaps_mock_flushes[] = (bool) $hard;
}

/** Minimal WordPress 7.1 Abilities API test double. */
class Cybermaps_Mock_WP_Ability {
	public function __construct( private string $name, private array $args ) {}
	public function get_name() { return $this->name; }
	public function get_label() { return (string) ( $this->args['label'] ?? '' ); }
	public function get_description() { return (string) ( $this->args['description'] ?? '' ); }
	public function get_input_schema() { return (array) ( $this->args['input_schema'] ?? array() ); }
	public function get_output_schema() { return (array) ( $this->args['output_schema'] ?? array() ); }
	public function get_meta() { return (array) ( $this->args['meta'] ?? array() ); }
	public function execute( $input = null ) {
		$permission = ( $this->args['permission_callback'] )( $input );
		if ( true !== $permission ) {
			return is_wp_error( $permission ) ? $permission : new WP_Error( 'forbidden', 'Forbidden' );
		}
		return ( $this->args['execute_callback'] )( $input );
	}
}

function wp_register_ability_category( $slug, $args ) {
	$GLOBALS['cybermaps_mock_ability_categories'][ $slug ] = $args;
	return (object) array( 'slug' => $slug );
}

function wp_register_ability( $name, $args ) {
	$ability = new Cybermaps_Mock_WP_Ability( $name, $args );
	$GLOBALS['cybermaps_mock_abilities'][ $name ] = $ability;
	return $ability;
}

function wp_get_abilities( $args = array() ) {
	$abilities = $GLOBALS['cybermaps_mock_abilities'] ?? array();
	$meta      = is_array( $args['meta'] ?? null ) ? $args['meta'] : array();
	if ( array_key_exists( 'public', $meta ) ) {
		$abilities = array_filter(
			$abilities,
			static fn( $ability ) => ( $ability->get_meta()['public'] ?? false ) === $meta['public']
		);
	}
	return $abilities;
}

function wp_get_ability( $name ) {
	return $GLOBALS['cybermaps_mock_abilities'][ $name ] ?? null;
}

function wp_has_ability( $name ) {
	return isset( $GLOBALS['cybermaps_mock_abilities'][ $name ] );
}

function wp_prepare_json_schema_for_client( $schema, $profile = 'draft-04' ) {
	unset( $profile );
	return $schema;
}

function wp_set_current_user( $user_id ) {
	$GLOBALS['cybermaps_mock_current_user_id'] = (int) $user_id;
	return (object) array( 'ID' => (int) $user_id );
}

/**
 * Mock strip_shortcodes
 */
function strip_shortcodes( $content ) {
	return preg_replace( '/\[[^\]]+\]/', '', (string) $content );
}

/**
 * Mock wp_trim_words
 */
function wp_trim_words( $text, $num_words = 55 ) {
	$words = preg_split( '/\s+/', trim( (string) $text ) );
	if ( ! is_array( $words ) ) {
		return '';
	}
	return implode( ' ', array_slice( $words, 0, (int) $num_words ) );
}

/**
 * Mock get_the_excerpt
 */
function get_the_excerpt( $post ) {
	if ( is_object( $post ) && ! empty( $post->post_excerpt ) ) {
		return $post->post_excerpt;
	}
	return '';
}

/**
 * Mock get_site_option
 */
function get_site_option( $option, $default = false ) {
	global $cybermaps_mock_site_options;
	return array_key_exists( $option, (array) $cybermaps_mock_site_options )
		? $cybermaps_mock_site_options[ $option ]
		: $default;
}

/**
 * Mock update_site_option
 */
function update_site_option( $option, $value ) {
	global $cybermaps_mock_site_options;
	$cybermaps_mock_site_options[ $option ] = $value;
	return true;
}

/**
 * Mock add_site_option with WordPress's insert-only semantics.
 */
function add_site_option( $option, $value ) {
	global $cybermaps_mock_site_options;
	if ( array_key_exists( $option, (array) $cybermaps_mock_site_options ) ) {
		return false;
	}
	$cybermaps_mock_site_options[ $option ] = $value;
	return true;
}

/**
 * Mock get_network_option
 */
function get_network_option( $network_id, $option, $default = false ) {
	global $cybermaps_mock_current_network_id,
		$cybermaps_mock_network_option_calls,
		$cybermaps_mock_network_options_by_network,
		$cybermaps_mock_site_options;

	$network_id = (int) $network_id;
	$cybermaps_mock_network_option_calls[] = array( $network_id, $option );
	if (
		isset( $cybermaps_mock_network_options_by_network[ $network_id ] )
		&& array_key_exists( $option, $cybermaps_mock_network_options_by_network[ $network_id ] )
	) {
		return $cybermaps_mock_network_options_by_network[ $network_id ][ $option ];
	}

	if (
		$network_id === (int) $cybermaps_mock_current_network_id
		&& array_key_exists( $option, (array) $cybermaps_mock_site_options )
	) {
		return $cybermaps_mock_site_options[ $option ];
	}

	return $default;
}

/**
 * Mock delete_site_option
 */
function delete_site_option( $option ) {
	global $cybermaps_mock_site_options;
	unset( $cybermaps_mock_site_options[ $option ] );
	return true;
}

/**
 * Mock delete_network_option
 */
function delete_network_option( $network_id, $option ) {
	global $cybermaps_mock_deleted_network_options;
	$cybermaps_mock_deleted_network_options[] = array( (int) $network_id, $option );
	return true;
}

/**
 * Mock get_networks
 */
function get_networks( $args = array() ) {
	unset( $args );
	global $cybermaps_mock_network_ids;
	return $cybermaps_mock_network_ids;
}

/**
 * Mock get_current_network_id
 */
function get_current_network_id() {
	global $cybermaps_mock_current_network_id;
	return isset( $cybermaps_mock_current_network_id )
		? (int) $cybermaps_mock_current_network_id
		: 1;
}

/**
 * Mock get_sites
 */
function get_sites( $args = array() ) {
	global $cybermaps_mock_get_sites_args, $cybermaps_mock_site_ids, $cybermaps_mock_site_ids_by_network;
	$cybermaps_mock_get_sites_args[] = $args;

	$network_id = isset( $args['network_id'] ) ? (int) $args['network_id'] : 0;
	if ( $network_id > 0 && isset( $cybermaps_mock_site_ids_by_network[ $network_id ] ) ) {
		return $cybermaps_mock_site_ids_by_network[ $network_id ];
	}

	return $cybermaps_mock_site_ids;
}

/**
 * Mock get_current_blog_id
 */
function get_current_blog_id() {
	global $cybermaps_mock_current_blog_id;
	return (int) $cybermaps_mock_current_blog_id;
}

/**
 * Mock switch_to_blog
 */
function switch_to_blog( $site_id ) {
	global $cybermaps_mock_blog_stack, $cybermaps_mock_current_blog_id, $cybermaps_mock_switched_blogs;
	$cybermaps_mock_blog_stack[] = (int) $cybermaps_mock_current_blog_id;
	$cybermaps_mock_switched_blogs[] = (int) $site_id;
	$cybermaps_mock_current_blog_id  = (int) $site_id;
	return true;
}

/**
 * Mock restore_current_blog
 */
function restore_current_blog() {
	global $cybermaps_mock_blog_stack, $cybermaps_mock_current_blog_id;
	$previous = array_pop( $cybermaps_mock_blog_stack );
	$cybermaps_mock_current_blog_id = null === $previous ? 1 : (int) $previous;
	return true;
}

/**
 * Mock is_main_site
 */
function is_main_site() {
	global $cybermaps_mock_is_main_site;
	return (bool) $cybermaps_mock_is_main_site;
}

/**
 * Mock dbDelta
 */
function dbDelta( $queries = '', $execute = true ) {
	$GLOBALS['cybermaps_mock_dbdelta_queries'][] = $queries;
	$callback = $GLOBALS['cybermaps_mock_dbdelta_callback'] ?? null;
	if ( is_callable( $callback ) ) {
		return $callback( $queries, $execute );
	}

	unset( $execute );
	return array( $queries );
}

/**
 * Mock wp_mkdir_p
 */
function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0755, true );
}

/**
 * Mock wp_delete_file
 */
function wp_delete_file( $file ) {
	return @unlink( $file );
}

/**
 * Mock get_rest_url
 */
function get_rest_url( $blog_id = null, $path = '/', $scheme = 'rest' ) {
	return "https://example.com/wp-json" . $path;
}

/**
 * Mock wp_strip_all_tags
 */
function wp_strip_all_tags( $string, $remove_breaks = false ) {
	return strip_tags( $string );
}

/**
 * Mock number_format_i18n
 */
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}

/**
 * Mock get_locale
 */
function get_locale() {
	return 'en_US';
}

/**
 * Mock WP_Query
 */
class WP_Query {
	public $posts       = array();
	public $found_posts = 0;
	private $current_post = -1;
	public function __construct( $args ) {
		$GLOBALS['cybermaps_mock_wp_query_args'][] = $args;
		if ( is_callable( $GLOBALS['cybermaps_mock_wp_query_callback'] ?? null ) ) {
			$result = ( $GLOBALS['cybermaps_mock_wp_query_callback'] )( $args );
			$this->posts = is_array( $result ) ? $result : array();
			$this->found_posts = count( $this->posts );
			return;
		}
		if ( array_key_exists( 'cybermaps_mock_wp_query_posts', $GLOBALS ) ) {
			$this->posts = (array) $GLOBALS['cybermaps_mock_wp_query_posts'];
			$this->found_posts = count( $this->posts );
			return;
		}

		$this->found_posts = 10;
		$this->posts       = array( (object) array( 'ID' => 1, 'post_title' => 'Post 1' ) );
	}

	public function have_posts() {
		return $this->current_post + 1 < count( $this->posts );
	}

	public function the_post() {
		++$this->current_post;
		$GLOBALS['post'] = $this->posts[ $this->current_post ] ?? null;
		$GLOBALS['cybermaps_mock_current_post_id'] = is_object( $GLOBALS['post'] )
			? (int) ( $GLOBALS['post']->ID ?? 0 )
			: 0;
	}
}

if ( ! class_exists( 'Cybermaps\Autoloader', false ) ) {
	require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
	\Cybermaps\Autoloader::register();
}

/** Convert the PHP memory-limit shorthand used by the full LLMS renderer. */
function wp_convert_hr_to_bytes( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$bytes = (int) $value;
	foreach ( array( 'g' => 1073741824, 'm' => 1048576, 'k' => 1024 ) as $suffix => $multiplier ) {
		if ( str_contains( $value, $suffix ) ) {
			return $bytes * $multiplier;
		}
	}
	return $bytes;
}
