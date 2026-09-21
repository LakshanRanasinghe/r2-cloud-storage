<?php
/**
 * Sync / Migration tool.
 *
 * Handles bulk offloading of existing media to R2.
 *
 * @package R2CloudStorage
 */

namespace R2CS;

defined( 'ABSPATH' ) || exit;

class R2_Sync {

	/** @var R2_Client */
	private $client;

	/** @var R2_Settings */
	private $settings;

	/** @var int Batch size for sync operations. */
	const BATCH_SIZE = 10;

	/**
	 * @param R2_Client   $client
	 * @param R2_Settings $settings
	 */
	public function __construct( R2_Client $client, R2_Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Get a batch of attachment IDs that have NOT been offloaded yet.
	 *
	 * @param int  $limit          Number of IDs to return.
	 * @param int  $offset         Offset for pagination.
	 * @param bool $include_failed Whether to include previously failed (-1) attachments.
	 * @return int[] Attachment IDs.
	 */
	public function get_pending_attachments( $limit = 50, $offset = 0, $include_failed = true ) {
		global $wpdb;

		$limit  = absint( $limit );
		$offset = absint( $offset );

		if ( $include_failed ) {
			$where = "AND ( pm.meta_value IS NULL OR pm.meta_value != '1' )";
		} else {
			$where = "AND ( pm.meta_value IS NULL OR ( pm.meta_value != '1' AND pm.meta_value != '-1' ) )";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Pagination query for pending attachments.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			 WHERE p.post_type = 'attachment'
			   {$where}
			 ORDER BY p.ID ASC
			 LIMIT %d OFFSET %d",
			R2_Media_Offload::META_KEY,
			$limit,
			$offset
		) ) );
	}

	/**
	 * Reset failed (-1) status on attachments so they can be retried.
	 *
	 * @return int Number of reset attachments.
	 */
	public function reset_failed_attachments() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '-1'",
			R2_Media_Offload::META_KEY
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_r2cs_error'
		) );

		return (int) $deleted;
	}

	/**
	 * Count attachments currently in failed status (-1).
	 *
	 * @return int
	 */
	public function get_failed_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '-1'",
			R2_Media_Offload::META_KEY
		) );
	}

	/**
	 * Sync a single batch of attachments.
	 *
	 * @param int  $batch_size   Number of attachments to process.
	 * @param bool $retry_failed Whether to retry failed items.
	 * @return array { processed: int, success: int, errors: array, remaining: int }
	 */
	public function sync_batch( $batch_size = 0, $retry_failed = true ) {
		if ( $batch_size <= 0 ) {
			$batch_size = self::BATCH_SIZE;
		}

		$ids     = $this->get_pending_attachments( $batch_size, 0, $retry_failed );
		$results = array(
			'processed' => 0,
			'success'   => 0,
			'errors'    => array(),
			'remaining' => 0,
		);

		if ( empty( $ids ) ) {
			return $results;
		}

		$media = r2cs()->get( 'media' );

		foreach ( $ids as $attachment_id ) {
			$results['processed']++;

			$result = $media->offload_attachment( $attachment_id );

			if ( is_wp_error( $result ) ) {
				$results['errors'][] = array(
					'id'      => $attachment_id,
					'message' => $result->get_error_message(),
				);
				// Mark as failed (-1) so the queue can advance past problematic files.
				update_post_meta( $attachment_id, R2_Media_Offload::META_KEY, '-1' );
				update_post_meta( $attachment_id, '_r2cs_error', $result->get_error_message() );
			} else {
				$results['success']++;
				delete_post_meta( $attachment_id, '_r2cs_error' );
			}
		}

		// Count remaining.
		$stats = $media->get_stats();
		$results['remaining'] = $stats['pending'];

		return $results;
	}

	/**
	 * Get overall sync progress.
	 *
	 * @return array { total: int, offloaded: int, failed: int, pending: int, percentage: float }
	 */
	public function get_progress() {
		$media = r2cs()->get( 'media' );
		$stats = $media->get_stats();

		$percentage = $stats['total'] > 0
			? round( ( $stats['offloaded'] / $stats['total'] ) * 100, 1 )
			: 0;

		return array_merge( $stats, array( 'percentage' => $percentage ) );
	}

	// ------------------------------------------------------------------
	//  Direct Folder Sync (besv-uploads / UPLOADS)
	// ------------------------------------------------------------------

	/**
	 * Resolve the uploads / besv-uploads root folder path.
	 *
	 * @return string|false
	 */
	public function get_uploads_folder_path() {
		// 1. Check if user configured a manual path in settings.
		$custom_path = $this->settings->get( 'local_uploads_path' );
		if ( ! empty( $custom_path ) ) {
			return apply_filters( 'r2cs_uploads_folder_path', untrailingslashit( trim( $custom_path ) ) );
		}

		$custom_uploads = defined( 'UPLOADS' ) ? trim( UPLOADS, '/' ) : 'besv-uploads';

		$candidates = array();

		// Priority 1: When UPLOADS is defined, it is located at root level (dirname(WP_CONTENT_DIR) or ABSPATH), NOT inside wp-content.
		if ( defined( 'WP_CONTENT_DIR' ) && ! empty( $custom_uploads ) ) {
			// dirname(WP_CONTENT_DIR) is /srv/htdocs
			$candidates[] = trailingslashit( dirname( WP_CONTENT_DIR ) ) . $custom_uploads;
		}

		if ( defined( 'ABSPATH' ) && ! empty( $custom_uploads ) ) {
			$candidates[] = trailingslashit( ABSPATH ) . $custom_uploads;
			$candidates[] = trailingslashit( dirname( ABSPATH ) ) . $custom_uploads;
		}

		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) && ! empty( $custom_uploads ) ) {
			$candidates[] = trailingslashit( $_SERVER['DOCUMENT_ROOT'] ) . $custom_uploads;
			$candidates[] = trailingslashit( dirname( $_SERVER['DOCUMENT_ROOT'] ) ) . $custom_uploads;
		}

		// Priority 2: Direct 'besv-uploads' in root directory.
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$candidates[] = trailingslashit( dirname( WP_CONTENT_DIR ) ) . 'besv-uploads';
		}
		if ( defined( 'ABSPATH' ) ) {
			$candidates[] = trailingslashit( ABSPATH ) . 'besv-uploads';
			$candidates[] = trailingslashit( dirname( ABSPATH ) ) . 'besv-uploads';
		}
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$candidates[] = trailingslashit( $_SERVER['DOCUMENT_ROOT'] ) . 'besv-uploads';
		}

		// Priority 3: wp_upload_dir() basedir.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$candidates[] = untrailingslashit( $upload_dir['basedir'] );
		}

		// Priority 4 (Fallback only): Inside wp-content.
		if ( defined( 'WP_CONTENT_DIR' ) && ! empty( $custom_uploads ) ) {
			$candidates[] = trailingslashit( WP_CONTENT_DIR ) . $custom_uploads;
		}

		$existing = array();
		foreach ( array_unique( $candidates ) as $dir ) {
			$cleaned = untrailingslashit( $dir );
			if ( ! empty( $cleaned ) && is_dir( $cleaned ) ) {
				$existing[] = $cleaned;
			}
		}

		if ( empty( $existing ) ) {
			return false;
		}

		// Prefer root-level directory over wp-content when UPLOADS does not contain 'wp-content'.
		if ( false === strpos( $custom_uploads, 'wp-content' ) ) {
			foreach ( $existing as $dir ) {
				if ( false === strpos( $dir, '/wp-content/' ) && substr( $dir, -11 ) !== '/wp-content' ) {
					return apply_filters( 'r2cs_uploads_folder_path', $dir );
				}
			}
		}

		return apply_filters( 'r2cs_uploads_folder_path', $existing[0] );
	}

	/**
	 * Scan the uploads folder recursively for all images and PDFs.
	 *
	 * @param array $extensions Supported extensions.
	 * @return array List of relative paths.
	 */
	public function scan_uploads_folder( $extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'pdf' ), $limit = 5000 ) {
		$folder = $this->get_uploads_folder_path();
		if ( ! $folder || ! is_dir( $folder ) ) {
			return array();
		}

		$extensions = array_map( 'strtolower', $extensions );
		$files      = array();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		try {
			$dir_iterator = new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS );
			$iterator     = new \RecursiveIteratorIterator( $dir_iterator, \RecursiveIteratorIterator::SELF_FIRST );
			$folder_len   = strlen( trailingslashit( $folder ) );

			foreach ( $iterator as $item ) {
				if ( $item->isFile() ) {
					$ext       = strtolower( $item->getExtension() );
					$pathname  = $item->getPathname();
					if ( in_array( $ext, $extensions, true ) || preg_match( '/\.(jpe?g|png|gif)\.webp$/i', $pathname ) ) {
						$rel     = substr( $pathname, $folder_len );
						$files[] = ltrim( $rel, '/' );
						if ( $limit > 0 && count( $files ) >= $limit ) {
							break;
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			// Fallback: directory iterator failed.
		}

		sort( $files );
		return $files;
	}

	/**
	 * Initialize or reset the folder sync queue.
	 *
	 * @return array { total: int, folder: string }
	 */
	public function init_folder_sync() {
		$folder = $this->get_uploads_folder_path();
		$files  = $this->scan_uploads_folder();

		$state = array(
			'folder'    => $folder,
			'total'     => count( $files ),
			'queue'     => $files,
			'synced'    => 0,
			'errors'    => array(),
			'timestamp' => time(),
		);

		update_option( 'r2cs_folder_sync_state', $state, false );

		return array(
			'total'  => $state['total'],
			'folder' => basename( $folder ),
		);
	}

	/**
	 * Process a batch of files from the folder sync queue.
	 *
	 * @param int $batch_size Number of files to process.
	 * @return array { processed: int, success: int, errors: array, remaining: int, total: int, synced: int }
	 */
	public function sync_folder_batch( $batch_size = 0 ) {
		if ( $batch_size <= 0 ) {
			$batch_size = self::BATCH_SIZE;
		}

		$state = get_option( 'r2cs_folder_sync_state', false );
		if ( empty( $state ) || ! is_array( $state ) || ! isset( $state['queue'] ) ) {
			$this->init_folder_sync();
			$state = get_option( 'r2cs_folder_sync_state', array() );
		}

		$folder = ! empty( $state['folder'] ) ? $state['folder'] : $this->get_uploads_folder_path();
		$queue  = isset( $state['queue'] ) && is_array( $state['queue'] ) ? $state['queue'] : array();
		$prefix = $this->settings->get( 'path_prefix', 'wp-content/uploads/' );

		$results = array(
			'processed' => 0,
			'success'   => 0,
			'errors'    => array(),
			'remaining' => count( $queue ),
			'total'     => isset( $state['total'] ) ? (int) $state['total'] : 0,
			'synced'    => isset( $state['synced'] ) ? (int) $state['synced'] : 0,
		);

		if ( empty( $queue ) ) {
			return $results;
		}

		global $wpdb;
		$batch = array_splice( $queue, 0, $batch_size );

		foreach ( $batch as $rel_path ) {
			$results['processed']++;
			$local_file = trailingslashit( $folder ) . $rel_path;
			$remote_key = trailingslashit( $prefix ) . $rel_path;

			if ( ! file_exists( $local_file ) ) {
				$results['errors'][] = array(
					'file'    => $rel_path,
					'message' => __( 'File not found on disk', 'r2-cloud-storage' ),
				);
				continue;
			}

			$mime_type = $this->client->detect_mime_type( $local_file );
			$upload    = $this->client->upload_file( $local_file, $remote_key, $mime_type );

			if ( is_wp_error( $upload ) ) {
				$results['errors'][] = array(
					'file'    => $rel_path,
					'message' => $upload->get_error_message(),
				);
			} else {
				$results['success']++;
				$results['synced']++;

				// If an attachment matches this file in WordPress, link it as offloaded.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$attachment_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = '_wp_attached_file'
					   AND ( meta_value = %s OR meta_value = %s OR meta_value LIKE %s )
					 LIMIT 1",
					$rel_path,
					'besv-uploads/' . $rel_path,
					'%' . $wpdb->esc_like( $rel_path )
				) );

				if ( $attachment_id > 0 ) {
					update_post_meta( $attachment_id, R2_Media_Offload::META_KEY, true );
					update_post_meta( $attachment_id, R2_Media_Offload::REMOTE_KEY_META, $remote_key );
					delete_post_meta( $attachment_id, '_r2cs_error' );
				}
			}
		}

		$state['queue']     = $queue;
		$state['synced']    = $results['synced'];
		$results['remaining'] = count( $queue );

		update_option( 'r2cs_folder_sync_state', $state, false );

		return $results;
	}

	/**
	 * Get current folder sync progress.
	 *
	 * @return array
	 */
	public function get_folder_sync_progress() {
		$state = get_option( 'r2cs_folder_sync_state', false );
		if ( empty( $state ) || ! is_array( $state ) ) {
			return array(
				'total'      => 0,
				'synced'     => 0,
				'remaining'  => 0,
				'percentage' => 0,
				'folder'     => basename( (string) $this->get_uploads_folder_path() ),
			);
		}

		$total     = (int) ( $state['total'] ?? 0 );
		$synced    = (int) ( $state['synced'] ?? 0 );
		$remaining = count( (array) ( $state['queue'] ?? array() ) );
		$pct       = $total > 0 ? round( ( $synced / $total ) * 100, 1 ) : 0;

		return array(
			'total'      => $total,
			'synced'     => $synced,
			'remaining'  => $remaining,
			'percentage' => $pct,
			'folder'     => basename( (string) ( $state['folder'] ?? '' ) ),
		);
	}
}
