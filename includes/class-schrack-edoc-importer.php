<?php
/** Selected ERP articles are a third catalog source; editorial content stays in WooCommerce. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_EDoc_Importer {
	private Schrack_Category_Markup $markup;
	private Schrack_Settings $settings;
	public function __construct( Schrack_Settings $settings ) { $this->settings = $settings; $this->markup = new Schrack_Category_Markup( $settings ); }

	/** One page is acknowledged only after every row has been handled successfully. */
	public function run_batch(): array {
		if ( ! Schrack_EDoc_Client::enabled() ) { return array( 'disabled' => true ); }
		$c = Schrack_EDoc_Client::config();
		if ( ! $c['entity_id'] ) {
			$health = ( new Schrack_EDoc_Client() )->request( 'GET', '/api/webshop/v1/health' );
			if ( 1 !== ( $health['protocol_version'] ?? null ) || 'RON' !== ( $health['currency'] ?? '' ) || (int) ( $health['entity_id'] ?? 0 ) < 1 ) { throw new Schrack_EDoc_Exception( 'Identitatea conexiunii ERP nu poate fi verificată.' ); }
			$current = Schrack_EDoc_Client::config( true );
			foreach ( array( 'url', 'key_id', 'secret' ) as $field ) { if ( $current[ $field ] !== $c[ $field ] ) { throw new Schrack_EDoc_Exception( 'Conexiunea a fost modificată. Importul va fi reluat.' ); } }
			$current['entity_id'] = (int) $health['entity_id'];
			update_option( Schrack_EDoc_Client::OPTION, $current, false );
			$c = $current;
		}
		if ( 'RON' !== get_woocommerce_currency() ) { throw new Schrack_EDoc_Exception( 'Integrarea eDoc necesită moneda RON.' ); }
		$after = (int) get_option( 'schrack_edoc_catalog_after', 0 );
		$page = ( new Schrack_EDoc_Client() )->request( 'GET', '/api/webshop/v1/catalog', array( 'after' => $after, 'limit' => 50 ) );
		if ( ! isset( $page['items'] ) || ! is_array( $page['items'] ) || count( $page['items'] ) > 50 || ! array_key_exists( 'next_after', $page ) ) { throw new Schrack_EDoc_Exception( 'Pagină de catalog eDoc invalidă.' ); }
		$result = array( 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0, 'blocked' => 0 );
		$last = $after;
		foreach ( $page['items'] as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) <= $last ) { throw new Schrack_EDoc_Exception( 'Ordinea catalogului eDoc este invalidă.' ); }
			$last = (int) $row['id'];
			$outcome = $this->import_item( $row, $c );
			++$result['processed'];
			if ( isset( $result[ $outcome ] ) ) { ++$result[ $outcome ]; }
		}
		$next = $page['next_after'];
		if ( null !== $next && ( ! is_int( $next ) || $next !== $last || $next <= $after ) ) { throw new Schrack_EDoc_Exception( 'Cursor de catalog eDoc invalid.' ); }
		update_option( 'schrack_edoc_catalog_after', null === $next ? 0 : $next, false );
		$result['has_more'] = null !== $next;
		$result['cursor'] = null === $next ? 0 : $next;
		$result['last_error'] = '';
		$this->settings->update_status( 'edoc_catalog', $result );
		return $result;
	}

	public function import_item( array $row, ?array $config = null ): string {
		$c = $config ?? Schrack_EDoc_Client::config();
		$entity = (int) ( $row['entity_id'] ?? 0 );
		$article = (int) ( $row['article_id'] ?? 0 );
		$sku = 'ERP-' . $entity . '-' . $article;
		if ( $entity < 1 || $article < 1 || $sku !== ( $row['sku'] ?? '' ) || 'RON' !== ( $row['currency'] ?? '' ) || ( (int) $c['entity_id'] > 0 && $entity !== (int) $c['entity_id'] ) ) { throw new Schrack_EDoc_Exception( 'Identitatea articolului eDoc este invalidă.' ); }
		$id = wc_get_product_id_by_sku( $sku );
		$product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
		if ( ! $product || ( $id && ( 'edoc' !== $product->get_meta( '_schrack_catalog_source' ) || $entity !== (int) $product->get_meta( '_edoc_entity_id' ) || $article !== (int) $product->get_meta( '_edoc_article_id' ) || ! $product->is_type( 'simple' ) ) ) ) {
			throw new Schrack_EDoc_Exception( 'Coliziune SKU eDoc: ' . $sku . '. Produsul existent nu a fost modificat.' );
		}
		$enabled = true === ( $row['enabled'] ?? false );
		if ( ! $id && ! $enabled ) { return 'updated'; }
		$rate = $row['vat_rate'] ?? null;
		$price = $row['purchase_price'] ?? null;
		$tax_class = is_numeric( $rate ) ? $this->tax_class( (float) $rate, (array) $c['tax_map'] ) : null;
		$valid = $enabled && is_numeric( $price ) && is_finite( (float) $price ) && (float) $price > 0 && null !== $tax_class;
		if ( ! $id ) { $product->set_sku( $sku ); $product->set_name( sanitize_text_field( (string) ( $row['name'] ?? $sku ) ) ); $product->set_status( 'draft' ); }
		$product->set_manage_stock( false ); $product->set_stock_quantity( '' ); $product->set_backorders( 'no' );
		$product->set_stock_status( $valid && true === ( $row['available'] ?? false ) ? 'instock' : 'outofstock' );
		if ( ! $valid ) { $product->set_status( 'draft' ); }
		if ( $valid ) {
			$product->set_tax_status( (float) $rate > 0 ? 'taxable' : 'none' );
			$product->set_tax_class( $tax_class );
			$rule = $this->markup->get_rule_for_product( (int) $id );
			$markup = '' !== $rule['markup'] ? (float) $rule['markup'] : (float) $this->settings->get( 'default_markup', 20 );
			$net = max( (float) $price * ( 1 + $markup / 100 ), (float) $price + (float) $rule['min_margin'] );
			$automatic = $this->markup->apply_rounding( $net * ( wc_prices_include_tax() ? ( 1 + (float) $rate / 100 ) : 1 ), (string) $rule['rounding'] );
			$resolved = Schrack_Manual_Price::resolve_product( $product, $automatic );
			$product->set_regular_price( Schrack_Manual_Price::format_price( $resolved['price'] ) );
			$product->set_price( $product->is_on_sale( 'edit' ) ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' ) );
		}
		$barcodes = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $row['barcodes'] ?? array() ) ) ) );
		$meta = array( '_schrack_catalog_source' => 'edoc', '_schrack_supplier' => 'eDoc ERP', '_edoc_entity_id' => $entity, '_edoc_article_id' => $article, '_edoc_item_number' => (string) ( $row['code'] ?? '' ), '_edoc_ean' => implode( ' ', $barcodes ), '_edoc_barcodes' => $barcodes, '_edoc_vat_rate' => $rate, '_edoc_enabled' => $enabled ? 'yes' : 'no', '_edoc_available' => true === ( $row['available'] ?? false ) ? 'yes' : 'no', '_edoc_validation_error' => $valid ? '' : ( ! $enabled ? 'Articol retras din ERP.' : ( null === $tax_class ? 'Lipsește maparea TVA.' : 'Prețul ERP trebuie să fie pozitiv.' ) ), '_schrack_unit' => (string) ( $row['unit'] ?? '' ), '_schrack_purchase_price' => is_numeric( $price ) ? (string) $price : '', '_schrack_purchase_price_raw' => is_numeric( $price ) ? (string) $price : '', '_edoc_last_price_sync' => current_time( 'mysql' ), '_edoc_last_stock_sync' => current_time( 'mysql' ), '_edoc_last_catalog_sync' => current_time( 'mysql' ) );
		foreach ( $meta as $key => $value ) { $product->update_meta_data( $key, $value ); }
		$product->save();
		return ! $valid && $enabled ? 'blocked' : ( $id ? 'updated' : 'created' );
	}

	public function tax_class( float $rate, array $mapping ): ?string {
		if ( $rate < 0 || $rate > 100 ) { return null; }
		$key = rtrim( rtrim( number_format( $rate, 4, '.', '' ), '0' ), '.' );
		if ( ! array_key_exists( $key, $mapping ) ) { return null; }
		$class = (string) $mapping[ $key ];
		$classes = array_merge( array( '' ), WC_Tax::get_tax_class_slugs() );
		if ( ! in_array( $class, $classes, true ) ) { return null; }
		if ( 0.0 === $rate ) { return $class; }
		if ( ! wc_tax_enabled() ) { return null; }
		$base_rates = WC_Tax::get_base_tax_rates( $class );
		if ( count( $base_rates ) !== 1 ) { return null; }
		$base = reset( $base_rates );
		return abs( (float) $base['rate'] - $rate ) < 0.0001 && ! in_array( $base['compound'] ?? false, array( true, 'yes', 1, '1' ), true ) ? $class : null;
	}

	/** A manual publish action must not bypass a missing price/tax mapping or withdrawal. */
	public static function guard_publication( $product ): void {
		if ( ! $product instanceof WC_Product || 'edoc' !== $product->get_meta( '_schrack_catalog_source' ) ) { return; }
		$c = Schrack_EDoc_Client::config();
		$importer = new self( new Schrack_Settings() );
		$price = $product->get_meta( '_schrack_purchase_price' );
		$rate = $product->get_meta( '_edoc_vat_rate' );
		$tax_class = is_numeric( $rate ) ? $importer->tax_class( (float) $rate, (array) $c['tax_map'] ) : null;
		$valid = 'yes' === $product->get_meta( '_edoc_enabled' ) && is_numeric( $price ) && (float) $price > 0 && null !== $tax_class;
		$product->set_manage_stock( false ); $product->set_stock_quantity( '' ); $product->set_backorders( 'no' );
		if ( ! $valid ) { $product->set_status( 'draft' ); $product->set_stock_status( 'outofstock' ); }
		else {
			$product->set_tax_status( (float) $rate > 0 ? 'taxable' : 'none' );
			$product->set_tax_class( $tax_class );
			$product->set_stock_status( 'yes' === $product->get_meta( '_edoc_available' ) ? 'instock' : 'outofstock' );
		}
	}
}
