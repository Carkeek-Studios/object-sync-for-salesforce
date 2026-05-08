<?php
/**
 * Admin template for the Environments settings tab (Carkeek fork).
 *
 * Allows admins to store credentials for both sandbox and production
 * Salesforce environments and toggle which is active. Switching environments
 * clears the stored OAuth tokens so a fresh authorisation flow is required.
 *
 * @package Object_Sync_Salesforce
 */

$environments     = object_sync_for_salesforce()->environments;
$active_env       = $environments->get_active_environment();
$sandbox_creds    = $environments->get_environment_credentials( 'sandbox' );
$production_creds = $environments->get_environment_credentials( 'production' );

$authorize_tab_url = esc_url( get_admin_url( null, 'options-general.php?page=object-sync-salesforce-admin&tab=authorize' ) );

// phpcs:ignore WordPress.Security.NonceVerification
$updated  = isset( $_GET['updated'] ) && '1' === $_GET['updated'];
// phpcs:ignore WordPress.Security.NonceVerification
$switched = isset( $_GET['switched'] ) ? sanitize_key( $_GET['switched'] ) : '';

// A credential set is "configured" when the consumer_key is present.
$sandbox_configured    = ! empty( $sandbox_creds['consumer_key'] );
$production_configured = ! empty( $production_creds['consumer_key'] );
?>

<?php if ( $updated && $switched ) : ?>
<div class="notice notice-warning is-dismissible">
	<p>
	<?php
	if ( '' === $switched ) {
		esc_html_e( 'Environment set to single-credential mode (Settings tab).', 'object-sync-for-salesforce' );
	} else {
		echo wp_kses(
			sprintf(
				/* translators: 1: environment name, 2: Authorize tab URL */
				__( 'Switched to <strong>%1$s</strong>. OAuth tokens have been cleared &mdash; you must <a href="%2$s">re-authorize Salesforce</a> to reconnect.', 'object-sync-for-salesforce' ),
				esc_html( ucfirst( $switched ) ),
				$authorize_tab_url
			),
			array(
				'strong' => array(),
				'a'      => array( 'href' => array() ),
			)
		);
	}
	?>
	</p>
</div>
<?php elseif ( $updated ) : ?>
<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Environment settings saved.', 'object-sync-for-salesforce' ); ?></p></div>
<?php endif; ?>

