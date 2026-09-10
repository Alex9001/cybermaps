<?php
/**
 * Advisory discovery deployment guidance.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders copy-ready rules without mutating server or edge configuration.
 */
final class DeploymentGuidance {
	/**
	 * Summarize configured, advertised, verified, and intercepted endpoints.
	 *
	 * @param array<int,array<string,mixed>> $endpoints Status endpoint rows.
	 * @return array{configured:int,advertised:int,verified:int,intercepted:array<int,array{canonical:string,alias:string}>}
	 */
	public static function summarize( array $endpoints ): array {
		$summary = array(
			'configured'  => 0,
			'advertised'  => 0,
			'verified'    => 0,
			'intercepted' => self::intercepted_canonical_paths( $endpoints ),
		);
		foreach ( $endpoints as $endpoint ) {
			if ( empty( $endpoint['canonical'] ) || empty( $endpoint['enabled'] ) ) {
				continue;
			}
			++$summary['configured'];
			if ( ! empty( $endpoint['url'] ) ) {
				++$summary['advertised'];
			}
			if ( 'healthy' === ( $endpoint['status'] ?? '' ) ) {
				++$summary['verified'];
			}
		}

		return $summary;
	}

	/** Render deployment status semantics and copy-ready rules. */
	public static function render( array $endpoints ): void {
		$summary = self::summarize( $endpoints );
		$rules   = self::routing_rules();
		?>
		<section class="cm-card-sm cm-mt-20 cm-deployment-guidance" aria-describedby="deployment-guidance-maturity">
			<h2><?php esc_html_e( 'Generated server and edge rules', 'cybermaps' ); ?></h2>
			<p><?php esc_html_e( 'Cybermaps never writes server, cache, reverse-proxy, or CDN configuration. Review and apply only the rule for your deployment, then refresh public validation.', 'cybermaps' ); ?></p>
			<?php MaturityGuidance::render( 'deployment-guidance', 'deployment' ); ?>
			<div class="cm-deployment-state-grid">
				<?php self::render_state( __( 'Configured', 'cybermaps' ), $summary['configured'], __( 'A Cybermaps setting enables the canonical publication.', 'cybermaps' ) ); ?>
				<?php self::render_state( __( 'Advertised', 'cybermaps' ), $summary['advertised'], __( 'Cybermaps exposes a canonical public URL for clients to follow.', 'cybermaps' ) ); ?>
				<?php self::render_state( __( 'Publicly verified', 'cybermaps' ), $summary['verified'], __( 'The latest live HTTP check validated the canonical response.', 'cybermaps' ) ); ?>
			</div>
			<?php self::render_interception_diagnostics( $summary['intercepted'] ); ?>
			<div class="cm-deployment-rules">
			<?php foreach ( $rules as $label => $rule ) : ?>
				<details>
					<summary><?php echo esc_html( $label ); ?></summary>
					<pre><code><?php echo esc_html( $rule ); ?></code></pre>
				</details>
			<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/** Render one deployment maturity count. */
	private static function render_state( string $label, int $count, string $description ): void {
		?>
		<div class="cm-deployment-state">
			<span class="cm-page-badge cm-page-badge-neutral"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( (string) $count ); ?></strong>
			<small><?php echo esc_html( $description ); ?></small>
		</div>
		<?php
	}

	/** Render canonical interception diagnostics. */
	private static function render_interception_diagnostics( array $intercepted ): void {
		foreach ( $intercepted as $item ) {
			?>
			<p class="notice notice-warning inline cm-deployment-interception">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: canonical well-known path, 2: working compatibility path. */
						__( 'The canonical %1$s appears intercepted: compatibility URL %2$s is publicly verified, but the canonical URL is not. Apply the matching deployment rule and verify again.', 'cybermaps' ),
						$item['canonical'],
						$item['alias']
					)
				);
				?>
			</p>
			<?php
		}
	}

	/** Return human-labelled copy-ready routing rules. */
	private static function routing_rules(): array {
		$manifest = ( new \Cybermaps\Discovery\StaticHeaderManifest() )->get_manifest();
		$snippets = is_array( $manifest['snippets']['routing'] ?? null ) ? $manifest['snippets']['routing'] : array();

		return array(
			__( 'nginx', 'cybermaps' )                  => (string) ( $snippets['nginx'] ?? '' ),
			__( 'Apache / OpenLiteSpeed', 'cybermaps' ) => (string) ( $snippets['apache_openlitespeed'] ?? '' ),
			__( 'LiteSpeed Cache', 'cybermaps' )        => (string) ( $snippets['litespeed_cache'] ?? '' ),
			__( 'Varnish', 'cybermaps' )                => (string) ( $snippets['varnish'] ?? '' ),
			__( 'Reverse proxy / CDN', 'cybermaps' )    => (string) ( $snippets['reverse_proxy_cdn'] ?? '' ),
		);
	}

	/** Find well-known canonical paths whose compatibility aliases validate. */
	private static function intercepted_canonical_paths( array $endpoints ): array {
		$healthy_aliases = self::healthy_aliases( $endpoints );
		$intercepted     = array();
		foreach ( $endpoints as $endpoint ) {
			$endpoint_id = (string) ( $endpoint['endpoint_id'] ?? '' );
			$path        = (string) ( $endpoint['path'] ?? '' );
			if ( empty( $endpoint['canonical'] ) || ! str_starts_with( $path, '/.well-known/' ) || 'healthy' === ( $endpoint['status'] ?? '' ) || empty( $healthy_aliases[ $endpoint_id ] ) ) {
				continue;
			}
			$intercepted[] = array(
				'canonical' => $path,
				'alias'     => $healthy_aliases[ $endpoint_id ],
			);
		}

		return $intercepted;
	}

	/** Return the first publicly verified compatibility alias per endpoint. */
	private static function healthy_aliases( array $endpoints ): array {
		$aliases = array();
		foreach ( $endpoints as $endpoint ) {
			$endpoint_id = (string) ( $endpoint['endpoint_id'] ?? '' );
			if ( ! empty( $endpoint['canonical'] ) || 'healthy' !== ( $endpoint['status'] ?? '' ) || '' === $endpoint_id || isset( $aliases[ $endpoint_id ] ) ) {
				continue;
			}
			$aliases[ $endpoint_id ] = (string) ( $endpoint['path'] ?? '' );
		}

		return $aliases;
	}
}
