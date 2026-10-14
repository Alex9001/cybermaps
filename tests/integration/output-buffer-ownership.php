<?php
declare(strict_types=1);

// Run only in the disposable release installation, after multisite conversion.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'CYBERMAPS_BUFFER_FIXTURE' ) ) {
	throw new RuntimeException( 'Disposable buffer fixture opt-in is required.' );
}
require_once __DIR__ . '/output-buffer-cases.php';
$original = array();
foreach ( array( 'cybermaps_settings', 'blog_public', \Cybermaps\Sitemap\PageOccupancyManifest::MANIFEST_OPTION ) as $name ) {
	$original[ $name ] = get_option( $name );
}
$styles_original = $GLOBALS['wp_styles'] ?? null;
$styles_existed = array_key_exists( 'wp_styles', $GLOBALS );
$style_checks = array();
try {
	update_option( 'cybermaps_settings', array( 'enable_caching' => '1', 'static_engine_mode' => 'off' ) );
	update_option( 'blog_public', '1' );
	\Cybermaps\Core\ConfigurationStore::reset_memo();
	$cases = cybermaps_buffer_cases( ABSPATH . 'wp-load.php', home_url() );
	$GLOBALS['wp_styles'] = new WP_Styles();
	wp_enqueue_style( 'cybermaps-report', CYBERMAPS_PLUGIN_URL . 'assets/css/report.css', array(), CYBERMAPS_VERSION );
	wp_register_style( 'cybermaps-buffer-dependency', home_url( '/fixture-dependency.css' ), array(), '1' );
	wp_styles()->registered['cybermaps-report']->deps = array( 'cybermaps-buffer-dependency' );
	wp_enqueue_style( 'cybermaps-buffer-unrelated', home_url( '/unrelated.css' ) );
	wp_styles()->done = array( 'cybermaps-report', 'cybermaps-buffer-dependency' );
	wp_styles()->to_do = array( 'cybermaps-buffer-unrelated' );
	wp_styles()->groups = array( 'cybermaps-report' => 1 );
	wp_styles()->print_html = 'untouched';
	$before_styles = serialize( wp_styles() );
	$source_filter = static fn( $src, $handle ) => 'cybermaps-report' === $handle ? add_query_arg( 'buffer-test', '1', $src ) : $src;
	$tag_filter = static fn( $tag, $handle ) => 'cybermaps-report' === $handle ? str_replace( '<link ', '<link data-buffer-test="1" ', $tag ) : $tag;
	add_filter( 'style_loader_src', $source_filter, 10, 2 );
	add_filter( 'style_loader_tag', $tag_filter, 10, 2 );
	$level = ob_get_level();
	ob_start();
	echo 'caller-prefix|';
	try {
		$first = \Cybermaps\Audit\ReportPresentation::stylesheet_markup();
		$second = \Cybermaps\Audit\ReportPresentation::stylesheet_markup();
		if ( $first !== $second || ! str_contains( $first, 'report.css?ver=' . CYBERMAPS_VERSION ) || ! str_contains( $first, 'buffer-test=1' ) || ! str_contains( $first, 'data-buffer-test="1"' ) || ! str_contains( $first, 'fixture-dependency.css' ) || str_contains( $first, 'unrelated.css' ) ) { throw new RuntimeException( 'Native stylesheet accumulation/filter/dependency/repeat failure.' ); }
		$style_checks[] = 'native-accumulation-filters-dependencies-repeat';
		$exports = array( ( new \Cybermaps\Audit\AuditExporter() )->html( array() ), ( new \Cybermaps\Audit\DiscoveryReportExporter() )->html( array() ) );
		foreach ( $exports as $html ) {
			if ( ! str_contains( $html, '<!doctype html>' ) || ! str_contains( $html, 'report.css' ) ) { throw new RuntimeException( 'Standalone report lost stylesheet.' ); }
		}
		$style_checks[] = 'both-report-exporters';
		$throwing = static function (): never { throw new RuntimeException( 'expected-style-error' ); };
		add_filter( 'style_loader_tag', $throwing, 99 );
		try {
			try { \Cybermaps\Audit\ReportPresentation::stylesheet_markup(); throw new RuntimeException( 'Style exception was swallowed.' ); }
			catch ( RuntimeException $error ) { if ( 'expected-style-error' !== $error->getMessage() ) { throw $error; } }
		} finally { remove_filter( 'style_loader_tag', $throwing, 99 ); }
		if ( $level + 1 !== ob_get_level() || 'caller-prefix|' !== ob_get_contents() || $before_styles !== serialize( wp_styles() ) ) { throw new RuntimeException( 'Report altered caller output or global styles.' ); }
		$style_checks[] = 'exception-and-global-state';
	} finally {
		ob_end_clean();
		remove_filter( 'style_loader_src', $source_filter, 10 );
		remove_filter( 'style_loader_tag', $tag_filter, 10 );
	}
} finally {
	foreach ( $original as $name => $value ) { false === $value ? delete_option( $name ) : update_option( $name, $value ); }
	if ( $styles_existed ) { $GLOBALS['wp_styles'] = $styles_original; } else { unset( $GLOBALS['wp_styles'] ); }
	\Cybermaps\Core\ConfigurationStore::reset_memo();
}
echo wp_json_encode( array( 'schema_version' => 1, 'output_buffer_ownership_passed' => true, 'multisite' => is_multisite(), 'cases' => $cases, 'style_checks' => $style_checks ) );
