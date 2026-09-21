<?php
/**
 * Signed URL generator.
 *
 * Provides pre-signed URLs for protected content delivery.
 * Used by the core and extended by add-ons (WooCommerce downloads, LMS videos, etc.).
 *
 * @package R2CloudStorage
 */

namespace R2CS;

defined( 'ABSPATH' ) || exit;

class R2_Signed_Url {

	/** @var R2_Client */
	private $client;

	/**
	 * @param R2_Client $client
	 */
	public function __construct( R2_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Generate a signed URL for a remote key.
	 *
	 * @param string $remote_key Object key in the bucket.
	 * @param int    $expires    Seconds until expiry.
	 * @param string $method     HTTP method (default GET).
	 * @return string Signed URL.
	 */
	public function generate( $remote_key, $expires = 3600, $method = 'GET' ) {
		/**
		 * Filter the signed URL expiry time.
		 *
		 * @param int    $expires    Expiry in seconds.
		 * @param string $remote_key Object key.
		 */
		$expires = apply_filters( 'r2cs_signed_url_expiry', $expires, $remote_key );

		return $this->client->get_presigned_url( $remote_key, $expires, $method );
	}

	/**
	 * Generate a signed URL for an attachment by ID.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @param int $expires       Seconds until expiry.
	 * @return string|\WP_Error Signed URL or error.
	 */
	public function generate_for_attachment( $attachment_id, $expires = 3600 ) {
		$remote_key = get_post_meta( $attachment_id, R2_Media_Offload::REMOTE_KEY_META, true );

		if ( empty( $remote_key ) ) {
			return new \WP_Error(
				'r2cs_not_offloaded',
				__( 'This attachment was not offloaded to R2.', 'r2-cloud-storage' )
			);
		}

		return $this->generate( $remote_key, $expires );
	}

	/**
	 * Generate a signed upload URL (PUT) for direct browser uploads.
	 *
	 * @param string $remote_key   Desired object key.
	 * @param int    $expires      Seconds until expiry.
	 * @return string Signed PUT URL.
	 */
	public function generate_upload_url( $remote_key, $expires = 900 ) {
		return $this->generate( $remote_key, $expires, 'PUT' );
	}
}
