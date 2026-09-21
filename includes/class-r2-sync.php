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
	 * @param int $limit Number of IDs to return.
	 * @param int $offset Offset for pagination.
	 * @return int[] Attachment IDs.
	 */
	public function get_pending_attachments( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$limit  = absint( $limit );
		$offset = absint( $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Pagination query for pending attachments.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			 WHERE p.post_type = 'attachment'
			   AND ( pm.meta_value IS NULL OR ( pm.meta_value != '1' AND pm.meta_value != '-1' ) )
			 ORDER BY p.ID ASC
			 LIMIT %d OFFSET %d",
			R2_Media_Offload::META_KEY,
			$limit,
			$offset
		) ) );
	}

	/**
	 * Sync a single batch of attachments.
	 *
	 * @param int $batch_size Number of attachments to process.
	 * @return array { processed: int, success: int, errors: array }
	 */
	public function sync_batch( $batch_size = 0 ) {
		if ( $batch_size <= 0 ) {
			$batch_size = self::BATCH_SIZE;
		}

		$ids     = $this->get_pending_attachments( $batch_size );
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
	 * @return array { total: int, offloaded: int, pending: int, percentage: float }
	 */
	public function get_progress() {
		$media = r2cs()->get( 'media' );
		$stats = $media->get_stats();

		$percentage = $stats['total'] > 0
			? round( ( $stats['offloaded'] / $stats['total'] ) * 100, 1 )
			: 0;

		return array_merge( $stats, array( 'percentage' => $percentage ) );
	}
}
