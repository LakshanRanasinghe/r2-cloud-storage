<?php
/**
 * Settings page template.
 *
 * @package R2CloudStorage
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap r2cs-wrap">
	<h1>
		<span class="dashicons dashicons-cloud-saved r2cs-header-icon"></span>
		<?php esc_html_e( 'R2 Cloud Storage', 'r2-cloud-storage' ); ?>
		<span class="r2cs-version">v<?php echo esc_html( R2CS_VERSION ); ?></span>
	</h1>

	<?php settings_errors(); ?>

	<!-- Dashboard Stats -->
	<div class="r2cs-dashboard-cards">
		<div class="r2cs-card">
			<div class="r2cs-card-icon dashicons dashicons-admin-media"></div>
			<div class="r2cs-card-content">
				<span class="r2cs-card-number" id="r2cs-stat-total">—</span>
				<span class="r2cs-card-label"><?php esc_html_e( 'Total Media', 'r2-cloud-storage' ); ?></span>
			</div>
		</div>
		<div class="r2cs-card">
			<div class="r2cs-card-icon dashicons dashicons-cloud-saved"></div>
			<div class="r2cs-card-content">
				<span class="r2cs-card-number" id="r2cs-stat-offloaded">—</span>
				<span class="r2cs-card-label"><?php esc_html_e( 'On R2', 'r2-cloud-storage' ); ?></span>
			</div>
		</div>
		<div class="r2cs-card">
			<div class="r2cs-card-icon dashicons dashicons-upload"></div>
			<div class="r2cs-card-content">
				<span class="r2cs-card-number" id="r2cs-stat-pending">—</span>
				<span class="r2cs-card-label"><?php esc_html_e( 'Pending', 'r2-cloud-storage' ); ?></span>
			</div>
		</div>
		<div class="r2cs-card">
			<div class="r2cs-card-icon dashicons dashicons-admin-plugins"></div>
			<div class="r2cs-card-content">
				<span class="r2cs-card-number" id="r2cs-stat-addons">—</span>
				<span class="r2cs-card-label"><?php esc_html_e( 'Active Add-ons', 'r2-cloud-storage' ); ?></span>
			</div>
		</div>
	</div>

	<!-- Settings Form -->
	<div class="r2cs-settings-container">
		<form method="post" action="options.php">
			<?php
			settings_fields( 'r2cs_settings_group' );
			do_settings_sections( 'r2-cloud-storage' );
			?>

			<div class="r2cs-actions-row">
				<?php submit_button( __( 'Save Settings', 'r2-cloud-storage' ), 'primary', 'submit', false ); ?>

				<button type="button" id="r2cs-test-connection" class="button button-secondary">
					<span class="dashicons dashicons-networking"></span>
					<?php esc_html_e( 'Test Connection', 'r2-cloud-storage' ); ?>
				</button>

				<span id="r2cs-test-result" class="r2cs-inline-message"></span>
			</div>
		</form>
	</div>

	<!-- R2 vs S3 Comparison -->
	<div class="r2cs-comparison-section" style="margin-top: 30px;">
		<h2>
			<span class="dashicons dashicons-chart-bar"></span>
			<?php esc_html_e( 'R2 vs S3 — Why Cloudflare R2?', 'r2-cloud-storage' ); ?>
		</h2>
		<p class="description" style="margin-bottom: 15px;">
			<?php esc_html_e( 'See how Cloudflare R2 compares to Amazon S3 and save up to 10x on storage costs.', 'r2-cloud-storage' ); ?>
		</p>
		<table class="widefat striped" style="max-width: 800px;">
			<thead>
				<tr>
					<th style="font-weight: 600;"><?php esc_html_e( 'Feature', 'r2-cloud-storage' ); ?></th>
					<th style="font-weight: 600; text-align: center; color: #f97316;">☁️ Cloudflare R2</th>
					<th style="font-weight: 600; text-align: center; color: #999;">Amazon S3</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Egress Fee', 'r2-cloud-storage' ); ?></strong></td>
					<td style="text-align: center; color: #16a34a; font-weight: 600;"><?php esc_html_e( 'Free — $0/GB', 'r2-cloud-storage' ); ?></td>
					<td style="text-align: center; color: #666;">$0,09/GB</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Storage Price', 'r2-cloud-storage' ); ?></strong></td>
					<td style="text-align: center; color: #16a34a; font-weight: 600;">$0.015/GB/<?php esc_html_e( 'mo', 'r2-cloud-storage' ); ?></td>
					<td style="text-align: center; color: #666;">$0.023/GB/<?php esc_html_e( 'mo', 'r2-cloud-storage' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Class A Ops (write)', 'r2-cloud-storage' ); ?></strong></td>
					<td style="text-align: center; color: #16a34a; font-weight: 600;">$4.50/<?php esc_html_e( 'million', 'r2-cloud-storage' ); ?></td>
					<td style="text-align: center; color: #666;">$5.00/<?php esc_html_e( 'million', 'r2-cloud-storage' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Class B Ops (read)', 'r2-cloud-storage' ); ?></strong></td>
					<td style="text-align: center; color: #16a34a; font-weight: 600;">$0.36/<?php esc_html_e( 'million', 'r2-cloud-storage' ); ?></td>
					<td style="text-align: center; color: #666;">$0.40/<?php esc_html_e( 'million', 'r2-cloud-storage' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Built-in CDN', 'r2-cloud-storage' ); ?></strong></td>
					<td style="text-align: center; color: #16a34a; font-weight: 600;"><?php esc_html_e( 'Yes — Cloudflare global network', 'r2-cloud-storage' ); ?></td>
					<td style="text-align: center; color: #666;"><?php esc_html_e( 'No — requires CloudFront (extra cost)', 'r2-cloud-storage' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'S3-Compatible API', 'r2-cloud-storage' ); ?></strong></td>
					<td style="text-align: center; color: #16a34a; font-weight: 600;"><?php esc_html_e( 'Yes', 'r2-cloud-storage' ); ?></td>
					<td style="text-align: center; color: #666;"><?php esc_html_e( 'Yes (native)', 'r2-cloud-storage' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Free Tier', 'r2-cloud-storage' ); ?></strong></td>
					<td style="text-align: center; color: #16a34a; font-weight: 600;"><?php esc_html_e( '10 GB storage + 10M reads/mo', 'r2-cloud-storage' ); ?></td>
					<td style="text-align: center; color: #666;"><?php esc_html_e( '5 GB storage (12 months only)', 'r2-cloud-storage' ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description" style="margin-top: 12px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 4px; padding: 10px 14px;">
			💡 <?php esc_html_e( 'With 1 TB of storage and 10 TB of egress/month, you save over $900/month with R2.', 'r2-cloud-storage' ); ?>
		</p>
	</div>
</div>
