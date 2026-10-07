<?php
declare(strict_types=1);

if ( 'cli-server' !== PHP_SAPI ) { exit; }
require dirname( __DIR__, 2 ) . '/bootstrap.php';
function get_queried_object(): mixed { return get_post( 7 ); }
cybermaps_mock_reset_cache_runtime();
$GLOBALS['cybermaps_mock_status_headers'] = array( 200 );
$GLOBALS['cybermaps_mock_is_singular'] = true;
$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'enable_markdown_negotiation' => '1', 'enable_llms_full' => '1', 'enable_rag_chunks' => '1', 'enable_content_hints' => '0', 'llms_included_types' => array( 'post' ), 'ai_sitemap_types' => array( 'post' ), 'frontend_base_url' => 'https://example.com' ) );
$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
$GLOBALS['cybermaps_mock_posts'] = array( 7 => (object) array( 'ID' => 7, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Guide', 'post_excerpt' => '', 'post_content' => '<p>REVOKED_MARKDOWN_BODY</p>', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
$GLOBALS['cybermaps_mock_permalinks'] = array( 7 => 'https://example.com/guides/setup/' );
$GLOBALS['cybermaps_mock_url_to_postid'] = array( 'https://example.com/guides/setup/' => 7 );
$revoke = static function ( mixed $value ): mixed {
	$GLOBALS['cybermaps_mock_posts'][7]->post_status = 'private';
	\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
	return $value;
};
$hook = match ( $_GET['stage'] ?? 'eligibility' ) {
	'policy' => 'cybermaps_publication_cache_policy',
	'encoding' => 'cybermaps_publication_final_content_encoding',
	default => 'cybermaps_publication_eligibility',
};
if ( 'unavailable' === ( $_GET['stage'] ?? '' ) ) {
	$GLOBALS['cybermaps_mock_options']['cybermaps_cache_generation_discovery'] = '-1';
} elseif ( ! str_starts_with( (string) ( $_GET['stage'] ?? '' ), 'healthy' ) ) {
	$GLOBALS['cybermaps_mock_filter_callbacks'][ $hook ] = array( $revoke );
}
ob_start();
register_shutdown_function( static function (): void {
	http_response_code( end( $GLOBALS['cybermaps_mock_status_headers'] ) ?: 200 );
	ob_end_flush();
} );
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( in_array( $path, array( '/llms.txt', '/llms-full.txt' ), true ) ) {
	( new \Cybermaps\Discovery\LLMS() )->handle();
} elseif ( '/discovery/chunks/7.json' === $path ) {
	\Cybermaps\Core\CacheManager::put( 'cybermaps_ai_publication_inventory', array( 7 ), 900, 'discovery' );
	( new \Cybermaps\Discovery\RAGChunk() )->handle();
} elseif ( '/updates.json' === $path ) {
	( new \Cybermaps\Discovery\Updates() )->handle();
} elseif ( str_contains( $_SERVER['REQUEST_URI'], 'index.md' ) ) {
	( new \Cybermaps\Discovery\MarkdownAlternate() )->handle();
} else {
	( new \Cybermaps\Discovery\MarkdownNegotiation() )->handle();
}
echo 'Ordinary HTML fallthrough';
