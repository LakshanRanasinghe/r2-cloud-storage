<?php
/**
 * Settings management and admin page.
 *
 * @package R2CloudStorage
 */

namespace R2CS;

defined( 'ABSPATH' ) || exit;

class R2_Settings {

	/** @var string Option key in wp_options. */
	const OPTION_KEY = 'r2cs_settings';

	/** @var array Cached settings. */
	private $settings = null;

	/**
	 * Constructor — register admin menus and settings.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	// ------------------------------------------------------------------
	//  Getters
	// ------------------------------------------------------------------

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( $key, $default = '' ) {
		$settings = $this->get_all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function get_all() {
		if ( null === $this->settings ) {
			$this->settings = get_option( self::OPTION_KEY, array() );
		}
		return $this->settings;
	}

	/**
	 * Update a single setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 */
	public function set( $key, $value ) {
		$settings         = $this->get_all();
		$settings[ $key ] = $value;
		update_option( self::OPTION_KEY, $settings );
		$this->settings = $settings;
	}

	/**
	 * Check if the plugin is configured (has credentials).
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->get( 'account_id' ) )
			&& ! empty( $this->get( 'access_key' ) )
			&& ! empty( $this->get( 'secret_key' ) )
			&& ! empty( $this->get( 'bucket' ) );
	}

	// ------------------------------------------------------------------
	//  Admin Menus
	// ------------------------------------------------------------------

	/**
	 * Register admin menu pages.
	 */
	public function register_menus() {
		add_menu_page(
			__( 'R2 Cloud Storage', 'r2-cloud-storage' ),
			__( 'R2 Storage', 'r2-cloud-storage' ),
			'manage_options',
			'r2-cloud-storage',
			array( $this, 'render_settings_page' ),
			'dashicons-cloud-saved',
			81
		);

		add_submenu_page(
			'r2-cloud-storage',
			__( 'Settings', 'r2-cloud-storage' ),
			__( 'Settings', 'r2-cloud-storage' ),
			'manage_options',
			'r2-cloud-storage',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'r2-cloud-storage',
			__( 'Sync', 'r2-cloud-storage' ),
			__( 'Sync', 'r2-cloud-storage' ),
			'manage_options',
			'r2cs-sync',
			array( $this, 'render_sync_page' )
		);

		add_submenu_page(
			'r2-cloud-storage',
			__( 'Add-ons', 'r2-cloud-storage' ),
			__( 'Add-ons', 'r2-cloud-storage' ),
			'manage_options',
			'r2cs-addons',
			array( $this, 'render_addons_page' )
		);
	}

	// ------------------------------------------------------------------
	//  Settings API
	// ------------------------------------------------------------------

