<?php
declare(strict_types=1);

/** Serial disposable native runtime: normal require from wp eval, not eval-file. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'CYBERMAPS_VERSION' ) || '1' !== getenv( 'CYBERMAPS_TEMPLATE_SOURCE_PROBE' ) ) {
	throw new RuntimeException( 'Explicit disposable stored-template probe required.' );
}
global $wpdb;
function cybermaps_template_source_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function cybermaps_template_source_hook_counts(): array {
	global $wp_filter;
	$result = array();
	foreach ( array( 'pre_get_posts', 'split_the_query', 'posts_where', 'posts_request', 'posts_results', 'get_terms_args', 'get_terms' ) as $hook ) {
		$result[$hook] = isset( $wp_filter[$hook] ) ? array_sum( array_map( 'count', $wp_filter[$hook]->callbacks ) ) : 0;
	}
	return $result;
}
function cybermaps_template_source_absent_row( string $table, string $column, int $id ): bool {
	global $wpdb;
	$wpdb->last_error = '';
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i = %d', $table, $column, $id ) );
	cybermaps_template_source_assert( '' === $wpdb->last_error && is_numeric( $count ), 'Cleanup absence read failed.' );
	return 0 === (int) $count;
}
$prefix = 'cybermaps-source-' . wp_generate_uuid4();
$current_theme = get_stylesheet();
$inactive_theme = $prefix . '-inactive';
$owned_ids = array();
$owned_terms = array();
$theme_term_ids = array();
$verified_posts = 0;
$verified_terms = 0;
$original_terms = array( $current_theme => get_term_by( 'name', $current_theme, 'wp_theme' ), $inactive_theme => false );
$source = new \Cybermaps\Audit\StoredTemplateSource();
$query_method = new ReflectionMethod( $source, 'query_posts' );
$builder_calls = 0;
$render_calls = 0;
$block_hook_calls = 0;
$block_name = 'cybermaps-probe/' . $prefix;
$raw_markup = '<!-- wp:' . $block_name . ' /-->';
$render_guard = static function () use ( &$render_calls ): string { ++$render_calls; throw new RuntimeException( 'Raw source rendered the dynamic fixture block.' ); };
$block_hook_guard = static function ( array $types ) use ( &$block_hook_calls ): array { ++$block_hook_calls; return $types; };
$block_registered = false;
$builder_guard = static function () use ( &$builder_calls ): void { ++$builder_calls; throw new RuntimeException( 'Source called a template builder.' ); };
$slug_override = static function ( mixed $override, string $slug ) use ( $prefix ): mixed { return str_starts_with( $slug, $prefix ) ? $slug : $override; };
$observed_sql = array();
$unrelated_sql = array();
$nested_done = false;
$inactive_id = 0;
$request_observer = static function ( string $sql, WP_Query $query ) use ( &$observed_sql, &$unrelated_sql, &$nested_done, &$inactive_id ): string {
	if ( $query->get( 'cybermaps_stored_template_query' ) ) {
		cybermaps_template_source_assert( 'publish' === $query->get( 'post_status' ) && $query->get( 'posts_per_page' ) <= 1001 && false === $query->get( 'cache_results' ) && $query->get( 'no_found_rows' ), 'Source lost bounded authoritative query flags.' );
		$observed_sql[] = $sql;
		if ( ! $nested_done && $inactive_id > 0 ) {
			$nested_done = true;
			new WP_Query( array( 'post_type' => 'wp_template', 'post__in' => array( $inactive_id ), 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true, 'cache_results' => false, 'suppress_filters' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ) );
		}
	} else { $unrelated_sql[] = $sql; }
	return $sql;
};
$fault_hook = null;
$late_error_clear = static function ( array $rows, WP_Query $query ): array {
	if ( $query->get( 'cybermaps_stored_template_query' ) ) { $GLOBALS['wpdb']->last_error = ''; }
	return $rows;
};
$prior_error_suppression = $wpdb->suppress_errors( true );
$result = array( 'wordpress' => get_bloginfo( 'version' ), 'theme' => $current_theme );
add_filter( 'pre_wp_unique_post_slug', $slug_override, 10, 2 );
add_filter( 'posts_request', $request_observer, 10, 2 );
try {
	$registered = register_block_type( $block_name, array( 'render_callback' => $render_guard ) );
	cybermaps_template_source_assert( false !== $registered, 'Could not register owned dynamic block.' );
	$block_registered = true;
	foreach ( array( $current_theme, $inactive_theme ) as $theme ) {
		$term = $original_terms[$theme];
		if ( false === $term ) {
			$created_term = wp_insert_term( $theme, 'wp_theme' );
			cybermaps_template_source_assert( is_array( $created_term ) && (int) ( $created_term['term_id'] ?? 0 ) > 0, 'Could not explicitly create owned theme term.' );
			$term_id = (int) $created_term['term_id'];
			$owned_terms[$term_id] = true;
		} else { $term_id = (int) $term->term_id; }
		$theme_term_ids[$theme] = $term_id;
	}
	foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
		foreach ( array( $inactive_theme, $current_theme ) as $theme ) {
			$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_name' => $prefix . '-shared', 'post_title' => $prefix, 'post_content' => 'literal-' . $theme . "\n" . $raw_markup ), true, false );
			cybermaps_template_source_assert( is_int( $id ) && $id > 0, 'Could not create owned template.' );
			$owned_ids[] = $id;
			$terms = wp_set_object_terms( $id, array( $theme_term_ids[$theme] ), 'wp_theme' );
			cybermaps_template_source_assert( ! is_wp_error( $terms ), 'Could not assign owned theme membership.' );
			if ( 'wp_template' === $type && $theme === $inactive_theme ) { $inactive_id = $id; }
			if ( 'wp_template' === $type && $theme === $current_theme ) { $current_id = $id; }
		}
	}
	add_filter( 'pre_get_block_templates', $builder_guard );
	add_filter( 'pre_get_block_template', $builder_guard );
	add_filter( 'hooked_block_types', $block_hook_guard );
	$baseline_hooks = cybermaps_template_source_hook_counts();
	$ids = $query_method->invoke( $source, array( 'fields' => 'ids', 'post__in' => $owned_ids, 'posts_per_page' => 1001, 'orderby' => 'ID', 'order' => 'ASC' ), 'wp_template', $current_theme );
	cybermaps_template_source_assert( array( $current_id ) === array_map( 'intval', $ids ), 'Inactive-theme row leaked into current inventory.' );
	cybermaps_template_source_assert( 'literal-' . $current_theme . "\n" . $raw_markup === $source->part( $prefix . '-shared', $current_theme ), 'Same-slug part selected inactive theme.' );
	cybermaps_template_source_assert( $source->complete(), 'Healthy stored source was incomplete.' );
	cybermaps_template_source_assert( $baseline_hooks === cybermaps_template_source_hook_counts(), 'Healthy source leaked scoped query hooks.' );
	cybermaps_template_source_assert( ! empty( $unrelated_sql ), 'Nested unrelated-query control did not execute.' );
	foreach ( $observed_sql as $sql ) { cybermaps_template_source_assert( str_contains( $sql, 'EXISTS' ) && ! str_contains( $sql, 'GROUP BY' ), 'Source did not use scoped membership semi-join.' ); }
	foreach ( $unrelated_sql as $sql ) { cybermaps_template_source_assert( ! str_contains( $sql, 'cybermaps_theme_membership' ), 'Scoped predicate leaked into another query.' ); }
	$result['current_theme_only'] = true;
	$result['raw_same_slug_part'] = true;
	$result['unrelated_query_control'] = true;
	$result['source_sql'] = $observed_sql;

	$fault_count = 0;
	$fault_hook = static function ( string $sql ) use ( &$fault_count ): string {
		if ( str_contains( $sql, 'cybermaps_theme_membership' ) ) { ++$fault_count; return 'SELECT * FROM cybermaps_missing_stored_template_table'; }
		return $sql;
	};
	add_filter( 'query', $fault_hook );
	add_filter( 'posts_results', $late_error_clear, 10, 2 );
	$fault_baseline_hooks = cybermaps_template_source_hook_counts();
	$failed_source = new \Cybermaps\Audit\StoredTemplateSource();
	$rows = $query_method->invoke( $failed_source, array( 'post__in' => array( $current_id ), 'posts_per_page' => 1 ), 'wp_template', $current_theme );
	cybermaps_template_source_assert( 1 === $fault_count && array() === $rows && ! $failed_source->complete(), 'Failed body SELECT did not remain incomplete.' );
	remove_filter( 'query', $fault_hook ); $fault_hook = null;
	cybermaps_template_source_assert( false !== has_filter( 'posts_request', $request_observer ) && false !== has_filter( 'posts_results', $late_error_clear ), 'Source removed ordinary framework filters.' );
	cybermaps_template_source_assert( $fault_baseline_hooks === cybermaps_template_source_hook_counts(), 'Failed source leaked scoped query hooks.' );
	$result['scoped_hooks_restored'] = true;
	$result['body_fault_selects'] = $fault_count;
	$result['body_failure_incomplete'] = true;
	$term_faults = 0;
	$fault_hook = static function ( string $sql ) use ( &$term_faults ): string {
		global $wpdb;
		if ( preg_match( '/^\s*SELECT\b/i', $sql ) && str_contains( $sql, 'FROM ' . $wpdb->terms ) ) {
			++$term_faults;
			return 'SELECT * FROM cybermaps_missing_stored_theme_term';
		}
		return $sql;
	};
	add_filter( 'query', $fault_hook );
	$failed_term_source = new \Cybermaps\Audit\StoredTemplateSource();
	$rows = $query_method->invoke( $failed_term_source, array( 'fields' => 'ids', 'post__in' => $owned_ids, 'posts_per_page' => 1001 ), 'wp_template', $current_theme );
	cybermaps_template_source_assert( 1 === $term_faults && array() === $rows && ! $failed_term_source->complete(), 'Failed theme lookup appeared complete.' );
	remove_filter( 'query', $fault_hook ); $fault_hook = null;
	$result['term_fault_selects'] = $term_faults;
	$result['term_failure_incomplete'] = true;
	cybermaps_template_source_assert( $fault_baseline_hooks === cybermaps_template_source_hook_counts(), 'Failed term lookup leaked scoped hooks.' );
	cybermaps_template_source_assert( 0 === $builder_calls, 'Source built or rendered a template.' );
	cybermaps_template_source_assert( 0 === $render_calls && 0 === $block_hook_calls, 'Raw source executed block rendering or block hooks.' );
	$result['builder_calls'] = $builder_calls;
	$result['render_calls'] = $render_calls;
	$result['block_hook_calls'] = $block_hook_calls;
	$result['raw_dynamic_markup_unchanged'] = true;
} finally {
	if ( null !== $fault_hook ) { remove_filter( 'query', $fault_hook ); }
	remove_filter( 'posts_request', $request_observer, 10 );
	remove_filter( 'posts_results', $late_error_clear, 10 );
	remove_filter( 'pre_wp_unique_post_slug', $slug_override, 10 );
	remove_filter( 'pre_get_block_templates', $builder_guard );
	remove_filter( 'pre_get_block_template', $builder_guard );
	remove_filter( 'hooked_block_types', $block_hook_guard );
	$cleanup_failed = false;
	try {
		foreach ( $owned_ids as $id ) {
			try {
				wp_delete_post( $id, true );
				$absent = cybermaps_template_source_absent_row( $wpdb->posts, 'ID', $id );
				$cleanup_failed = ! $absent || $cleanup_failed;
				$verified_posts += (int) $absent;
			}
			catch ( Throwable ) { $cleanup_failed = true; }
		}
		foreach ( array_keys( $owned_terms ) as $id ) {
			try {
				wp_delete_term( $id, 'wp_theme' );
				$absent_term = cybermaps_template_source_absent_row( $wpdb->terms, 'term_id', $id );
				$absent_taxonomy = cybermaps_template_source_absent_row( $wpdb->term_taxonomy, 'term_id', $id );
				$absent = $absent_term && $absent_taxonomy;
				$cleanup_failed = ! $absent || $cleanup_failed;
				$verified_terms += (int) $absent;
			}
			catch ( Throwable ) { $cleanup_failed = true; }
		}
		if ( $block_registered ) {
			unregister_block_type( $block_name );
			$cleanup_failed = WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) || $cleanup_failed;
		}
		cybermaps_template_source_assert( ! $cleanup_failed, 'Owned stored-template fixture cleanup failed.' );
	} finally { $wpdb->suppress_errors( $prior_error_suppression ); }
}
$result['owned_posts_deleted'] = $verified_posts;
$result['owned_terms_deleted'] = $verified_terms;
$result['dynamic_block_unregistered'] = true;
$result['stored_template_source_passed'] = true;
echo wp_json_encode( $result ) . "\n";
