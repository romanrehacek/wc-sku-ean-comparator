<?php
/**
 * Template: Settings tab.
 *
 * Available variables (set by Admin_Page::render_settings()):
 *   $settings  array{delete_tables_on_uninstall: int, delete_files_on_uninstall: int}
 *   $saved     bool  True if settings were just saved successfully.
 *
 * @package WC_SKU_EAN_Comparator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php if ( $saved ) : ?>
	<div class="notice notice-success is-dismissible">
		<p><?php esc_html_e( 'Settings saved.', 'wc-sku-ean-comparator' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( WC_SKU_EAN_Comparator\Admin_Page::get_settings_url() ); ?>">
	<?php wp_nonce_field( 'wc_sec_save_settings', 'wc_sec_settings_nonce' ); ?>

	<h2><?php esc_html_e( 'Uninstall', 'wc-sku-ean-comparator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'These options take effect when the plugin is deleted via the WordPress Plugins screen. Deactivating the plugin does not remove any data.', 'wc-sku-ean-comparator' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Database tables', 'wc-sku-ean-comparator' ); ?>
			</th>
			<td>
				<label>
					<input
						type="checkbox"
						name="delete_tables_on_uninstall"
						value="1"
						<?php checked( 1, $settings['delete_tables_on_uninstall'] ); ?>
					/>
					<?php esc_html_e( 'Delete plugin database tables on uninstall', 'wc-sku-ean-comparator' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Removes the comparison history table. All saved comparison records will be permanently deleted.', 'wc-sku-ean-comparator' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Uploaded files', 'wc-sku-ean-comparator' ); ?>
			</th>
			<td>
				<label>
					<input
						type="checkbox"
						name="delete_files_on_uninstall"
						value="1"
						<?php checked( 1, $settings['delete_files_on_uninstall'] ); ?>
					/>
					<?php esc_html_e( 'Delete uploaded files and exports on uninstall', 'wc-sku-ean-comparator' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Removes the plugin upload directory including all price list files and generated CSV exports.', 'wc-sku-ean-comparator' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save Settings', 'wc-sku-ean-comparator' ) ); ?>
</form>
