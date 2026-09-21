<?php
/**
 * Plugin Name: R2 Cloud Storage
 * Plugin URI: https://r2cloudstorage.com
 * Description: Offload your WordPress media to Cloudflare R2 with zero egress fees. Modular add-on system for WooCommerce, LearnDash, EDD and more.
 * Version: 1.0.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: R2 Cloud Storage
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: r2-cloud-storage
 * Domain Path: /languages
 * Network: true
 *
 * @package R2CloudStorage
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'R2CS_VERSION', '1.0.2' );
define( 'R2CS_PLUGIN_FILE', __FILE__ );
define( 'R2CS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'R2CS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'R2CS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Environment mode — override in wp-config.php: define( 'R2CS_DEV_MODE', true );
if ( ! defined( 'R2CS_DEV_MODE' ) ) {
	define( 'R2CS_DEV_MODE', false );
}

// License API URL — override in wp-config.php: define( 'R2CS_LICENSE_API_URL', '...' ).
if ( ! defined( 'R2CS_LICENSE_API_URL' ) ) {
	define(
		'R2CS_LICENSE_API_URL',
		R2CS_DEV_MODE
			? 'https://wordpress.r2cloudstorage.com/api/v1/license'
			: 'https://r2cloudstorage.com/api/v1/license'
	);
}

// Minimum requirements.
define( 'R2CS_MIN_PHP', '7.4' );
define( 'R2CS_MIN_WP', '6.0' );

/**
 * Check minimum requirements before loading.
 */
function r2cs_check_requirements() {
	$errors = array();

	if ( version_compare( PHP_VERSION, R2CS_MIN_PHP, '<' ) ) {
		/* translators: %s: Minimum PHP version required */
		$errors[] = sprintf( __( 'R2 Cloud Storage requires PHP %s or higher.', 'r2-cloud-storage' ), R2CS_MIN_PHP );
	}

	if ( version_compare( get_bloginfo( 'version' ), R2CS_MIN_WP, '<' ) ) {
		/* translators: %s: Minimum WordPress version required */
		$errors[] = sprintf( __( 'R2 Cloud Storage requires WordPress %s or higher.', 'r2-cloud-storage' ), R2CS_MIN_WP );
	}

	if ( ! extension_loaded( 'json' ) ) {
		$errors[] = __( 'R2 Cloud Storage requires the PHP JSON extension.', 'r2-cloud-storage' );
	}

	if ( ! function_exists( 'hash_hmac' ) ) {
		$errors[] = __( 'R2 Cloud Storage requires hash_hmac support (PHP Hash extension).', 'r2-cloud-storage' );
	}

	return $errors;
}

/**
 * Display admin notice for requirement failures.
 */
function r2cs_requirements_notice() {
	$errors = r2cs_check_requirements();
	if ( empty( $errors ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>R2 Cloud Storage</strong></p><ul>';
	foreach ( $errors as $error ) {
		echo '<li>' . esc_html( $error ) . '</li>';
	}
	echo '</ul></div>';
}

// Bail early if requirements not met.
$r2cs_errors = r2cs_check_requirements();
if ( ! empty( $r2cs_errors ) ) {
	add_action( 'admin_notices', 'r2cs_requirements_notice' );
	return;
}

// Autoload classes.
require_once R2CS_PLUGIN_DIR . 'includes/class-r2-cloud-storage.php';
require_once R2CS_PLUGIN_DIR . 'includes/class-r2-client.php';
require_once R2CS_PLUGIN_DIR . 'includes/class-r2-settings.php';
require_once R2CS_PLUGIN_DIR . 'includes/class-r2-media-offload.php';
require_once R2CS_PLUGIN_DIR . 'includes/class-r2-signed-url.php';
require_once R2CS_PLUGIN_DIR . 'includes/class-r2-sync.php';
require_once R2CS_PLUGIN_DIR . 'includes/class-r2-addon-manager.php';
require_once R2CS_PLUGIN_DIR . 'includes/class-r2-rest-api.php';

/**
 * Returns the main plugin instance.
 *
 * @return R2CS\R2_Cloud_Storage
 */
function r2cs() {
	return R2CS\R2_Cloud_Storage::get_instance();
}

// Initialize the plugin.
add_action( 'plugins_loaded', 'r2cs', 5 );

// Activation / Deactivation hooks.
register_activation_hook( __FILE__, array( 'R2CS\R2_Cloud_Storage', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'R2CS\R2_Cloud_Storage', 'deactivate' ) );
