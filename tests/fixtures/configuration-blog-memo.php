<?php
declare(strict_types=1);

// Exercise the production memo path without the PHPUnit bootstrap constant.
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/src/Core/ConfigurationStore.php';

$site = 1;
$hooks = array();
function add_action( string $hook, callable $callback, int $priority, int $accepted_args ): void {
	$GLOBALS['hooks'][ $hook ][] = $callback;
}
function get_option( string $option, mixed $default = false ): mixed {
	$value = array( 'site' => $GLOBALS['site'] );
	return 'cybermaps_discovery_center' === $option ? json_encode( $value ) : $value;
}
function switch_site( int $site ): void {
	$GLOBALS['site'] = $site;
	foreach ( $GLOBALS['hooks']['switch_blog'] ?? array() as $callback ) {
		$callback();
	}
}
function read_roots(): array {
	return array_map(
		static fn( string $root ): array => \Cybermaps\Core\ConfigurationStore::$root(),
		array( 'settings', 'discovery', 'robots', 'identity' )
	);
}
\Cybermaps\Core\ConfigurationStore::register_hooks();
$results = array( read_roots() );
switch_site( 2 );
$results[] = read_roots();
switch_site( 1 );
$results[] = read_roots();
echo json_encode( $results, JSON_THROW_ON_ERROR );
