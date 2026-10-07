<?php
declare(strict_types=1);

// Use actual production memo branches, with bounded exact-byte option observations.
define( 'CYBERMAPS_PHPUNIT', false );
require dirname( __DIR__, 2 ) . '/bootstrap.php';

use Cybermaps\Core\CacheManager;
use Cybermaps\Core\ConfigurationStore;

final class PublicationPolicyFixtureDatabase {
	public string $options = 'wp_options';
	public string $last_error = '';
	public array $last_result = array();
	public int $generation = 0;
	public array $settings;
	private CybermapsMockStaticOwnershipDatabase $leases;
	public function __construct() { $this->leases = new CybermapsMockStaticOwnershipDatabase(); }
	public function prepare( string $sql, mixed ...$args ): mixed { return $this->leases->prepare( $sql, ...$args ); }
	public function get_var( mixed $sql ): mixed {
		$this->last_error = '';
		$option = $sql['args'][1] ?? '';
		if ( 'cybermaps_settings' === $option ) { return serialize( $this->settings ); }
		if ( 'cybermaps_discovery_center' === $option ) { return '{"archetype":"blog"}'; }
		if ( str_starts_with( (string) $option, 'cybermaps_cache_generation_' ) ) { return (string) $this->generation; }
		$result = $this->leases->get_var( $sql );
		$this->last_result = $this->leases->last_result;
		$this->last_error = $this->leases->last_error;
		return $result;
	}
	public function query( mixed $sql ): int|false { return $this->leases->query( $sql ); }

}
cybermaps_mock_reset_cache_runtime();
$GLOBALS['wpdb'] = new PublicationPolicyFixtureDatabase();
$settings = array( 'enable_discovery_hub' => '1', 'enable_llms_full' => '1', 'enable_content_hints' => '0', 'llms_included_types' => array( 'post' ), 'ai_sitemap_types' => array( 'post' ) );
$GLOBALS['wpdb']->settings = $settings;
$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => $settings, 'cybermaps_discovery_center' => '{"archetype":"blog"}' );
$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true, 'labels' => (object) array( 'name' => 'Posts' ) ) );
$GLOBALS['cybermaps_mock_posts'] = array( 1 => (object) array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Excluded public title sentinel', 'post_content' => 'Ordinary literal body.', 'post_excerpt' => '', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
$GLOBALS['cybermaps_mock_post_meta'] = array();
$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn(): array => array_values( $GLOBALS['cybermaps_mock_posts'] );
ConfigurationStore::settings();
ConfigurationStore::discovery();
$selector = new \Cybermaps\Discovery\AIContentSelector();
// Another request commits new policy plus generation; this request's options/memo stay old.
$GLOBALS['wpdb']->settings['llms_exclude_ids'] = '1';
$GLOBALS['wpdb']->generation = 1;
if ( 'llms-disabled-route' === ( $argv[1] ?? '' ) ) {
	$GLOBALS['wpdb']->settings['llms_exclude_ids'] = '';
	$GLOBALS['wpdb']->settings['enable_llms_full'] = '0';
	$_SERVER['REQUEST_URI'] = '/llms-full.txt';
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$GLOBALS['cybermaps_mock_status_headers'] = array();
	ob_start();
	register_shutdown_function( static function (): void {
		$body = ob_get_clean();
		echo json_encode( array( 'status' => $GLOBALS['cybermaps_mock_status_headers'], 'body' => $body, 'generation' => CacheManager::get_generation( 'discovery', true ) ) );
	} );
	( new \Cybermaps\Discovery\LLMS() )->handle();
	echo 'fell_through';
	exit;
}
$result = match ( $argv[1] ?? 'llms' ) {
	'selector' => $selector->get_posts(),
	'updates' => ( new \Cybermaps\Discovery\Updates() )->get_updates_data(),
	'search' => ( new \Cybermaps\Discovery\Search() )->handle_search( new class { public function get_param( string $key ): mixed { return 'q' === $key ? 'ordinary' : 2; } } )->get_data(),
	default => ( new \Cybermaps\Discovery\LLMS() )->get_llms_content( true ),
};
echo json_encode( array( 'generation' => CacheManager::get_generation( 'discovery', true ), 'returned_excluded_title' => str_contains( json_encode( $result ), 'Excluded public title sentinel' ), 'current_exclusion' => ConfigurationStore::settings()['llms_exclude_ids'] ?? '' ) );
