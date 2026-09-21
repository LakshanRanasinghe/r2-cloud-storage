<?php
/**
 * Main plugin class - Orchestrator.
 *
 * @package R2CloudStorage
 */

namespace R2CS;

defined( 'ABSPATH' ) || exit;

/**
 * Class R2_Cloud_Storage
 *
 * Singleton main class that initializes all components.
 */
final class R2_Cloud_Storage {

	/**
	 * Single instance.
	 *
	 * @var R2_Cloud_Storage|null
	 */
	private static $instance = null;

	/**
	 * Plugin components.
	 *
	 * @var array
	 */
	private $components = array();

	/**
	 * Get singleton instance.
	 *
	 * @return R2_Cloud_Storage
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_components();
		$this->init_hooks();

		/**
		 * Fires after R2 Cloud Storage core is fully loaded.
		 * Add-ons should hook here to register themselves.
		 *
		 * @param R2_Cloud_Storage $plugin Main plugin instance.
		 */
		do_action( 'r2cs_loaded', $this );
	}

	/**
	 * Initialize all core components.
	 */
	private function init_components() {
		$this->components['settings']     = new R2_Settings();
		$this->components['client']       = new R2_Client( $this->components['settings'] );
		$this->components['signed_url']   = new R2_Signed_Url( $this->components['client'] );
		$this->components['media']        = new R2_Media_Offload( $this->components['client'], $this->components['settings'] );
		$this->components['sync']         = new R2_Sync( $this->components['client'], $this->components['settings'] );
		$this->components['addons']       = new R2_Addon_Manager();
		$this->components['rest_api']     = new R2_REST_API( $this->components['client'], $this->components['settings'], $this->components['sync'] );
	}

	/**
	 * Register global hooks.
	 */
	private function init_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . R2CS_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Enqueue admin CSS/JS on plugin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		$plugin_pages = array(
			'toplevel_page_r2-cloud-storage',
			'r2-storage_page_r2cs-sync',
			'r2-storage_page_r2cs-addons',
		);

		if ( ! in_array( $hook, $plugin_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'r2cs-admin',
			R2CS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			R2CS_VERSION
		);

		wp_enqueue_script(
			'r2cs-admin',
			R2CS_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-util' ),
			R2CS_VERSION,
			true
		);

		wp_localize_script(
			'r2cs-admin',
			'r2csAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'restUrl'  => rest_url( 'r2cs/v1/' ),
				'nonce'    => wp_create_nonce( 'r2cs_admin' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'testing'       => __( 'Testing connection...', 'r2-cloud-storage' ),
					'success'       => __( 'Connection established successfully!', 'r2-cloud-storage' ),
					'error'         => __( 'Connection error.', 'r2-cloud-storage' ),
					'syncing'       => __( 'Syncing...', 'r2-cloud-storage' ),
					'confirm'       => __( 'Are you sure?', 'r2-cloud-storage' ),
					'saved'         => __( 'Settings saved.', 'r2-cloud-storage' ),
					'syncStarting'  => __( 'Starting sync...', 'r2-cloud-storage' ),
					'syncPaused'    => __( 'Sync paused by user.', 'r2-cloud-storage' ),
					'syncResumed'   => __( 'Resume', 'r2-cloud-storage' ),
					'paused'        => __( 'Paused', 'r2-cloud-storage' ),
					'completed'     => __( 'Completed', 'r2-cloud-storage' ),
					'syncComplete'  => __( 'Sync completed!', 'r2-cloud-storage' ),
					/* translators: %1$d: number of items succeeded, %2$d: total number of items in the batch */
					'batchSuccess'  => __( 'Batch processed: %1$d/%2$d succeeded.', 'r2-cloud-storage' ),
					/* translators: %1$d: the error ID number, %2$s: the error message */
					'errorId'       => __( 'Error ID %1$d: %2$s', 'r2-cloud-storage' ),
					'networkError'  => __( 'Network error', 'r2-cloud-storage' ),
					'enterLicense'  => __( 'Please enter a license key.', 'r2-cloud-storage' ),
					'activating'    => __( 'Activating...', 'r2-cloud-storage' ),
					'deactivating'  => __( 'Deactivating...', 'r2-cloud-storage' ),
					'deactivate'    => __( 'Deactivate', 'r2-cloud-storage' ),
					'activate'      => __( 'Activate License', 'r2-cloud-storage' ),
					'licenseActive' => __( 'Active', 'r2-cloud-storage' ),
					'licenseInactive' => __( 'Inactive', 'r2-cloud-storage' ),
					'startSync'          => __( 'Start Sync', 'r2-cloud-storage' ),
					'folderSyncStarting' => __( 'Scanning uploads folder and starting sync...', 'r2-cloud-storage' ),
					'failedReset'        => __( 'Failed status reset successfully.', 'r2-cloud-storage' ),
					'errorUnknown'       => __( 'Unknown error', 'r2-cloud-storage' ),
				),
			)
		);
	}

	/**
	 * Add Settings link to plugin list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=r2-cloud-storage' ),
			__( 'Settings', 'r2-cloud-storage' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Get a component by key.
	 *
	 * @param string $key Component key.
	 * @return object|null
	 */
	public function get( $key ) {
		return isset( $this->components[ $key ] ) ? $this->components[ $key ] : null;
	}

	/**
	 * Get the R2 Client.
	 *
	 * @return R2_Client
	 */
	public function client() {
		return $this->components['client'];
	}

	/**
	 * Get signed URL generator.
	 *
	 * @return R2_Signed_Url
	 */
	public function signed_url() {
		return $this->components['signed_url'];
	}

	/**
	 * Get the addon manager.
	 *
	 * @return R2_Addon_Manager
	 */
	public function addons() {
		return $this->components['addons'];
	}

	/**
	 * Get the settings.
	 *
	 * @return R2_Settings
	 */
	public function settings() {
		return $this->components['settings'];
	}

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		// Set default options.
		$defaults = array(
			'account_id'    => '',
			'access_key'    => '',
			'secret_key'    => '',
			'bucket'        => '',
			'custom_domain' => '',
			'offload_media' => false,
			'remove_local'  => false,
			'path_prefix'   => 'wp-content/uploads/',
			'signed_urls'   => false,
			'signed_expiry' => 3600,
		);

		if ( false === get_option( 'r2cs_settings' ) ) {
			add_option( 'r2cs_settings', $defaults );
		}

		// Store version for future migrations.
		update_option( 'r2cs_version', R2CS_VERSION );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}
}
