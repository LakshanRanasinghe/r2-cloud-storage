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
$unsynced_count  = isset( $r2cs_stats['pending'] ) ? (int) $r2cs_stats['pending'] : 0;
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
			<div class="r2cs-card r2cs-card-unsynced" style="border-left: 4px solid #f38020;">
				<span class="dashicons dashicons-warning r2cs-card-icon" style="color: #f38020;"></span>
				<div class="r2cs-card-content">
					<span class="r2cs-card-number" id="r2cs-stat-unsynced" style="color: #f38020;"><?php echo esc_html( (string) $unsynced_count ); ?></span>
					<span class="r2cs-card-label"><?php esc_html_e( 'Not Synced Files', 'r2-cloud-storage' ); ?></span>
				</div>
			</div>
			<div class="r2cs-card">
				<span class="dashicons dashicons-dismiss r2cs-card-icon" style="<?php echo ( ! empty( $r2cs_stats['failed'] ) ) ? 'color:#dc3232;' : 'color:#a7aaad;'; ?>"></span>
				<div class="r2cs-card-content">
					<span class="r2cs-card-number" id="r2cs-stat-failed"><?php echo esc_html( (string) ( $r2cs_stats['failed'] ?? 0 ) ); ?></span>
					<span class="r2cs-card-label"><?php esc_html_e( 'Failed Items', 'r2-cloud-storage' ); ?></span>
				</div>
			</div>
		</div>

		<!-- Not Synced Files Section -->
		<div class="r2cs-unsynced-section" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
			<div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 18px;">
				<div>
					<h2 style="margin: 0 0 6px 0; font-size: 19px; display: flex; align-items: center; gap: 8px;">
						<span class="dashicons dashicons-category" style="color: #f38020; font-size: 24px;"></span>
						<?php esc_html_e( 'Not Synced Files', 'r2-cloud-storage' ); ?>
						<span class="r2cs-badge r2cs-badge-warning" id="r2cs-unsynced-badge"><?php echo esc_html( (string) $unsynced_count ); ?> <?php esc_html_e( 'files', 'r2-cloud-storage' ); ?></span>
					</h2>
					<p style="margin: 0; color: #646970; font-size: 13px;">
						<?php
						printf(
							/* translators: %s: uploads folder name */
							esc_html__( 'Files safely kept locally inside %s that have not been synced to Cloudflare R2. You can sync them now or whenever you need.', 'r2-cloud-storage' ),
							'<code>' . esc_html( $folder_name ) . '</code>'
						);
						?>
					</p>
				</div>
				<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
					<button type="button" id="r2cs-sync-all-unsynced-btn" class="button button-primary" style="height: 38px; line-height: 36px; padding: 0 16px;">
						<span class="dashicons dashicons-cloud-upload" style="vertical-align: middle; margin-top: -2px;"></span>
						<?php esc_html_e( 'Sync All Not-Synced Files', 'r2-cloud-storage' ); ?>
					</button>
					<button type="button" id="r2cs-refresh-unsynced-btn" class="button button-secondary" style="height: 38px; line-height: 36px;">
						<span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
						<?php esc_html_e( 'Refresh List', 'r2-cloud-storage' ); ?>
					</button>
				</div>
			</div>

			<!-- Filter / Search toolbar -->
			<div class="r2cs-unsynced-toolbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; gap: 12px; flex-wrap: wrap;">
				<div style="display: flex; align-items: center; gap: 8px;">
					<input type="search" id="r2cs-unsynced-search" placeholder="<?php esc_attr_e( 'Search not synced files...', 'r2-cloud-storage' ); ?>" style="min-width: 260px; height: 34px;" autocomplete="off" />
					<span id="r2cs-unsynced-search-count" style="font-size: 12px; color: #646970;"></span>
				</div>
				<div style="font-size: 13px; color: #646970;">
					<span id="r2cs-unsynced-showing-text"></span>
				</div>
			</div>

			<!-- Unsynced Files Table -->
			<div class="r2cs-table-responsive" style="max-height: 480px; overflow-y: auto; border: 1px solid #dcdcde; border-radius: 6px;">
				<table class="wp-list-table widefat fixed striped table-view-list" id="r2cs-unsynced-table" style="border: none;">
					<thead>
						<tr>
							<th style="width: 58px; text-align: center;"><?php esc_html_e( 'Preview', 'r2-cloud-storage' ); ?></th>
							<th><?php esc_html_e( 'File / Relative Path', 'r2-cloud-storage' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Size', 'r2-cloud-storage' ); ?></th>
							<th style="width: 140px;"><?php esc_html_e( 'Modified', 'r2-cloud-storage' ); ?></th>
							<th style="width: 110px;"><?php esc_html_e( 'Status', 'r2-cloud-storage' ); ?></th>
							<th style="width: 140px; text-align: right;"><?php esc_html_e( 'Action', 'r2-cloud-storage' ); ?></th>
						</tr>
					</thead>
					<tbody id="r2cs-unsynced-table-body">
						<tr>
							<td colspan="6" style="text-align: center; padding: 24px; color: #646970;">
								<span class="spinner is-active" style="float: none; margin: 0 6px 0 0;"></span>
								<?php esc_html_e( 'Scanning for not synced files...', 'r2-cloud-storage' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Empty State -->
			<div id="r2cs-unsynced-empty" style="display: none; padding: 36px 20px; text-align: center; background: #f6f7f7; border-radius: 6px; margin-top: 10px;">
				<span class="dashicons dashicons-yes-alt" style="font-size: 42px; color: #46b450; width: 42px; height: 42px; margin-bottom: 8px;"></span>
				<h3 style="margin: 4px 0 8px;"><?php esc_html_e( 'All Files in besv-uploads Are Synced!', 'r2-cloud-storage' ); ?></h3>
				<p style="margin: 0; color: #646970; max-width: 600px; margin-left: auto; margin-right: auto;">
					<?php esc_html_e( 'All media files are safely stored locally in besv-uploads and offloaded to Cloudflare R2. Any new media uploaded from frontend or backend will appear here ready to sync.', 'r2-cloud-storage' ); ?>
				</p>
			</div>

			<!-- Pagination Toolbar -->
			<div class="r2cs-pagination-toolbar" id="r2cs-unsynced-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px; font-size: 13px;">
				<span id="r2cs-page-info"></span>
				<div style="display: flex; gap: 6px;">
					<button type="button" class="button" id="r2cs-prev-page" disabled="disabled">&laquo; <?php esc_html_e( 'Previous', 'r2-cloud-storage' ); ?></button>
					<button type="button" class="button" id="r2cs-next-page" disabled="disabled"><?php esc_html_e( 'Next', 'r2-cloud-storage' ); ?> &raquo;</button>
				</div>
			</div>
		</div>

		<div class="r2cs-sync-container">
			<div class="r2cs-sync-info">
				<h2><?php esc_html_e( 'Bulk Migration & Synchronization Tools', 'r2-cloud-storage' ); ?></h2>
				<p>
					<?php esc_html_e( 'Bulk sync all images (including companion .webp files), thumbnails, and PDF documents from your WordPress uploads folder to Cloudflare R2.', 'r2-cloud-storage' ); ?>
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
						<input type="checkbox" id="r2cs-remove-local-checkbox" <?php checked( (bool) $r2cs_settings->get( 'remove_local' ) && ! $r2cs_settings->get( 'keep_local_uploads', true ) ); ?> autocomplete="off">
						<strong><?php esc_html_e( 'Remove local files after successful upload or verification (Disabled when "Keep Local Uploads in Folder" is enabled)', 'r2-cloud-storage' ); ?></strong>
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
					<?php esc_html_e( 'Use "Sync All Not-Synced Files" above or "Start Media Library Sync" to offload your WordPress attachments and folder files to Cloudflare R2 on demand.', 'r2-cloud-storage' ); ?>
				</p>
			</div>
		</div>
	<?php endif; ?>
</div>
