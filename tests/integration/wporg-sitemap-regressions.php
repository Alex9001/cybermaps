<?php
declare(strict_types=1);

/** Run through WP-CLI only inside the disposable real WordPress runtime. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'CYBERMAPS_VERSION' ) ) {
	throw new RuntimeException( 'Run in the disposable Cybermaps WordPress validation runtime.' );
}

function cybermaps_sitemap_regression_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$original_options = array();
foreach ( array( 'cybermaps_settings', 'cybermaps_discovery_center', 'timezone_string', 'blog_public' ) as $name ) {
	$original_options[ $name ] = get_option( $name );
}
$had_global_post = array_key_exists( 'post', $GLOBALS );
$original_post = $GLOBALS['post'] ?? null;
$created_ids = array();
$block_http = static fn() => new WP_Error( 'fixture_http_blocked', 'External HTTP disabled by sitemap fixture.' );
add_filter( 'pre_http_request', $block_http );

try {
	update_option( 'cybermaps_settings', array( 'static_engine_mode' => 'off', 'enable_caching' => '0', 'include_archives' => '1', 'rss_sitemap_types' => array( 'post' ) ) );
	update_option( 'blog_public', '1' );
	\Cybermaps\Core\ConfigurationStore::reset_memo();
	foreach ( array( 'First queried RSS post', 'Second queried RSS post', 'Unrelated ambient post' ) as $title ) {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => 'Excerpt for ' . $title, 'post_content' => 'Content for ' . $title ), true );
		cybermaps_sitemap_regression_assert( is_int( $id ) && $id > 0, 'Could not create RSS fixture.' );
		$created_ids[] = $id;
	}
	$rows = array( get_post( $created_ids[0] ), get_post( $created_ids[1] ) );
	$ambient = get_post( $created_ids[2] );
	$render = new ReflectionMethod( \Cybermaps\Sitemap\RSSProvider::class, 'render_rss' );
	foreach ( array( 'absent', 'unrelated' ) as $context ) {
		if ( 'absent' === $context ) {
			unset( $GLOBALS['post'] );
		} else {
			$GLOBALS['post'] = $ambient;
		}
		$xml = $render->invoke( new \Cybermaps\Sitemap\RSSProvider(), $rows );
		$document = new DOMDocument();
		cybermaps_sitemap_regression_assert( $document->loadXML( $xml, LIBXML_NONET ), 'RSS was not valid XML.' );
		$items = $document->getElementsByTagName( 'item' );
		cybermaps_sitemap_regression_assert( 2 === $items->length, 'RSS lost a queried item.' );
		foreach ( $rows as $index => $row ) {
			$item = $items->item( $index );
			foreach ( array( 'title' => $row->post_title, 'description' => $row->post_excerpt, 'link' => get_permalink( $row ), 'guid' => get_permalink( $row ) ) as $tag => $expected ) {
				cybermaps_sitemap_regression_assert( $expected === $item->getElementsByTagName( $tag )->item( 0 )->textContent, 'RSS used ambient data: ' . $context . '/' . $tag );
			}
		}
		cybermaps_sitemap_regression_assert( 'absent' === $context ? ! array_key_exists( 'post', $GLOBALS ) : $GLOBALS['post'] === $ambient, 'RSS altered caller post context.' );
	}

	// Exercise native wpdb's failed-SELECT empty-array contract without native
	// query-cache poisoning. Only the first candidate SELECT is replaced.
	$settings = get_option( 'cybermaps_settings' );
	$settings['enable_caching'] = '1';
	$settings['enable_shortcode'] = '1';
	update_option( 'cybermaps_settings', $settings );
	\Cybermaps\Core\ConfigurationStore::reset_memo();
	\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
	$fail_next_candidate = true;
	$inject_failure = static function ( string $sql ) use ( &$fail_next_candidate ): string {
		global $wpdb;
		if ( $fail_next_candidate && preg_match( '/^\s*SELECT\b/i', $sql ) && str_contains( $sql, $wpdb->posts ) ) {
			$fail_next_candidate = false;
			return 'SELECT * FROM cybermaps_regression_missing_candidate_table';
		}
		return $sql;
	};
	$clear_later_error = static function ( array $posts ): array {
		$GLOBALS['wpdb']->last_error = '';
		return $posts;
	};
	add_filter( 'query', $inject_failure );
	add_filter( 'posts_results', $clear_later_error );
	$previous_suppression = $GLOBALS['wpdb']->suppress_errors( true );
	try {
		try {
			( new \Cybermaps\Sitemap\RSSProvider() )->generate();
			throw new RuntimeException( 'Failed native SELECT produced complete RSS.' );
		} catch ( \Cybermaps\Core\BuildUnavailableException ) {
			cybermaps_sitemap_regression_assert( false === \Cybermaps\Core\CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ), 'Failed RSS was cached.' );
		}
		$healthy_rss = ( new \Cybermaps\Sitemap\RSSProvider() )->generate();
		cybermaps_sitemap_regression_assert( str_contains( $healthy_rss, 'First queried RSS post' ), 'Healthy RSS retry inherited cached empty rows.' );
		cybermaps_sitemap_regression_assert( $healthy_rss === \Cybermaps\Core\CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ), 'Healthy RSS retry did not cache.' );
		$fail_next_candidate = true;
		$failed_html = ( new \Cybermaps\Sitemap\ShortcodeHandler() )->render_shortcode( array( 'only' => 'post' ) );
		cybermaps_sitemap_regression_assert( str_contains( $failed_html, 'temporarily unavailable' ) && ! str_contains( $failed_html, 'No pages found' ), 'Failed shortcode claimed empty inventory.' );
		$healthy_html = ( new \Cybermaps\Sitemap\ShortcodeHandler() )->render_shortcode( array( 'only' => 'post' ) );
		cybermaps_sitemap_regression_assert( str_contains( $healthy_html, 'First queried RSS post' ), 'Healthy shortcode retry inherited cached empty rows.' );
		cybermaps_sitemap_regression_assert( false !== has_filter( 'posts_results', $clear_later_error ), 'Publication guard removed an ordinary result filter.' );
	} finally {
		remove_filter( 'query', $inject_failure );
		remove_filter( 'posts_results', $clear_later_error );
		$GLOBALS['wpdb']->suppress_errors( $previous_suppression );
	}

	// An option read during rendering can publish a privacy change after selection.
	\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
	\Cybermaps\Core\CacheManager::reset_runtime();
	$privacy_changed = false;
	$make_private_during_render = static function ( mixed $name ) use ( $created_ids, &$privacy_changed ): mixed {
		if ( $privacy_changed ) {
			return $name;
		}
		$privacy_changed = true;
		$result = wp_update_post( array( 'ID' => $created_ids[0], 'post_status' => 'private' ), true );
		cybermaps_sitemap_regression_assert( ! is_wp_error( $result ), 'Could not privatize RSS fixture.' );
		\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
		return $name;
	};
	add_filter( 'option_blogname', $make_private_during_render );
	try {
		try {
			( new \Cybermaps\Sitemap\RSSProvider() )->generate();
			throw new RuntimeException( 'RSS returned content selected before a native privacy change.' );
		} catch ( \Cybermaps\Core\BuildUnavailableException ) {
			cybermaps_sitemap_regression_assert( false === \Cybermaps\Core\CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ), 'Privacy-invalidated RSS was cached as current.' );
		}
	} finally {
		remove_filter( 'option_blogname', $make_private_during_render );
	}
	set_transient( 'cybermaps_rss_sitemap', '<rss>First queried RSS post private legacy bytes</rss>', HOUR_IN_SECONDS );
	$privacy_safe_rss = ( new \Cybermaps\Sitemap\RSSProvider() )->generate();
	cybermaps_sitemap_regression_assert( ! str_contains( $privacy_safe_rss, 'First queried RSS post' ), 'Late legacy cache replayed private RSS.' );
	wp_update_post( array( 'ID' => $created_ids[0], 'post_status' => 'publish' ) );

	// A native settings update can precede an already-stale request memo/cache.
	$publication_settings = get_option( 'cybermaps_settings' );
	$updated_settings = $publication_settings;
	$updated_settings['exclude_post_ids'] = (string) $created_ids[0];
	\Cybermaps\Core\CacheManager::reset_runtime();
	update_option( 'cybermaps_settings', $updated_settings );
	update_option( 'cybermaps_discovery_center', '{"disabled":{"post_type:post":true}}' );
	\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
	\Cybermaps\Core\CacheManager::reset_runtime();
	( new ReflectionProperty( \Cybermaps\Core\ConfigurationStore::class, 'settings_memo' ) )->setValue( null, $publication_settings );
	( new ReflectionProperty( \Cybermaps\Core\ConfigurationStore::class, 'discovery_memo' ) )->setValue( null, array( 'disabled' => array() ) );
	$stale_alloptions = wp_load_alloptions();
	$stale_alloptions['cybermaps_settings'] = maybe_serialize( $publication_settings );
	$stale_alloptions['cybermaps_discovery_center'] = '{"disabled":{}}';
	wp_cache_set( 'alloptions', $stale_alloptions, 'options' );
	$native_option_filter = static fn( array $value ): array => array_merge( $value, array( 'publication_filter_marker' => true ) );
	add_filter( 'option_cybermaps_settings', $native_option_filter );
	try {
		$fresh_settings = \Cybermaps\Core\ConfigurationStore::publication_settings();
		cybermaps_sitemap_regression_assert( (string) $created_ids[0] === $fresh_settings['exclude_post_ids'] && ! empty( $fresh_settings['publication_filter_marker'] ), 'Authoritative publication snapshot lost current storage or a native option filter.' );
		$excluded_rss = ( new \Cybermaps\Sitemap\RSSProvider() )->generate();
		cybermaps_sitemap_regression_assert( ! str_contains( $excluded_rss, '<item>' ), 'RSS reused a stale settings/Discovery Center memo after native updates.' );
		cybermaps_sitemap_regression_assert( ! empty( \Cybermaps\Core\ConfigurationStore::settings()['publication_filter_marker'] ), 'Publication snapshot did not refresh ordinary production settings memo.' );
	} finally {
		remove_filter( 'option_cybermaps_settings', $native_option_filter );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'cybermaps_settings', 'options' );
		wp_cache_delete( 'cybermaps_discovery_center', 'options' );
		update_option( 'cybermaps_settings', $publication_settings );
		update_option( 'cybermaps_discovery_center', $original_options['cybermaps_discovery_center'] );
		\Cybermaps\Core\ConfigurationStore::reset_memo();
	}

	// These old years isolate month-boundary fixtures from normal sample posts.
	foreach ( array(
		array( 'Pacific/Kiritimati', '2001-01-01 00:30:00', '2000-12-31 10:30:00', 2001, 1 ),
		array( 'America/Los_Angeles', '2001-02-28 23:30:00', '2001-03-01 07:30:00', 2001, 2 ),
	) as $case ) {
		update_option( 'timezone_string', $case[0] );
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Local month boundary', 'post_date' => $case[1], 'post_date_gmt' => $case[2] ), true );
		cybermaps_sitemap_regression_assert( is_int( $id ) && $id > 0, 'Could not create archive fixture.' );
		$created_ids[] = $id;
		\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
		$archives = ( new \Cybermaps\Sitemap\EligibleContentRepository() )->get_archive_page( 1, 2000 );
		$months = array_map( static fn( array $row ): string => $row['year'] . '-' . $row['month'], $archives );
		cybermaps_sitemap_regression_assert( in_array( $case[3] . '-' . $case[4], $months, true ), 'Inventory omitted local month: ' . $case[0] );
		$query = new WP_Query( array( 'year' => $case[3], 'monthnum' => $case[4], 'post__in' => array( $id ), 'post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids', 'no_found_rows' => true ) );
		cybermaps_sitemap_regression_assert( array( $id ) === $query->posts, 'Inventory disagreed with real WordPress month query.' );
	}
} finally {
	foreach ( $created_ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $original_options as $name => $value ) {
		false === $value ? delete_option( $name ) : update_option( $name, $value );
	}
	\Cybermaps\Core\ConfigurationStore::reset_memo();
	if ( $had_global_post ) {
		$GLOBALS['post'] = $original_post;
	} else {
		unset( $GLOBALS['post'] );
	}
	remove_filter( 'pre_http_request', $block_http );
}

echo "Real WordPress RSS ambient-post, failed-candidate retry, privacy generation fence, and UTC+/UTC- local-month regressions passed.\n";