	/**
	 * Register settings fields.
	 */
	public function register_settings() {
		register_setting( 'r2cs_settings_group', self::OPTION_KEY, array(
			'type'              => 'object',
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );

		// Section: Credentials.
		add_settings_section(
			'r2cs_credentials',
			__( 'Cloudflare R2 Credentials', 'r2-cloud-storage' ),
			array( $this, 'render_credentials_section' ),
			'r2-cloud-storage'
		);

		add_settings_field( 'account_id', __( 'Account ID', 'r2-cloud-storage' ), array( $this, 'render_text_field' ), 'r2-cloud-storage', 'r2cs_credentials', array( 'key' => 'account_id', 'description' => __( 'Find it in Cloudflare Dashboard → R2 → Account ID', 'r2-cloud-storage' ) ) );
		add_settings_field( 'access_key', __( 'Access Key ID', 'r2-cloud-storage' ), array( $this, 'render_text_field' ), 'r2-cloud-storage', 'r2cs_credentials', array( 'key' => 'access_key', 'description' => __( 'Generate at R2 → Manage R2 API Tokens', 'r2-cloud-storage' ) ) );
		add_settings_field( 'secret_key', __( 'Secret Access Key', 'r2-cloud-storage' ), array( $this, 'render_password_field' ), 'r2-cloud-storage', 'r2cs_credentials', array( 'key' => 'secret_key' ) );
		add_settings_field( 'bucket', __( 'Bucket', 'r2-cloud-storage' ), array( $this, 'render_text_field' ), 'r2-cloud-storage', 'r2cs_credentials', array( 'key' => 'bucket', 'description' => __( 'R2 bucket name.', 'r2-cloud-storage' ) ) );

		// Section: Delivery.
		add_settings_section(
			'r2cs_delivery',
			__( 'Content Delivery', 'r2-cloud-storage' ),
			null,
			'r2-cloud-storage'
		);

		add_settings_field( 'custom_domain', __( 'Custom Domain', 'r2-cloud-storage' ), array( $this, 'render_text_field' ), 'r2-cloud-storage', 'r2cs_delivery', array( 'key' => 'custom_domain', 'description' => __( 'e.g. cdn.yoursite.com — Set up in Cloudflare R2 → Custom Domains.', 'r2-cloud-storage' ), 'placeholder' => 'cdn.yoursite.com' ) );

		// Section: Media Offload.
		add_settings_section(
			'r2cs_media',
			__( 'Media Offload', 'r2-cloud-storage' ),
			null,
			'r2-cloud-storage'
		);

		add_settings_field( 'offload_media', __( 'Auto Offload', 'r2-cloud-storage' ), array( $this, 'render_checkbox_field' ), 'r2-cloud-storage', 'r2cs_media', array( 'key' => 'offload_media', 'label' => __( 'Automatically upload new media to R2.', 'r2-cloud-storage' ) ) );
		add_settings_field( 'remove_local', __( 'Remove Local Copy', 'r2-cloud-storage' ), array( $this, 'render_checkbox_field' ), 'r2-cloud-storage', 'r2cs_media', array( 'key' => 'remove_local', 'label' => __( 'Remove local file after uploading to R2. Saves disk space.', 'r2-cloud-storage' ) ) );
		add_settings_field( 'path_prefix', __( 'Path Prefix', 'r2-cloud-storage' ), array( $this, 'render_text_field' ), 'r2-cloud-storage', 'r2cs_media', array( 'key' => 'path_prefix', 'description' => __( 'Prefix for object keys in R2.', 'r2-cloud-storage' ), 'placeholder' => 'wp-content/uploads/' ) );
		add_settings_field( 'local_uploads_path', __( 'Local Uploads Directory Path', 'r2-cloud-storage' ), array( $this, 'render_text_field' ), 'r2-cloud-storage', 'r2cs_media', array( 'key' => 'local_uploads_path', 'description' => __( 'Absolute server path to your local uploads directory (e.g. <code>/srv/htdocs/besv-uploads</code>). Leave empty to auto-detect.', 'r2-cloud-storage' ), 'placeholder' => '/srv/htdocs/besv-uploads' ) );

		// Section: Signed URLs.
		add_settings_section(
			'r2cs_signed',
			__( 'Signed URLs', 'r2-cloud-storage' ),
			null,
			'r2-cloud-storage'
		);

		add_settings_field( 'signed_urls', __( 'Enable Signed URLs', 'r2-cloud-storage' ), array( $this, 'render_checkbox_field' ), 'r2-cloud-storage', 'r2cs_signed', array( 'key' => 'signed_urls', 'label' => __( 'Generate expiring URLs to protect content.', 'r2-cloud-storage' ) ) );
		add_settings_field( 'signed_expiry', __( 'Expiration (seconds)', 'r2-cloud-storage' ), array( $this, 'render_number_field' ), 'r2-cloud-storage', 'r2cs_signed', array( 'key' => 'signed_expiry', 'description' => __( 'Time in seconds until the URL expires. Default: 3600 (1 hour).', 'r2-cloud-storage' ), 'min' => 60, 'max' => 604800 ) );
	}

	/**
	 * Sanitize settings before save.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['account_id']    = sanitize_text_field( $input['account_id'] ?? '' );
		$sanitized['access_key']    = sanitize_text_field( $input['access_key'] ?? '' );
		$sanitized['bucket']        = sanitize_text_field( $input['bucket'] ?? '' );
		$sanitized['custom_domain'] = sanitize_text_field( $input['custom_domain'] ?? '' );
		$sanitized['path_prefix']        = sanitize_text_field( $input['path_prefix'] ?? 'wp-content/uploads/' );
		$sanitized['local_uploads_path'] = sanitize_text_field( $input['local_uploads_path'] ?? '' );
		$sanitized['offload_media']      = ! empty( $input['offload_media'] );
		$sanitized['remove_local']  = ! empty( $input['remove_local'] );
		$sanitized['signed_urls']   = ! empty( $input['signed_urls'] );
		$sanitized['signed_expiry'] = absint( $input['signed_expiry'] ?? 3600 );

		// Secret key: preserve existing if empty (masked field).
		// Do not use sanitize_text_field() here as it may strip valid characters from the key.
		if ( ! empty( $input['secret_key'] ) ) {
			$sanitized['secret_key'] = trim( wp_unslash( $input['secret_key'] ) );
		} else {
			$existing = $this->get_all();
			$sanitized['secret_key'] = $existing['secret_key'] ?? '';
		}

		// Clamp signed_expiry.
		$sanitized['signed_expiry'] = max( 60, min( 604800, $sanitized['signed_expiry'] ) );

		// Reset cache.
		$this->settings = $sanitized;

		return $sanitized;
	}

	// ------------------------------------------------------------------
	//  Render pages
	// ------------------------------------------------------------------

	/**
	 * Render main settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include R2CS_PLUGIN_DIR . 'templates/settings-page.php';
	}

	/**
	 * Render sync page.
	 */
	public function render_sync_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include R2CS_PLUGIN_DIR . 'templates/sync-page.php';
	}

