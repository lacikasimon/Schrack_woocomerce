<?php
/** HPOS/legacy-compatible, allowlisted WooCommerce order snapshots. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_EDoc_Orders {
	private static function decimal( mixed $value ): string { return is_numeric( $value ) ? (string) wc_format_decimal( $value, false, false ) : '0'; }
	private static function text( mixed $value ): string { return is_scalar( $value ) ? (string) $value : ''; }
	private static function date( $value ): ?string { return $value ? gmdate( 'Y-m-d\TH:i:s\Z', $value->getTimestamp() ) : null; }

	/** Persist source identity on the line so deleted products keep their identity. */
	public static function remember_line( $item ): void {
		if ( ! $item instanceof WC_Order_Item_Product || '' !== $item->get_meta( '_edoc_line_identity', true ) ) { return; }
		$product = $item->get_product();
		if ( ! $product ) { return; }
		$source = self::text( $product->get_meta( '_schrack_catalog_source' ) );
		$supplier = self::text( $product->get_meta( '_schrack_supplier' ) ) ?: ( array( 'schrack' => 'Schrack', 'telesystem' => 'Telesystem', 'edoc' => 'eDoc ERP' )[ $source ] ?? '' );
		$item->update_meta_data( '_edoc_line_identity', array( 'sku' => $product->get_sku(), 'catalog_source' => $source, 'supplier' => $supplier, 'erp_article_id' => (int) $product->get_meta( '_edoc_article_id' ) ?: null, 'erp_entity_id' => (int) $product->get_meta( '_edoc_entity_id' ) ?: null ) );
	}

	public static function snapshot( WC_Order $order ): array {
		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		$addresses = array();
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			$stored = $order->get_address( $kind );
			$addresses[ $kind ] = array();
			foreach ( $fields as $field ) { $addresses[ $kind ][ $field ] = self::text( $stored[ $field ] ?? '' ); }
			$addresses[ $kind ]['vat_number'] = 'billing' === $kind ? self::text( $order->get_meta( '_billing_vat_number' ) ?: $order->get_meta( 'billing_vat_number' ) ) : '';
		}
		$data = array( 'id' => $order->get_id(), 'number' => (string) $order->get_order_number(), 'status' => $order->get_status(), 'currency' => $order->get_currency(), 'date_created_gmt' => self::date( $order->get_date_created() ), 'date_modified_gmt' => self::date( $order->get_date_modified() ), 'billing' => $addresses['billing'], 'shipping' => $addresses['shipping'], 'payment_method' => $order->get_payment_method(), 'payment_method_title' => $order->get_payment_method_title(), 'customer_note' => $order->get_customer_note() );
		foreach ( array( 'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'cart_tax', 'total', 'total_tax' ) as $field ) { $data[ $field ] = self::decimal( $order->{ 'get_' . $field }() ); }
		$data['line_items'] = array();
		$items = $order->get_items( 'line_item' );
		if ( count( $items ) > 500 ) { throw new Schrack_EDoc_Exception( 'Comanda depășește limita de 500 de poziții.' ); }
		foreach ( $items as $item ) {
			self::remember_line( $item );
			$item->save_meta_data();
			$identity = $item->get_meta( '_edoc_line_identity', true );
			$identity = is_array( $identity ) ? $identity : array();
			$row = array( 'id' => $item->get_id(), 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'sku' => self::text( $identity['sku'] ?? '' ), 'name' => $item->get_name() );
			foreach ( array( 'quantity', 'subtotal', 'subtotal_tax', 'total', 'total_tax' ) as $field ) { $row[ $field ] = self::decimal( $item->{ 'get_' . $field }() ); }
			$row['catalog_source'] = self::text( $identity['catalog_source'] ?? '' );
			$row['supplier'] = self::text( $identity['supplier'] ?? '' );
			$row['erp_article_id'] = (int) ( $identity['erp_article_id'] ?? 0 ) ?: null;
			$row['erp_entity_id'] = (int) ( $identity['erp_entity_id'] ?? 0 ) ?: null;
			$data['line_items'][] = $row;
		}
		foreach ( array( 'shipping' => 'shipping_lines', 'fee' => 'fee_lines', 'coupon' => 'coupon_lines', 'tax' => 'tax_lines' ) as $type => $target ) {
			$data[ $target ] = array();
			foreach ( $order->get_items( $type ) as $item ) {
				$row = array( 'id' => $item->get_id() );
				if ( 'shipping' === $type ) { $row += array( 'method_title' => $item->get_method_title(), 'method_id' => $item->get_method_id(), 'total' => self::decimal( $item->get_total() ), 'total_tax' => self::decimal( $item->get_total_tax() ) ); }
				if ( 'fee' === $type ) { $row += array( 'name' => $item->get_name(), 'total' => self::decimal( $item->get_total() ), 'total_tax' => self::decimal( $item->get_total_tax() ) ); }
				if ( 'coupon' === $type ) { $row += array( 'code' => $item->get_code(), 'discount' => self::decimal( $item->get_discount() ), 'discount_tax' => self::decimal( $item->get_discount_tax() ) ); }
				if ( 'tax' === $type ) { $row += array( 'rate_code' => $item->get_rate_code(), 'label' => $item->get_label(), 'compound' => (bool) $item->get_compound(), 'tax_total' => self::decimal( $item->get_tax_total() ), 'shipping_tax_total' => self::decimal( $item->get_shipping_tax_total() ), 'rate_percent' => self::decimal( method_exists( $item, 'get_rate_percent' ) ? $item->get_rate_percent() : 0 ) ); }
				$data[ $target ][] = $row;
			}
		}
		$data['refunds'] = array();
		foreach ( $order->get_refunds() as $refund ) { $data['refunds'][] = array( 'id' => $refund->get_id(), 'reason' => $refund->get_reason(), 'total' => self::decimal( $refund->get_total() ) ); }
		return $data;
	}
}
