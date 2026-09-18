<?php

if ( ! defined( 'ABSPATH' ) || ! defined( 'CYBERMAPS_VERSION' ) ) {
	throw new RuntimeException( 'Cybermaps was not loaded by WordPress.' );
}

$settings                         = (array) get_option( 'cybermaps_settings', array() );
$settings['enable_discovery_hub'] = '1';
$settings['report_theme']         = 'midnight';
update_option( 'cybermaps_settings', $settings, false );
\Cybermaps\Core\ConfigurationStore::reset_memo();

$openapi = ( new \Cybermaps\Discovery\OpenAPI() )->get_document();
if ( '3.2.0' !== ( $openapi['openapi'] ?? '' ) || empty( $openapi['paths'] ) ) {
	throw new RuntimeException( 'OpenAPI endpoint payload smoke test failed.' );
}

$feed = json_decode( ( new \Cybermaps\Discovery\Feed() )->get_json_content(), true, 512, JSON_THROW_ON_ERROR );
if ( 'https://jsonfeed.org/version/1.1' !== ( $feed['version'] ?? '' ) ) {
	throw new RuntimeException( 'JSON Feed endpoint payload smoke test failed.' );
}

$xml = ( new \Cybermaps\Discovery\AISitemap() )->get_content();
if ( ! str_contains( $xml, '<urlset' ) || ! str_ends_with( $xml, '</urlset>' ) ) {
	throw new RuntimeException( 'AI sitemap endpoint payload smoke test failed.' );
}
\Cybermaps\Core\ProtocolOutput::xml( $xml );

$report = ( new \Cybermaps\Audit\AuditExporter() )->html(
	array(
		'id'             => 1,
		'completed_gmt'  => gmdate( 'Y-m-d H:i:s' ),
		'resource_count' => 0,
		'finding_count'  => 0,
		'findings'       => array(),
	)
);
if (
	! str_contains( $report, 'assets/css/report.css' )
	|| ! str_contains( $report, 'cm-report-theme-midnight' )
	|| str_contains( strtolower( $report ), '<style' )
) {
	throw new RuntimeException( 'Standalone report stylesheet smoke test failed.' );
}

echo "Cybermaps endpoint and report smoke tests passed.\n";
