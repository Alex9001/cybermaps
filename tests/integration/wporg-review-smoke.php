<?php
declare(strict_types=1);

/** Review regressions executed only inside the disposable, real WordPress runtime. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'CYBERMAPS_VERSION' ) ) {
	throw new RuntimeException( 'Run this fixture through the release validation harness.' );
}

function cybermaps_review_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function cybermaps_review_capture( callable $render ): string {
	ob_start();
	try {
		$render();
		return (string) ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

$tooltip = wp_kses(
	\Cybermaps\Admin\AccessibleTooltip::get( '<img src=x onerror=alert(1)> Help' ) . '<button onclick="alert(1)">Unsafe</button>',
	\Cybermaps\Admin\AccessibleTooltip::allowed_html()
);
cybermaps_review_assert( ! str_contains( $tooltip, '<img' ) && ! str_contains( $tooltip, 'onclick=' ), 'Tooltip filtering retained executable markup.' );
foreach ( array( '<button', 'popovertarget=', 'popovertargetaction="hide"', 'popover="auto"', 'role="dialog"', 'tabindex="-1"', 'autofocus', 'aria-label=' ) as $required ) {
	cybermaps_review_assert( str_contains( $tooltip, $required ), 'Tooltip lost native accessibility/control markup: ' . $required );
}

$report = cybermaps_review_capture( static fn() => \Cybermaps\Core\ProtocolOutput::emit( '<!doctype html><html><head><title>Report</title><link rel="stylesheet" href="https://example.com/report.css"></head><body><main class="report"><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">bad</a></main></body></html>', 'html' ) );
cybermaps_review_assert( ! str_contains( $report, '<script' ) && ! str_contains( $report, 'onerror=' ) && ! str_contains( $report, 'javascript:' ), 'Report sink retained executable HTML.' );
cybermaps_review_assert( str_starts_with( $report, '<!doctype html>' ) && str_contains( $report, '<link rel="stylesheet"' ) && str_contains( $report, '<main class="report">' ), 'Report filtering damaged document structure.' );

$field = cybermaps_review_capture( static fn() => \Cybermaps\Admin\Settings\Fields\FieldRenderer::render_text_field( array( 'label_for' => 'test" autofocus onfocus="alert(1)', 'default' => '"><script>alert(1)</script>', 'type' => 'number', 'min' => '1" onfocus="alert(1)', 'max' => 99, 'maxlength' => 12 ) ) );
$dom = new DOMDocument();
@$dom->loadHTML( $field );
$input = $dom->getElementsByTagName( 'input' )->item( 0 );
cybermaps_review_assert( $input instanceof DOMElement && ! $input->hasAttribute( 'onfocus' ) && ! $input->hasAttribute( 'autofocus' ) && ! $input->hasAttribute( 'min' ), 'Field attributes escaped their value context.' );
cybermaps_review_assert( '99' === $input->getAttribute( 'max' ) && '12' === $input->getAttribute( 'maxlength' ), 'Field constraints were lost.' );

$request = array(
	'wizard_version' => \Cybermaps\Admin\SetupWizard\SetupWizardRegistry::VERSION,
	'answers' => array(
		'website_type' => 'blog', 'ai_visibility' => 'on', 'operations' => 'insights',
		'identity_type' => 'Organization', 'identity_image_id' => 0,
		'identity_name' => '<script>alert(1)</script><b>Example</b>',
		'identity_description' => "First <b>line</b>\nSecond line",
	),
);
$normalized = \Cybermaps\Admin\SetupWizard\SetupWizardRequestSanitizer::sanitize( $request );
cybermaps_review_assert( 'Example' === $normalized['answers']['identity_name'], 'Setup did not use real WordPress text sanitization.' );
cybermaps_review_assert( "First line\nSecond line" === $normalized['answers']['identity_description'], 'Setup description lost its line breaks.' );

$original_server = $_SERVER;
try {
	$_SERVER['REMOTE_ADDR'] = '173.245.48.1';
	foreach ( array( '<b>198.51.100.7</b>', '198.51.100.%307', "198.51.100.7\r\nInjected: yes" ) as $invalid_ip ) {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = wp_slash( $invalid_ip );
		cybermaps_review_assert( '173.245.48.1' === \Cybermaps\Core\ClientIPResolver::get_ip(), 'WordPress sanitation repaired an invalid forwarded address into a trusted value.' );
	}
	$_SERVER['HTTP_CF_CONNECTING_IP'] = wp_slash( '2001:db8::7' );
	cybermaps_review_assert( '2001:db8::7' === \Cybermaps\Core\ClientIPResolver::get_ip(), 'WordPress server adapter lost a valid forwarded IPv6 address.' );
} finally {
	$_SERVER = $original_server;
}

foreach ( array( new \Cybermaps\Discovery\Feed(), new \Cybermaps\Discovery\KnowledgeGraph() ) as $publication ) {
	$document = $publication->get_json_content();
	$emitted = cybermaps_review_capture( static fn() => \Cybermaps\Core\ProtocolOutput::emit( $document, 'json' ) );
	cybermaps_review_assert( $document === $emitted, 'JSON response bytes changed after integrity hashing.' );
}
$xml = ( new \Cybermaps\Discovery\AISitemap() )->get_content();
$emitted = cybermaps_review_capture( static fn() => \Cybermaps\Core\ProtocolOutput::emit( $xml, 'xml' ) );
$xml_document = new DOMDocument();
cybermaps_review_assert( $xml === $emitted && $xml_document->loadXML( $emitted, LIBXML_NONET ), 'XML emission is not valid, byte-preserved XML.' );
$csv_method = new ReflectionMethod( \Cybermaps\Admin\Logs::class, 'output_csv_row' );
$csv = cybermaps_review_capture( static fn() => $csv_method->invoke( null, array( '=1+1', " \t@SUM(1)", 'a"b', 'normal' ) ) );
cybermaps_review_assert( str_starts_with( $csv, '"\'=1+1","\' ' ) && str_contains( $csv, '"a""b"' ) && str_ends_with( $csv, "\r\n" ), 'CSV quoting/formula protection failed.' );

// A real WP AJAX die handler is intercepted only for negative-path assertions.
// Controller authorization is outside its try/catch; error-response catches do
// not catch an exception thrown from inside their own catch clause.
if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}
class CybermapsReviewAjaxExit extends RuntimeException {}
$die_filter = static fn() => static function ( $message ) {
	throw new CybermapsReviewAjaxExit( (string) $message );
};
add_filter( 'wp_die_ajax_handler', $die_filter );
$original_user = get_current_user_id();
$original_post = $_POST;
$original_request = $_REQUEST;
$original_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$options = array( 'cybermaps_settings', 'cybermaps_discovery_center', 'cybermaps_robots_manager', 'cybermaps_identity_data' );
$before = array_map( 'get_option', $options );
try {
	foreach ( array( 'method', 'permission', 'nonce', 'malformed', 'oversized', 'schema', 'stale' ) as $case ) {
		wp_set_current_user( 'permission' === $case ? 0 : 1 );
		$_SERVER['REQUEST_METHOD'] = 'method' === $case ? 'GET' : 'POST';
		$_REQUEST = array( 'nonce' => 'nonce' === $case ? 'invalid' : wp_create_nonce( \Cybermaps\Admin\SetupWizard\SetupWizardController::nonce_action() ) );
		$payload = match ( $case ) {
			'malformed' => '{invalid',
			'oversized' => str_repeat( 'x', 16385 ),
			'schema' => '{"wizard_version":1,"answers":[]}',
			default => wp_json_encode( $request ),
		};
		$_POST = array( 'payload' => wp_slash( $payload ), 'environment_hash' => 'stale', 'content_hash' => 'stale', 'configuration_hash' => 'stale' );
		ob_start();
		try {
			\Cybermaps\Admin\SetupWizard\SetupWizardController::apply();
			throw new RuntimeException( 'Rejected request returned normally: ' . $case );
		} catch ( CybermapsReviewAjaxExit $exit ) {
			$response = (string) ob_get_contents();
			if ( 'nonce' !== $case ) {
				$decoded = json_decode( $response, true, 512, JSON_THROW_ON_ERROR );
				cybermaps_review_assert( false === $decoded['success'], 'Rejected request reported success: ' . $case );
			}
		} finally {
			ob_end_clean();
		}
		cybermaps_review_assert( $before === array_map( 'get_option', $options ), 'Rejected request changed configuration: ' . $case );
	}
} finally {
	remove_filter( 'wp_die_ajax_handler', $die_filter );
	wp_set_current_user( $original_user );
	$_POST = $original_post;
	$_REQUEST = $original_request;
	$_SERVER['REQUEST_METHOD'] = $original_method;
}
echo "Cybermaps real-WordPress escaping, setup rejection, and serialization regressions passed.\n";
