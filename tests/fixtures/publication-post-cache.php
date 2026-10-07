<?php
declare(strict_types=1);

// Isolated cache/API contract fixture; native behavior is separately exercised
// by tests/integration/publication-cache-priming.php in disposable WordPress.
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
$GLOBALS['cache'] = array();
$GLOBALS['wpdb'] = (object) array( 'last_query' => 'SELECT full rows', 'last_result' => array(), 'last_error' => '' );
$GLOBALS['meta_calls'] = 0;
function is_wp_error( $value ): bool { return false; }
function esc_html__( $text, $domain ): string { return $text; }
function wp_suspend_cache_addition( $flag = null ): bool {
	static $suspended = false;
	if ( is_bool( $flag ) ) { $suspended = $flag; }
	return $suspended;
}
function wp_cache_get_multiple( $ids, $group ): array {
	$result = array();
	foreach ( $ids as $id ) { $result[$id] = $GLOBALS['cache'][$group][$id] ?? false; }
	return $result;
}
function wp_cache_add_multiple( $data, $group ): array {
	$result = array();
	foreach ( $data as $id => $value ) {
		$result[$id] = false;
		if ( ! wp_suspend_cache_addition() && ! isset( $GLOBALS['cache'][$group][$id] ) ) {
			$GLOBALS['cache'][$group][$id] = $value;
			$result[$id] = true;
		}
	}
	return $result;
}
function update_post_cache( &$posts ): void {
	$data = array();
	foreach ( $posts as $post ) { $data[$post->ID] = $post; }
	wp_cache_add_multiple( $data, 'posts' );
}
function update_meta_cache( $type, $ids ): array|bool {
	++$GLOBALS['meta_calls'];
	if ( isset( $GLOBALS['short_circuit'] ) ) { return $GLOBALS['short_circuit']; }
	if ( ! empty( $GLOBALS['throw'] ) ) { throw new RuntimeException( 'metadata hook failure' ); }
	$data = wp_cache_get_multiple( $ids, 'post_meta' );
	$fail = ( $GLOBALS['fail_call'] ?? 0 ) === $GLOBALS['meta_calls'];
	if ( $fail ) { $GLOBALS['wpdb']->last_error = 'metadata SELECT failed'; }
	foreach ( $data as $id => $value ) {
		if ( false === $value ) { $data[$id] = $fail ? array() : array( 'duplicate' => array( 'first', 'second' ), 'serialized' => array( 'a:1:{i:0;s:3:"raw";}' ) ); }
	}
	// This intentionally reproduces native Core's error-derived empty additions.
	wp_cache_add_multiple( $data, 'post_meta' );
	return $data;
}
class WP_Query {
	public string $request = 'SELECT full rows';
	public function __construct( private array $args ) {}
	public function get( $key ): mixed { return $this->args[$key] ?? null; }
}
function reset_fixture( int $count = 201, bool $meta = true ): array {
	$GLOBALS['cache'] = array(); $GLOBALS['meta_calls'] = 0;
	unset( $GLOBALS['short_circuit'], $GLOBALS['throw'], $GLOBALS['fail_call'] );
	wp_suspend_cache_addition( false );
	$GLOBALS['wpdb']->last_error = '';
	$GLOBALS['wpdb']->last_query = 'SELECT full rows';
	$rows = array();
	$columns = array( 'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count' );
	for ( $id = 1; $id <= $count; ++$id ) { $rows[] = (object) array_replace( array_fill_keys( $columns, '' ), array( 'ID' => $id, 'post_content' => 'raw', 'post_type' => 'post', 'post_status' => 'publish' ) ); }
	$GLOBALS['wpdb']->last_result = $rows;
	return array( $rows, new WP_Query( array( 'posts_per_page' => $count, 'update_post_meta_cache' => $meta, 'update_post_term_cache' => false ) ) );
}
function prime_fixture( array $fixture ): void { \Cybermaps\Sitemap\PublicationPostCache::prime( ...$fixture ); }
$result = array();
$fixture = reset_fixture();
$existing = (object) array( 'ID' => 1, 'post_content' => 'existing' );
$GLOBALS['cache']['posts'][1] = $existing;
$GLOBALS['cache']['post_meta'][1] = array( 'kept' => array( 'existing' ) );
prime_fixture( $fixture );
$result['bulk'] = array( 'queries' => $GLOBALS['meta_calls'], 'posts' => count( $GLOBALS['cache']['posts'] ), 'meta' => count( $GLOBALS['cache']['post_meta'] ), 'existing_post' => $GLOBALS['cache']['posts'][1] === $existing, 'existing_meta' => $GLOBALS['cache']['post_meta'][1], 'raw_values' => $GLOBALS['cache']['post_meta'][2], 'suspended' => wp_suspend_cache_addition() );
$fixture = reset_fixture( 3, false ); prime_fixture( $fixture );
$result['false_flag'] = array( $GLOBALS['meta_calls'], isset( $GLOBALS['cache']['post_meta'] ), count( $GLOBALS['cache']['posts'] ) );
$fixture = reset_fixture(); $GLOBALS['fail_call'] = 2;
try { prime_fixture( $fixture ); $result['failed'] = false; } catch ( \Cybermaps\Core\BuildUnavailableException ) { $result['failed'] = true; }
$result['failure_cache'] = $GLOBALS['cache']; $result['failure_suspended'] = wp_suspend_cache_addition();
$fixture = reset_fixture(); $GLOBALS['throw'] = true;
try { prime_fixture( $fixture ); } catch ( RuntimeException ) {}
$result['exception_suspended'] = wp_suspend_cache_addition();
$fixture = reset_fixture(); wp_suspend_cache_addition( true ); prime_fixture( $fixture );
$result['already_suspended'] = array( wp_suspend_cache_addition(), $GLOBALS['meta_calls'], $GLOBALS['cache'] );
foreach ( array( false, true ) as $short ) {
	$fixture = reset_fixture( 3 ); $GLOBALS['short_circuit'] = $short; prime_fixture( $fixture );
	$result['short_' . (int) $short] = array( isset( $GLOBALS['cache']['post_meta'] ), count( $GLOBALS['cache']['posts'] ) );
}
$fixture = reset_fixture( 3 ); $GLOBALS['wpdb']->last_query = 'SELECT unrelated'; prime_fixture( $fixture );
$result['unrelated'] = $GLOBALS['cache'];
$fixture = reset_fixture( 3 ); $fixture[0][0] = clone $fixture[0][0]; $fixture[0][0]->post_content = 'filter modified'; prime_fixture( $fixture );
$result['raw_post'] = $GLOBALS['cache']['posts'][1]->post_content;
$fixture = reset_fixture( 1 );
$GLOBALS['wpdb']->last_result = array( (object) array( 'ID' => 1, 'post_content' => 'raw', 'post_type' => 'post', 'post_status' => 'publish' ) );
prime_fixture( $fixture );
$result['partial_projection'] = array( $GLOBALS['meta_calls'], $GLOBALS['cache'] );
$fixture = reset_fixture( 1 ); unset( $GLOBALS['wpdb']->last_result[0]->post_name ); prime_fixture( $fixture );
$result['missing_slug'] = array( $GLOBALS['meta_calls'], $GLOBALS['cache'] );
echo json_encode( $result, JSON_THROW_ON_ERROR );
