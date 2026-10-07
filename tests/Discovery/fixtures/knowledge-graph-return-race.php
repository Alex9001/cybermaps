<?php
declare(strict_types=1);
namespace Cybermaps\Discovery {
 function get_bloginfo( string $show = '', string $filter = 'raw' ): mixed {
  if ( 'name' === $show && ! empty( $GLOBALS['kg_revoke'] ) ) {
   foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 ) as $frame ) {
    if ( KnowledgeGraph::class === ( $frame['class'] ?? '' ) && 'website_entity' === ( $frame['function'] ?? '' ) ) {
     $GLOBALS['kg_revoke'] = false;
     $GLOBALS['kg_fired'] = true;
     $GLOBALS['cybermaps_mock_posts'][101]->post_status = 'private';
     \Cybermaps\Core\CacheManager::clear_family( 'discovery' );
     break;
    }
   }
  }
  return \get_bloginfo( $show, $filter );
 }
}
namespace {
 require dirname( __DIR__, 2 ) . '/bootstrap.php';
 $scenario = $argv[1] ?? 'website';
 $GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'enable_discovery_hub' => '1' ), 'cybermaps_identity_data' => array( 'name' => 'Operator identity', 'catalogs' => array( array( 'mode' => 'auto', 'parent_id' => 100 ) ) ) );
 $GLOBALS['cybermaps_mock_post_types'] = array( 'page' );
 $GLOBALS['cybermaps_mock_post_type_objects'] = array( 'page' => (object) array( 'name' => 'page', 'public' => true, 'publicly_queryable' => true ) );
 $GLOBALS['cybermaps_mock_taxonomies'] = array();
 $GLOBALS['cybermaps_mock_post_meta'] = array();
 $GLOBALS['cybermaps_mock_posts'] = array();
 foreach ( array( 100 => 'Derived parent', 101 => 'Private derived child' ) as $id => $title ) {
  $GLOBALS['cybermaps_mock_posts'][$id] = (object) array( 'ID' => $id, 'post_parent' => 100 === $id ? 0 : 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'post_title' => $title );
 }
 $GLOBALS['cybermaps_mock_pages'] = array( $GLOBALS['cybermaps_mock_posts'][101] );
 \cybermaps_mock_reset_cache_runtime();
 $before = \Cybermaps\Core\CacheManager::get_generation( 'discovery', true );
 $GLOBALS['kg_revoke'] = 'website' === $scenario; $GLOBALS['kg_fired'] = false; $output = ''; $blocked = false;
 $revoke = static function (): void {
  $GLOBALS['kg_fired'] = true;
  $GLOBALS['cybermaps_mock_posts'][101]->post_status = 'private';
  \Cybermaps\Core\CacheManager::clear_family( 'discovery' );
 };
 if ( in_array( $scenario, array( 'settings', 'discovery' ), true ) ) {
  $option = 'settings' === $scenario ? 'cybermaps_settings' : 'cybermaps_discovery_center';
  $GLOBALS['cybermaps_mock_filter_callbacks']['pre_option_' . $option][] = static function ( mixed $pre ) use ( $revoke ): mixed { $revoke(); return $pre; };
 }
 if ( 'filter' === $scenario ) {
  $GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_knowledge_graph_data'][] = static function ( array $data ) use ( $revoke ): array { $revoke(); return $data; };
 }
 if ( 'serialization' === $scenario ) {
  $GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_knowledge_graph_data'][] = static function ( array $data ) use ( $revoke ): array {
   $data['fixture'] = new class( $revoke ) implements \JsonSerializable {
    public function __construct( private \Closure $revoke ) {}
    public function jsonSerialize(): mixed { ( $this->revoke )(); return 'serialized'; }
   };
   return $data;
  };
 }
 try { $output = ( new \Cybermaps\Discovery\KnowledgeGraph() )->get_json_content(); }
 catch ( \Cybermaps\Core\BuildUnavailableException ) { $blocked = true; }
 echo json_encode( array( 'fired' => $GLOBALS['kg_fired'], 'before' => $before, 'after' => \Cybermaps\Core\CacheManager::get_generation( 'discovery', true ), 'post_status' => $GLOBALS['cybermaps_mock_posts'][101]->post_status, 'blocked' => $blocked, 'returned_private_offer' => str_contains( $output, 'Private derived child' ) ), JSON_PRETTY_PRINT ), "\n";
}
