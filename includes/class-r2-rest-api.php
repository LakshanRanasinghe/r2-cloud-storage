<?php
/**
 * REST API endpoints for R2 Cloud Storage.
 *
 * Provides AJAX-like endpoints for the admin UI (test connection, sync, stats).
 *
 * @package R2CloudStorage
 */

namespace R2CS;

defined( 'ABSPATH' ) || exit;

class R2_REST_API {

	/** @var R2_Client */
	private $client;

	/** @var R2_Settings */
	private $settings;

	/** @var R2_Sync */
	private $sync;

	/**
	 * @param R2_Client   $client
	 * @param R2_Settings $settings
	 * @param R2_Sync     $sync
	 */
	public function __construct( R2_Client $client, R2_Settings $settings, R2_Sync $sync ) {
		$this->client   = $client;
		$this->settings = $settings;
		$this->sync     = $sync;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		$namespace = 'r2cs/v1';

		register_rest_route( $namespace, '/test-connection', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'test_connection' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $namespace, '/sync/start', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'start_sync' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $namespace, '/sync/reset-failed', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'reset_failed' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $namespace, '/sync/batch', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'sync_batch' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $namespace, '/sync/progress', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'sync_progress' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $namespace, '/sync/folder/start', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'start_folder_sync' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $namespace, '/sync/folder/batch', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'sync_folder_batch' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $namespace, '/sync/folder/progress', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'sync_folder_progress' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( $namespace, '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_stats' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		/**
		 * Fires after core REST routes are registered.
		 * Add-ons can register their own routes here.
		 *
		 * @param string $namespace REST namespace.
		 */
		do_action( 'r2cs_register_rest_routes', $namespace );

		// License management endpoints.
		register_rest_route( $namespace, '/license/activate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'activate_license' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'addon_key'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				'license_key' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $namespace, '/license/deactivate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'deactivate_license' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
			'args'                => array(
				'addon_key' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );
	}

	/**
	 * Permission check: admin only.
	 *
	 * @return bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * POST /r2cs/v1/test-connection
	 *
	 * @return \WP_REST_Response
	 */
	public function test_connection() {
		$result = $this->client->test_connection();

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => $result->get_error_message(),
			), 400 );
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'message' => __( 'R2 connection established successfully!', 'r2-cloud-storage' ),
		) );
	}

	/**
	 * POST /r2cs/v1/sync/start
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function start_sync( \WP_REST_Request $request ) {
		if ( ! $this->settings->is_configured() ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Configure R2 credentials before syncing.', 'r2-cloud-storage' ),
			), 400 );
		}

		$reset_failed = $request->get_param( 'reset_failed' );
		if ( null === $reset_failed || $reset_failed ) {
			$this->sync->reset_failed_attachments();
		}

		$progress = $this->sync->get_progress();

		return new \WP_REST_Response( array(
			'success'  => true,
			'progress' => $progress,
		) );
	}

	/**
	 * POST /r2cs/v1/sync/reset-failed
	 *
	 * @return \WP_REST_Response
	 */
	public function reset_failed() {
		$reset_count = $this->sync->reset_failed_attachments();

		return new \WP_REST_Response( array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of attachments reset */
				__( '%d failed attachment(s) have been reset and are ready to sync.', 'r2-cloud-storage' ),
				$reset_count
			),
			'reset'   => $reset_count,
			'stats'   => r2cs()->get( 'media' )->get_stats(),
		) );
	}

	/**
	 * POST /r2cs/v1/sync/batch
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function sync_batch( \WP_REST_Request $request ) {
		$batch_size   = $request->get_param( 'batch_size' );
		$batch_size   = $batch_size ? absint( $batch_size ) : R2_Sync::BATCH_SIZE;
		$retry_failed = (bool) ( $request->get_param( 'retry_failed' ) ?? true );

		$result = $this->sync->sync_batch( $batch_size, $retry_failed );

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
		) );
	}

	/**
	 * GET /r2cs/v1/sync/progress
	 *
	 * @return \WP_REST_Response
	 */
	public function sync_progress() {
		$progress = $this->sync->get_progress();

		return new \WP_REST_Response( array(
			'success'  => true,
			'progress' => $progress,
		) );
	}

	/**
	 * POST /r2cs/v1/sync/folder/start
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function start_folder_sync( \WP_REST_Request $request ) {
		if ( ! $this->settings->is_configured() ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Configure R2 credentials before syncing.', 'r2-cloud-storage' ),
			), 400 );
		}

		$force_restart = (bool) $request->get_param( 'force_restart' );
		$init          = $this->sync->init_folder_sync( $force_restart );

		$message = ! empty( $init['resumed'] )
			? sprintf(
				/* translators: 1: remaining files, 2: total files, 3: folder name */
				__( 'Resuming sync for %3$s: %1$d remaining of %2$d files.', 'r2-cloud-storage' ),
				$init['remaining'],
				$init['total'],
				$init['folder']
			)
			: sprintf(
				/* translators: 1: number of files, 2: folder name */
				__( 'Found %1$d files in %2$s ready to sync.', 'r2-cloud-storage' ),
				$init['total'],
				$init['folder']
			);

		return new \WP_REST_Response( array(
			'success'  => true,
			'message'  => $message,
			'resumed'  => ! empty( $init['resumed'] ),
			'progress' => $this->sync->get_folder_sync_progress(),
		) );
	}

	/**
	 * POST /r2cs/v1/sync/folder/batch
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function sync_folder_batch( \WP_REST_Request $request ) {
		$batch_size    = $request->get_param( 'batch_size' );
		$batch_size    = $batch_size ? absint( $batch_size ) : R2_Sync::BATCH_SIZE;
		$skip_existing = (bool) ( $request->get_param( 'skip_existing' ) ?? true );

		$result = $this->sync->sync_folder_batch( $batch_size, $skip_existing );

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
		) );
	}

	/**
	 * GET /r2cs/v1/sync/folder/progress
	 *
	 * @return \WP_REST_Response
	 */
	public function sync_folder_progress() {
		$progress = $this->sync->get_folder_sync_progress();

		return new \WP_REST_Response( array(
			'success'  => true,
			'progress' => $progress,
		) );
	}

	/**
	 * GET /r2cs/v1/stats
	 *
	 * @return \WP_REST_Response
	 */
	public function get_stats() {
		$media = r2cs()->get( 'media' );

		$data = array(
			'media'       => $media->get_stats(),
			'configured'  => $this->settings->is_configured(),
			'folder'      => array(
				'name'  => basename( (string) $this->sync->get_uploads_folder_path() ),
				'path'  => $this->sync->get_uploads_folder_path(),
				'state' => $this->sync->get_folder_sync_progress(),
			),
			'addons'      => r2cs()->addons()->count_active(),
		);

		/**
		 * Filter stats data. Add-ons can append their own stats.
		 *
		 * @param array $data Stats data.
		 */
		$data = apply_filters( 'r2cs_stats_data', $data );

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => $data,
		) );
	}

	/**
	 * POST /r2cs/v1/license/activate
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function activate_license( \WP_REST_Request $request ) {
		$addon_key   = $request->get_param( 'addon_key' );
		$license_key = $request->get_param( 'license_key' );

		$manager = r2cs()->addons();
		$result  = $manager->activate_license( $addon_key, $license_key );

		$status = $result['success'] ? 200 : 400;

		return new \WP_REST_Response( $result, $status );
	}

	/**
	 * POST /r2cs/v1/license/deactivate
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function deactivate_license( \WP_REST_Request $request ) {
		$addon_key = $request->get_param( 'addon_key' );

		$manager = r2cs()->addons();
		$result  = $manager->deactivate_license( $addon_key );

		$status = $result['success'] ? 200 : 400;

		return new \WP_REST_Response( $result, $status );
	}
}
