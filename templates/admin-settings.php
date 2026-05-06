<?php
/**
 * Settings template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Schrack WooCommerce Sync', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'settings' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="schrack_wc_sync_save_settings">
		<?php wp_nonce_field( 'schrack_wc_sync_settings' ); ?>

		<h2><?php esc_html_e( 'Connection', 'schrack-woocommerce-sync' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="schrack_environment"><?php esc_html_e( 'Environment', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_environment" name="schrack_settings[environment]">
						<option value="test" <?php selected( $settings['environment'], 'test' ); ?>><?php esc_html_e( 'TEST', 'schrack-woocommerce-sync' ); ?></option>
						<option value="live" <?php selected( $settings['environment'], 'live' ); ?>><?php esc_html_e( 'LIVE', 'schrack-woocommerce-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_endpoint"><?php esc_html_e( 'SOAP endpoint URL', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input class="regular-text code" id="schrack_endpoint" type="url" name="schrack_settings[soap_endpoint_url]" value="<?php echo esc_attr( $settings['soap_endpoint_url'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_wsdl"><?php esc_html_e( 'WSDL URL', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input class="regular-text code" id="schrack_wsdl" type="url" name="schrack_settings[wsdl_url]" value="<?php echo esc_attr( $settings['wsdl_url'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_datanorm"><?php esc_html_e( 'Datanorm URL', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input class="regular-text code" id="schrack_datanorm" type="url" name="schrack_settings[datanorm_url]" value="<?php echo esc_attr( $settings['datanorm_url'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_customer_number"><?php esc_html_e( 'Customer number', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input class="regular-text" id="schrack_customer_number" type="text" name="schrack_settings[customer_number]" value="<?php echo esc_attr( $settings['customer_number'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_webshop_username"><?php esc_html_e( 'Webshop username', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input class="regular-text" id="schrack_webshop_username" type="text" name="schrack_settings[webshop_username]" value="<?php echo esc_attr( $settings['webshop_username'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_webshop_password"><?php esc_html_e( 'Webshop password', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input class="regular-text" id="schrack_webshop_password" type="password" name="schrack_settings[webshop_password]" value="" placeholder="<?php echo esc_attr( $this->configured_label( (string) $settings['webshop_password'] ) ); ?>" autocomplete="new-password"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_provider_code"><?php esc_html_e( 'Provider code', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input class="regular-text" id="schrack_provider_code" type="password" name="schrack_settings[provider_code]" value="" placeholder="<?php echo esc_attr( $this->configured_label( (string) $settings['provider_code'] ) ); ?>" autocomplete="new-password"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Sync Behavior', 'schrack-woocommerce-sync' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="schrack_default_markup"><?php esc_html_e( 'Default markup %', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input id="schrack_default_markup" type="number" min="0" max="500" step="0.01" name="schrack_settings[default_markup]" value="<?php echo esc_attr( $settings['default_markup'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_catalog_batch_size"><?php esc_html_e( 'Catalog batch size', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_catalog_batch_size" type="number" min="1" max="1000" step="1" name="schrack_settings[catalog_batch_size]" value="<?php echo esc_attr( min( 1000, max( 1, absint( $settings['catalog_batch_size'] ) ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'For 2 GB cPanel hosting, 500 is the recommended value. Runtime protection caps this automatically on low-memory hosts.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_catalog_batches_per_run"><?php esc_html_e( 'Catalog batches per run', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_catalog_batches_per_run" type="number" min="1" max="5" step="1" name="schrack_settings[catalog_batches_per_run]" value="<?php echo esc_attr( min( 5, max( 1, absint( $settings['catalog_batches_per_run'] ) ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'For 2 GB cPanel hosting, 3 is now the recommended value. Runtime protection stops before memory or execution time gets risky.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_batch_size"><?php esc_html_e( 'Price/stock batch size', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_batch_size" type="number" min="1" max="500" step="1" name="schrack_settings[sync_batch_size]" value="<?php echo esc_attr( $settings['sync_batch_size'] ); ?>">
					<p class="description"><?php esc_html_e( 'On 2 GB hosting, price and stock batches use 100 products at runtime.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_sync_batches_per_run"><?php esc_html_e( 'Price/stock batches per run', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_sync_batches_per_run" type="number" min="1" max="5" step="1" name="schrack_settings[sync_batches_per_run]" value="<?php echo esc_attr( min( 5, max( 1, absint( $settings['sync_batches_per_run'] ) ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'Use 1 on 2 GB hosting. Follow-up batches are queued instead of being chained in one PHP request.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_price_request_size"><?php esc_html_e( 'Price items per SOAP request', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_price_request_size" type="number" min="1" max="100" step="1" name="schrack_settings[price_request_size]" value="<?php echo esc_attr( $settings['price_request_size'] ); ?>">
					<p class="description"><?php esc_html_e( '100 is recommended because it reduces Schrack SOAP message count.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_stock_request_size"><?php esc_html_e( 'Stock items per SOAP request', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_stock_request_size" type="number" min="1" max="100" step="1" name="schrack_settings[stock_request_size]" value="<?php echo esc_attr( $settings['stock_request_size'] ); ?>">
					<p class="description"><?php esc_html_e( '100 is recommended because it reduces Schrack SOAP message count.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_rate_limit_sleep"><?php esc_html_e( 'Batch sleep seconds', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input id="schrack_rate_limit_sleep" type="number" min="0" max="30" step="1" name="schrack_settings[rate_limit_sleep]" value="<?php echo esc_attr( $settings['rate_limit_sleep'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_soap_rate_limit_cooldown"><?php esc_html_e( 'SOAP rate-limit cooldown seconds', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input id="schrack_soap_rate_limit_cooldown" type="number" min="300" max="3600" step="1" name="schrack_settings[soap_rate_limit_cooldown]" value="<?php echo esc_attr( $settings['soap_rate_limit_cooldown'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_soap_retries"><?php esc_html_e( 'SOAP retries', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input id="schrack_soap_retries" type="number" min="0" max="5" step="1" name="schrack_settings[soap_retries]" value="<?php echo esc_attr( $settings['soap_retries'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_import_mode"><?php esc_html_e( 'Import mode', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_import_mode" name="schrack_settings[import_mode]">
						<option value="catalog_only" <?php selected( $settings['import_mode'], 'catalog_only' ); ?>><?php esc_html_e( 'Catalog only', 'schrack-woocommerce-sync' ); ?></option>
						<option value="catalog_price" <?php selected( $settings['import_mode'], 'catalog_price' ); ?>><?php esc_html_e( 'Catalog + price', 'schrack-woocommerce-sync' ); ?></option>
						<option value="catalog_price_stock" <?php selected( $settings['import_mode'], 'catalog_price_stock' ); ?>><?php esc_html_e( 'Catalog + price + stock', 'schrack-woocommerce-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_publish_status"><?php esc_html_e( 'Product publish status', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_publish_status" name="schrack_settings[publish_status]">
						<option value="draft" <?php selected( $settings['publish_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'schrack-woocommerce-sync' ); ?></option>
						<option value="publish" <?php selected( $settings['publish_status'], 'publish' ); ?>><?php esc_html_e( 'Publish', 'schrack-woocommerce-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Product images', 'schrack-woocommerce-sync' ); ?></th>
				<td>
					<label><input type="checkbox" name="schrack_settings[image_import_enabled]" value="yes" <?php checked( $settings['image_import_enabled'], 'yes' ); ?>> <?php esc_html_e( 'Download catalog images into the WordPress media library', 'schrack-woocommerce-sync' ); ?></label>
					<p class="description"><?php esc_html_e( 'When disabled, catalog sync still stores the external Schrack image URL and products without a downloaded image keep using that URL on the storefront.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_image_batch_size"><?php esc_html_e( 'Image batch size', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_image_batch_size" type="number" min="1" max="250" step="1" name="schrack_settings[image_batch_size]" value="<?php echo esc_attr( min( 250, max( 1, absint( $settings['image_batch_size'] ?? 50 ) ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'Products claimed per image worker. Workers stop safely before PHP timeout and continue in the next wave.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_image_parallel_workers"><?php esc_html_e( 'Parallel image workers', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_image_parallel_workers" type="number" min="1" max="8" step="1" name="schrack_settings[image_parallel_workers]" value="<?php echo esc_attr( min( 8, max( 1, absint( $settings['image_parallel_workers'] ) ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'For shared hosting, start with 2 to 4. Runtime protection caps this to 4 on low-memory hosts.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_image_followup_delay"><?php esc_html_e( 'Image follow-up delay seconds', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_image_followup_delay" type="number" min="5" max="300" step="1" name="schrack_settings[image_parallel_followup_delay]" value="<?php echo esc_attr( min( 300, max( 5, absint( $settings['image_parallel_followup_delay'] ?? 10 ) ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'How soon the dispatcher checks for the next image worker wave.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_image_download_timeout"><?php esc_html_e( 'Image download timeout seconds', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_image_download_timeout" type="number" min="5" max="60" step="1" name="schrack_settings[image_download_timeout]" value="<?php echo esc_attr( min( 60, max( 5, absint( $settings['image_download_timeout'] ?? 15 ) ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'Lower values prevent broken remote image URLs from blocking the queue for too long.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_image_retry_cooldown"><?php esc_html_e( 'Image retry cooldown seconds', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_image_retry_cooldown" type="number" min="60" max="86400" step="60" name="schrack_settings[image_retry_cooldown]" value="<?php echo esc_attr( min( 86400, max( 60, absint( $settings['image_retry_cooldown'] ?? HOUR_IN_SECONDS ) ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'Failed image URLs are skipped until this cooldown expires.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Stock handling', 'schrack-woocommerce-sync' ); ?></th>
				<td><label><input type="checkbox" name="schrack_settings[stock_handling_enabled]" value="yes" <?php checked( $settings['stock_handling_enabled'], 'yes' ); ?>> <?php esc_html_e( 'Enabled', 'schrack-woocommerce-sync' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Delete missing products', 'schrack-woocommerce-sync' ); ?></th>
				<td><label><input type="checkbox" name="schrack_settings[delete_missing_products]" value="yes" <?php checked( $settings['delete_missing_products'], 'yes' ); ?>> <?php esc_html_e( 'Enabled', 'schrack-woocommerce-sync' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_stock_source"><?php esc_html_e( 'Stock source', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_stock_source" name="schrack_settings[stock_source]">
						<option value="central" <?php selected( $settings['stock_source'], 'central' ); ?>><?php esc_html_e( 'Central warehouse only', 'schrack-woocommerce-sync' ); ?></option>
						<option value="store" <?php selected( $settings['stock_source'], 'store' ); ?>><?php esc_html_e( 'Own store only', 'schrack-woocommerce-sync' ); ?></option>
						<option value="all" <?php selected( $settings['stock_source'], 'all' ); ?>><?php esc_html_e( 'All available warehouses', 'schrack-woocommerce-sync' ); ?></option>
					</select>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Frontend modules', 'schrack-woocommerce-sync' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Floating chat module', 'schrack-woocommerce-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="schrack_settings[support_widget_enabled]" value="yes" <?php checked( $settings['support_widget_enabled'], 'yes' ); ?>>
						<?php esc_html_e( 'Enable the Syshub support widget on all frontend pages', 'schrack-woocommerce-sync' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When enabled, the WhatsApp / phone / request panel appears as a floating module without adding an Elementor widget.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Schedules and Logging', 'schrack-woocommerce-sync' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="schrack_price_frequency"><?php esc_html_e( 'Price sync frequency', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_price_frequency" name="schrack_settings[price_sync_frequency]">
						<option value="daily" <?php selected( $settings['price_sync_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'schrack-woocommerce-sync' ); ?></option>
						<option value="six_hours" <?php selected( $settings['price_sync_frequency'], 'six_hours' ); ?>><?php esc_html_e( 'Every 6 hours', 'schrack-woocommerce-sync' ); ?></option>
						<option value="hourly" <?php selected( $settings['price_sync_frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'schrack-woocommerce-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_stock_frequency"><?php esc_html_e( 'Stock sync frequency', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_stock_frequency" name="schrack_settings[stock_sync_frequency]">
						<option value="hourly" <?php selected( $settings['stock_sync_frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'schrack-woocommerce-sync' ); ?></option>
						<option value="thirty_minutes" <?php selected( $settings['stock_sync_frequency'], 'thirty_minutes' ); ?>><?php esc_html_e( 'Every 30 minutes', 'schrack-woocommerce-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_catalog_frequency"><?php esc_html_e( 'Catalog import frequency', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_catalog_frequency" name="schrack_settings[catalog_sync_frequency]">
						<option value="daily" <?php selected( $settings['catalog_sync_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'schrack-woocommerce-sync' ); ?></option>
						<option value="weekly" <?php selected( $settings['catalog_sync_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'schrack-woocommerce-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="schrack_log_level"><?php esc_html_e( 'Log level', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_log_level" name="schrack_settings[log_level]">
						<option value="debug" <?php selected( $settings['log_level'], 'debug' ); ?>><?php esc_html_e( 'Debug', 'schrack-woocommerce-sync' ); ?></option>
						<option value="info" <?php selected( $settings['log_level'], 'info' ); ?>><?php esc_html_e( 'Info', 'schrack-woocommerce-sync' ); ?></option>
						<option value="warning" <?php selected( $settings['log_level'], 'warning' ); ?>><?php esc_html_e( 'Warning', 'schrack-woocommerce-sync' ); ?></option>
						<option value="error" <?php selected( $settings['log_level'], 'error' ); ?>><?php esc_html_e( 'Error', 'schrack-woocommerce-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Debug mode', 'schrack-woocommerce-sync' ); ?></th>
				<td><label><input type="checkbox" name="schrack_settings[debug_enabled]" value="yes" <?php checked( $settings['debug_enabled'], 'yes' ); ?>> <?php esc_html_e( 'Enabled', 'schrack-woocommerce-sync' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Missing price handling', 'schrack-woocommerce-sync' ); ?></th>
				<td><label><input type="checkbox" name="schrack_settings[skip_price_when_missing]" value="yes" <?php checked( $settings['skip_price_when_missing'], 'yes' ); ?>> <?php esc_html_e( 'Do not update product price when no price is returned', 'schrack-woocommerce-sync' ); ?></label></td>
			</tr>
		</table>

		<?php submit_button( __( 'Save settings', 'schrack-woocommerce-sync' ) ); ?>
	</form>

	<div class="schrack-panel">
		<h2><?php esc_html_e( 'SOAP Debug', 'schrack-woocommerce-sync' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
			<input type="hidden" name="action" value="schrack_wc_sync_soap_debug">
			<?php wp_nonce_field( 'schrack_wc_sync_soap_debug' ); ?>
			<button type="submit" class="button button-secondary" name="debug_task" value="test_connection"><?php esc_html_e( 'Test WSDL connection', 'schrack-woocommerce-sync' ); ?></button>
			<button type="submit" class="button button-secondary" name="debug_task" value="list_wsdl"><?php esc_html_e( 'List WSDL functions/types', 'schrack-woocommerce-sync' ); ?></button>
		</form>
	</div>
</div>
