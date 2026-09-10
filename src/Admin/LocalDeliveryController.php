<?php
/**
 * Administrator-approved local server configuration and verification.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Discovery\ManagedHtaccess;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps filesystem success distinct from externally observed HTTP delivery. */
final class LocalDeliveryController {
	private const ACTION = 'cybermaps_local_delivery';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public static function render(): void {
		add_action( 'admin_footer', array( self::class, 'render_action_form' ) );
		$state      = ManagedHtaccess::state();
		$limitation = ManagedHtaccess::limitation();
		$feedback   = get_transient( self::ACTION . '_' . get_current_user_id() );
		$message    = is_string( $feedback ) ? $feedback : (string) ( $state['message'] ?? '' );
		?>
		<div class="cm-card-sm" id="cybermaps-local-delivery">
			<h3><?php esc_html_e( 'Automatically configure local delivery', 'cybermaps' ); ?></h3>
			<p><?php esc_html_e( 'Let WordPress supply discovery response headers without Cloudflare. Adds a marked Cybermaps block before existing root .htaccess rules; WordPress, LiteSpeed Cache, and custom blocks are preserved.', 'cybermaps' ); ?></p>
			<p class="description"><?php esc_html_e( 'This opt-in mode routes fixed discovery URLs through PHP, including URLs with static copies. It prioritizes correct headers over static-file speed. Static files are not deleted. OpenLiteSpeed may need a server reload; an upstream nginx server may bypass these rules entirely.', 'cybermaps' ); ?></p>
			<?php if ( '' !== $limitation ) : ?>
				<p class="description"><?php echo esc_html( $limitation ); ?></p>
			<?php endif; ?>
			<p>
				<button class="button button-primary" type="submit" form="cybermaps-local-delivery-form" name="cybermaps_delivery_operation" value="install" <?php disabled( '' !== $limitation ); ?>><?php esc_html_e( 'Configure local delivery', 'cybermaps' ); ?></button>
				<button class="button" type="submit" form="cybermaps-local-delivery-form" name="cybermaps_delivery_operation" value="verify"><?php esc_html_e( 'Check local routing', 'cybermaps' ); ?></button>
				<button class="button" type="submit" form="cybermaps-local-delivery-form" name="cybermaps_delivery_operation" value="remove" <?php disabled( empty( $state['block'] ) ); ?>><?php esc_html_e( 'Undo Cybermaps local configuration', 'cybermaps' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'These actions do not save pending settings changes. Undo removes only the unchanged Cybermaps block, not another plugin\'s rules. Local configuration is also removed on deactivation when ownership is intact. Reapply after enabling newly registered publications.', 'cybermaps' ); ?></p>
			<?php if ( '' !== $message ) : ?>
				<p role="status"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
			<?php self::render_checks( $state ); ?>
		</div>
		<?php
	}

