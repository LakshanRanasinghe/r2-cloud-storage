<?php
/**
 * Sync page template.
 *
 * @package R2CloudStorage
 */

defined( 'ABSPATH' ) || exit;

$r2cs_settings   = r2cs()->settings();
$r2cs_configured = $r2cs_settings->is_configured();
?>
<div class="wrap r2cs-wrap">
	<h1>
		<span class="dashicons dashicons-update r2cs-header-icon"></span>
		<?php esc_html_e( 'Media Sync', 'r2-cloud-storage' ); ?>
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
		<div class="r2cs-sync-container">
			<div class="r2cs-sync-info">
				<h2><?php esc_html_e( 'Migrate Existing Media to R2', 'r2-cloud-storage' ); ?></h2>
				<p><?php esc_html_e( 'This tool uploads all existing media files to Cloudflare R2. The process runs in batches to avoid overloading the server.', 'r2-cloud-storage' ); ?></p>

				<div class="r2cs-progress-container" style="display:none;">
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

				<div class="r2cs-sync-log" id="r2cs-sync-log" style="display:none;">
					<h3><?php esc_html_e( 'Log', 'r2-cloud-storage' ); ?></h3>
					<div class="r2cs-log-entries" id="r2cs-log-entries"></div>
				</div>

				<div class="r2cs-sync-actions">
					<button type="button" id="r2cs-start-sync" class="button button-primary button-hero">
						<span class="dashicons dashicons-cloud-upload"></span>
						<?php esc_html_e( 'Start Sync', 'r2-cloud-storage' ); ?>
					</button>

					<button type="button" id="r2cs-stop-sync" class="button button-secondary" style="display:none;">
						<span class="dashicons dashicons-controls-pause"></span>
						<?php esc_html_e( 'Pause', 'r2-cloud-storage' ); ?>
					</button>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
