<?php
/**
 * Sync page template.
 *
 * @package R2CloudStorage
 */

defined( 'ABSPATH' ) || exit;

$r2cs_settings   = r2cs()->settings();
$r2cs_configured = $r2cs_settings->is_configured();
$r2cs_sync       = r2cs()->get( 'sync' );
$r2cs_media      = r2cs()->get( 'media' );
$r2cs_stats      = $r2cs_media ? $r2cs_media->get_stats() : array( 'total' => 0, 'offloaded' => 0, 'failed' => 0, 'pending' => 0 );
$uploads_folder  = $r2cs_sync ? $r2cs_sync->get_uploads_folder_path() : '';
$custom_override = $r2cs_settings ? $r2cs_settings->get( 'local_uploads_path' ) : '';
$folder_name     = $uploads_folder ? basename( $uploads_folder ) : 'besv-uploads';
?>
<div class="wrap r2cs-wrap">
	<h1>
		<span class="dashicons dashicons-update r2cs-header-icon"></span>
		<?php esc_html_e( 'Media & Uploads Sync', 'r2-cloud-storage' ); ?>
	</h1>

	<?php if ( ! $r2cs_configured ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: link to settings page */
					esc_html__( 'Configure your R2 credentials first in the %s.', 'r2-cloud-storage' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=r2-cloud-storage' ) ) . '">' . esc_html__( 'Settings', 'r2-cloud-storage' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php else : ?>

		<!-- Dashboard Summary Cards -->
		<div class="r2cs-dashboard-cards">
			<div class="r2cs-card">
				<span class="dashicons dashicons-admin-media r2cs-card-icon"></span>
				<div class="r2cs-card-content">
					<span class="r2cs-card-number" id="r2cs-stat-total"><?php echo esc_html( (string) $r2cs_stats['total'] ); ?></span>
					<span class="r2cs-card-label"><?php esc_html_e( 'Total Media', 'r2-cloud-storage' ); ?></span>
				</div>
			</div>
			<div class="r2cs-card">
				<span class="dashicons dashicons-cloud-saved r2cs-card-icon"></span>
				<div class="r2cs-card-content">
					<span class="r2cs-card-number" id="r2cs-stat-offloaded"><?php echo esc_html( (string) $r2cs_stats['offloaded'] ); ?></span>
					<span class="r2cs-card-label"><?php esc_html_e( 'Offloaded to R2', 'r2-cloud-storage' ); ?></span>
				</div>
			</div>
			<div class="r2cs-card">
				<span class="dashicons dashicons-clock r2cs-card-icon"></span>
				<div class="r2cs-card-content">
					<span class="r2cs-card-number" id="r2cs-stat-pending"><?php echo esc_html( (string) $r2cs_stats['pending'] ); ?></span>
					<span class="r2cs-card-label"><?php esc_html_e( 'Pending Media', 'r2-cloud-storage' ); ?></span>
				</div>
			</div>
			<div class="r2cs-card">
				<span class="dashicons dashicons-warning r2cs-card-icon" style="<?php echo ( ! empty( $r2cs_stats['failed'] ) ) ? 'color:#dc3232;' : ''; ?>"></span>
				<div class="r2cs-card-content">
					<span class="r2cs-card-number" id="r2cs-stat-failed"><?php echo esc_html( (string) ( $r2cs_stats['failed'] ?? 0 ) ); ?></span>
					<span class="r2cs-card-label"><?php esc_html_e( 'Failed Items', 'r2-cloud-storage' ); ?></span>
				</div>
			</div>
		</div>

		<div class="r2cs-sync-container">
			<div class="r2cs-sync-info">
				<h2><?php esc_html_e( 'Migrate Media & Uploads to Cloudflare R2', 'r2-cloud-storage' ); ?></h2>
				<p>
					<?php esc_html_e( 'Sync all images (including companion .webp files), thumbnails, and PDF documents from your WordPress uploads folder to Cloudflare R2.', 'r2-cloud-storage' ); ?>
				</p>

				<?php if ( $uploads_folder ) : ?>
					<div class="notice notice-info inline" style="margin: 12px 0; padding: 10px 14px; border-left-color: #2271b1;">
						<p style="margin: 4px 0;">
							<strong><?php echo ! empty( $custom_override ) ? esc_html__( 'Configured Uploads Directory:', 'r2-cloud-storage' ) : esc_html__( 'Detected Uploads Directory:', 'r2-cloud-storage' ); ?></strong>
							<code style="font-weight: 600; font-size: 13px;"><?php echo esc_html( $uploads_folder ); ?></code>
							<?php if ( defined( 'UPLOADS' ) ) : ?>
								<span class="r2cs-badge r2cs-badge-success" style="margin-left: 6px;"><?php echo esc_html( 'UPLOADS: ' . UPLOADS ); ?></span>
							<?php endif; ?>
						</p>
						<p style="margin: 4px 0 0; font-size: 12px; color: #646970;">
							<?php
							printf(
								/* translators: %s: link to settings page */
								esc_html__( 'Need to override this path? You can specify your directory path in %s.', 'r2-cloud-storage' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=r2-cloud-storage' ) ) . '">' . esc_html__( 'Settings &rarr; Local Uploads Directory Path', 'r2-cloud-storage' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<!-- Options -->
				<div style="background: #fdfdfd; border: 1px solid #e2e4e7; border-radius: 6px; padding: 12px 16px; margin: 16px 0;">
					<label style="display: block; margin-bottom: 8px;">
						<input type="checkbox" id="r2cs-retry-failed-checkbox" checked="checked" autocomplete="off">
						<strong><?php esc_html_e( 'Retry previously failed attachments (reset error status)', 'r2-cloud-storage' ); ?></strong>
					</label>
					<label style="display: block; margin-bottom: 8px;">
						<input type="checkbox" id="r2cs-skip-existing-checkbox" checked="checked" autocomplete="off">
						<strong><?php esc_html_e( 'Skip files already offloaded to R2 (prevents redundant re-uploads)', 'r2-cloud-storage' ); ?></strong>
					</label>
					<label style="display: block; margin-bottom: 8px;">
						<input type="checkbox" id="r2cs-remove-local-checkbox" <?php checked( (bool) $r2cs_settings->get( 'remove_local' ) ); ?> autocomplete="off">
						<strong><?php esc_html_e( 'Remove local files after successful upload or verification (Saves disk space)', 'r2-cloud-storage' ); ?></strong>
					</label>
					<label style="display: block;">
						<input type="checkbox" id="r2cs-sync-webp-checkbox" checked="checked" disabled="disabled">
						<span><?php esc_html_e( 'Auto-detect & sync companion .webp images alongside JPG/PNG files (Enabled)', 'r2-cloud-storage' ); ?></span>
					</label>
				</div>

				<!-- Progress Container -->
				<div class="r2cs-progress-container" style="display:none; margin: 20px 0;">
					<div class="r2cs-progress-bar-wrapper">
						<div class="r2cs-progress-bar" id="r2cs-progress-bar" style="width: 0%;">
							<span id="r2cs-progress-text">0%</span>
						</div>
					</div>
					<div class="r2cs-progress-details">
						<span id="r2cs-sync-status"><?php esc_html_e( 'Waiting...', 'r2-cloud-storage' ); ?></span>
						<span id="r2cs-sync-count"></span>
					</div>
				</div>

				<!-- Log -->
				<div class="r2cs-sync-log" id="r2cs-sync-log" style="display:none; margin: 20px 0;">
					<h3><?php esc_html_e( 'Log', 'r2-cloud-storage' ); ?></h3>
					<div class="r2cs-log-entries" id="r2cs-log-entries"></div>
				</div>

				<!-- Action Buttons -->
				<div class="r2cs-sync-actions" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
					<button type="button" id="r2cs-start-sync" class="button button-primary button-hero">
						<span class="dashicons dashicons-cloud-upload"></span>
						<?php esc_html_e( 'Start Media Library Sync', 'r2-cloud-storage' ); ?>
					</button>

					<button type="button" id="r2cs-start-folder-sync" class="button button-secondary button-hero" title="<?php esc_attr_e( 'Scans the uploads folder directly on disk for all images and PDFs', 'r2-cloud-storage' ); ?>">
						<span class="dashicons dashicons-category"></span>
						<?php
						printf(
							/* translators: %s: uploads folder name */
							esc_html__( "Sync '%s' Folder Directly", 'r2-cloud-storage' ),
							esc_html( $folder_name )
						);
						?>
					</button>

					<button type="button" id="r2cs-reset-failed-btn" class="button button-secondary" style="height: 46px; line-height: 44px;">
						<span class="dashicons dashicons-undo"></span>
						<?php esc_html_e( 'Reset Failed Status', 'r2-cloud-storage' ); ?>
					</button>

					<button type="button" id="r2cs-stop-sync" class="button button-secondary" style="display:none; height: 46px; line-height: 44px;">
						<span class="dashicons dashicons-controls-pause"></span>
						<?php esc_html_e( 'Pause Sync', 'r2-cloud-storage' ); ?>
					</button>
				</div>

				<p style="margin-top: 14px; color: #50575e; font-size: 13px; line-height: 1.5;">
					<strong><?php esc_html_e( 'Recommended:', 'r2-cloud-storage' ); ?></strong>
					<?php esc_html_e( 'Use "Start Media Library Sync" to offload all WordPress attachments (images, companion .webp files, and PDFs). Use "Sync Folder Directly" only to scan for extra unindexed files sitting on the server.', 'r2-cloud-storage' ); ?>
				</p>
			</div>
		</div>
	<?php endif; ?>
</div>
