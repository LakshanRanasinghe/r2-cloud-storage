<?php
/**
 * Add-on Manager — Registration, licensing, and lifecycle management for add-ons.
 *
 * This is the backbone of the modular business model.
 * Add-ons register themselves here and the core provides common infrastructure.
 *
 * @package R2CloudStorage
 */

namespace R2CS;

defined( 'ABSPATH' ) || exit;

class R2_Addon_Manager {

	/**
	 * Registry of all known add-ons (installed + available).
	 *
	 * @var array
	 */
	private $addons = array();

	/**
	 * Registry of active (loaded) add-on instances.
	 *
	 * @var array
	 */
	private $active = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Let add-ons register themselves.
		add_action( 'r2cs_loaded', array( $this, 'register_catalog' ), 5 );

		/**
		 * Fires when the add-on manager is ready.
		 * Add-ons should hook into 'r2cs_register_addon' to register.
		 */
		add_action( 'r2cs_loaded', array( $this, 'init_addons' ), 15 );
	}

	/**
	 * Register the catalog of all known add-ons (both installed and available for purchase).
	 */
	public function register_catalog() {
		$this->addons = array(
			'woocommerce' => array(
				'name'         => __( 'WooCommerce', 'r2-cloud-storage' ),
				'slug'         => 'r2cs-addon-woocommerce',
				'description'  => __( 'Digital downloads via R2, offloaded product images, signed URLs per order.', 'r2-cloud-storage' ),
				'icon'         => 'dashicons-cart',
				'requires'     => 'woocommerce/woocommerce.php',
				'price_brl'    => 'R$ 197/ano',
				'price_usd'    => '$49/yr',
				'url'          => 'https://r2cloudstorage.com/addons/woocommerce',
				'status'       => 'available', // available | installed | active
				'version'      => '',
				'class'        => '',
			),
			'learndash' => array(
				'name'         => __( 'LearnDash', 'r2-cloud-storage' ),
				'slug'         => 'r2cs-addon-learndash',
				'description'  => __( 'Course videos and materials served via R2 with signed URL protection.', 'r2-cloud-storage' ),
				'icon'         => 'dashicons-welcome-learn-more',
				'requires'     => 'sfwd-lms/sfwd_lms.php',
				'price_brl'    => 'R$ 247/ano',
				'price_usd'    => '$59/yr',
				'url'          => 'https://r2cloudstorage.com/addons/learndash',
				'status'       => 'available',
				'version'      => '',
				'class'        => '',
			),
			'tutor-lms' => array(
				'name'         => __( 'Tutor LMS', 'r2-cloud-storage' ),
				'slug'         => 'r2cs-addon-tutor-lms',
				'description'  => __( 'Protected video streaming and course materials via Cloudflare R2.', 'r2-cloud-storage' ),
				'icon'         => 'dashicons-video-alt3',
				'requires'     => 'tutor/tutor.php',
				'price_brl'    => 'R$ 197/ano',
				'price_usd'    => '$49/yr',
				'url'          => 'https://r2cloudstorage.com/addons/tutor-lms',
				'status'       => 'available',
				'version'      => '',
				'class'        => '',
			),
			'edd' => array(
				'name'         => __( 'Easy Digital Downloads', 'r2-cloud-storage' ),
				'slug'         => 'r2cs-addon-edd',
				'description'  => __( 'Secure digital download delivery via R2 with temporary signed links.', 'r2-cloud-storage' ),
				'icon'         => 'dashicons-download',
				'requires'     => 'easy-digital-downloads/easy-digital-downloads.php',
				'price_brl'    => 'R$ 147/ano',
				'price_usd'    => '$39/yr',
				'url'          => 'https://r2cloudstorage.com/addons/edd',
				'status'       => 'available',
				'version'      => '',
				'class'        => '',
			),
			'memberpress' => array(
				'name'         => __( 'MemberPress', 'r2-cloud-storage' ),
				'slug'         => 'r2cs-addon-memberpress',
				'description'  => __( 'Protected member content served via R2 with access control.', 'r2-cloud-storage' ),
				'icon'         => 'dashicons-groups',
				'requires'     => 'memberpress/memberpress.php',
				'price_brl'    => 'R$ 197/ano',
				'price_usd'    => '$49/yr',
				'url'          => 'https://r2cloudstorage.com/addons/memberpress',
				'status'       => 'available',
				'version'      => '',
				'class'        => '',
			),
			'buddyboss' => array(
				'name'         => __( 'BuddyBoss', 'r2-cloud-storage' ),
				'slug'         => 'r2cs-addon-buddyboss',
				'description'  => __( 'Community uploads and user media stored on R2.', 'r2-cloud-storage' ),
				'icon'         => 'dashicons-buddicons-buddypress-logo',
				'requires'     => 'buddyboss-platform/bp-loader.php',
				'price_brl'    => 'R$ 197/ano',
				'price_usd'    => '$49/yr',
				'url'          => 'https://r2cloudstorage.com/addons/buddyboss',
				'status'       => 'available',
				'version'      => '',
				'class'        => '',
			),
		);

		/**
		 * Filter the add-on catalog.
		 * Third-party developers can register their own add-ons here.
		 *
		 * @param array $addons Add-on catalog.
		 */
		$this->addons = apply_filters( 'r2cs_addon_catalog', $this->addons );
	}

	/**
	 * Initialize active add-ons.
	 * Called after all plugins are loaded so add-ons can register themselves.
	 */
	public function init_addons() {
		/**
		 * Fires to let add-ons register themselves.
		 *
		 * Add-on plugins should hook here:
		 *
		 *   add_action( 'r2cs_register_addon', function( $manager ) {
		 *       $manager->register( 'woocommerce', '1.0.0', 'R2CS_Addon_WooCommerce' );
		 *   });
		 */
		do_action( 'r2cs_register_addon', $this );
	}

	/**
	 * Register an active add-on.
	 *
	 * Called by add-on plugins during 'r2cs_register_addon'.
	 *
	 * @param string $addon_key Add-on key (must match catalog).
	 * @param string $version   Add-on version.
	 * @param string $class     Fully-qualified class name of the add-on.
	 */
	public function register( $addon_key, $version, $class ) {
		if ( ! isset( $this->addons[ $addon_key ] ) ) {
			// Unknown add-on — still allow it but log.
			$this->addons[ $addon_key ] = array(
				'name'    => $addon_key,
				'slug'    => 'r2cs-addon-' . $addon_key,
				'status'  => 'active',
				'version' => $version,
				'class'   => $class,
			);
		} else {
			$this->addons[ $addon_key ]['status']  = 'active';
			$this->addons[ $addon_key ]['version'] = $version;
			$this->addons[ $addon_key ]['class']   = $class;
		}

		// Instantiate the add-on.
		if ( class_exists( $class ) ) {
			$this->active[ $addon_key ] = new $class( r2cs() );
		}
	}

	/**
	 * Check if an add-on is active.
	 *
	 * @param string $addon_key Add-on key.
	 * @return bool
	 */
	public function is_active( $addon_key ) {
		return isset( $this->active[ $addon_key ] );
	}

	/**
	 * Get an active add-on instance.
	 *
	 * @param string $addon_key Add-on key.
	 * @return object|null
	 */
	public function get_addon( $addon_key ) {
		return $this->active[ $addon_key ] ?? null;
	}

	/**
	 * Get all registered add-ons (catalog).
	 *
	 * @return array
	 */
	public function get_all() {
		// Update status for installed but not active add-ons.
		foreach ( $this->addons as $key => &$addon ) {
			if ( 'active' !== $addon['status'] && ! empty( $addon['slug'] ) ) {
				$plugin_file = $addon['slug'] . '/' . $addon['slug'] . '.php';
				if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
					$addon['status'] = is_plugin_active( $plugin_file ) ? 'active' : 'installed';
				}
			}
		}

		return $this->addons;
	}

	/**
	 * Get only active add-ons.
	 *
	 * @return array
	 */
	public function get_active() {
		return $this->active;
	}

	/**
	 * Get catalog for the admin page (with install status).
	 *
	 * @return array
	 */
	public function get_catalog_for_display() {
		$catalog = $this->get_all();

		// Check if the required parent plugin is active.
		foreach ( $catalog as $key => &$addon ) {
			$addon['parent_active'] = true;
			if ( ! empty( $addon['requires'] ) ) {
				$addon['parent_active'] = is_plugin_active( $addon['requires'] );
			}
		}

		return $catalog;
	}

	/**
	 * Get the count of active add-ons.
	 *
	 * @return int
	 */
	public function count_active() {
		return count( $this->active );
	}

	/**
	 * Get the localized price for an add-on based on WordPress locale.
	 *
	 * Uses pt_BR locale → BRL; everything else → USD.
	 *
	 * @param array $addon Add-on data from catalog.
	 * @return string Formatted price string.
	 */
	public function get_localized_price( $addon ) {
		$locale = determine_locale();

		/**
		 * Filter the locale used to determine the add-on price currency.
		 *
		 * @param string $locale Current WordPress locale.
		 * @param array  $addon  Add-on data.
		 */
		$locale = apply_filters( 'r2cs_pricing_locale', $locale, $addon );

		if ( str_starts_with( $locale, 'pt_' ) ) {
			return $addon['price_brl'] ?? '';
		}

		return $addon['price_usd'] ?? '';
	}

	// ------------------------------------------------------------------
	//  License Activation API
	// ------------------------------------------------------------------

	/**
	 * Get the license API base URL.
	 *
	 * @return string
	 */
	private function get_license_api_url() {
		return defined( 'R2CS_LICENSE_API_URL' )
			? R2CS_LICENSE_API_URL
			: 'https://r2cloudstorage.com/api/v1/license';
	}

	/** @var string Option key for stored licenses. */
	const LICENSES_OPTION = 'r2cs_addon_licenses';

	/**
	 * Activate an add-on license key on this site.
	 *
	 * @param string $addon_key  Add-on slug (e.g. 'woocommerce').
	 * @param string $license_key License key from the dashboard.
	 * @return array { success: bool, message: string }
	 */
	public function activate_license( $addon_key, $license_key ) {
		$domain = $this->get_site_domain();

		$response = wp_remote_post( $this->get_license_api_url() . '/activate', array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'license_key' => sanitize_text_field( $license_key ),
				'addon_slug'  => sanitize_key( $addon_key ),
				'domain'      => $domain,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code === 200 && ! empty( $body['success'] ) ) {
			// Store the license locally.
			$licenses = get_option( self::LICENSES_OPTION, array() );
			$licenses[ $addon_key ] = array(
				'license_key' => sanitize_text_field( $license_key ),
				'domain'      => $domain,
				'expires_at'  => $body['data']['expires_at'] ?? '',
				'activated'   => true,
			);
			update_option( self::LICENSES_OPTION, $licenses );

			return array(
				'success' => true,
				'message' => $body['message'] ?? __( 'License activated successfully!', 'r2-cloud-storage' ),
			);
		}

		return array(
			'success' => false,
			'message' => $body['message'] ?? __( 'Error activating license.', 'r2-cloud-storage' ),
		);
	}

	/**
	 * Deactivate an add-on license from this site.
	 *
	 * @param string $addon_key Add-on slug.
	 * @return array { success: bool, message: string }
	 */
	public function deactivate_license( $addon_key ) {
		$licenses = get_option( self::LICENSES_OPTION, array() );

		if ( empty( $licenses[ $addon_key ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'No license found for this add-on.', 'r2-cloud-storage' ),
			);
		}

		$license = $licenses[ $addon_key ];
		$domain  = $this->get_site_domain();

		$response = wp_remote_post( $this->get_license_api_url() . '/deactivate', array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'license_key' => $license['license_key'],
				'addon_slug'  => sanitize_key( $addon_key ),
				'domain'      => $domain,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code === 200 && ! empty( $body['success'] ) ) {
			unset( $licenses[ $addon_key ] );
			update_option( self::LICENSES_OPTION, $licenses );

			return array(
				'success' => true,
				'message' => $body['message'] ?? __( 'License deactivated successfully.', 'r2-cloud-storage' ),
			);
		}

		return array(
			'success' => false,
			'message' => $body['message'] ?? __( 'Error deactivating license.', 'r2-cloud-storage' ),
		);
	}

	/**
	 * Verify an add-on license is valid (called periodically).
	 *
	 * @param string $addon_key Add-on slug.
	 * @return bool
	 */
	public function verify_license( $addon_key ) {
		$licenses = get_option( self::LICENSES_OPTION, array() );

		if ( empty( $licenses[ $addon_key ] ) ) {
			return false;
		}

		$license = $licenses[ $addon_key ];
		$domain  = $this->get_site_domain();

		$response = wp_remote_post( $this->get_license_api_url() . '/verify', array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'license_key' => $license['license_key'],
				'addon_slug'  => sanitize_key( $addon_key ),
				'domain'      => $domain,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			// On network error, trust the local cache.
			return ! empty( $license['activated'] );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$valid = ! empty( $body['valid'] );

		// Update local cache.
		$licenses[ $addon_key ]['activated'] = $valid;
		if ( ! empty( $body['data']['expires_at'] ) ) {
			$licenses[ $addon_key ]['expires_at'] = $body['data']['expires_at'];
		}
		update_option( self::LICENSES_OPTION, $licenses );

		return $valid;
	}

	/**
	 * Check if an add-on has a valid local license.
	 *
	 * @param string $addon_key Add-on slug.
	 * @return bool
	 */
	public function has_valid_license( $addon_key ) {
		$licenses = get_option( self::LICENSES_OPTION, array() );

		if ( empty( $licenses[ $addon_key ] ) || empty( $licenses[ $addon_key ]['activated'] ) ) {
			return false;
		}

		// Check expiry if we have it cached.
		if ( ! empty( $licenses[ $addon_key ]['expires_at'] ) ) {
			$expires = strtotime( $licenses[ $addon_key ]['expires_at'] );
			if ( $expires && $expires < time() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the stored license data for an add-on.
	 *
	 * @param string $addon_key Add-on slug.
	 * @return array|null
	 */
	public function get_license( $addon_key ) {
		$licenses = get_option( self::LICENSES_OPTION, array() );
		return $licenses[ $addon_key ] ?? null;
	}

	/**
	 * Get the site domain (cleaned).
	 *
	 * @return string
	 */
	private function get_site_domain() {
		$url = get_site_url();
		$domain = preg_replace( '#^https?://#', '', $url );
		$domain = rtrim( $domain, '/' );
		return strtolower( $domain );
	}
}
