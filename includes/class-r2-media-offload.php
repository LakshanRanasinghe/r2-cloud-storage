<?php
/**
 * Media Library offload to R2.
 *
 * Hooks into WordPress upload flow to automatically send media to R2
 * and optionally rewrite URLs.
 *
 * @package R2CloudStorage
 */

namespace R2CS;

defined( 'ABSPATH' ) || exit;

class R2_Media_Offload {

	/** @var R2_Client */
	private $client;

	/** @var R2_Settings */
	private $settings;

	/** @var string Meta key to track offloaded files. */
	const META_KEY = '_r2cs_offloaded';

	/** @var string Meta key for remote key. */
	const REMOTE_KEY_META = '_r2cs_remote_key';

	/**
	 * @param R2_Client   $client
	 * @param R2_Settings $settings
	 */
	public function __construct( R2_Client $client, R2_Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;

		// Only hook if offload is enabled and configured.
		if ( $this->settings->is_configured() ) {
			$this->init_hooks();
		}
	}

	/**
	 * Register WordPress hooks.
	 */
	private function init_hooks() {
		// Upload: offload after WordPress processes the upload.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'offload_on_upload' ), 10, 2 );

		// Delete: remove from R2 when attachment is deleted.
		add_action( 'delete_attachment', array( $this, 'delete_from_r2' ) );

		// URL rewrite: serve from R2 instead of local.
		add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_attachment_url' ), 10, 2 );

		// Intercept thumbnail/intermediate size URL generation.
		add_filter( 'image_downsize', array( $this, 'rewrite_image_downsize' ), 10, 3 );

		// Also rewrite srcset URLs for responsive images.
		add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite_srcset_urls' ), 10, 5 );

		/**
		 * Filter to allow add-ons to hook into post-offload actions.
		 */
		add_action( 'r2cs_after_offload', array( $this, 'maybe_remove_local' ), 10, 3 );
	}

