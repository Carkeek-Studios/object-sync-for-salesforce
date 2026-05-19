<?php
/**
 * Plugin Name: Object Sync for Salesforce
 * Description: Fork of Object Sync for Salesforce plugin, with customizations to allow bulk importing and chaining of syncs.
 * Version: 3.0.00
 * Author: Carkeek Studios / MinnPost
 * Author URI: https://carkeekstudios.com
 * GitHub Plugin URI: https://github.com/Carkeek-Studios/object-sync-for-salesforce
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: object-sync-for-salesforce
 *
 * @package Object_Sync_Salesforce
 */

/* Exit if accessed directly */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * The full path to the main file of this plugin
 *
 * This can later be passed to functions such as
 * plugin_dir_path(), plugins_url() and plugin_basename()
 * to retrieve information about plugin paths
 *
 * @since 2.0.0
 * @var string
 */
define( 'OBJECT_SYNC_SF_FILE', __FILE__ );

/**
 * The plugin's current version
 *
 * @since 2.0.0
 * @var string
 */
define( 'OBJECT_SYNC_SF_VERSION', '2.2.13' );

/**
 * The default Salesforce API version, unless it has been overridden by pre-existing option or by developers
 *
 * @since 2.0.0
 * @var string
 */
define( 'OBJECT_SYNC_SF_DEFAULT_API_VERSION', '55.0' );

// Load the autoloader.
require_once 'lib/autoloader.php';

/**
 * Retrieve the instance of the main plugin class
 *
 * @since 2.0.0
 * @return Object_Sync_Salesforce
 */
function object_sync_for_salesforce() {
	static $plugin;

	if ( is_null( $plugin ) ) {
		$plugin = new Object_Sync_Salesforce( OBJECT_SYNC_SF_VERSION, OBJECT_SYNC_SF_FILE );
	}

	return $plugin;
}

object_sync_for_salesforce()->init();