<p>
	<?php esc_html_e( 'Store credentials for your Salesforce sandbox and production environments. Choose the active environment to use for sync. Switching environments clears the stored OAuth tokens and requires re-authorisation.', 'object-sync-for-salesforce' ); ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'osf_save_environments' ); ?>
	<input type="hidden" name="action" value="osf_save_environments">

	<h2><?php esc_html_e( 'Active Environment', 'object-sync-for-salesforce' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Environment', 'object-sync-for-salesforce' ); ?></th>
			<td>
				<fieldset>
					<label>
						<input type="radio" name="active_environment" value="" <?php checked( '', $active_env ); ?>>
						<?php esc_html_e( 'None (use Settings tab credentials)', 'object-sync-for-salesforce' ); ?>
					</label><br>

					<label <?php echo ! $sandbox_configured ? 'title="' . esc_attr__( 'Enter Sandbox credentials below before activating.', 'object-sync-for-salesforce' ) . '"' : ''; ?>>
						<input type="radio" name="active_environment" value="sandbox"
							<?php checked( 'sandbox', $active_env ); ?>
							<?php disabled( ! $sandbox_configured && 'sandbox' !== $active_env ); ?>>
						<?php esc_html_e( 'Sandbox', 'object-sync-for-salesforce' ); ?>
						<?php if ( ! $sandbox_configured ) : ?>
							<em class="description"> &mdash; <?php esc_html_e( 'credentials not yet configured', 'object-sync-for-salesforce' ); ?></em>
						<?php endif; ?>
					</label><br>

					<label <?php echo ! $production_configured ? 'title="' . esc_attr__( 'Enter Production credentials below before activating.', 'object-sync-for-salesforce' ) . '"' : ''; ?>>
						<input type="radio" name="active_environment" value="production"
							<?php checked( 'production', $active_env ); ?>
							<?php disabled( ! $production_configured && 'production' !== $active_env ); ?>>
						<?php esc_html_e( 'Production', 'object-sync-for-salesforce' ); ?>
						<?php if ( ! $production_configured ) : ?>
							<em class="description"> &mdash; <?php esc_html_e( 'credentials not yet configured', 'object-sync-for-salesforce' ); ?></em>
						<?php endif; ?>
					</label>
				</fieldset>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: URL of the Authorize tab */
							__( 'After switching environments, visit the <a href="%s">Authorize tab</a> to connect to Salesforce.', 'object-sync-for-salesforce' ),
							$authorize_tab_url
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</p>
			</td>
		</tr>
	</table>

	<?php foreach ( array( 'sandbox' => $sandbox_creds, 'production' => $production_creds ) as $env => $creds ) : ?>
	<?php $is_active = ( $env === $active_env ); ?>
	<h2>
		<?php echo esc_html( ucfirst( $env ) ); ?>
		<?php if ( $is_active ) : ?>
			<span class="dashicons dashicons-yes-alt" style="color:#46b450; vertical-align:middle;" title="<?php esc_attr_e( 'Active environment', 'object-sync-for-salesforce' ); ?>"></span>
			<span class="screen-reader-text"><?php esc_html_e( '(active)', 'object-sync-for-salesforce' ); ?></span>
		<?php endif; ?>
	</h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="env-<?php echo esc_attr( $env ); ?>-consumer-key"><?php esc_html_e( 'Consumer Key', 'object-sync-for-salesforce' ); ?></label></th>
			<td>
				<input type="text" class="regular-text"
					id="env-<?php echo esc_attr( $env ); ?>-consumer-key"
					name="env_<?php echo esc_attr( $env ); ?>[consumer_key]"
					value="<?php echo esc_attr( $creds['consumer_key'] ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="env-<?php echo esc_attr( $env ); ?>-consumer-secret"><?php esc_html_e( 'Consumer Secret', 'object-sync-for-salesforce' ); ?></label></th>
			<td>
				<input type="text" class="regular-text"
					id="env-<?php echo esc_attr( $env ); ?>-consumer-secret"
					name="env_<?php echo esc_attr( $env ); ?>[consumer_secret]"
					value="<?php echo esc_attr( $creds['consumer_secret'] ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="env-<?php echo esc_attr( $env ); ?>-callback-url"><?php esc_html_e( 'Callback URL', 'object-sync-for-salesforce' ); ?></label></th>
			<td>
				<input type="url" class="regular-text"
					id="env-<?php echo esc_attr( $env ); ?>-callback-url"
					name="env_<?php echo esc_attr( $env ); ?>[callback_url]"
					value="<?php echo esc_attr( $creds['callback_url'] ); ?>">
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: admin URL for the Authorize tab */
							__( 'Typically: %s', 'object-sync-for-salesforce' ),
							get_admin_url( null, 'options-general.php?page=object-sync-salesforce-admin&tab=authorize' )
						)
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="env-<?php echo esc_attr( $env ); ?>-login-base-url"><?php esc_html_e( 'Login Base URL', 'object-sync-for-salesforce' ); ?></label></th>
			<td>
				<input type="url" class="regular-text"
					id="env-<?php echo esc_attr( $env ); ?>-login-base-url"
					name="env_<?php echo esc_attr( $env ); ?>[login_base_url]"
					value="<?php echo esc_attr( $creds['login_base_url'] ); ?>">
				<p class="description">
					<?php
					/* translators: 1: production login URL, 2: sandbox login URL */
					echo wp_kses_post(
						sprintf(
							__( 'Use %1$s for production or %2$s for sandbox.', 'object-sync-for-salesforce' ),
							'<code>https://login.salesforce.com</code>',
							'<code>https://test.salesforce.com</code>'
						)
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="env-<?php echo esc_attr( $env ); ?>-authorize-url-path"><?php esc_html_e( 'Authorize URL Path', 'object-sync-for-salesforce' ); ?></label></th>
			<td>
				<input type="text" class="regular-text"
					id="env-<?php echo esc_attr( $env ); ?>-authorize-url-path"
					name="env_<?php echo esc_attr( $env ); ?>[authorize_url_path]"
					value="<?php echo esc_attr( $creds['authorize_url_path'] ); ?>">
				<p class="description"><?php esc_html_e( 'For most installs, leave as default.', 'object-sync-for-salesforce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="env-<?php echo esc_attr( $env ); ?>-token-url-path"><?php esc_html_e( 'Token URL Path', 'object-sync-for-salesforce' ); ?></label></th>
			<td>
				<input type="text" class="regular-text"
					id="env-<?php echo esc_attr( $env ); ?>-token-url-path"
					name="env_<?php echo esc_attr( $env ); ?>[token_url_path]"
					value="<?php echo esc_attr( $creds['token_url_path'] ); ?>">
				<p class="description"><?php esc_html_e( 'For most installs, leave as default.', 'object-sync-for-salesforce' ); ?></p>
			</td>
		</tr>
	</table>
	<?php endforeach; ?>

	<?php submit_button( esc_html__( 'Save environment settings', 'object-sync-for-salesforce' ) ); ?>
</form>
