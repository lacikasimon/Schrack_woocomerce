<?php
/** Validation and WooCommerce CRUD effects for eDoc protocol v1.1 commands. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_EDoc_Commands {
	private const STATUSES = array( 'pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed' );
	private const ADDRESS_FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );

	private static function invalid(): WP_Error { return new WP_Error( 'invalid_command', 'Datele comenzii eDoc nu sunt valide.', array( 'status' => 400 ) ); }
	private static function uuid( mixed $id ): bool { return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $id ); }
	private static function text( mixed $value, int $limit ): bool { return is_string( $value ) && false !== ( $length = preg_match_all( '/./us', $value ) ) && $length <= $limit && ! str_contains( $value, "\0" ); }
	private static function country( string $value ): bool { return '' === $value || ( 1 === preg_match( '/^[A-Z]{2}$/D', $value ) && array_key_exists( $value, ( new WC_Countries() )->get_countries() ) ); }
	private static function decimal( mixed $value, ?int $precision = null ): bool {
		return is_string( $value ) && strlen( $value ) <= 64 && 1 === preg_match( null === $precision ? '/^\d+(?:\.\d+)?$/D' : '/^\d+(?:\.\d{1,' . $precision . '})?$/D', $value ) && is_finite( (float) $value );
	}

	/** Only object key order is normalized; every supplied field contributes to the hash. */
	private static function normalized( array $body ): array {
		foreach ( $body as &$value ) { if ( is_array( $value ) ) { $value = self::normalized( $value ); } }
		unset( $value );
		if ( ! array_is_list( $body ) ) { ksort( $body, SORT_STRING ); }
		return $body;
	}

	public static function edit_body( mixed $body ) {
		if ( ! is_array( $body ) || array_diff( array_keys( $body ), array( 'command_id', 'expected_version', 'billing', 'shipping', 'line_items' ) ) || ! self::uuid( $body['command_id'] ?? null ) || ! is_int( $body['expected_version'] ?? null ) || $body['expected_version'] < 1 || ! array_intersect( array_keys( $body ), array( 'billing', 'shipping', 'line_items' ) ) ) { return self::invalid(); }
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			if ( ! array_key_exists( $kind, $body ) ) { continue; }
			$fields = 'billing' === $kind ? array_merge( self::ADDRESS_FIELDS, array( 'vat_number' ) ) : self::ADDRESS_FIELDS;
			if ( ! is_array( $body[ $kind ] ) || array_diff( array_keys( $body[ $kind ] ), $fields ) ) { return self::invalid(); }
			foreach ( $body[ $kind ] as $field => $value ) {
				if ( ! self::text( $value, 255 ) || ( 'email' === $field && '' !== $value && ! is_email( $value ) ) || ( 'country' === $field && ! self::country( $value ) ) ) { return self::invalid(); }
			}
		}
		if ( array_key_exists( 'line_items', $body ) ) {
			if ( ! is_array( $body['line_items'] ) || ! array_is_list( $body['line_items'] ) || count( $body['line_items'] ) > 500 ) { return self::invalid(); }
			$ids = array();
			foreach ( $body['line_items'] as $line ) {
				if ( ! is_array( $line ) || count( $line ) !== 3 || array_diff( array_keys( $line ), array( 'id', 'quantity', 'unit_price' ) ) || ! is_int( $line['id'] ?? null ) || $line['id'] < 1 || isset( $ids[ $line['id'] ] ) || ! is_int( $line['quantity'] ?? null ) || $line['quantity'] < 1 || ! self::decimal( $line['unit_price'] ?? null, 4 ) || (float) $line['unit_price'] <= 0 || ! is_finite( $line['quantity'] * (float) $line['unit_price'] ) ) { return self::invalid(); }
				$ids[ $line['id'] ] = true;
			}
		}
		return self::normalized( $body );
	}

	public static function invoice_body( mixed $body ) {
		$keys = array( 'erp_invoice_id', 'number', 'issue_date', 'currency', 'total', 'state' );
		if ( ! is_array( $body ) || count( $body ) !== 2 || array_diff( array_keys( $body ), array( 'command_id', 'invoice' ) ) || ! self::uuid( $body['command_id'] ?? null ) || ! is_array( $body['invoice'] ?? null ) || count( $body['invoice'] ) !== count( $keys ) || array_diff( array_keys( $body['invoice'] ), $keys ) ) { return self::invalid(); }
		$i = $body['invoice'];
		if ( ! is_int( $i['erp_invoice_id'] ) || $i['erp_invoice_id'] < 1 || ! self::text( $i['number'], 64 ) || ! is_string( $i['issue_date'] ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $i['issue_date'], $date ) || ! checkdate( (int) $date[2], (int) $date[3], (int) $date[1] ) || ! is_string( $i['currency'] ) || ! preg_match( '/^[A-Z]{3}$/D', $i['currency'] ) || ! self::decimal( $i['total'] ) || ! in_array( $i['state'], array( 'issued', 'cancelled' ), true ) ) { return self::invalid(); }
		return self::normalized( $body );
	}

	public static function editable( array $current, array $body ): ?WP_REST_Response {
		if ( ! in_array( $current['order']['status'], self::STATUSES, true ) ) { return new WP_REST_Response( array_merge( array( 'error' => 'Acest status se gestionează numai în WooCommerce.', 'code' => 'status_read_only' ), $current ), 409 ); }
		if ( $current['version'] !== $body['expected_version'] ) { return new WP_REST_Response( array_merge( array( 'error' => 'Comanda s-a modificat în magazin.', 'code' => 'version_conflict' ), $current ), 409 ); }
		return null;
	}

	public static function validate_lines( array $snapshot, array $body ): ?WP_Error {
		$ids = array_column( $snapshot['line_items'], 'id' );
		foreach ( $body['line_items'] ?? array() as $line ) { if ( ! in_array( $line['id'], $ids, true ) ) { return self::invalid(); } }
		return null;
	}

	public static function edit_matches( array $snapshot, array $body ): bool {
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			foreach ( $body[ $kind ] ?? array() as $field => $value ) { if ( ( $snapshot[ $kind ][ $field ] ?? '' ) !== $value ) { return false; } }
		}
		$lines = array_column( $snapshot['line_items'], null, 'id' );
		foreach ( $body['line_items'] ?? array() as $line ) {
			$stored = $lines[ $line['id'] ] ?? null;
			if ( ! $stored || (float) $stored['quantity'] !== (float) $line['quantity'] || wc_format_decimal( $stored['subtotal'], wc_get_price_decimals() ) !== self::line_total( $line ) ) { return false; }
		}
		return true;
	}

	private static function line_total( array $line ): string { return wc_format_decimal( $line['quantity'] * (float) $line['unit_price'], wc_get_price_decimals() ); }

	/** Woo otherwise fills a cleared billing email from the customer on every save. */
	private static function order( int $id ): WC_Order {
		return new class( $id ) extends WC_Order {
			protected function maybe_set_user_billing_email() {}
		};
	}

	public static function apply_edit( int $id, array $body ): void {
		$order = self::order( $id );
		$changed = array();
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			foreach ( $body[ $kind ] ?? array() as $field => $value ) {
				if ( 'vat_number' === $field ) {
					$order->update_meta_data( '_billing_vat_number', $value );
					// Clearing the canonical value must not resurrect an older fallback VAT number.
					$order->delete_meta_data( 'billing_vat_number' );
				} elseif ( 'shipping' === $kind && 'email' === $field && ! method_exists( $order, 'set_shipping_email' ) ) {
					// Woo 8.2 has no native shipping email property; keep it in CRUD metadata.
					$order->update_meta_data( '_shipping_email', $value );
				} else { $order->{ 'set_' . $kind . '_' . $field }( $value ); }
				$changed[] = $kind . '.' . $field;
			}
		}
		if ( ! empty( $body['line_items'] ) && ! function_exists( 'wc_maybe_adjust_line_item_product_stock' ) ) { require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php'; }
		$items = $order->get_items( 'line_item' );
		foreach ( $body['line_items'] ?? array() as $line ) {
			$item = $items[ $line['id'] ];
			$item->set_quantity( $line['quantity'] );
			$item->set_subtotal( self::line_total( $line ) );
			$item->set_total( self::line_total( $line ) );
			$stock = wc_maybe_adjust_line_item_product_stock( $item );
			if ( is_wp_error( $stock ) ) { throw new Schrack_EDoc_Exception( 'Stocul nu a putut fi ajustat. Verificați comanda în WooCommerce.' ); }
			$changed[] = 'line_items.quantity';
			$changed[] = 'line_items.unit_price';
		}
		$order->recalculate_coupons();
		$order->calculate_totals( true );
		$order->save();
		$order->add_order_note( 'Comandă modificată din eDoc ERP: ' . implode( ', ', array_unique( $changed ) ), false );
	}

	public static function invoice_matches( int $id, array $invoice ): bool {
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order ) { return false; }
		$order->read_meta_data( true );
		$stored = $order->get_meta( '_edoc_invoice', true );
		if ( ! is_array( $stored ) ) { return false; }
		foreach ( $invoice as $key => $value ) { if ( ! array_key_exists( $key, $stored ) || $stored[ $key ] !== $value ) { return false; } }
		return true;
	}

	public static function apply_invoice( int $id, array $invoice ): void {
		$order = self::order( $id );
		$order->update_meta_data( '_edoc_invoice', array_merge( $invoice, array( 'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) ) );
		$modified = $order->get_date_modified( 'edit' );
		if ( $modified ) {
			// HPOS automatically changes this timestamp on save unless it is explicitly dirty.
			// Clone through the public setter so the unchanged timestamp is persisted on both stores.
			$order->set_date_modified( clone $modified );
			$order->save();
		} else {
			// Old orders without a modified date must also keep the exact original snapshot.
			// A pending CRUD property prevents HPOS save_meta_data() from auto-saving the order.
			// This temporary object is discarded: only its metadata is persisted.
			$order->set_date_modified( time() );
			$order->save_meta_data();
		}
		$order->add_order_note( 'Factură eDoc ' . sanitize_text_field( $invoice['number'] ) . ( 'issued' === $invoice['state'] ? ' emisă' : ' anulată' ), false );
	}
}
