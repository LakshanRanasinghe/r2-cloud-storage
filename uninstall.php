<?php
/**
 * Uninstall handler — Cleanup when plugin is deleted.
 *
 * @package R2CloudStorage
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options.
delete_option( 'r2cs_settings' );
delete_option( 'r2cs_version' );

// Remove post meta created by the plugin.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, no caching needed.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
		'_r2cs_offloaded',
		'_r2cs_remote_key'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, no caching needed.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_r2cs_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_r2cs_' ) . '%'
	)
);
