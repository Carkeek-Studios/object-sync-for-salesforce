<?php
/**
 * Environment switching support for Object Sync for Salesforce.
 *
 * Stores per-environment credentials (sandbox / production) as a single
 * serialised option and provides helpers used by the main plugin class and
 * the admin settings page.
 *
 * @class   Object_Sync_Sf_Environments
 * @package Object_Sync_Salesforce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Object_Sync_Sf_Environments class.
 */
class Object_Sync_Sf_Environments {

	/**
	 * Option prefix shared with the rest of the plugin.
	 *
	 * @var string
	 */
	private $option_prefix = 'object_sync_for_salesforce_';

	/**
	 * Option key that stores all environment credential arrays.
	 *
	 * @var string
	 */
	private $environments_option;

	/**
	 * Option key that stores the currently active environment slug.
	 *
	 * @var string
	 */
	private $active_env_option;

	/**
	 * Credential keys managed per-environment.
	 * Runtime token keys are kept in the shared flat options and cleared on switch.
	 *
	 * @var string[]
	 */
	private $credential_keys = array(
		'consumer_key',
		'consumer_secret',
		'callback_url',
		'login_base_url',
		'authorize_url_path',
		'token_url_path',
	);

	/**
	 * Runtime token keys stored as flat options by the Salesforce API class.
	 * These are deleted when the active environment is changed.
	 *
	 * @var string[]
	 */
	private $token_keys = array(
		'access_token',
		'refresh_token',
		'instance_url',
		'identity',
	);

	/**
	 * Default credential values per environment.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $defaults = array(
		'sandbox'    => array(
			'consumer_key'       => '',
			'consumer_secret'    => '',
			'callback_url'       => '',
			'login_base_url'     => 'https://test.salesforce.com',
			'authorize_url_path' => '/services/oauth2/authorize',
			'token_url_path'     => '/services/oauth2/token',
		),
		'production' => array(
			'consumer_key'       => '',
			'consumer_secret'    => '',
			'callback_url'       => '',
			'login_base_url'     => 'https://login.salesforce.com',
			'authorize_url_path' => '/services/oauth2/authorize',
			'token_url_path'     => '/services/oauth2/token',
		),
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->environments_option = $this->option_prefix . 'environments';
		$this->active_env_option   = $this->option_prefix . 'active_environment';
	}

	/**
	 * Returns true when multi-environment mode is active (i.e. an environment slug is stored).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return '' !== $this->get_active_environment();
	}

	/**
	 * Returns the active environment slug, or an empty string when multi-env mode is off.
	 *
	 * @return string 'sandbox' | 'production' | ''
	 */
	public function get_active_environment(): string {
		$env = get_option( $this->active_env_option, '' );
		if ( ! in_array( $env, array( 'sandbox', 'production' ), true ) ) {
			return '';
		}
		return $env;
	}

	/**
	 * Returns credentials for the active environment in the same shape as
	 * Object_Sync_Salesforce::get_login_credentials().
	 *
	 * @return array
	 */
	public function get_active_credentials(): array {
		$env  = $this->get_active_environment();
		$creds = $this->get_environment_credentials( $env );

		// Build the same array structure expected by the rest of the plugin.
		return array(
			'consumer_key'    => $creds['consumer_key'],
			'consumer_secret' => $creds['consumer_secret'],
			'callback_url'    => $creds['callback_url'],
			'login_url'       => $creds['login_base_url'],
			'authorize_path'  => $creds['authorize_url_path'],
			'token_path'      => $creds['token_url_path'],
		);
	}

	/**
	 * Returns stored credentials for a given environment, merged with defaults.
	 *
	 * @param string $env 'sandbox' | 'production'.
	 * @return array
	 */
	public function get_environment_credentials( string $env ): array {
		if ( ! array_key_exists( $env, $this->defaults ) ) {
			return array();
		}
		$all  = get_option( $this->environments_option, array() );
		$stored = isset( $all[ $env ] ) && is_array( $all[ $env ] ) ? $all[ $env ] : array();
		return array_merge( $this->defaults[ $env ], $stored );
	}

	/**
	 * Persists credentials for a given environment.
	 *
	 * @param string $env   'sandbox' | 'production'.
	 * @param array  $creds Associative array of credential key → value.
	 * @return void
	 */
	public function save_environment_credentials( string $env, array $creds ): void {
		if ( ! array_key_exists( $env, $this->defaults ) ) {
			return;
		}
		$all = get_option( $this->environments_option, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		// Only store recognised credential keys.
		$sanitised = array();
		foreach ( $this->credential_keys as $key ) {
			if ( isset( $creds[ $key ] ) ) {
				$sanitised[ $key ] = sanitize_text_field( $creds[ $key ] );
			}
		}
		$all[ $env ] = array_merge( $this->get_environment_credentials( $env ), $sanitised );
		update_option( $this->environments_option, $all );
	}

	/**
	 * Switches the active environment.
	 *
	 * Clears the runtime auth tokens so Salesforce will prompt for re-authorisation
	 * with the new environment's credentials.
	 *
	 * @param string $env 'sandbox' | 'production' | '' (empty disables multi-env mode).
	 * @return void
	 */
	public function set_active_environment( string $env ): void {
		if ( ! in_array( $env, array( 'sandbox', 'production', '' ), true ) ) {
			return;
		}
		update_option( $this->active_env_option, $env );
		$this->clear_runtime_tokens();
	}

	/**
	 * Deletes the flat runtime token options used by Object_Sync_Sf_Salesforce.
	 * Called after switching environments so the plugin prompts for re-auth.
	 *
	 * @return void
	 */
	public function clear_runtime_tokens(): void {
		foreach ( $this->token_keys as $key ) {
			delete_option( $this->option_prefix . $key );
		}
	}
}
