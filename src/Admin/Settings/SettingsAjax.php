<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsAjax {
	private const WRITE_ALERT_DISPLAY_LIMIT = 20;

	public static function display_write_alerts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$errors = get_option( 'cybermaps_static_write_errors', array() );
		if ( ! is_array( $errors ) || empty( $errors ) ) {
			return;
		}

		$total             = count( $errors );
		$is_cybermaps_page = self::is_cybermaps_admin_screen();
		$visible_errors    = $is_cybermaps_page
			? array_slice( $errors, 0, self::WRITE_ALERT_DISPLAY_LIMIT, true )
			: array();
		$settings_url      = add_query_arg(
			array(
				'page' => 'cybermaps-settings',
				'tab'  => 'sitemaps',
			),
			admin_url( 'admin.php' )
		);

		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Cybermaps Static File Engine needs attention', 'cybermaps' ); ?></strong><br>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of unresolved generated-file errors. */
						_n(
							'Cybermaps recorded %d unresolved generated-file error. The conflicted or failed file was not changed.',
							'Cybermaps recorded %d unresolved generated-file errors. Conflicted or failed files were not changed.',
							$total,
							'cybermaps'
						),
						$total
					)
				);
				?>
			</p>
			<?php if ( $is_cybermaps_page ) : ?>
				<p><?php esc_html_e( 'Resolve the ownership conflict or filesystem error, then regenerate static files.', 'cybermaps' ); ?></p>
				<ul>
					<?php foreach ( $visible_errors as $filename => $error ) : ?>
						<li>
							<code><?php echo esc_html( (string) $filename ); ?></code>
							—
							<?php
							echo esc_html(
								is_array( $error ) && ! empty( $error['message'] )
									? (string) $error['message']
									: __( 'The file could not be written.', 'cybermaps' )
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $total > count( $visible_errors ) ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of displayed errors, 2: total number of errors. */
								__( 'Showing the first %1$d of %2$d errors.', 'cybermaps' ),
								count( $visible_errors ),
								$total
							)
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Review Static File Engine settings', 'cybermaps' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private static function is_cybermaps_admin_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return is_object( $screen )
			&& isset( $screen->id )
			&& str_contains( (string) $screen->id, 'cybermaps' );
	}
	/**
	 * Stream a configuration backup or editable template as an attachment.
	 */
	public static function handle_export_config(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to export Cybermaps configuration.', 'cybermaps' ),
				esc_html__( 'Configuration Export Error', 'cybermaps' ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( 'cybermaps_export_config' );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
		$include_values = isset( $_GET['include_values'] )
			&& is_scalar( $_GET['include_values'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_GET['include_values'] ) );
		$hub            = \Cybermaps\Admin\MigrationHub::get_instance();
		try {
			$content = $include_values ? $hub->generate_backup() : $hub->generate_markdown();
		} catch ( \Throwable $error ) {
			$message = $error instanceof \RuntimeException && '' !== $error->getMessage()
				? $error->getMessage()
				: esc_html__( 'Cybermaps could not generate the configuration download. Review the stored settings and try again.', 'cybermaps' );
			wp_die(
				esc_html( $message ),
				esc_html__( 'Configuration Export Error', 'cybermaps' ),
				array(
					'response'  => 500,
					'back_link' => true,
				)
			);
		}
		$mime     = $include_values ? 'application/json' : 'text/markdown';
		$filename = self::export_filename( $include_values );

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $mime . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate download response.
		echo $content;
		exit;
	}

	/**
	 * Validate and normalize a configuration file without changing the site.
	 */
	public static function ajax_preview_config() {
		self::require_post_request();
		check_ajax_referer( 'cybermaps_exchange_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'cybermaps' ) ), 403 );
		}

		$request = self::exchange_request();

		try {
			$preview = \Cybermaps\Admin\MigrationHub::get_instance()->preview(
				$request['content'],
				$request['mode']
			);
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( \RuntimeException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 500 );
		} catch ( \Throwable $error ) {
			wp_send_json_error(
				array( 'message' => __( 'Cybermaps could not preview this configuration file. No settings were changed.', 'cybermaps' ) ),
				500
			);
		}

		wp_send_json_success(
			array(
				'preview' => $preview,
			)
		);
	}

	/**
	 * Apply the exact content and destination state approved in a preview.
	 */
	public static function ajax_import_config() {
		self::require_post_request();
		check_ajax_referer( 'cybermaps_exchange_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'cybermaps' ) ), 403 );
		}

		$request = self::exchange_request();

		$content_hash       = isset( $_POST['content_hash'] ) && is_scalar( $_POST['content_hash'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['content_hash'] ) )
			: '';
		$configuration_hash = isset( $_POST['configuration_hash'] ) && is_scalar( $_POST['configuration_hash'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['configuration_hash'] ) )
			: '';
		if ( '' === $content_hash || '' === $configuration_hash ) {
			wp_send_json_error(
				array( 'message' => __( 'Preview this file before applying it.', 'cybermaps' ) ),
				400
			);
		}

		$hub = \Cybermaps\Admin\MigrationHub::get_instance();
		try {
			// Re-evaluate the non-mutating preview so high-impact acknowledgement
			// cannot be bypassed by calling the apply action directly.
			$preview = $hub->preview( $request['content'], $request['mode'] );
			self::require_high_impact_acknowledgement( $preview );

			$result = $hub->import_previewed(
				$request['content'],
				$request['mode'],
				$content_hash,
				$configuration_hash
			);
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( \RuntimeException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 500 );
		} catch ( \Throwable $error ) {
			wp_send_json_error(
				array( 'message' => __( 'The configuration import did not complete. Review the current settings before retrying.', 'cybermaps' ) ),
				500
			);
		}

		$message = self::import_success_message( $result );

		wp_send_json_success(
			array(
				'message' => $message,
				'result'  => $result,
			)
		);
	}

	/**
	 * Read the bounded exchange payload shared by preview and apply actions.
	 *
	 * @return array{content:string,mode:string}
	 */
	private static function exchange_request(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- This private helper is reached only after ajax_preview_config() or ajax_import_config() verifies the cybermaps_exchange_action nonce.
		$raw = '';
		if ( isset( $_POST['configuration'] ) && is_string( $_POST['configuration'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- MigrationHub validates JSON and sanitizes every imported field.
			$raw = wp_unslash( $_POST['configuration'] );
		}
		if ( '' === trim( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No configuration data provided.', 'cybermaps' ) ), 400 );
		}

		// Values are decoded and sanitized field-by-field by MigrationHub. A
		// blanket text sanitizer here would corrupt JSON and multiline values.
		$content = $raw;
		$mode    = 'merge';
		if ( isset( $_POST['mode'] ) ) {
			$mode = is_scalar( $_POST['mode'] )
				? sanitize_key( wp_unslash( (string) $_POST['mode'] ) )
				: '';
		}
        // phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'content' => $content,
			'mode'    => $mode,
		);
	}

	/**
	 * Keep the configuration-exchange acknowledgement contract local while
	 * sharing its exact rule with Guided Setup.
	 *
	 * @param array<string,mixed> $preview
	 */
	private static function preview_has_high_impact_changes( array $preview ): bool {
		return \Cybermaps\Admin\ConfigurationReviewGuard::has_high_impact_changes( $preview );
	}

	/**
	 * Enforce acknowledgement only when the current preview contains high-impact changes.
	 *
	 * @param array<string,mixed> $preview Non-mutating import preview.
	 */
	private static function require_high_impact_acknowledgement( array $preview ): void {
		if ( ! self::preview_has_high_impact_changes( $preview ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- ajax_import_config() verifies the exchange nonce before calling this helper.
		$acknowledged = isset( $_POST['acknowledge_high_impact'] )
			&& is_scalar( $_POST['acknowledge_high_impact'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_POST['acknowledge_high_impact'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $acknowledged ) {
			wp_send_json_error(
				array( 'message' => __( 'Acknowledge the high-impact changes before applying this configuration.', 'cybermaps' ) ),
				400
			);
		}
	}

	/**
	 * Build the bounded import completion message from a normalized result.
	 *
	 * @param array<string,mixed> $result Import result.
	 */
	private static function import_success_message( array $result ): string {
		$changed   = is_array( $result['changed_groups'] ?? null ) ? $result['changed_groups'] : array();
		$unchanged = is_array( $result['unchanged_groups'] ?? null ) ? $result['unchanged_groups'] : array();
		$warnings  = is_array( $result['warnings'] ?? null ) ? $result['warnings'] : array();
		$message   = sprintf(
			/* translators: 1: changed configuration groups, 2: unchanged configuration groups. */
			__( 'Configuration import complete. Changed groups: %1$d; already current: %2$d.', 'cybermaps' ),
			count( $changed ),
			count( $unchanged )
		);
		if ( ! empty( $warnings ) ) {
			$message .= ' ' . implode( ' ', array_map( 'strval', $warnings ) );
		}

		return $message;
	}

	/**
	 * Reject state-changing AJAX actions sent with a safe/read-only method.
	 */
	private static function require_post_request(): void {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $request_method ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid request method.', 'cybermaps' ) ),
				405
			);
		}
	}

	private static function export_filename( bool $backup ): string {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = '' !== $host ? sanitize_file_name( $host ) : 'site';
		if ( ! $backup ) {
			return 'cybermaps-ai-brief-' . $host . '-' . current_time( 'Y-m-d-His' ) . '.cyberconf.md';
		}

		return 'cybermaps-backup-' . $host . '-' . current_time( 'Y-m-d-His' ) . '.cybermaps.json';
	}

	public static function add_plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=cybermaps-settings' ) ) . '">' . esc_html__( 'Settings', 'cybermaps' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}
