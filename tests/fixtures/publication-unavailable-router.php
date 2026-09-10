<?php
/** Local HTTP fixture for publication failures; excluded from release packages. */
declare(strict_types=1);

if ( PHP_SAPI !== 'cli-server' ) {
	http_response_code( 404 );
	exit;
}
$bootstrap = getenv( 'CYBERMAPS_TEST_WP_BOOTSTRAP' );
if ( ! is_string( $bootstrap ) || ! is_file( $bootstrap ) || ! str_starts_with( realpath( $bootstrap ), sys_get_temp_dir() . '/' ) ) {
	throw new RuntimeException( 'A disposable WordPress installation is required.' );
}
require $bootstrap;
require_once ( getenv( 'CYBERMAPS_TEST_PLUGIN' ) ?: dirname( __DIR__, 2 ) ) . '/cybermaps.php';
add_filter( 'pre_option_cybermaps_settings', static fn(): array => array( 'enable_discovery_hub' => '1', 'enable_llms_tldr' => '1', 'enable_llms_full' => '1', 'llms_included_types' => array( 'post' ) ) );
Cybermaps\Core\CacheManager::clear_family( 'discovery' );
add_action( 'pre_get_posts', static function (): never {
	throw new Cybermaps\Core\BuildUnavailableException( 'Publication is being rebuilt.' );
} );
if ( '/llms-tldr.txt' === $_SERVER['REQUEST_URI'] ) {
	( new Cybermaps\Discovery\LLMSTLDR() )->handle();
} else {
	( new Cybermaps\Discovery\LLMS() )->handle();
}
http_response_code( 404 );
