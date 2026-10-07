<?php
declare(strict_types=1);
namespace Cybermaps\Discovery {
function get_post($id) {
    if (!empty($GLOBALS['rag_fixture_revoke']) && 'get_chunks' === (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '')) {
        $GLOBALS['rag_fixture_revoke'] = false;
        $GLOBALS['cybermaps_mock_posts'][$id]->post_status = 'private';
        \Cybermaps\Core\CacheManager::clear_family('discovery');
        \Cybermaps\Core\CacheManager::clear_family('chunks');
    }
    return \get_post($id);
}
}
namespace {
require dirname( __DIR__, 2 ) . '/bootstrap.php';
use Cybermaps\Core\CacheManager;
use Cybermaps\Discovery\RAGChunk;
cybermaps_mock_reset_cache_runtime();
$GLOBALS['cybermaps_mock_options'] = ['blog_public'=>'1','cybermaps_settings'=>['enable_discovery_hub'=>'1','ai_sitemap_types'=>['post'],'ai_sitemap_limit'=>2]];
$GLOBALS['cybermaps_mock_post_types'] = ['post'];
$GLOBALS['cybermaps_mock_post_type_objects'] = ['post'=>(object)['name'=>'post','public'=>true]];
$GLOBALS['cybermaps_mock_post_meta'] = [];
$GLOBALS['cybermaps_mock_filter_callbacks'] = [];
$GLOBALS['cybermaps_mock_posts'] = [7=>(object)['ID'=>7,'post_type'=>'post','post_status'=>'publish','post_password'=>'','post_title'=>'Resource','post_content'=>'Private after authorization sentinel.','post_excerpt'=>'','post_date_gmt'=>'2026-10-01 00:00:00','post_modified_gmt'=>'2026-10-01 00:00:00']];
CacheManager::put('cybermaps_ai_publication_inventory', [7], 900, 'discovery');
$before = CacheManager::get_generation('discovery', true);
$GLOBALS['rag_fixture_revoke'] = true;
$blocked = false;
try { $output = (new RAGChunk())->get_content(7); }
catch (\Cybermaps\Core\BuildUnavailableException) { $blocked = true; $output = ''; }
echo wp_json_encode(['before'=>$before,'after'=>CacheManager::get_generation('discovery',true),'status'=>$GLOBALS['cybermaps_mock_posts'][7]->post_status,'blocked'=>$blocked,'returned_private_text'=>str_contains((string)$output,'Private after authorization sentinel.')], JSON_PRETTY_PRINT), "\n";
}