	/** Show only escaped diagnostic evidence from the most recent server-side check. */
	private static function render_checks( array $state ): void {
		$checks = $state['sample_checks'] ?? array();
		if ( ! is_array( $checks ) || array() === $checks ) {
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'These are WordPress server-side requests, not an independent external test. Loopback DNS, proxies, and caching can make their results differ from your browser. A missing PHP marker does not mean that a publication is unavailable.', 'cybermaps' ); ?></p>
		<p class="description"><?php esc_html_e( 'Use Open public URL to inspect the publication in a new tab or window through your browser. To check its media type, inspect Content-Type in your browser network tools. Opening the link does not automatically verify its headers or change the server-side result.', 'cybermaps' ); ?></p>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %s: UTC timestamp of the most recent check. */ __( 'Last checked: %s UTC', 'cybermaps' ), gmdate( 'Y-m-d H:i:s', (int) ( $state['verified_at'] ?? 0 ) ) ) ); ?></p>
		<div style="overflow-x:auto;">
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Publication', 'cybermaps' ); ?></th>
				<th><?php esc_html_e( 'Server-side HTTP status', 'cybermaps' ); ?></th>
				<th><?php esc_html_e( 'Expected media type', 'cybermaps' ); ?></th>
				<th><?php esc_html_e( 'Server-side Content-Type', 'cybermaps' ); ?></th>
				<th><?php esc_html_e( 'PHP marker', 'cybermaps' ); ?></th>
				<th><?php esc_html_e( 'Cloudflare rule marker', 'cybermaps' ); ?></th>
				<th><?php esc_html_e( 'Server-side result / transport error', 'cybermaps' ); ?></th>
				<th><?php esc_html_e( 'Manual public check', 'cybermaps' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $checks as $check ) : ?>
				<?php
				if ( ! is_array( $check ) ) {
					continue;
				}
				?>
				<?php self::render_check( $check ); ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/** Render one escaped diagnostic row. */
	private static function render_check( array $check ): void {
		?>
				<tr>
					<td><code><?php echo esc_html( (string) ( $check['path'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( empty( $check['code'] ) ? __( 'No response', 'cybermaps' ) : (string) $check['code'] ); ?></td>
					<td><code><?php echo esc_html( (string) ( $check['expected_type'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( ! empty( $check['content_type'] ) ? (string) $check['content_type'] : __( 'Missing', 'cybermaps' ) ); ?></td>
					<td><?php echo esc_html( ! empty( $check['php_marker'] ) ? (string) $check['php_marker'] : __( 'Not observed', 'cybermaps' ) ); ?></td>
					<td><?php echo esc_html( ! empty( $check['edge_marker'] ) ? (string) $check['edge_marker'] : __( 'Not observed', 'cybermaps' ) ); ?></td>
					<td><?php echo esc_html( self::check_message( $check ) ); ?></td>
					<td><a class="button" href="<?php echo esc_url( home_url( (string) ( $check['path'] ?? '/' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open public URL', 'cybermaps' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab or window)', 'cybermaps' ); ?></span></a></td>
				</tr>
		<?php
	}

	/** Keep availability and format separate from evidence of PHP routing. */
	private static function check_message( array $check ): string {
		if ( ! empty( $check['transport_error'] ) ) {
			return (string) $check['transport_error'];
		}
		if ( empty( $check['response_valid'] ) ) {
			return __( 'Server-side check: HTTP status or media type did not match. The public browser response may differ. Body not validated.', 'cybermaps' );
		}
		return ! empty( $check['passed'] )
			? __( 'HTTP/media type passed; PHP marker observed. Body not validated.', 'cybermaps' )
			: __( 'HTTP/media type passed; PHP routing remains unverified. Body not validated.', 'cybermaps' );
	}

	/** Render outside the settings form so its action=update cannot override dispatch. */
	public static function render_action_form(): void {
		?>
		<form id="cybermaps-local-delivery-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION, 'cybermaps_delivery_nonce', false ); ?>
		</form>
		<?php
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'An administrator POST request is required.', 'cybermaps' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION, 'cybermaps_delivery_nonce' );
		$operation = isset( $_POST['cybermaps_delivery_operation'] ) && is_string( $_POST['cybermaps_delivery_operation'] )
			? sanitize_key( wp_unslash( $_POST['cybermaps_delivery_operation'] ) ) : '';
		try {
			$message = $this->operate( $operation );
		} catch ( \Throwable $error ) {
			$message = $error->getMessage();
		}
		set_transient( self::ACTION . '_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=cybermaps-settings&tab=advanced#cybermaps-local-delivery' ) );
		exit;
	}

	private function operate( string $operation ): string {
		$manager = new ManagedHtaccess();
		if ( 'remove' === $operation ) {
			$manager->change( false );
			return (string) ManagedHtaccess::state()['message'];
		}
		if ( 'verify' === $operation ) {
			return $this->verify();
		}
		if ( 'install' !== $operation ) {
			throw new \RuntimeException( esc_html__( 'Unknown local delivery operation.', 'cybermaps' ) );
		}
		$before = $this->home_status();
		$manager->change( true );
		$after = $this->home_status();
		if ( $before >= 200 && $before < 400 && $after >= 500 ) {
			$manager->change( false );
			return __( 'The homepage returned a server error after configuration. Cybermaps removed its managed block; other rules were preserved.', 'cybermaps' );
		}
		return $this->verify();
	}

	private function home_status(): int {
		$response = wp_safe_remote_get(
			add_query_arg( 'cybermaps_delivery_check', wp_generate_uuid4(), home_url( '/' ) ),
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 1024,
			)
		);
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/** Check a bounded sample, requiring evidence that PHP actually handled it. */
	private function verify(): string {
		$checks = array();
		foreach ( array(
			'/ai-discovery'            => 'application/json',
			'/ai-actions.json'         => 'application/ld+json',
			'/.well-known/api-catalog' => 'application/linkset+json',
		) as $path => $mime ) {
			$checks[] = $this->probe( $path, $mime );
		}
		$passed  = count( array_filter( $checks, static fn( array $check ): bool => $check['passed'] ) );
		$message = sprintf(
			/* translators: %d: number of successful sample checks, out of three. */
			__( '%d of 3 sampled discovery URLs returned HTTP 200, their expected media type, and a PHP response marker. This is a header/routing sample, not full body validation.', 'cybermaps' ),
			$passed
		);
		if ( 3 !== $passed ) {
			$message .= ' ' . __( 'PHP routing is not verified for every sample. Review the per-URL results below: a usable static or edge-served response can pass HTTP and media-type checks without a PHP marker.', 'cybermaps' );
		}
		update_option(
			ManagedHtaccess::OPTION,
			array_merge(
				ManagedHtaccess::state(),
				array(
					'message'       => $message,
					'verified_at'   => time(),
					'sample_passed' => $passed,
					'sample_checks' => $checks,
				)
			),
			false
		);
		return $message;
	}

	/** @return array<string,mixed> Bounded, administrator-only response evidence. */
	private function probe( string $path, string $mime ): array {
		$url      = add_query_arg( 'cybermaps_delivery_check', wp_generate_uuid4(), home_url( $path ) );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 4096,
				'headers'             => array(
					'Accept'                 => $mime,
					'X-Cybermaps-Diagnostic' => '1',
				),
			)
		);
		return $this->probe_result( $path, $mime, $response );
	}

	/** Preserve evidence instead of collapsing every failure to false. */
	private function probe_result( string $path, string $mime, mixed $response ): array {
		$result = array(
			'path'            => $path,
			'expected_type'   => $mime,
			'code'            => 0,
			'content_type'    => '',
			'php_marker'      => '',
			'edge_marker'     => '',
			'transport_error' => '',
			'response_valid'  => false,
			'passed'          => false,
		);
		if ( is_wp_error( $response ) ) {
			$result['transport_error'] = substr( sanitize_text_field( (string) $response->get_error_code() . ': ' . $response->get_error_message() ), 0, 500 );
			return $result;
		}
		$result['code'] = (int) wp_remote_retrieve_response_code( $response );
		foreach ( array(
			'content_type' => 'content-type',
			'php_marker'   => 'x-cybermaps-version',
			'edge_marker'  => 'x-cybermaps-cloudflare-rule',
		) as $key => $header ) {
			$result[ $key ] = substr( sanitize_text_field( (string) wp_remote_retrieve_header( $response, $header ) ), 0, 300 );
		}
		$type                     = strtolower( trim( explode( ';', $result['content_type'], 2 )[0] ) );
		$result['response_valid'] = 200 === $result['code'] && $mime === $type;
		$result['passed']         = $result['response_valid'] && '' !== $result['php_marker'];
		return $result;
	}
}