	/**
	 * Render add-ons page.
	 */
	public function render_addons_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include R2CS_PLUGIN_DIR . 'templates/addons-page.php';
	}

	// ------------------------------------------------------------------
	//  Field renderers
	// ------------------------------------------------------------------

	/**
	 * Section description for credentials.
	 */
	public function render_credentials_section() {
		echo '<p>' . esc_html__( 'Enter your Cloudflare R2 credentials. You can find them in the Cloudflare dashboard under R2 → Manage R2 API Tokens.', 'r2-cloud-storage' ) . '</p>';
	}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_text_field( $args ) {
		$key         = $args['key'];
		$value       = $this->get( $key );
		$placeholder = $args['placeholder'] ?? '';
		$description = $args['description'] ?? '';

		printf(
			'<input type="text" id="r2cs_%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text" placeholder="%4$s" />',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);

		if ( ! empty( $description ) ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
	}

	/**
	 * Render a password input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_password_field( $args ) {
		$key   = $args['key'];
		$value = $this->get( $key );
		$masked = ! empty( $value ) ? '••••••••••••••••' : '';

		printf(
			'<input type="password" id="r2cs_%1$s" name="%2$s[%1$s]" value="" class="regular-text" placeholder="%3$s" autocomplete="new-password" />',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $masked )
		);

		if ( ! empty( $value ) ) {
			echo '<p class="description">' . esc_html__( 'Already configured. Leave blank to keep the current key.', 'r2-cloud-storage' ) . '</p>';
		}
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_checkbox_field( $args ) {
		$key   = $args['key'];
		$value = $this->get( $key );
		$label = $args['label'] ?? '';

		printf(
			'<label><input type="checkbox" id="r2cs_%1$s" name="%2$s[%1$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			checked( $value, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render a number input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_number_field( $args ) {
		$key         = $args['key'];
		$value       = $this->get( $key, 3600 );
		$min         = $args['min'] ?? 0;
		$max         = $args['max'] ?? 999999;
		$description = $args['description'] ?? '';

		printf(
			'<input type="number" id="r2cs_%1$s" name="%2$s[%1$s]" value="%3$s" class="small-text" min="%4$d" max="%5$d" />',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			intval( $min ),
			intval( $max )
		);

		if ( ! empty( $description ) ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
	}
}
