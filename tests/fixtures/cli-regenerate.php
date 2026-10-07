<?php
declare(strict_types=1);

require dirname( __DIR__ ) . '/bootstrap.php';

final class WP_CLI {
	public static function line( string $message ): void {}
	public static function success( string $message ): void {}
	public static function error( string $message ): never {
		throw new RuntimeException( $message );
	}
}

// Multisite skips static filesystem mutation while exercising the complete CLI
// regeneration path. The command must still invalidate its local occupancy.
$GLOBALS['cybermaps_mock_is_multisite'] = true;
$GLOBALS['cybermaps_mock_options'] = array(
	'cybermaps_settings' => array( 'static_engine_mode' => 'off' ),
	\Cybermaps\Sitemap\PageOccupancyManifest::GENERATION_OPTION => 4,
	\Cybermaps\Sitemap\PageOccupancyManifest::TOKEN_OPTION => 'old-token',
	\Cybermaps\Sitemap\PageOccupancyManifest::MANIFEST_OPTION => array(
		'generation' => 4,
		'token' => 'old-token',
		'complete' => true,
		'providers' => array(),
	),
);

( new \Cybermaps\CLI\Command() )->regenerate( array(), array() );
echo json_encode( array(
	'generation' => \Cybermaps\Sitemap\PageOccupancyManifest::current_generation(),
	'manifest' => \Cybermaps\Sitemap\PageOccupancyManifest::load(),
	'token_changed' => 'old-token' !== \Cybermaps\Sitemap\PageOccupancyManifest::current_token(),
	'scheduled' => false !== wp_next_scheduled( \Cybermaps\Sitemap\PageOccupancyBuilder::HOOK ),
) );
