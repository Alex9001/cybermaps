<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs\Advanced;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdvancedFields {
	public static function optimization_section_callback(): void {
		$status  = \Cybermaps\Admin\SystemStatusCollector::collect();
		$summary = $status['summary'];
		$url     = add_query_arg( 'page', 'cybermaps-system-status', admin_url( 'admin.php' ) );
		?>
		<div class="cm-section-prose" id="cybermaps-edge-optimization-summary">
			<strong><?php esc_html_e( 'Use every safe optimization the current stack provides.', 'cybermaps' ); ?></strong>
			<?php esc_html_e( 'Cybermaps distinguishes between technology that is installed and technology it is actively using. Automatic local integrations are enabled by default and remain independently reversible.', 'cybermaps' ); ?>
			<div class="cm-edge-grid">
				<div class="cm-edge-stat"><strong><?php echo esc_html( (string) $summary['active'] ); ?></strong><?php esc_html_e( 'Active optimizations', 'cybermaps' ); ?></div>
				<div class="cm-edge-stat"><strong><?php echo esc_html( (string) $summary['available'] ); ?></strong><?php esc_html_e( 'Available but unused', 'cybermaps' ); ?></div>
				<div class="cm-edge-stat"><strong><?php echo esc_html( (string) $summary['attention'] ); ?></strong><?php esc_html_e( 'Need attention', 'cybermaps' ); ?></div>
			</div>
			<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open Debugging', 'cybermaps' ); ?></a>
		</div>
		<?php
	}

	public static function render_edge_optimization_field(): void {
		$context       = self::edge_optimization_context();
		$routing       = $context['routing'];
		$oauth_mode    = $context['oauth_mode'];
		$oauth_client  = $context['oauth_client'];
		$install_url   = $context['install_url'];
		$remove_url    = $context['remove_url'];
		$callback_url  = $context['callback_url'];
		$install_label = $context['install_label'];
		?>
		<div id="cybermaps-edge-optimization">
			<?php \Cybermaps\Admin\LocalDeliveryController::render(); ?>
			<div class="cm-card-sm">
				<h3><?php esc_html_e( 'Connect Cloudflare and optimize', 'cybermaps' ); ?></h3>
				<p><?php esc_html_e( 'Open Cloudflare, choose the account that owns this hostname, review three narrowly scoped permissions, and press Authorize. Cybermaps then installs or repairs both discovery-header and cache-safety rules.', 'cybermaps' ); ?></p>
				<?php self::render_cloudflare_detection( $context['cloudflare_detected'], $context['cloudflare_host'] ); ?>
				<?php self::render_oauth_mode_description( $oauth_mode ); ?>
				<div class="cm-status-actions">
					<a class="button button-primary" href="<?php echo esc_url( $install_url ); ?>" target="_blank" rel="noopener noreferrer" data-cybermaps-oauth-start="install" data-cloudflare-required="1"><?php echo esc_html( $install_label ); ?></a>
					<button type="button" class="button" data-cybermaps-edge-action="cybermaps_edge_verify"><?php esc_html_e( 'Verify public delivery', 'cybermaps' ); ?></button>
					<a class="button button-link-delete" href="<?php echo esc_url( $remove_url ); ?>" target="_blank" rel="noopener noreferrer" data-cybermaps-oauth-start="remove" data-cloudflare-required="1"><?php esc_html_e( 'Remove Cybermaps rules from Cloudflare', 'cybermaps' ); ?></a>
				</div>
				<p class="description"><?php esc_html_e( 'Removal opens Cloudflare only to request temporary permission to delete the Cybermaps-managed rules. Approving removal does not install rules, reconnect the site, or retain a Cloudflare credential.', 'cybermaps' ); ?></p>
				<p id="cybermaps-edge-feedback" class="cm-status-feedback" role="status" aria-live="polite"></p>
				<div id="cybermaps-edge-verification-results" class="cm-status-feedback" aria-live="polite"></div>
				<details class="cm-mt-20">
					<summary><strong><?php esc_html_e( 'Advanced: use your own Cloudflare OAuth client', 'cybermaps' ); ?></strong></summary>
					<?php self::render_custom_oauth_settings( $oauth_mode, $oauth_client, $callback_url ); ?>
				</details>
				<details class="cm-mt-20">
					<summary><strong><?php esc_html_e( 'Troubleshooting: use a one-time API token', 'cybermaps' ); ?></strong></summary>
					<?php self::render_cloudflare_token_troubleshooting(); ?>
				</details>
			</div>
			<div class="cm-card-sm cm-mt-20">
				<h3><?php esc_html_e( 'Safe local maintenance', 'cybermaps' ); ?></h3>
				<p><?php esc_html_e( 'Advance only Cybermaps cache generations and remove its legacy compatibility entries. Redis, Memcached, page-cache, and unrelated WordPress entries are not flushed.', 'cybermaps' ); ?></p>
				<button type="button" class="button" data-cybermaps-edge-action="cybermaps_edge_clear_caches"><?php esc_html_e( 'Invalidate Cybermaps caches', 'cybermaps' ); ?></button>
				<button type="button" class="button" data-cybermaps-edge-action="cybermaps_edge_rebuild_static"><?php esc_html_e( 'Rebuild static publications', 'cybermaps' ); ?></button>
			</div>
			<details class="cm-card-sm cm-mt-20">
				<summary><strong><?php esc_html_e( 'Other stack guidance', 'cybermaps' ); ?></strong></summary>
				<p><?php esc_html_e( 'Redis and Memcached work through a conforming WordPress object-cache drop-in. OPcache is managed by PHP. Varnish remains explicitly configured because its PURGE ACL and secret belong to the deployment. nginx and OpenLiteSpeed may need optional response-header configuration when they serve physical extensionless files before PHP; Cybermaps’ static fallback still supplies the response body.', 'cybermaps' ); ?></p>
				<?php self::render_copyable_snippet( 'cybermaps-nginx-guidance', __( 'nginx dynamic-route safety', 'cybermaps' ), (string) ( $routing['nginx'] ?? '' ) ); ?>
				<?php self::render_copyable_snippet( 'cybermaps-litespeed-guidance', __( 'LiteSpeed Cache bypass', 'cybermaps' ), (string) ( $routing['litespeed_cache'] ?? '' ) ); ?>
				<?php self::render_copyable_snippet( 'cybermaps-varnish-guidance', __( 'Varnish pass policy', 'cybermaps' ), (string) ( $routing['varnish'] ?? '' ) ); ?>
			</details>
		</div>
		<?php
	}

	/** @return array{routing:array<string,mixed>,oauth_mode:string,oauth_client:string,install_url:string,remove_url:string,callback_url:string,install_label:string,cloudflare_detected:bool,cloudflare_host:string} */
	private static function edge_optimization_context(): array {
		$manifest  = ( new \Cybermaps\Discovery\StaticHeaderManifest() )->get_manifest();
		$settings  = \Cybermaps\Core\ConfigurationStore::settings();
		$mode      = 'custom' === ( $settings['cloudflare_oauth_mode'] ?? 'managed' ) ? 'custom' : 'managed';
		$client_id = is_scalar( $settings['cloudflare_oauth_client_id'] ?? null ) ? (string) $settings['cloudflare_oauth_client_id'] : '';
		return array(
			'routing'             => is_array( $manifest['snippets']['routing'] ?? null ) ? $manifest['snippets']['routing'] : array(),
			'oauth_mode'          => $mode,
			'oauth_client'        => $client_id,
			'install_url'         => \Cybermaps\Admin\EdgeOptimizationController::oauth_start_url( 'install' ),
			'remove_url'          => \Cybermaps\Admin\EdgeOptimizationController::oauth_start_url( 'remove' ),
			'callback_url'        => \Cybermaps\Admin\EdgeOptimizationController::oauth_callback_url(),
			'install_label'       => 'custom' === $mode ? __( 'Connect with your OAuth client & optimize', 'cybermaps' ) : __( 'Connect Cloudflare & optimize', 'cybermaps' ),
			'cloudflare_detected' => \Cybermaps\Admin\CloudflareRuleManager::request_is_cloudflare(),
			'cloudflare_host'     => \Cybermaps\Admin\CloudflareRuleManager::public_host(),
		);
	}

	private static function render_cloudflare_detection( bool $detected, string $host ): void {
		$message = $detected
			? __( 'Cloudflare proxy traffic was detected for this request. Rule tools are available.', 'cybermaps' )
			: __( 'Cloudflare proxy traffic was not detected. Cybermaps does not need Cloudflare, and these controls will remain disabled unless you confirm that this hostname is orange-cloud proxied.', 'cybermaps' );
		?>
		<div id="cybermaps-cloudflare-detection" class="notice <?php echo esc_attr( $detected ? 'notice-success' : 'notice-warning' ); ?> inline" data-detected="<?php echo esc_attr( $detected ? '1' : '0' ); ?>">
			<p id="cybermaps-cloudflare-detection-message"><strong><?php esc_html_e( 'Cloudflare eligibility:', 'cybermaps' ); ?></strong> <?php echo esc_html( $message ); ?></p>
			<p id="cybermaps-cloudflare-confirm-row"
			<?php
			if ( $detected ) :
				?>
				hidden<?php endif; ?>><label><input id="cybermaps-cloudflare-confirm" type="checkbox" value="1"> <?php echo esc_html( sprintf( /* translators: %s: configured public hostname. */ __( 'I confirm that %s is proxied (orange-clouded) through Cloudflare.', 'cybermaps' ), $host ) ); ?></label></p>
			<p class="description"><?php esc_html_e( 'Manual confirmation bypasses automatic detection only. Cloudflare authorization, scoped permissions, zone selection, and post-install public verification are still required.', 'cybermaps' ); ?></p>
		</div>
		<?php
	}

	private static function render_oauth_mode_description( string $oauth_mode ): void {
		if ( 'custom' === $oauth_mode ) {
			?>
			<p class="description"><?php esc_html_e( 'Custom OAuth is active. The browser goes directly to Cloudflare and returns to this WordPress site. Cybermaps Connect is bypassed; WordPress keeps PKCE and state locally, exchanges the code, performs the selected rule operation, then revokes and discards the access token.', 'cybermaps' ); ?></p>
			<?php
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'Uses Authorization Code with PKCE. Cybermaps Connect briefly holds an unusable authorization code, but never receives the PKCE verifier, access token, WordPress identity, site URL, or rule payload. WordPress exchanges and revokes the token directly with Cloudflare.', 'cybermaps' ); ?></p>
		<?php
	}

	private static function render_custom_oauth_settings( string $oauth_mode, string $client_id, string $callback_url ): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Administrator documentation/navigation link; no remote assets are loaded.
		$docs_url = 'https://developers.cloudflare.com/fundamentals/oauth/create-an-oauth-client/';
		?>
		<p><?php esc_html_e( 'Choose the managed connection for zero-configuration setup. Choose your own client when your organization wants authorization to stay between Cloudflare and this WordPress installation. Save this Advanced page after changing the mode or client ID.', 'cybermaps' ); ?></p>
		<label for="cybermaps-cloudflare-oauth-mode"><strong><?php esc_html_e( 'OAuth connection mode', 'cybermaps' ); ?></strong></label><br>
		<select id="cybermaps-cloudflare-oauth-mode" name="cybermaps_settings[cloudflare_oauth_mode]">
			<option value="managed" <?php selected( $oauth_mode, 'managed' ); ?>><?php esc_html_e( 'Cybermaps managed connection', 'cybermaps' ); ?></option>
			<option value="custom" <?php selected( $oauth_mode, 'custom' ); ?>><?php esc_html_e( 'Your Cloudflare OAuth client', 'cybermaps' ); ?></option>
		</select>
		<p>
			<label for="cybermaps-cloudflare-oauth-client-id"><strong><?php esc_html_e( 'Cloudflare OAuth public client ID', 'cybermaps' ); ?></strong></label><br>
			<input id="cybermaps-cloudflare-oauth-client-id" class="regular-text code" type="text" name="cybermaps_settings[cloudflare_oauth_client_id]" value="<?php echo esc_attr( $client_id ); ?>" autocomplete="off" spellcheck="false">
		</p>
		<p><strong><?php esc_html_e( 'Exact callback / redirect URI', 'cybermaps' ); ?></strong></p>
		<input id="cybermaps-cloudflare-oauth-callback" class="large-text code" type="text" readonly value="<?php echo esc_attr( $callback_url ); ?>">
		<p><button type="button" class="button" data-cybermaps-copy-target="cybermaps-cloudflare-oauth-callback"><?php esc_html_e( 'Copy callback URI', 'cybermaps' ); ?></button></p>
		<h4><?php esc_html_e( 'Register the client in Cloudflare', 'cybermaps' ); ?></h4>
		<ol>
			<li><?php esc_html_e( 'Create an OAuth client in the Cloudflare account that owns the target zone. The client name is your choice.', 'cybermaps' ); ?></li>
			<li><?php esc_html_e( 'Set Response type to code, Grant type to authorization_code, Token endpoint authentication method to none, and PKCE method to S256.', 'cybermaps' ); ?></li>
			<li><?php esc_html_e( 'Register the exact callback URI shown above. Do not add or remove a slash, change the hostname, or substitute the WordPress REST URL.', 'cybermaps' ); ?></li>
			<li><?php esc_html_e( 'Grant exactly Zone Read, Zone Transform Rules Write, and Cache Settings Write: zone.read, zone-transform-rules.write, and cache-settings.write.', 'cybermaps' ); ?></li>
			<li><?php esc_html_e( 'Copy the public client ID into the field above, select Your Cloudflare OAuth client, and save this page before connecting.', 'cybermaps' ); ?></li>
		</ol>
		<p><a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Cloudflare’s OAuth client documentation', 'cybermaps' ); ?></a></p>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Private visibility is sufficient when every administrator authorizing the plugin belongs to the Cloudflare account that owns the client. Serving unrelated customer accounts requires Cloudflare’s public-client approval and verified-domain process; public promotion is permanent.', 'cybermaps' ); ?></p></div>
		<p class="description"><?php esc_html_e( 'This mode stores only the public client ID. Client secrets are unsupported and unnecessary. Authorization state, PKCE verifier, and code expire after five minutes; the access token is held only in memory for the operation and is then revoked and discarded.', 'cybermaps' ); ?></p>
		<?php
	}

	private static function render_cloudflare_token_troubleshooting(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Administrator documentation/navigation link; no remote assets are loaded.
		$tokens_url = 'https://dash.cloudflare.com/profile/api-tokens';
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Administrator documentation/navigation link; no remote assets are loaded.
		$permissions_url = 'https://developers.cloudflare.com/fundamentals/api/reference/permissions/';
		?>
		<p><?php esc_html_e( 'Use this fallback only when a Cloudflare administrator blocks public OAuth applications or Cybermaps Connect is unavailable. The browser sends the token to this WordPress site, which uses it directly with api.cloudflare.com for the selected operation.', 'cybermaps' ); ?></p>
		<h4><?php esc_html_e( 'Create a least-privilege temporary token', 'cybermaps' ); ?></h4>
		<ol>
			<li>
				<a href="<?php echo esc_url( $tokens_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Cloudflare API Tokens', 'cybermaps' ); ?></a>
				<?php esc_html_e( 'and select Create Token, then Create Custom Token. Do not use the Global API Key.', 'cybermaps' ); ?>
			</li>
			<li>
				<?php esc_html_e( 'Give the token a recognizable temporary name, such as Cybermaps setup, and add these three permissions:', 'cybermaps' ); ?>
				<ul>
					<li><strong><?php esc_html_e( 'Zone Read', 'cybermaps' ); ?></strong> <code>zone.read</code> — <?php esc_html_e( 'finds the zone that owns this WordPress hostname.', 'cybermaps' ); ?></li>
					<li><strong><?php esc_html_e( 'Zone Transform Rules Write', 'cybermaps' ); ?></strong> <code>zone-transform-rules.write</code> — <?php esc_html_e( 'installs and removes Cybermaps discovery-response headers.', 'cybermaps' ); ?></li>
					<li><strong><?php esc_html_e( 'Cache Settings Write', 'cybermaps' ); ?></strong> <code>cache-settings.write</code> — <?php esc_html_e( 'installs and removes the Cybermaps cache-safety rule.', 'cybermaps' ); ?></li>
				</ul>
			</li>
			<li><?php esc_html_e( 'Under Zone Resources, choose Include and select only the zone for this site. Selecting all zones is unnecessary.', 'cybermaps' ); ?></li>
			<li><?php esc_html_e( 'Set a short expiration when available. Complete Cloudflare’s summary, create the token, and copy it immediately; Cloudflare will not show it again.', 'cybermaps' ); ?></li>
			<li><?php esc_html_e( 'Paste only the token value below. Do not include “Bearer”, quotation marks, or surrounding spaces.', 'cybermaps' ); ?></li>
		</ol>
		<p>
			<a href="<?php echo esc_url( $permissions_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Review Cloudflare’s current permission reference', 'cybermaps' ); ?></a>
		</p>
		<div class="notice notice-warning inline">
			<p><strong><?php esc_html_e( 'Important:', 'cybermaps' ); ?></strong> <?php esc_html_e( '“One-time” describes Cybermaps handling: the field is cleared after every request and the token is never saved by the plugin. Cybermaps cannot revoke an API token. Revoke it in Cloudflare immediately after finishing, or rely on the short expiration you selected.', 'cybermaps' ); ?></p>
			<p><?php esc_html_e( 'The header and cache buttons are separate operations. Paste the same still-valid token again before the second operation because Cybermaps clears the field after the first.', 'cybermaps' ); ?></p>
		</div>
		<label for="cybermaps-cloudflare-token"><strong><?php esc_html_e( 'Temporary Cloudflare API token', 'cybermaps' ); ?></strong></label><br>
		<input id="cybermaps-cloudflare-token" class="regular-text cm-cloudflare-token" type="password" autocomplete="new-password" spellcheck="false" value="" aria-describedby="cybermaps-cloudflare-token-help">
		<p id="cybermaps-cloudflare-token-help" class="description"><?php esc_html_e( 'Use all three permissions to install or remove both Cybermaps rule families. The token is transmitted only when you press one of these buttons.', 'cybermaps' ); ?></p>
		<div class="cm-status-actions">
			<button type="button" class="button" data-cybermaps-edge-action="cybermaps_edge_install_headers" data-requires-token="1" data-cloudflare-required="1"><?php esc_html_e( 'Install/repair discovery headers', 'cybermaps' ); ?></button>
			<button type="button" class="button" data-cybermaps-edge-action="cybermaps_edge_install_cache" data-requires-token="1" data-cloudflare-required="1"><?php esc_html_e( 'Install/repair cache safety', 'cybermaps' ); ?></button>
			<button type="button" class="button button-link-delete" data-cybermaps-edge-action="cybermaps_edge_remove_rules" data-requires-token="1" data-cloudflare-required="1"><?php esc_html_e( 'Remove Cybermaps Cloudflare rules', 'cybermaps' ); ?></button>
		</div>
		<h4><?php esc_html_e( 'If Cloudflare rejects the token', 'cybermaps' ); ?></h4>
		<ul>
			<li><?php esc_html_e( 'Confirm the token belongs to the Cloudflare account containing this site’s active zone.', 'cybermaps' ); ?></li>
			<li><?php esc_html_e( 'Confirm Zone Read is present. DNS Read or DNS Write does not replace Zone Read.', 'cybermaps' ); ?></li>
			<li><?php esc_html_e( 'Confirm the token includes the specific site zone, has not expired, and is not restricted to a different client IP.', 'cybermaps' ); ?></li>
			<li><?php esc_html_e( 'If only one button fails, confirm its matching Write permission is present and supported by the zone’s Cloudflare plan.', 'cybermaps' ); ?></li>
		</ul>
		<h4><?php esc_html_e( 'Prefer your own OAuth client?', 'cybermaps' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Use the custom OAuth section above instead of an API token. It keeps the authorization callback, PKCE transaction, code exchange, and token revocation on this WordPress site while storing only Cloudflare’s public client ID.', 'cybermaps' ); ?></p>
		<?php
	}

	private static function render_copyable_snippet( string $id, string $label, string $snippet ): void {
		if ( '' === $snippet ) {
			return;
		}
		?>
		<p><strong><?php echo esc_html( $label ); ?></strong></p>
		<textarea id="<?php echo esc_attr( $id ); ?>" class="large-text code" rows="6" readonly><?php echo esc_textarea( $snippet ); ?></textarea>
		<p><button type="button" class="button" data-cybermaps-copy-target="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy snippet', 'cybermaps' ); ?></button></p>
		<?php
	}

	public static function exchange_section_callback() {
		?>
		<div class="cm-section-prose">
			<strong><?php esc_html_e( 'Portable configuration.', 'cybermaps' ); ?></strong>
			<?php esc_html_e( 'Export this site’s complete Cybermaps configuration as a versioned JSON backup for migration, client handoff, or recovery. The AI Configuration Brief is a separate workflow that excludes the private Cybermaps REST API secret and IndexNow key.', 'cybermaps' ); ?>
			<span class="cm-desc-warn"><?php esc_html_e( 'The complete backup can contain the private Cybermaps REST API secret and information entered in plugin settings. Keep it private. Network-wide settings, cross-site translation relationships, analytics history, report runs, WordPress content, media, and generated files are not included.', 'cybermaps' ); ?></span>
		</div>
		<?php
	}
	public static function headless_section_callback() {
		echo '<div class="cm-section-prose"><strong>' . esc_html__( 'Headless & sitemap-media URLs.', 'cybermaps' ) . '</strong> ' . wp_kses_post( __( 'If your WordPress backend is decoupled from your frontend, set the frontend base URL so publication locations use the public site. The frontend must proxy Cybermaps routes it advertises; IndexNow additionally requires the generated <code>/{key}.txt</code> verification path on that same public host. The optional CDN origin rewrites same-site media URLs inside XML sitemap entries; it does not publish Cybermaps files or assets to a CDN.', 'cybermaps' ) ) . '</div>';
		echo '<div class="cybermaps-settings-subheading"><span class="dashicons dashicons-rest-api"></span> ' . esc_html__( 'REST API Security', 'cybermaps' ) . '</div>';
	}
	public static function render_exchange_fields() {
		?>
		<div id="cybermaps-exchange-root"></div>
		<?php
	}
	public static function render_nuclear_purge_field() {
		echo '<div id="cybermaps-nuclear-purge-root"></div>';
	}
	public static function maintenance_section_callback() {
		echo '<div class="cm-section-prose"><strong>' . esc_html__( 'Housekeeping & safety nets.', 'cybermaps' ) . '</strong> ' . esc_html__( 'Remove unchanged Core-owned static files and control opt-in uninstall cleanup.', 'cybermaps' ) . ' <span class="cm-desc-warn">' . esc_html__( 'Files that existed before Cybermaps or were edited afterward are retained as conflicts.', 'cybermaps' ) . '</span></div>';
	}
	public static function proxy_section_callback() {
		echo '<div class="cm-section-prose"><strong>' . esc_html__( 'Trust forwarding headers only from infrastructure you control.', 'cybermaps' ) . '</strong> ' . esc_html__( 'Leave this disabled unless a reverse proxy or load balancer terminates requests in front of WordPress. Cybermaps will use the selected header only when the direct remote address matches one of the configured CIDRs.', 'cybermaps' ) . '</div>';
	}
}