	/**
	 * Offload attachment and its thumbnails after upload.
	 *
	 * @param array $metadata Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public function offload_on_upload( $metadata, $attachment_id ) {
		if ( ! $this->settings->get( 'offload_media' ) ) {
			return $metadata;
		}

		$this->process_attachment_offload( $attachment_id, $metadata );

		return $metadata;
	}

	/**
	 * Delete object from R2 when attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete_from_r2( $attachment_id ) {
		if ( ! get_post_meta( $attachment_id, self::META_KEY, true ) ) {
			return;
		}

		$remote_key = get_post_meta( $attachment_id, self::REMOTE_KEY_META, true );
		if ( ! empty( $remote_key ) ) {
			$this->client->delete_object( $remote_key );

			// Delete thumbnails using remote key directory.
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$remote_dir = dirname( $remote_key );
				$remote_dir = ( '.' === $remote_dir || empty( $remote_dir ) ) ? '' : trailingslashit( $remote_dir );

				foreach ( $metadata['sizes'] as $size_data ) {
					if ( ! empty( $size_data['file'] ) ) {
						$thumb_remote = $remote_dir . $size_data['file'];
						$this->client->delete_object( $thumb_remote );
					}
				}
			}
		}
	}

	/**
	 * Rewrite attachment URL to R2.
	 *
	 * @param string $url           Original URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function rewrite_attachment_url( $url, $attachment_id ) {
		if ( ! get_post_meta( $attachment_id, self::META_KEY, true ) ) {
			return $url;
		}

		$remote_key = get_post_meta( $attachment_id, self::REMOTE_KEY_META, true );
		if ( empty( $remote_key ) ) {
			return $url;
		}

		// The R2 S3 endpoint always requires authentication, so we must use
		// presigned URLs unless a custom domain with public access is configured.
		$custom_domain = $this->settings->get( 'custom_domain' );
		if ( $this->settings->get( 'signed_urls' ) || empty( $custom_domain ) ) {
			$expiry = (int) $this->settings->get( 'signed_expiry', 3600 );
			return $this->client->get_presigned_url( $remote_key, $expiry );
		}

		return $this->client->get_object_url( $remote_key );
	}

	/**
	 * Intercept image_downsize to return correct R2 URLs for intermediate sizes.
	 *
	 * Without this, WordPress tries to manipulate the presigned URL string to
	 * swap the filename, which breaks the signature query parameters.
	 *
	 * @param false|array $downsize  False to proceed with default behavior.
	 * @param int         $id        Attachment ID.
	 * @param string      $size      Requested image size name.
	 * @return false|array Array of (url, width, height, is_intermediate) or false.
	 */
	public function rewrite_image_downsize( $downsize, $id, $size ) {
		if ( ! get_post_meta( $id, self::META_KEY, true ) ) {
			return false;
		}

		$metadata = wp_get_attachment_metadata( $id );
		if ( empty( $metadata ) ) {
			return false;
		}

		$remote_key = get_post_meta( $id, self::REMOTE_KEY_META, true );
		if ( empty( $remote_key ) ) {
			return false;
		}

		$custom_domain = $this->settings->get( 'custom_domain' );
		$use_signed    = $this->settings->get( 'signed_urls' ) || empty( $custom_domain );
		$expiry        = (int) $this->settings->get( 'signed_expiry', 3600 );

		$remote_dir = dirname( $remote_key );
		$remote_dir = ( '.' === $remote_dir || empty( $remote_dir ) ) ? '' : trailingslashit( $remote_dir );

		// Handle 'full' size.
		if ( 'full' === $size || empty( $metadata['sizes'] ) ) {
			$url    = $use_signed ? $this->client->get_presigned_url( $remote_key, $expiry ) : $this->client->get_object_url( $remote_key );
			$width  = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
			$height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

			return array( $url, $width, $height, false );
		}

		// Named size (thumbnail, medium, large, etc.).
		if ( is_string( $size ) && isset( $metadata['sizes'][ $size ] ) ) {
			$size_data = $metadata['sizes'][ $size ];
			$thumb_key = $remote_dir . $size_data['file'];
			$url       = $use_signed ? $this->client->get_presigned_url( $thumb_key, $expiry ) : $this->client->get_object_url( $thumb_key );

			return array( $url, (int) $size_data['width'], (int) $size_data['height'], true );
		}

		// Array size [width, height] — find the best match.
		if ( is_array( $size ) ) {
			foreach ( $metadata['sizes'] as $size_data ) {
				if ( $size_data['width'] == $size[0] && $size_data['height'] == $size[1] ) {
					$thumb_key = $remote_dir . $size_data['file'];
					$url       = $use_signed ? $this->client->get_presigned_url( $thumb_key, $expiry ) : $this->client->get_object_url( $thumb_key );

					return array( $url, (int) $size_data['width'], (int) $size_data['height'], true );
				}
			}
		}

		// Requested size not found; fall back to full.
		$url    = $use_signed ? $this->client->get_presigned_url( $remote_key, $expiry ) : $this->client->get_object_url( $remote_key );
		$width  = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
		$height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

		return array( $url, $width, $height, false );
	}

