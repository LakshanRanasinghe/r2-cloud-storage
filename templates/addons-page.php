<?php
/**
 * Add-ons page template.
 *
 * @package R2CloudStorage
 */

defined( 'ABSPATH' ) || exit;

$r2cs_addon_manager = r2cs()->addons();
$r2cs_catalog       = $r2cs_addon_manager->get_catalog_for_display();
?>
<div class="wrap r2cs-wrap">
	<h1>
		<span class="dashicons dashicons-admin-plugins r2cs-header-icon"></span>
		<?php esc_html_e( 'Add-ons R2 Cloud Storage', 'r2-cloud-storage' ); ?>
	</h1>

	<p class="r2cs-addons-intro">
		<?php esc_html_e( 'Extend R2 Cloud Storage with integrations for your favorite platforms. Each add-on is optimized for its specific platform.', 'r2-cloud-storage' ); ?>
	</p>

	<div class="r2cs-addons-grid">
		<?php foreach ( $r2cs_catalog as $r2cs_key => $r2cs_addon ) :
			$r2cs_license = $r2cs_addon_manager->get_license( $r2cs_key );
			$r2cs_has_license = ! empty( $r2cs_license ) && ! empty( $r2cs_license['activated'] );
		?>
			<div class="r2cs-addon-card r2cs-addon-status-<?php echo esc_attr( $r2cs_addon['status'] ); ?>" data-addon="<?php echo esc_attr( $r2cs_key ); ?>">
				<div class="r2cs-addon-header">
					<span class="dashicons <?php echo esc_attr( $r2cs_addon['icon'] ?? 'dashicons-admin-plugins' ); ?> r2cs-addon-icon"></span>
					<h3><?php echo esc_html( $r2cs_addon['name'] ); ?></h3>
					<?php if ( ! empty( $r2cs_addon['version'] ) ) : ?>
						<span class="r2cs-addon-version">v<?php echo esc_html( $r2cs_addon['version'] ); ?></span>
					<?php endif; ?>
				</div>

				<p class="r2cs-addon-description">
					<?php echo esc_html( $r2cs_addon['description'] ?? '' ); ?>
				</p>

				<?php if ( 'active' === $r2cs_addon['status'] || 'installed' === $r2cs_addon['status'] ) : ?>
					<div class="r2cs-license-section">
						<label class="r2cs-license-label">
						<?php esc_html_e( 'License Key', 'r2-cloud-storage' ); ?>
						<span class="r2cs-license-status <?php echo $r2cs_has_license ? 'active' : ''; ?>">
							<?php echo $r2cs_has_license ? esc_html__( 'Active', 'r2-cloud-storage' ) : esc_html__( 'Inactive', 'r2-cloud-storage' ); ?>
							</span>
						</label>
						<div class="r2cs-license-row">
							<input type="text" class="r2cs-license-input" placeholder="<?php esc_attr_e( 'XXXX-XXXX-XXXX-XXXX', 'r2-cloud-storage' ); ?>" value="<?php echo $r2cs_has_license ? esc_attr( str_repeat( '•', 16 ) ) : ''; ?>" />
							<?php if ( $r2cs_has_license ) : ?>
								<button type="button" class="button r2cs-deactivate-btn"><?php esc_html_e( 'Deactivate', 'r2-cloud-storage' ); ?></button>
							<?php else : ?>
								<button type="button" class="button button-primary r2cs-activate-btn"><?php esc_html_e( 'Activate License', 'r2-cloud-storage' ); ?></button>
							<?php endif; ?>
						</div>
						<span class="r2cs-license-message"></span>
					</div>
				<?php endif; ?>

				<div class="r2cs-addon-footer">
					<?php if ( 'active' === $r2cs_addon['status'] ) : ?>
						<span class="r2cs-badge r2cs-badge-active">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Active', 'r2-cloud-storage' ); ?>
						</span>
					<?php elseif ( 'installed' === $r2cs_addon['status'] ) : ?>
						<span class="r2cs-badge r2cs-badge-installed">
							<?php esc_html_e( 'Installed', 'r2-cloud-storage' ); ?>
						</span>
					<?php else : ?>
					<?php $r2cs_localized_price = $r2cs_addon_manager->get_localized_price( $r2cs_addon ); ?>
					<?php if ( ! empty( $r2cs_localized_price ) ) : ?>
						<div class="r2cs-addon-pricing">
							<span class="r2cs-addon-price"><?php echo esc_html( $r2cs_localized_price ); ?></span>
						</div>
					<?php endif; ?>
					<?php endif; ?>

					<?php if ( ! $r2cs_addon['parent_active'] && ! empty( $r2cs_addon['requires'] ) ) : ?>
						<span class="r2cs-badge r2cs-badge-warning">
							<?php
							printf(
								/* translators: %s: required plugin name */
								esc_html__( 'Requires %s', 'r2-cloud-storage' ),
								esc_html( $r2cs_addon['name'] )
							);
							?>
						</span>
					<?php endif; ?>

					<?php if ( 'available' === $r2cs_addon['status'] && ! empty( $r2cs_addon['url'] ) ) : ?>
						<a href="<?php echo esc_url( $r2cs_addon['url'] ); ?>" class="button button-primary r2cs-addon-buy" target="_blank" rel="noopener">
							<?php esc_html_e( 'Get Add-on', 'r2-cloud-storage' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- Developer CTA -->
	<div class="r2cs-developer-cta">
		<h2><?php esc_html_e( 'Build Your Own Add-on', 'r2-cloud-storage' ); ?></h2>
		<p>
			<?php esc_html_e( 'R2 Cloud Storage is extensible. Use our hooks and filters to create custom integrations.', 'r2-cloud-storage' ); ?>
		</p>
		<code>add_action( 'r2cs_register_addon', function( $manager ) {
    $manager->register( 'my-addon', '1.0.0', 'My_Addon_Class' );
});</code>
	</div>
</div>
