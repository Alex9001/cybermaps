<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NetworkSettings {

	/**
	 * Register hooks for network settings.
	 */
	public function register_hooks() {
		add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
		add_action( 'network_admin_edit_cybermaps_save_network_settings', array( $this, 'save_network_settings' ) );
	}

	/**
	 * Add menu item to the Network Admin.
	 */
	public function add_network_menu() {
		add_menu_page(
			__( 'Cybermaps Network Settings', 'cybermaps' ),
			__( 'Cybermaps', 'cybermaps' ),
			'manage_network_options',
			'cybermaps-network',
			array( $this, 'render_network_settings' ),
			'dashicons-location-alt'
		);
	}

	/**
	 * Render the network settings page.
	 */
	public function render_network_settings() {
		$options             = get_site_option( 'cybermaps_network_settings', array() );
		$enable_master_index = isset( $options['enable_master_index'] ) && '1' === (string) $options['enable_master_index'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cybermaps Network Settings', 'cybermaps' ); ?></h1>
			<?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['updated'] ) ) :
				?>
				<div class="updated notice is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'cybermaps' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="edit.php?action=cybermaps_save_network_settings">
				<?php wp_nonce_field( 'cybermaps_network_settings_save' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Network sitemap index', 'cybermaps' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cybermaps_network_settings[enable_master_index]" value="1" <?php checked( $enable_master_index ); ?>>
								<?php esc_html_e( 'Publish a centralized sitemap index', 'cybermaps' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The index lists the Cybermaps sitemap for each public site where the plugin is active:', 'cybermaps' ); ?>
								<code><?php echo esc_url( network_home_url( 'sitemap-network.xml' ) ); ?></code>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save network settings.
	 */
	public function save_network_settings() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die(
				esc_html__( 'Unauthorized', 'cybermaps' ),
				'',
				array( 'response' => 403 )
			);
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $request_method ) {
			wp_die(
				esc_html__( 'Invalid request method.', 'cybermaps' ),
				'',
				array( 'response' => 405 )
			);
		}
		check_admin_referer( 'cybermaps_network_settings_save' );

		$enable_master_index = isset( $_POST['cybermaps_network_settings'] )
			&& is_array( $_POST['cybermaps_network_settings'] )
			&& isset( $_POST['cybermaps_network_settings']['enable_master_index'] )
			&& is_scalar( $_POST['cybermaps_network_settings']['enable_master_index'] )
				? sanitize_key(
					wp_unslash( (string) $_POST['cybermaps_network_settings']['enable_master_index'] )
				)
				: '';
		$sanitized           = array(
			'enable_master_index' => '1' === $enable_master_index ? '1' : '0',
		);

		update_site_option( 'cybermaps_network_settings', $sanitized );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'cybermaps-network',
					'updated' => 'true',
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
