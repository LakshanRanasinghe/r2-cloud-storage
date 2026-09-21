<?php
/**
 * Cloudflare R2 Client — S3-compatible API with AWS Signature V4.
 *
 * Uses only WordPress HTTP API (no AWS SDK dependency).
 *
 * @package R2CloudStorage
 */

namespace R2CS;

defined( 'ABSPATH' ) || exit;

class R2_Client {

	/** @var R2_Settings */
	private $settings;

	/** @var string */
	private $region = 'auto';

	/** @var string */
	private $service = 's3';

	/**
	 * @param R2_Settings $settings
	 */
	public function __construct( R2_Settings $settings ) {
		$this->settings = $settings;
	}

	// ------------------------------------------------------------------
	//  Public API
	// ------------------------------------------------------------------

	/**
	 * Test the connection by listing bucket contents (max 1 key).
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection() {
		$response = $this->request( 'GET', '/', array( 'list-type' => '2', 'max-keys' => '1' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			return new \WP_Error( 'r2cs_connection_failed', sprintf(
				/* translators: 1: HTTP status code 2: response body */
				__( 'R2 returned HTTP %1$d: %2$s', 'r2-cloud-storage' ),
				$code,
				wp_strip_all_tags( $body )
			) );
		}

		return true;
	}

	/**
	 * Upload a file to R2.
	 *
	 * @param string $local_path  Absolute local file path.
	 * @param string $remote_key  Object key in the bucket.
	 * @param string $content_type MIME type.
	 * @param array  $extra_headers Additional headers (e.g. Cache-Control).
	 * @return true|\WP_Error
	 */
	public function upload_file( $local_path, $remote_key, $content_type = '', $extra_headers = array() ) {
		if ( ! file_exists( $local_path ) ) {
			return new \WP_Error( 'r2cs_file_not_found', sprintf( __( 'Local file does not exist: %s', 'r2-cloud-storage' ), $local_path ) );
		}
		if ( ! is_readable( $local_path ) ) {
			return new \WP_Error( 'r2cs_file_not_readable', sprintf( __( 'Local file is not readable (check permissions): %s', 'r2-cloud-storage' ), $local_path ) );
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$body = $wp_filesystem->get_contents( $local_path );
		if ( false === $body ) {
			return new \WP_Error( 'r2cs_file_read_error', __( 'Unable to read local file.', 'r2-cloud-storage' ) );
		}

		if ( empty( $content_type ) ) {
			$content_type = $this->detect_mime_type( $local_path );
		}

		$headers = array_merge(
			array( 'Content-Type' => $content_type ),
			$extra_headers
		);

		$response = $this->request( 'PUT', '/' . ltrim( $remote_key, '/' ), array(), $body, $headers );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			return new \WP_Error( 'r2cs_upload_failed', sprintf(
				/* translators: %d: HTTP status code returned by the R2 API */
				__( 'Upload failed HTTP %d', 'r2-cloud-storage' ),
				$code
			) );
		}

		return true;
	}

	/**
	 * Delete an object from R2.
	 *
	 * @param string $remote_key Object key.
	 * @return true|\WP_Error
	 */
	public function delete_object( $remote_key ) {
		$response = $this->request( 'DELETE', '/' . ltrim( $remote_key, '/' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 204 !== $code && 200 !== $code ) {
			return new \WP_Error( 'r2cs_delete_failed', sprintf(
				/* translators: %d: HTTP status code returned by the R2 API */
				__( 'Delete failed HTTP %d', 'r2-cloud-storage' ),
				$code
			) );
		}

		return true;
	}

	/**
	 * Check if an object exists.
	 *
	 * @param string $remote_key Object key.
	 * @return bool
	 */
	public function object_exists( $remote_key ) {
		$response = $this->request( 'HEAD', '/' . ltrim( $remote_key, '/' ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Get object metadata (HEAD).
	 *
	 * @param string $remote_key Object key.
	 * @return array|\WP_Error
	 */
	public function head_object( $remote_key ) {
		$response = $this->request( 'HEAD', '/' . ltrim( $remote_key, '/' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'r2cs_not_found', __( 'Object not found.', 'r2-cloud-storage' ) );
		}

		return wp_remote_retrieve_headers( $response )->getAll();
	}

	/**
	 * List objects in the bucket.
	 *
	 * @param string $prefix     Key prefix filter.
	 * @param int    $max_keys   Maximum number of keys.
	 * @param string $continuation_token Pagination token.
	 * @return array|\WP_Error { keys: string[], is_truncated: bool, next_token: string }
	 */
	public function list_objects( $prefix = '', $max_keys = 1000, $continuation_token = '' ) {
		$params = array(
			'list-type' => '2',
			'max-keys'  => (string) min( $max_keys, 1000 ),
		);

		if ( ! empty( $prefix ) ) {
			$params['prefix'] = $prefix;
		}

		if ( ! empty( $continuation_token ) ) {
			$params['continuation-token'] = $continuation_token;
		}

		$response = $this->request( 'GET', '/', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$xml  = simplexml_load_string( $body );

		if ( false === $xml ) {
			return new \WP_Error( 'r2cs_parse_error', __( 'Unable to parse R2 response.', 'r2-cloud-storage' ) );
		}

		$keys = array();
		if ( isset( $xml->Contents ) ) {
			foreach ( $xml->Contents as $item ) {
				$keys[] = array(
					'key'           => (string) $item->Key,
					'size'          => (int) $item->Size,
					'last_modified' => (string) $item->LastModified,
				);
			}
		}

		return array(
			'keys'         => $keys,
			'is_truncated' => isset( $xml->IsTruncated ) && 'true' === (string) $xml->IsTruncated,
			'next_token'   => isset( $xml->NextContinuationToken ) ? (string) $xml->NextContinuationToken : '',
		);
	}

	/**
	 * Copy an object within the bucket.
	 *
	 * @param string $source_key Source object key.
	 * @param string $dest_key   Destination object key.
	 * @return true|\WP_Error
	 */
	public function copy_object( $source_key, $dest_key ) {
		$bucket  = $this->settings->get( 'bucket' );
		$headers = array(
			'x-amz-copy-source' => '/' . $bucket . '/' . ltrim( $source_key, '/' ),
		);

		$response = $this->request( 'PUT', '/' . ltrim( $dest_key, '/' ), array(), '', $headers );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			return new \WP_Error( 'r2cs_copy_failed', sprintf(
				/* translators: %d: HTTP status code returned by the R2 API */
				__( 'Copy failed HTTP %d', 'r2-cloud-storage' ),
				$code
			) );
		}

		return true;
	}

	/**
	 * Generate a pre-signed URL for an object.
	 *
	 * @param string $remote_key Object key.
	 * @param int    $expires    Seconds until expiry (default: 3600).
	 * @param string $method     HTTP method (GET or PUT).
	 * @return string
	 */
	public function get_presigned_url( $remote_key, $expires = 3600, $method = 'GET' ) {
		$account_id = $this->settings->get( 'account_id' );
		$bucket     = $this->settings->get( 'bucket' );
		$host       = sanitize_text_field( $account_id ) . '.r2.cloudflarestorage.com';
		$path       = '/' . sanitize_text_field( $bucket ) . '/' . ltrim( $remote_key, '/' );
		$now        = time();
		$date       = gmdate( 'Ymd', $now );
		$datetime   = gmdate( 'Ymd\THis\Z', $now );
		$cred       = $this->settings->get( 'access_key' ) . '/' . $date . '/' . $this->region . '/' . $this->service . '/aws4_request';

		$params = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $cred,
			'X-Amz-Date'          => $datetime,
			'X-Amz-Expires'       => (string) $expires,
			'X-Amz-SignedHeaders' => 'host',
		);

		ksort( $params );

		$query_string    = $this->build_query_string( $params );
		$canonical_request = implode( "\n", array(
			$method,
			$this->uri_encode_path( $path ),
			$query_string,
			'host:' . $host . "\n",
			'host',
			'UNSIGNED-PAYLOAD',
		) );

		$string_to_sign = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$datetime,
			$date . '/' . $this->region . '/' . $this->service . '/aws4_request',
			hash( 'sha256', $canonical_request ),
		) );

		$signing_key = $this->get_signing_key( $date );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return 'https://' . $host . $path . '?' . $query_string . '&X-Amz-Signature=' . $signature;
	}

	/**
	 * Get the public URL for an object (custom domain or R2 endpoint).
	 *
	 * @param string $remote_key Object key.
	 * @return string
	 */
	public function get_object_url( $remote_key ) {
		$custom_domain = $this->settings->get( 'custom_domain' );

		if ( ! empty( $custom_domain ) ) {
			$domain = rtrim( $custom_domain, '/' );
			if ( strpos( $domain, 'http' ) !== 0 ) {
				$domain = 'https://' . $domain;
			}
			return $domain . '/' . ltrim( $remote_key, '/' );
		}

		return $this->get_endpoint() . '/' . ltrim( $remote_key, '/' );
	}

	/**
	 * Detect MIME type for a file with robust fallbacks for WebP, PDF, AVIF, SVG, etc.
	 *
	 * @param string $file_path Local file path or filename.
	 * @return string
	 */
	public function detect_mime_type( $file_path ) {
		$filetype = wp_check_filetype( $file_path );
		if ( ! empty( $filetype['type'] ) ) {
			return $filetype['type'];
		}

		if ( function_exists( 'mime_content_type' ) && file_exists( $file_path ) ) {
			$mime = @mime_content_type( $file_path );
			if ( ! empty( $mime ) && 'application/octet-stream' !== $mime ) {
				return $mime;
			}
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$map = array(
			'webp' => 'image/webp',
			'pdf'  => 'application/pdf',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'svg'  => 'image/svg+xml',
			'svgz' => 'image/svg+xml',
			'avif' => 'image/avif',
			'ico'  => 'image/x-icon',
			'bmp'  => 'image/bmp',
			'mp4'  => 'video/mp4',
			'mp3'  => 'audio/mpeg',
			'zip'  => 'application/zip',
		);

		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';
	}

	// ------------------------------------------------------------------
	//  Internal: HTTP + Signature V4
	// ------------------------------------------------------------------

	/**
	 * Build the R2 endpoint URL.
	 *
	 * @return string
	 */
	private function get_endpoint() {
		$account_id = $this->settings->get( 'account_id' );
		$bucket     = $this->settings->get( 'bucket' );

		return 'https://' . sanitize_text_field( $account_id ) . '.r2.cloudflarestorage.com/' . sanitize_text_field( $bucket );
	}

	/**
	 * Send a signed request to R2.
	 *
	 * @param string $method       HTTP method.
	 * @param string $path         URI path (after bucket).
	 * @param array  $query_params Query parameters.
	 * @param string $body         Request body.
	 * @param array  $extra_headers Additional headers.
	 * @return array|\WP_Error wp_remote_request response.
	 */
	private function request( $method, $path, $query_params = array(), $body = '', $extra_headers = array() ) {
		$account_id = $this->settings->get( 'account_id' );
		$access_key = $this->settings->get( 'access_key' );
		$secret_key = $this->settings->get( 'secret_key' );
		$bucket     = $this->settings->get( 'bucket' );

		if ( empty( $account_id ) || empty( $access_key ) || empty( $secret_key ) || empty( $bucket ) ) {
			return new \WP_Error( 'r2cs_not_configured', __( 'R2 Cloud Storage is not configured. Please fill in your credentials.', 'r2-cloud-storage' ) );
		}

		$host     = sanitize_text_field( $account_id ) . '.r2.cloudflarestorage.com';
		$uri      = '/' . sanitize_text_field( $bucket ) . $path;
		$now      = time();
		$date     = gmdate( 'Ymd', $now );
		$datetime = gmdate( 'Ymd\THis\Z', $now );

		// Payload hash.
		$payload_hash = hash( 'sha256', $body );

		// Build headers.
		$headers = array_merge(
			array(
				'Host'                 => $host,
				'x-amz-content-sha256' => $payload_hash,
				'x-amz-date'           => $datetime,
			),
			$extra_headers
		);

		// Signed headers.
		$signed_headers_list = array_keys( $headers );
		$signed_headers_list = array_map( 'strtolower', $signed_headers_list );
		sort( $signed_headers_list );
		$signed_headers = implode( ';', $signed_headers_list );

		// Canonical headers.
		$canonical_headers = '';
		$lower_headers     = array();
		foreach ( $headers as $k => $v ) {
			$lower_headers[ strtolower( $k ) ] = trim( $v );
		}
		ksort( $lower_headers );
		foreach ( $lower_headers as $k => $v ) {
			$canonical_headers .= $k . ':' . $v . "\n";
		}

		// Canonical query string.
		ksort( $query_params );
		$canonical_query = $this->build_query_string( $query_params );

		// Canonical request.
		$canonical_request = implode( "\n", array(
			$method,
			$this->uri_encode_path( $uri ),
			$canonical_query,
			$canonical_headers,
			$signed_headers,
			$payload_hash,
		) );

		// String to sign.
		$credential_scope = $date . '/' . $this->region . '/' . $this->service . '/aws4_request';
		$string_to_sign   = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$datetime,
			$credential_scope,
			hash( 'sha256', $canonical_request ),
		) );

		// Signing key.
		$signing_key = $this->get_signing_key( $date );

		// Signature.
		$signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		// Authorization header.
		$headers['Authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$access_key,
			$credential_scope,
			$signed_headers,
			$signature
		);

		// Build full URL.
		$url = 'https://' . $host . $uri;
		if ( ! empty( $canonical_query ) ) {
			$url .= '?' . $canonical_query;
		}

		// Remove Host from wp_remote headers (WordPress sets it).
		unset( $headers['Host'] );

		$timeout = (int) apply_filters( 'r2cs_request_timeout', 120, $method, $path );
		$args = array(
			'method'    => $method,
			'headers'   => $headers,
			'body'      => $body,
			'timeout'   => $timeout,
			'sslverify' => true,
		);

		return wp_remote_request( $url, $args );
	}

	/**
	 * Derive the signing key for AWS Signature V4.
	 *
	 * @param string $date Date in Ymd format.
	 * @return string Binary signing key.
	 */
	private function get_signing_key( $date ) {
		$secret_key   = $this->settings->get( 'secret_key' );
		$date_key     = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$region_key   = hash_hmac( 'sha256', $this->region, $date_key, true );
		$service_key  = hash_hmac( 'sha256', $this->service, $region_key, true );
		$signing_key  = hash_hmac( 'sha256', 'aws4_request', $service_key, true );
		return $signing_key;
	}

	/**
	 * Build canonical query string (RFC 3986 encoded, sorted).
	 *
	 * @param array $params Key-value pairs.
	 * @return string
	 */
	private function build_query_string( $params ) {
		if ( empty( $params ) ) {
			return '';
		}
		ksort( $params );
		$parts = array();
		foreach ( $params as $k => $v ) {
			$parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
		}
		return implode( '&', $parts );
	}

	/**
	 * URI-encode path segments per S3 spec (don't encode /).
	 *
	 * @param string $path
	 * @return string
	 */
	private function uri_encode_path( $path ) {
		$segments = explode( '/', $path );
		$encoded  = array_map( 'rawurlencode', $segments );
		return implode( '/', $encoded );
	}
}