	/**
	 * Rewrite srcset URLs to R2.
	 *
	 * @param array  $sources       Array of srcset sources.
	 * @param array  $size_array    Size array.
	 * @param string $image_src     Image src URL.
	 * @param array  $image_meta    Image metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	public function rewrite_srcset_urls( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! get_post_meta( $attachment_id, self::META_KEY, true ) ) {
			return $sources;
		}

		$remote_key = get_post_meta( $attachment_id, self::REMOTE_KEY_META, true );
		if ( empty( $remote_key ) ) {
			return $sources;
		}

		$remote_dir = dirname( $remote_key );
		$remote_dir = ( '.' === $remote_dir || empty( $remote_dir ) ) ? '' : trailingslashit( $remote_dir );

		$custom_domain = $this->settings->get( 'custom_domain' );
		$use_signed    = $this->settings->get( 'signed_urls' ) || empty( $custom_domain );
		$expiry        = (int) $this->settings->get( 'signed_expiry', 3600 );

		foreach ( $sources as &$source ) {
			$parsed_path    = wp_parse_url( $source['url'], PHP_URL_PATH );
			$thumb_filename = basename( $parsed_path );
			$thumb_key      = $remote_dir . $thumb_filename;

			if ( $use_signed ) {
				$source['url'] = $this->client->get_presigned_url( $thumb_key, $expiry );
			} else {
				$source['url'] = $this->client->get_object_url( $thumb_key );
			}
		}

		return $sources;
	}

	/**
	 * Remove local file after offload if configured.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $metadata      Attachment metadata.
	 * @param string $file          Resolved local file path.
	 */
	public function maybe_remove_local( $attachment_id, $metadata, $file = '' ) {
		if ( ! $this->settings->get( 'remove_local' ) ) {
			return;
		}

		if ( empty( $file ) || ! file_exists( $file ) ) {
			$file_info = $this->get_local_file_path( $attachment_id );
			$file      = is_array( $file_info ) && ! empty( $file_info['path'] ) ? $file_info['path'] : '';
		}

		if ( $file && file_exists( $file ) ) {
			$file_dir  = dirname( $file );
			$file_name = pathinfo( $file, PATHINFO_FILENAME );

			wp_delete_file( $file );

			// Remove companion WebP files for main file if present.
			$main_webp = $file_dir . '/' . $file_name . '.webp';
			if ( file_exists( $main_webp ) && $main_webp !== $file ) {
				wp_delete_file( $main_webp );
			}
			if ( file_exists( $file . '.webp' ) ) {
				wp_delete_file( $file . '.webp' );
			}

			// Remove thumbnails located in the same directory as the main file.
			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_data ) {
					if ( empty( $size_data['file'] ) ) {
						continue;
					}
					$thumb      = trailingslashit( $file_dir ) . $size_data['file'];
					$thumb_name = pathinfo( $size_data['file'], PATHINFO_FILENAME );

					if ( file_exists( $thumb ) ) {
						wp_delete_file( $thumb );
					}
					// Remove companion WebP for thumbnails.
					$thumb_webp = $file_dir . '/' . $thumb_name . '.webp';
					if ( file_exists( $thumb_webp ) && $thumb_webp !== $thumb ) {
						wp_delete_file( $thumb_webp );
					}
					if ( file_exists( $thumb . '.webp' ) ) {
						wp_delete_file( $thumb . '.webp' );
					}
				}
			}
		}
	}

	/**
	 * Check if an attachment is offloaded.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_offloaded( $attachment_id ) {
		return (bool) get_post_meta( $attachment_id, self::META_KEY, true );
	}

	/**
	 * Manually offload a single attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return true|\WP_Error
	 */
	public function offload_attachment( $attachment_id ) {
		return $this->process_attachment_offload( $attachment_id );
	}

	/**
	 * Locate the local file path for an attachment, with fallbacks for custom
	 * upload directories (e.g. UPLOADS constant), site migrations, and HTTP fetch.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|false Array with 'path' and 'is_temp', or false if not found.
	 */
	public function get_local_file_path( $attachment_id ) {
		// 1. Standard WordPress lookup.
		$file = get_attached_file( $attachment_id );
		if ( ! empty( $file ) && file_exists( $file ) ) {
			return array( 'path' => $file, 'is_temp' => false );
		}
		if ( ! empty( $file ) && file_exists( urldecode( $file ) ) ) {
			return array( 'path' => urldecode( $file ), 'is_temp' => false );
		}

		$upload_dir = wp_upload_dir();
		$basedir    = trailingslashit( $upload_dir['basedir'] );

		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$meta          = wp_get_attachment_metadata( $attachment_id );
		$meta_file     = is_array( $meta ) && ! empty( $meta['file'] ) ? $meta['file'] : '';
		$guid          = get_post_field( 'guid', $attachment_id );
		$url           = wp_get_attachment_url( $attachment_id );

		$raw_candidates = array();

		if ( ! empty( $attached_file ) ) {
			$raw_candidates[] = $attached_file;
			if ( 0 === strpos( $attached_file, 'http://' ) || 0 === strpos( $attached_file, 'https://' ) ) {
				$parsed = wp_parse_url( $attached_file, PHP_URL_PATH );
				if ( $parsed ) {
					$raw_candidates[] = ltrim( $parsed, '/' );
				}
			}
		}

		if ( ! empty( $meta_file ) ) {
			$raw_candidates[] = $meta_file;
		}

		foreach ( array( $url, $guid ) as $u ) {
			if ( ! empty( $u ) && is_string( $u ) ) {
				$parsed = wp_parse_url( $u, PHP_URL_PATH );
				if ( $parsed ) {
					$raw_candidates[] = ltrim( $parsed, '/' );
				}
			}
		}

		// Normalize and clean relative paths.
		$clean_rel = array();
		$custom_uploads = defined( 'UPLOADS' ) ? trim( UPLOADS, '/' ) : 'besv-uploads';
		$u_prefix = ! empty( $custom_uploads ) ? $custom_uploads . '/' : '';

		foreach ( $raw_candidates as $cand ) {
			if ( empty( $cand ) ) {
				continue;
			}
			$clean_rel[] = $cand;
			$clean_rel[] = ltrim( $cand, '/' );
			$clean_rel[] = urldecode( $cand );
			$clean_rel[] = rawurldecode( $cand );
			$clean_rel[] = str_replace( '+', ' ', $cand );
			$clean_rel[] = str_replace( '%20', ' ', $cand );
			$clean_rel[] = urldecode( str_replace( '+', ' ', $cand ) );

			if ( false !== strpos( $cand, 'wp-content/uploads/' ) ) {
				$clean_rel[] = substr( $cand, strpos( $cand, 'wp-content/uploads/' ) + 19 );
			}
			if ( false !== strpos( $cand, 'besv-uploads/' ) ) {
				$clean_rel[] = substr( $cand, strpos( $cand, 'besv-uploads/' ) + 13 );
			}
			if ( ! empty( $u_prefix ) && false !== strpos( $cand, $u_prefix ) ) {
				$clean_rel[] = substr( $cand, strpos( $cand, $u_prefix ) + strlen( $u_prefix ) );
			}

			$clean_rel[] = preg_replace( '#^(?:' . preg_quote( $custom_uploads, '#' ) . '/|besv-uploads/|wp-content/uploads/)#', '', ltrim( $cand, '/' ) );
			// Also add the bare filename as fallback in case directory structure was flattened.
			$clean_rel[] = basename( $cand );
			$clean_rel[] = urldecode( basename( $cand ) );
		}
		$clean_rel = array_unique( array_filter( $clean_rel ) );

		// Directories to probe on disk.
		$dirs_to_probe = array();

		// Add detected sync uploads folder (e.g. /srv/htdocs/besv-uploads) as top priority.
		$sync = r2cs()->get( 'sync' );
		if ( $sync && method_exists( $sync, 'get_uploads_folder_path' ) ) {
			$sync_folder = $sync->get_uploads_folder_path();
			if ( ! empty( $sync_folder ) ) {
				$dirs_to_probe[] = trailingslashit( $sync_folder );
			}
		}

		// Probe custom uploads folder (e.g. besv-uploads) at root level.
		if ( ! empty( $custom_uploads ) ) {
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$dirs_to_probe[] = trailingslashit( dirname( WP_CONTENT_DIR ) ) . $custom_uploads . '/';
			}
			if ( defined( 'ABSPATH' ) ) {
				$dirs_to_probe[] = trailingslashit( ABSPATH ) . $custom_uploads . '/';
				$dirs_to_probe[] = trailingslashit( dirname( ABSPATH ) ) . $custom_uploads . '/';
			}
			if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
				$dirs_to_probe[] = trailingslashit( $_SERVER['DOCUMENT_ROOT'] ) . $custom_uploads . '/';
				$dirs_to_probe[] = trailingslashit( dirname( $_SERVER['DOCUMENT_ROOT'] ) ) . $custom_uploads . '/';
			}
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$dirs_to_probe[] = trailingslashit( dirname( WP_CONTENT_DIR ) ) . 'besv-uploads/';
		}

		if ( defined( 'ABSPATH' ) ) {
			$dirs_to_probe[] = trailingslashit( ABSPATH ) . 'besv-uploads/';
			$dirs_to_probe[] = trailingslashit( dirname( ABSPATH ) ) . 'besv-uploads/';
		}

		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$dirs_to_probe[] = trailingslashit( $_SERVER['DOCUMENT_ROOT'] ) . 'besv-uploads/';
		}

		$dirs_to_probe[] = $basedir;

		// Fallback: inside wp-content.
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$dirs_to_probe[] = trailingslashit( WP_CONTENT_DIR ) . 'uploads/';
			$dirs_to_probe[] = trailingslashit( WP_CONTENT_DIR ) . 'besv-uploads/';
			$dirs_to_probe[] = trailingslashit( WP_CONTENT_DIR );
		}

		if ( defined( 'ABSPATH' ) ) {
			$dirs_to_probe[] = trailingslashit( ABSPATH ) . 'wp-content/uploads/';
			$dirs_to_probe[] = trailingslashit( ABSPATH );
		}

		$dirs_to_probe = array_unique( array_filter( $dirs_to_probe ) );

		// Check all directory + relative path combinations.
		foreach ( $dirs_to_probe as $dir ) {
			foreach ( $clean_rel as $rel ) {
				$candidate = trailingslashit( $dir ) . ltrim( $rel, '/' );
				if ( file_exists( $candidate ) ) {
					return array( 'path' => $candidate, 'is_temp' => false );
				}

				// Case-insensitive extension probe (e.g. .pdf vs .PDF, .webp vs .WEBP).
				$dot_pos = strrpos( $candidate, '.' );
				if ( false !== $dot_pos ) {
					$base_part = substr( $candidate, 0, $dot_pos );
					$ext_part  = substr( $candidate, $dot_pos + 1 );
					$alt_exts  = array( strtolower( $ext_part ), strtoupper( $ext_part ), ucfirst( strtolower( $ext_part ) ) );
					foreach ( $alt_exts as $alt ) {
						$alt_candidate = $base_part . '.' . $alt;
						if ( $alt_candidate !== $candidate && file_exists( $alt_candidate ) ) {
							return array( 'path' => $alt_candidate, 'is_temp' => false );
						}
					}
				}
			}
		}

		// Check if any candidate is already an absolute path that exists.
		foreach ( $raw_candidates as $cand ) {
			if ( 0 === strpos( $cand, '/' ) && file_exists( $cand ) ) {
				return array( 'path' => $cand, 'is_temp' => false );
			}
		}

		// Fallback: If local file cannot be found on disk, but the image is accessible via URL on the frontend:
		if ( ! empty( $url ) && ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) ) ) {
			$response = wp_remote_get( $url, array(
				'timeout'   => 30,
				'sslverify' => false,
			) );

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				if ( ! empty( $body ) ) {
					$parsed_name = basename( wp_parse_url( $url, PHP_URL_PATH ) );
					$temp_file   = wp_tempnam( $parsed_name );
					if ( $temp_file && false !== file_put_contents( $temp_file, $body ) ) {
						return array( 'path' => $temp_file, 'is_temp' => true );
					}
				}
			}
		}

		return false;
	}

	/**
	 * Compute the relative path for an attachment to be used in the R2 remote key.
	 *
	 * @param string $file          Local file path.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $basedir       Upload base directory.
	 * @return string Clean relative path (e.g. '2024/03/image.jpg').
	 */
	public function get_relative_path( $file, $attachment_id, $basedir ) {
		$basedir = trailingslashit( $basedir );

		// 0. If file is inside detected or configured uploads directory (e.g. /srv/htdocs/besv-uploads):
		$sync = r2cs()->get( 'sync' );
		if ( $sync && method_exists( $sync, 'get_uploads_folder_path' ) ) {
			$sync_folder = $sync->get_uploads_folder_path();
			if ( ! empty( $sync_folder ) ) {
				$sync_dir = trailingslashit( $sync_folder );
				if ( 0 === strpos( $file, $sync_dir ) ) {
					return ltrim( substr( $file, strlen( $sync_dir ) ), '/' );
				}
			}
		}

		// 1. If file is inside current upload basedir:
		if ( 0 === strpos( $file, $basedir ) ) {
			return ltrim( substr( $file, strlen( $basedir ) ), '/' );
		}

		// 2. If file is inside custom UPLOADS or besv-uploads:
		$custom_uploads = defined( 'UPLOADS' ) ? trim( UPLOADS, '/' ) : 'besv-uploads';
		if ( ! empty( $custom_uploads ) ) {
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$c_dir = trailingslashit( dirname( WP_CONTENT_DIR ) ) . $custom_uploads . '/';
				if ( 0 === strpos( $file, $c_dir ) ) {
					return ltrim( substr( $file, strlen( $c_dir ) ), '/' );
				}
			}
			if ( defined( 'ABSPATH' ) ) {
				$c_dir = trailingslashit( ABSPATH ) . $custom_uploads . '/';
				if ( 0 === strpos( $file, $c_dir ) ) {
					return ltrim( substr( $file, strlen( $c_dir ) ), '/' );
				}
				$c_dir = trailingslashit( dirname( ABSPATH ) ) . $custom_uploads . '/';
				if ( 0 === strpos( $file, $c_dir ) ) {
					return ltrim( substr( $file, strlen( $c_dir ) ), '/' );
				}
			}
			if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
				$c_dir = trailingslashit( $_SERVER['DOCUMENT_ROOT'] ) . $custom_uploads . '/';
				if ( 0 === strpos( $file, $c_dir ) ) {
					return ltrim( substr( $file, strlen( $c_dir ) ), '/' );
				}
			}
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$c_dir = trailingslashit( WP_CONTENT_DIR ) . $custom_uploads . '/';
				if ( 0 === strpos( $file, $c_dir ) ) {
					return ltrim( substr( $file, strlen( $c_dir ) ), '/' );
				}
			}
		}

		// 3. If file is inside legacy WP_CONTENT_DIR/uploads:
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$legacy_dir = trailingslashit( WP_CONTENT_DIR ) . 'uploads/';
			if ( 0 === strpos( $file, $legacy_dir ) ) {
				return ltrim( substr( $file, strlen( $legacy_dir ) ), '/' );
			}
		}

		// 4. Fallback: normalize from _wp_attached_file postmeta:
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! empty( $attached ) ) {
			$cleaned = ltrim( $attached, '/' );
			if ( false !== strpos( $attached, 'wp-content/uploads/' ) ) {
				$cleaned = substr( $attached, strpos( $attached, 'wp-content/uploads/' ) + 19 );
			} elseif ( false !== strpos( $attached, 'besv-uploads/' ) ) {
				$cleaned = substr( $attached, strpos( $attached, 'besv-uploads/' ) + 13 );
			} elseif ( ! empty( $custom_uploads ) && false !== strpos( $attached, $custom_uploads . '/' ) ) {
				$cleaned = substr( $attached, strpos( $attached, $custom_uploads . '/' ) + strlen( $custom_uploads ) + 1 );
			} else {
				$cleaned = preg_replace( '#^(?:' . preg_quote( $custom_uploads, '#' ) . '/|besv-uploads/|wp-content/uploads/)#', '', $cleaned );
			}
			if ( ! empty( $cleaned ) && false === strpos( $cleaned, '://' ) ) {
				return $cleaned;
			}
		}

		// 5. Fallback: normalize from metadata['file']:
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
			return ltrim( $meta['file'], '/' );
		}

		// 6. Fallback: normalize from URL path:
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! empty( $url ) ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( $path ) {
				$cleaned = preg_replace( '#^/(?:' . preg_quote( $custom_uploads, '#' ) . '/|besv-uploads/|wp-content/uploads/)#', '', $path );
				return ltrim( $cleaned, '/' );
			}
		}

		// 7. Default to basename.
		return basename( $file );
	}

	/**
	 * Unified offloading pipeline for an attachment and its thumbnails.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Optional attachment metadata.
	 * @return true|\WP_Error True on success, or WP_Error on failure.
	 */
	public function process_attachment_offload( $attachment_id, $metadata = null ) {
		$file_info = $this->get_local_file_path( $attachment_id );
		if ( ! $file_info || empty( $file_info['path'] ) || ! file_exists( $file_info['path'] ) ) {
			$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
			$url           = wp_get_attachment_url( $attachment_id );
			return new \WP_Error(
				'r2cs_file_missing',
				sprintf(
					/* translators: 1: stored DB path, 2: attachment URL */
					__( 'File not found on disk or URL. Stored DB path: "%1$s" | URL: "%2$s"', 'r2-cloud-storage' ),
					$attached_file ?: '(empty)',
					$url ?: '(none)'
				)
			);
		}

		$file    = $file_info['path'];
		$is_temp = ! empty( $file_info['is_temp'] );

		$upload_dir    = wp_upload_dir();
		$base_dir      = trailingslashit( $upload_dir['basedir'] );
		$prefix        = $this->settings->get( 'path_prefix', 'wp-content/uploads/' );
		$relative_path = $this->get_relative_path( $file, $attachment_id, $base_dir );
		$remote_key    = trailingslashit( $prefix ) . $relative_path;

		// 1. Upload the main file.
		$result = $this->client->upload_file( $file, $remote_key );
		if ( $is_temp ) {
			@unlink( $file );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Mark as offloaded.
		update_post_meta( $attachment_id, self::META_KEY, true );
		update_post_meta( $attachment_id, self::REMOTE_KEY_META, $remote_key );

		$file_dir   = dirname( $file );
		$file_name  = pathinfo( $file, PATHINFO_FILENAME );
		$remote_dir = dirname( $remote_key );
		$remote_dir = ( '.' === $remote_dir || empty( $remote_dir ) ) ? '' : trailingslashit( $remote_dir );

		// Check and upload companion WebP files for main file (e.g. image.webp or image.jpg.webp).
		$main_webp_alt1 = $file_dir . '/' . $file_name . '.webp';
		$main_webp_alt2 = $file . '.webp';
		if ( file_exists( $main_webp_alt1 ) && $main_webp_alt1 !== $file ) {
			$this->client->upload_file( $main_webp_alt1, $remote_dir . $file_name . '.webp', 'image/webp' );
		}
		if ( file_exists( $main_webp_alt2 ) && $main_webp_alt2 !== $file ) {
			$this->client->upload_file( $main_webp_alt2, $remote_key . '.webp', 'image/webp' );
		}

		// 2. Upload thumbnails if metadata has sizes.
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$thumb_path   = trailingslashit( $file_dir ) . $size_data['file'];
				$thumb_remote = $remote_dir . $size_data['file'];

				if ( file_exists( $thumb_path ) ) {
					$this->client->upload_file( $thumb_path, $thumb_remote );

					// Companion WebP for thumbnail (e.g. image-300x200.webp or image-300x200.jpg.webp).
					$thumb_name      = pathinfo( $size_data['file'], PATHINFO_FILENAME );
					$thumb_webp_alt1 = $file_dir . '/' . $thumb_name . '.webp';
					$thumb_webp_alt2 = $thumb_path . '.webp';
					if ( file_exists( $thumb_webp_alt1 ) && $thumb_webp_alt1 !== $thumb_path ) {
						$this->client->upload_file( $thumb_webp_alt1, $remote_dir . $thumb_name . '.webp', 'image/webp' );
					}
					if ( file_exists( $thumb_webp_alt2 ) && $thumb_webp_alt2 !== $thumb_path ) {
						$this->client->upload_file( $thumb_webp_alt2, $thumb_remote . '.webp', 'image/webp' );
					}
				} elseif ( $is_temp ) {
					// If main file was fetched via URL, fetch thumbnail via URL as well:
					$thumb_url = wp_get_attachment_image_url( $attachment_id, $size );
					if ( ! empty( $thumb_url ) ) {
						$thumb_resp = wp_remote_get( $thumb_url, array( 'timeout' => 20, 'sslverify' => false ) );
						if ( ! is_wp_error( $thumb_resp ) && 200 === wp_remote_retrieve_response_code( $thumb_resp ) ) {
							$thumb_body = wp_remote_retrieve_body( $thumb_resp );
							$temp_thumb = wp_tempnam( $size_data['file'] );
							if ( $temp_thumb && false !== file_put_contents( $temp_thumb, $thumb_body ) ) {
								$this->client->upload_file( $temp_thumb, $thumb_remote );
								@unlink( $temp_thumb );
							}
						}
					}
				}
			}
		} else {
			// If metadata has no sizes (common for direct WebP or PDF), probe disk for matching generated thumbnails.
			$escaped_base = preg_replace( '/([\[\]*?])/', '[$1]', $file_name );
			$extra_thumbs = glob( $file_dir . '/' . $escaped_base . '-[0-9]*x[0-9]*.*' );
			if ( ! empty( $extra_thumbs ) && is_array( $extra_thumbs ) ) {
				foreach ( $extra_thumbs as $extra_thumb ) {
					$extra_base = basename( $extra_thumb );
					$this->client->upload_file( $extra_thumb, $remote_dir . $extra_base );
				}
			}
		}

		/**
		 * Fires after an attachment is offloaded to R2.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param array  $metadata      Attachment metadata.
		 * @param string $file          Resolved local file path.
		 */
		do_action( 'r2cs_after_offload', $attachment_id, $metadata, $is_temp ? '' : $file );

	}

	/**
	 * Get offload statistics.
	 *
	 * @return array { total: int, offloaded: int, failed: int, pending: int }
	 */
	public function get_stats() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate count, not cacheable.
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
			'attachment'
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate count, not cacheable.
		$offloaded = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
			self::META_KEY
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Failed count.
		$failed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '-1'",
			self::META_KEY
		) );

		return array(
			'total'     => $total,
			'offloaded' => $offloaded,
			'failed'    => $failed,
			'pending'   => max( 0, $total - $offloaded ),
		);
	}
}
