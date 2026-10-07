<?php
declare(strict_types=1);

// Keep production memo/SQL branches active in this isolated process.
define( 'CYBERMAPS_PHPUNIT', false );
require dirname( __DIR__ ) . '/bootstrap.php';

use Cybermaps\Core\Plugin;
use Cybermaps\Integration\EdgeCache\Coordinator;

cybermaps_mock_reset_cache_runtime();
$GLOBALS['cybermaps_mock_is_multisite'] = true;
$GLOBALS['cybermaps_mock_options_by_blog'] = array( 1 => array(), 2 => array() );
cybermaps_mock_enable_static_ownership_database( true );
( new ReflectionProperty( Plugin::class, 'edge_cache_coordinator' ) )->setValue( null, new Coordinator() );
$counts = array();
Plugin::on_cache_family_invalidated( 'chunks', 1 );
Plugin::on_cache_family_invalidated( 'chunks', 1 );
$counts[] = count( ( new Coordinator() )->get_status() );
switch_to_blog( 2 );
Plugin::on_cache_family_invalidated( 'chunks', 1 );
Plugin::on_cache_family_invalidated( 'chunks', 1 );
$counts[] = count( ( new Coordinator() )->get_status() );
restore_current_blog();
Plugin::on_cache_family_invalidated( 'chunks', 1 );
$counts[] = count( ( new Coordinator() )->get_status() );
Plugin::on_cache_family_invalidated( 'chunks', 2 );
$counts[] = count( ( new Coordinator() )->get_status() );
Plugin::on_cache_family_invalidated( 'sitemap', 2 );
Plugin::on_cache_family_invalidated( 'sitemap', 2 );
$counts[] = count( ( new Coordinator() )->get_status() );
echo json_encode( $counts, JSON_THROW_ON_ERROR );
