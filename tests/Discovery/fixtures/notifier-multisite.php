<?php
declare(strict_types=1);

require dirname( __DIR__, 2 ) . '/bootstrap.php';
require dirname( __DIR__ ) . '/IndexNowTest.php';

use Cybermaps\Core\DiagnosticLogger;
use Cybermaps\Discovery\IndexNow;
use Cybermaps\Discovery\PublicationNotifier;
use Cybermaps\Discovery\WebSub;

$scenario = $argv[1] ?? 'origins';
$GLOBALS['cybermaps_mock_is_multisite'] = true;
$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
$GLOBALS['cybermaps_mock_blog_stack'] = array();
$GLOBALS['cybermaps_mock_safe_remote_post_calls'] = array();
$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
$GLOBALS['cybermaps_mock_post_meta'] = array();
$databases = array();
$posts = array();
$urls = array();
foreach ( array( 1 => 'one', 2 => 'two' ) as $blog_id => $name ) {
	$database = new \Cybermaps\Tests\Discovery\IndexNowQueueTestWpdb();
	$database->prefix = 1 === $blog_id ? 'wp_' : 'wp_2_';
	$database->options = $database->prefix . 'options';
	$databases[ $blog_id ] = $database;
	$posts[ $blog_id ] = array( 7 => (object) array( 'ID' => 7, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '' ) );
	$urls[ $blog_id ] = array( 7 => 'https://site-' . $name . '.example/old/' );
	$GLOBALS['cybermaps_mock_options_by_blog'][ $blog_id ] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'enable_indexnow' => '1', 'enable_websub' => 2 === $blog_id ? '1' : '0', 'frontend_base_url' => 'https://front-' . $name . '.example', 'websub_hubs' => 'https://hub-' . $name . '.example/' ) );
}
// Native switch_to_blog changes table/cache namespaces. This small adapter selects
// each site's stored rows at settings reads; notification services/queues are real.
$activate = static function ( int $blog_id ) use ( &$posts, &$urls, $databases ): void {
	$GLOBALS['wpdb'] = $databases[ $blog_id ];
	$GLOBALS['cybermaps_mock_posts'] = $posts[ $blog_id ];
	$GLOBALS['cybermaps_mock_permalinks'] = $urls[ $blog_id ];
	$GLOBALS['cybermaps_mock_home_url'] = 1 === $blog_id ? 'https://site-one.example' : 'https://site-two.example';
};
$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $key ) use ( $activate ): void {
	if ( 'cybermaps_settings' === $key ) { $activate( get_current_blog_id() ); }
};
$activate( 1 );
$notifier = new PublicationNotifier();
if ( in_array( $scenario, array( 'overflow', 'overflow-disabled' ), true ) ) {
	$spy = new class extends IndexNow {
		public int $count = 0;
		public function __construct() {}
		public function notify_url( string $url ): void { ++$this->count; }
	};
	$websub = new class extends WebSub { public function __construct() {} public function notify_change(): void {} };
	$notifier = new PublicationNotifier( $spy, $websub );
	if ( 'overflow' === $scenario ) { DiagnosticLogger::enable(); }
	foreach ( range( 1, 1001 ) as $id ) {
		$posts[1][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '' );
		$activate( 1 );
		$notifier->capture_before_post_write( $id );
	}
	$pending_count = ( new ReflectionProperty( PublicationNotifier::class, 'pending_count' ) )->getValue( $notifier );
	$before_flush_count = $spy->count;
	$notifier->flush();
	$entries = get_option( DiagnosticLogger::ENTRIES_OPTION, array() );
	echo json_encode( array( 'pending_count' => $pending_count, 'before_flush_count' => $before_flush_count, 'after_flush_count' => $spy->count, 'diagnostic' => end( $entries ) ) );
	exit;
}
if ( 'failure' === $scenario ) {
	$throwing = new class extends IndexNow {
		public function __construct() {}
		public function notify_url( string $url ): void { throw new RuntimeException( 'Injected notification failure' ); }
	};
	$notifier = new PublicationNotifier( $throwing );
}
switch_to_blog( 2 );
$activate( 2 );
$notifier->capture_before_post_write( 7 );
$urls[2][7] = 'https://site-two.example/new/';
restore_current_blog();
$activate( 1 );
if ( 'origins' === $scenario ) {
	$notifier->capture_before_post_write( 7 );
	$urls[1][7] = 'https://site-one.example/new/';
}
if ( 'disabled' === $scenario ) {
	$GLOBALS['cybermaps_mock_options_by_blog'][2]['cybermaps_settings']['enable_indexnow'] = '0';
	$GLOBALS['cybermaps_mock_options_by_blog'][2]['cybermaps_settings']['enable_websub'] = '0';
}
$error = '';
try { $notifier->flush(); } catch ( RuntimeException $exception ) { $error = $exception->getMessage(); }
echo json_encode( array( 'queues' => array( 1 => array_column( $databases[1]->rows, 'url' ), 2 => array_column( $databases[2]->rows, 'url' ) ), 'websub' => $GLOBALS['cybermaps_mock_safe_remote_post_calls'], 'current_blog' => get_current_blog_id(), 'blog_stack' => $GLOBALS['cybermaps_mock_blog_stack'], 'error' => $error ) );
