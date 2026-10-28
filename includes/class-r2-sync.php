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

		// Priority 3: wp_upload_dir() basedir (protected against recursion).
		static $resolving_upload_dir = false;
		if ( ! $resolving_upload_dir ) {
			$resolving_upload_dir = true;
			$upload_dir = wp_upload_dir();
			$resolving_upload_dir = false;
			if ( ! empty( $upload_dir['basedir'] ) ) {
				$candidates[] = untrailingslashit( $upload_dir['basedir'] );
			}
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
	public function scan_uploads_folder( $extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'pdf' ), $limit = 0 ) {
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
	 * @param bool $force_restart Whether to force a full re-scan and discard any previous interrupted queue.
	 * @return array { total: int, folder: string, resumed: bool, remaining: int }
	 */
	public function init_folder_sync( $force_restart = false ) {
		$folder = $this->get_uploads_folder_path();
		if ( ! $folder || ! is_dir( $folder ) ) {
			return array(
				'total'     => 0,
				'folder'    => '',
				'resumed'   => false,
				'remaining' => 0,
			);
		}

		$existing_state = get_option( 'r2cs_folder_sync_state', false );

		// Resume existing queue if one exists with remaining files for the same folder, unless force_restart is requested.
		if ( ! $force_restart && is_array( $existing_state ) && ! empty( $existing_state['queue'] ) && ( $existing_state['folder'] ?? '' ) === $folder ) {
			return array(
				'total'     => isset( $existing_state['total'] ) ? (int) $existing_state['total'] : count( $existing_state['queue'] ),
				'folder'    => basename( $folder ),
				'resumed'   => true,
				'remaining' => count( $existing_state['queue'] ),
			);
		}

		$files  = $this->scan_uploads_folder( array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'pdf' ), 0 );

		$state = array(
			'folder'    => $folder,
			'total'     => count( $files ),
			'queue'     => $files,
			'synced'    => 0,
			'skipped'   => 0,
			'errors'    => array(),
			'timestamp' => time(),
		);

		update_option( 'r2cs_folder_sync_state', $state, false );

		return array(
			'total'     => $state['total'],
			'folder'    => basename( $folder ),
			'resumed'   => false,
			'remaining' => $state['total'],
		);
	}

	/**
	 * Process a batch of files from the folder sync queue.
	 *
	 * @param int       $batch_size    Number of files to process.
	 * @param bool      $skip_existing Whether to skip files already offloaded.
	 * @param bool|null $remove_local  Whether to remove local files after upload/verification. Defaults to plugin setting.
	 * @return array { processed: int, success: int, skipped: int, deleted: int, errors: array, remaining: int, total: int, synced: int }
	 */
	public function sync_folder_batch( $batch_size = 0, $skip_existing = true, $remove_local = null ) {
		if ( $batch_size <= 0 ) {
			$batch_size = self::BATCH_SIZE;
		}

		if ( null === $remove_local ) {
			$remove_local = (bool) $this->settings->get( 'remove_local' );
		} else {
			$remove_local = (bool) $remove_local;
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
			'skipped'   => 0,
			'deleted'   => 0,
			'errors'    => array(),
			'remaining' => count( $queue ),
			'total'     => isset( $state['total'] ) ? (int) $state['total'] : 0,
			'synced'    => isset( $state['synced'] ) ? (int) $state['synced'] : 0,
		);

		if ( empty( $queue ) ) {
			delete_option( 'r2cs_folder_sync_state' );
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

			// If an attachment matches this file in WordPress, look it up.
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

			if ( $skip_existing ) {
				$already_offloaded = false;
				$verified_in_r2    = false;

				if ( $attachment_id > 0 && get_post_meta( $attachment_id, R2_Media_Offload::META_KEY, true ) ) {
					$already_offloaded = true;
				} elseif ( $this->client->object_exists( $remote_key ) ) {
					$already_offloaded = true;
					$verified_in_r2    = true;
					if ( $attachment_id > 0 ) {
						update_post_meta( $attachment_id, R2_Media_Offload::META_KEY, true );
						update_post_meta( $attachment_id, R2_Media_Offload::REMOTE_KEY_META, $remote_key );
						delete_post_meta( $attachment_id, '_r2cs_error' );
					}
				}

				if ( $already_offloaded ) {
					$results['success']++;
					$results['synced']++;
					$results['skipped']++;

					$this->mark_file_synced( $rel_path, $attachment_id, $remote_key );

					if ( ! $this->settings->get( 'keep_local_uploads', true ) && $remove_local && file_exists( $local_file ) ) {
						// Safety check: before deleting an existing file, ensure it exists in R2.
						if ( $verified_in_r2 || $this->client->object_exists( $remote_key ) ) {
							wp_delete_file( $local_file );
							if ( file_exists( $local_file ) ) {
								@unlink( $local_file );
							}
							if ( ! file_exists( $local_file ) ) {
								$results['deleted']++;
							}
						}
					}

					continue;
				}
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

				$this->mark_file_synced( $rel_path, $attachment_id, $remote_key );

				if ( ! $this->settings->get( 'keep_local_uploads', true ) && $remove_local && file_exists( $local_file ) ) {
					wp_delete_file( $local_file );
					if ( file_exists( $local_file ) ) {
						@unlink( $local_file );
					}
					if ( ! file_exists( $local_file ) ) {
						$results['deleted']++;
					}
				}
			}
		}

		$state['queue']       = $queue;
		$state['synced']      = $results['synced'];
		$state['skipped']     = ( isset( $state['skipped'] ) ? (int) $state['skipped'] : 0 ) + $results['skipped'];
		$state['deleted']     = ( isset( $state['deleted'] ) ? (int) $state['deleted'] : 0 ) + $results['deleted'];
		$results['remaining'] = count( $queue );

		if ( empty( $queue ) ) {
			// All files completed in folder sync. Clear queue so next run starts fresh.
			delete_option( 'r2cs_folder_sync_state' );
		} else {
			update_option( 'r2cs_folder_sync_state', $state, false );
		}

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
				'deleted'    => 0,
				'remaining'  => 0,
				'percentage' => 0,
				'folder'     => basename( (string) $this->get_uploads_folder_path() ),
			);
		}

		$total     = (int) ( $state['total'] ?? 0 );
		$synced    = (int) ( $state['synced'] ?? 0 );
		$deleted   = (int) ( $state['deleted'] ?? 0 );
		$remaining = count( (array) ( $state['queue'] ?? array() ) );
		$pct       = $total > 0 ? round( ( $synced / $total ) * 100, 1 ) : 0;

		return array(
			'total'      => $total,
			'synced'     => $synced,
			'deleted'    => $deleted,
			'remaining'  => $remaining,
			'percentage' => $pct,
			'folder'     => basename( (string) ( $state['folder'] ?? '' ) ),
		);
	}

	/**
	 * Mark a file as synced locally in WordPress options and postmeta.
	 *
	 * @param string $rel_path      Relative file path.
	 * @param int    $attachment_id Optional attachment ID.
	 * @param string $remote_key    Optional remote key.
	 */
	public function mark_file_synced( $rel_path, $attachment_id = 0, $remote_key = '' ) {
		$clean_rel = ltrim( preg_replace( '#^(?:besv-uploads/|wp-content/uploads/)#', '', $rel_path ), '/' );

		if ( $attachment_id > 0 ) {
			update_post_meta( $attachment_id, R2_Media_Offload::META_KEY, true );
			if ( ! empty( $remote_key ) ) {
				update_post_meta( $attachment_id, R2_Media_Offload::REMOTE_KEY_META, $remote_key );
			}
			delete_post_meta( $attachment_id, '_r2cs_error' );
		}

		$synced = get_option( 'r2cs_folder_synced_files', array() );
		if ( ! is_array( $synced ) ) {
			$synced = array();
		}
		$synced[ $clean_rel ] = time();
		update_option( 'r2cs_folder_synced_files', $synced, false );
	}

	/**
	 * Check if a specific file is marked as synced.
	 *
	 * @param string $rel_path      Relative file path.
	 * @param int    $attachment_id Optional attachment ID.
	 * @return bool
	 */
	public function is_file_synced( $rel_path, $attachment_id = 0 ) {
		if ( $attachment_id > 0 && get_post_meta( $attachment_id, R2_Media_Offload::META_KEY, true ) == '1' ) {
			return true;
		}

		$clean_rel = ltrim( preg_replace( '#^(?:besv-uploads/|wp-content/uploads/)#', '', $rel_path ), '/' );
		$synced    = get_option( 'r2cs_folder_synced_files', array() );
		if ( is_array( $synced ) && isset( $synced[ $clean_rel ] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get list of files in uploads folder (besv-uploads) that are NOT synced to R2.
	 *
	 * @param int    $limit  Limit for pagination.
	 * @param int    $offset Offset for pagination.
	 * @param string $search Optional search filter.
	 * @return array { total: int, items: array, limit: int, offset: int, folder: string }
	 */
	public function get_unsynced_files( $limit = 50, $offset = 0, $search = '' ) {
		$folder = $this->get_uploads_folder_path();
		if ( ! $folder || ! is_dir( $folder ) ) {
			return array(
				'total'  => 0,
				'items'  => array(),
				'limit'  => $limit,
				'offset' => $offset,
				'folder' => '',
			);
		}

		$all_files = $this->scan_uploads_folder();
		if ( empty( $all_files ) ) {
			return array(
				'total'  => 0,
				'items'  => array(),
				'limit'  => $limit,
				'offset' => $offset,
				'folder' => basename( $folder ),
			);
		}

		global $wpdb;
		// Fast fetch of offloaded attachment files in DB.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$offloaded_query = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm_file.meta_value
			 FROM {$wpdb->postmeta} pm_file
			 INNER JOIN {$wpdb->postmeta} pm_off ON pm_file.post_id = pm_off.post_id
			 WHERE pm_file.meta_key = '_wp_attached_file'
			   AND pm_off.meta_key = %s
			   AND pm_off.meta_value = '1'",
			R2_Media_Offload::META_KEY
		) );

		$offloaded_set = array();
		if ( ! empty( $offloaded_query ) ) {
			foreach ( $offloaded_query as $op ) {
				$clean = ltrim( preg_replace( '#^(?:besv-uploads/|wp-content/uploads/)#', '', $op ), '/' );
				$offloaded_set[ $clean ] = true;
			}
		}

		$folder_synced = get_option( 'r2cs_folder_synced_files', array() );
		if ( ! is_array( $folder_synced ) ) {
			$folder_synced = array();
		}

		// Fast lookup map for attachment IDs by attached file.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachments_map_raw = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'",
			ARRAY_A
		);
		$attachments_map = array();
		if ( ! empty( $attachments_map_raw ) ) {
			foreach ( $attachments_map_raw as $row ) {
				$clean = ltrim( preg_replace( '#^(?:besv-uploads/|wp-content/uploads/)#', '', $row['meta_value'] ), '/' );
				$attachments_map[ $clean ] = (int) $row['post_id'];
			}
		}

		$folder_dir  = trailingslashit( $folder );
		$site_url    = function_exists( 'site_url' ) ? site_url() : '';
		$folder_name = basename( $folder );
		$folder_url  = untrailingslashit( $site_url ) . '/' . $folder_name;

		$unsynced = array();
		foreach ( $all_files as $rel_path ) {
			$clean_path = ltrim( preg_replace( '#^(?:besv-uploads/|wp-content/uploads/)#', '', $rel_path ), '/' );

			// Check if already offloaded via attachment meta or folder index.
			if ( isset( $offloaded_set[ $clean_path ] ) || isset( $folder_synced[ $clean_path ] ) ) {
				continue;
			}

			// If search is provided, filter:
			if ( ! empty( $search ) && false === stripos( $rel_path, $search ) ) {
				continue;
			}

			$full_path = $folder_dir . $rel_path;
			$size      = file_exists( $full_path ) ? (int) @filesize( $full_path ) : 0;
			$mtime     = file_exists( $full_path ) ? (int) @filemtime( $full_path ) : 0;
			$ext       = strtolower( pathinfo( $rel_path, PATHINFO_EXTENSION ) );
			$is_image  = in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg' ), true );
			$att_id    = $attachments_map[ $clean_path ] ?? 0;

			$file_url = $folder_url . '/' . $rel_path;

			$unsynced[] = array(
				'id'             => $att_id,
				'path'           => $rel_path,
				'filename'       => basename( $rel_path ),
				'size_bytes'     => $size,
				'size_formatted' => size_format( $size, 1 ),
				'date'           => $mtime > 0 ? gmdate( 'Y-m-d H:i', $mtime ) : '',
				'extension'      => $ext,
				'is_image'       => $is_image,
				'url'            => $file_url,
				'synced'         => false,
			);
		}

		$total = count( $unsynced );
		$paged = array_slice( $unsynced, $offset, $limit );

		return array(
			'total'  => $total,
			'items'  => $paged,
			'limit'  => $limit,
			'offset' => $offset,
			'folder' => $folder_name,
		);
	}

	/**
	 * Get total count of unsynced files.
	 *
	 * @return int
	 */
	public function get_unsynced_count() {
		$data = $this->get_unsynced_files( 1, 0 );
		return (int) ( $data['total'] ?? 0 );
	}

	/**
	 * Sync an individual file to Cloudflare R2 on-demand.
	 *
	 * @param string $rel_path      Relative file path in uploads folder.
	 * @param int    $attachment_id Optional attachment ID.
	 * @return array|\WP_Error
	 */
	public function sync_single_file( $rel_path, $attachment_id = 0 ) {
		$folder     = $this->get_uploads_folder_path();
		$clean_rel  = ltrim( preg_replace( '#^(?:besv-uploads/|wp-content/uploads/)#', '', $rel_path ), '/' );
		$local_file = trailingslashit( $folder ) . $clean_rel;
		$prefix     = $this->settings->get( 'path_prefix', 'wp-content/uploads/' );
		$remote_key = trailingslashit( $prefix ) . $clean_rel;

		if ( ! file_exists( $local_file ) ) {
			return new \WP_Error( 'r2cs_file_not_found', sprintf( __( 'Local file not found on disk: %s', 'r2-cloud-storage' ), $local_file ) );
		}

		// If attachment ID was not passed, check if one matches in DB.
		if ( empty( $attachment_id ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$attachment_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file'
				   AND ( meta_value = %s OR meta_value = %s OR meta_value LIKE %s )
				 LIMIT 1",
				$clean_rel,
				'besv-uploads/' . $clean_rel,
				'%' . $wpdb->esc_like( $clean_rel )
			) );
		}

		if ( $attachment_id > 0 ) {
			$media  = r2cs()->get( 'media' );
			$upload = $media->offload_attachment( $attachment_id );
			if ( is_wp_error( $upload ) ) {
				return $upload;
			}
		} else {
			$mime_type = $this->client->detect_mime_type( $local_file );
			$upload    = $this->client->upload_file( $local_file, $remote_key, $mime_type );
			if ( is_wp_error( $upload ) ) {
				return $upload;
			}

			// Also upload companion WebP files if present.
			$file_dir   = dirname( $local_file );
			$file_name  = pathinfo( $local_file, PATHINFO_FILENAME );
			$remote_dir = dirname( $remote_key );
			$remote_dir = ( '.' === $remote_dir || empty( $remote_dir ) ) ? '' : trailingslashit( $remote_dir );

			$webp_1 = $file_dir . '/' . $file_name . '.webp';
			$webp_2 = $local_file . '.webp';
			if ( file_exists( $webp_1 ) && $webp_1 !== $local_file ) {
				$this->client->upload_file( $webp_1, $remote_dir . $file_name . '.webp', 'image/webp' );
			}
			if ( file_exists( $webp_2 ) && $webp_2 !== $local_file ) {
				$this->client->upload_file( $webp_2, $remote_key . '.webp', 'image/webp' );
			}
		}

		$this->mark_file_synced( $clean_rel, $attachment_id, $remote_key );

		return array(
			'success'    => true,
			'path'       => $clean_rel,
			'remote_key' => $remote_key,
			'message'    => sprintf( __( 'Successfully synced %s to Cloudflare R2.', 'r2-cloud-storage' ), basename( $clean_rel ) ),
		);
	}
}
