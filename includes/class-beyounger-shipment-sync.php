<?php
/** Event-driven, one-way shipment synchronization. */
defined( 'ABSPATH' ) || exit;

class Beyounger_Shipment_Sync {

	const META = '_wc_shipment_tracking_items';
	const JOB = 'beyounger_sync_shipments';
	const GROUP = 'beyounger-shipments';
	private static $dirty = array();

	public static function init() {
		if ( ! function_exists( 'wc_get_order' ) ) return;
		foreach ( array( 'added_order_meta', 'updated_order_meta', 'added_post_meta', 'updated_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'meta_changed' ), 10, 4 );
		}
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		add_action( self::JOB, array( __CLASS__, 'run' ), 10, 2 );
	}

	public static function meta_changed( $meta_id, $order_id, $key, $value ) {
		try {
			if ( self::META === $key ) {
				// No delete hook: removed items are never deleted from the payment system.
				self::$dirty[ (int) $order_id ] = true;
			} elseif ( '_beyounger_transaction_no' === $key && get_option( 'by_shipment_pending_' . (int) $order_id ) ) {
				// Only resume orders whose logistics actually changed after installation.
				self::$dirty[ (int) $order_id ] = true;
			}
		} catch ( Throwable $e ) {
			self::log( (int) $order_id, 'Unable to observe shipment change.' );
		}
	}

	private static function eligible( $order ) {
		return $order && in_array( $order->get_payment_method(), array( 'beyounger', 'beyounger_paypal', 'beyounger_google_pay', 'beyounger_cash_app', 'beyounger_apple_pay', 'beyounger_card_to_crypto' ), true );
	}

	public static function flush() {
		$ids = array_keys( self::$dirty );
		self::$dirty = array();
		foreach ( $ids as $id ) {
			try {
				$order = wc_get_order( $id );
				if ( ! self::eligible( $order ) ) continue;
				update_option( 'by_shipment_pending_' . $id, '1', false );
				self::schedule( $id, 0, 10 );
			} catch ( Throwable $e ) {
				self::log( $id, 'Unable to queue shipment synchronization.' );
			}
		}
	}

	private static function schedule( $id, $attempt, $delay ) {
		try {
			$args = array( (int) $id, (int) $attempt );
			if ( function_exists( 'as_schedule_single_action' ) ) {
				// Keep follow-up events even when a worker for this order is already running.
				$scheduled = as_schedule_single_action( time() + $delay, self::JOB, $args, self::GROUP );
			} else {
				$scheduled = wp_schedule_single_event( time() + $delay, self::JOB, $args );
			}
			if ( ! $scheduled || is_wp_error( $scheduled ) ) self::log( $id, 'Unable to schedule shipment synchronization.' );
		} catch ( Throwable $e ) {
			self::log( $id, 'Unable to schedule shipment synchronization.' );
		}
	}

	private static function log( $id, $message ) {
		try {
			wc_get_logger()->warning( $message, array( 'source' => 'beyounger-shipment-sync', 'order_id' => $id ) );
		} catch ( Throwable $e ) {
			// Logging must never interrupt checkout, order saves, or other queue jobs.
		}
	}

	/** Atomic option lock; release only our token, including after expiration. */
	private static function lock( $id ) {
		global $wpdb;
		$key = 'by_shipment_lock_' . $id;
		$token = ( time() + 600 ) . ':' . wp_generate_uuid4();
		if ( add_option( $key, $token, '', false ) ) return $token;
		$old = get_option( $key );
		if ( (int) $old < time() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $key, $old ) );
			wp_cache_delete( $key, 'options' );
			if ( add_option( $key, $token, '', false ) ) return $token;
		}
		return false;
	}

	private static function unlock( $id, $token ) {
		global $wpdb;
		$key = 'by_shipment_lock_' . $id;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $key, $token ) );
		wp_cache_delete( $key, 'options' );
	}

	/** Resolve the official plugin formatter when available; never invent carrier URLs. */
	public static function items( $order ) {
		$order->read_meta_data( true );
		$raw = $order->get_meta( self::META, true );
		if ( empty( $raw ) ) return array();
		if ( ! is_array( $raw ) ) throw new RuntimeException( 'Invalid shipment tracking metadata.' );
		$formatted = array();
		if ( is_callable( array( 'WC_Shipment_Tracking_Actions', 'get_instance' ) ) ) {
			$actions = WC_Shipment_Tracking_Actions::get_instance();
			if ( is_callable( array( $actions, 'get_tracking_items' ) ) ) {
				foreach ( (array) $actions->get_tracking_items( $order->get_id(), true ) as $item ) {
					if ( is_array( $item ) && ! empty( $item['tracking_id'] ) ) $formatted[ $item['tracking_id'] ] = $item;
				}
			}
		}
		$result = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) || empty( $item['tracking_id'] ) || empty( $item['tracking_number'] ) ) {
				throw new RuntimeException( 'Shipment is missing tracking_id or tracking_number.' );
			}
			$id = (string) $item['tracking_id'];
			$display = $formatted[ $id ] ?? array();
			$custom = (string) ( $item['custom_tracking_provider'] ?? '' );
			$code = (string) ( $item['tracking_provider'] ?? '' );
			$name = (string) ( $display['formatted_tracking_provider'] ?? ( $custom ?: $code ) );
			if ( '' === $name ) throw new RuntimeException( 'Shipment carrier is missing.' );
			if ( '' === $code || 'custom' === $code ) $code = 'custom_' . substr( hash( 'sha256', $name ), 0, 24 );
			$link = (string) ( $display['formatted_tracking_link'] ?? $item['custom_tracking_link'] ?? '' );
			if ( strpos( $link, '%' ) !== false ) {
				$link = strtr( $link, array(
					'%1$s' => rawurlencode( (string) $item['tracking_number'] ),
					'%2$s' => rawurlencode( str_replace( ' ', '', $order->get_shipping_postcode() ) ),
					'%3$s' => rawurlencode( $order->get_shipping_country() ),
				) );
			}
			if ( $link !== '' && ! preg_match( '#^https?://#i', $link ) ) throw new RuntimeException( 'Invalid shipment tracking URL.' );
			if ( '' === $link && '' === $custom ) {
				throw new RuntimeException( 'Built-in carrier link unavailable; check Shipment Tracking formatter compatibility.' );
			}
			$date = $item['date_shipped'] ?? null;
			if ( is_numeric( $date ) && (int) $date > 0 ) $date = wp_date( 'Y-m-d', (int) $date, wp_timezone() );
			$result[ $id ] = array(
				'source_tracking_id' => $id, 'carrier_code' => $code, 'carrier_name' => $name,
				'tracking_number' => (string) $item['tracking_number'], 'tracking_url' => html_entity_decode( $link, ENT_QUOTES, 'UTF-8' ),
				'shipped_date' => $date ?: null,
			);
		}
		return $result;
	}

	/** A request always signs the exact JSON bytes sent over the wire. */
	public static function payload( $email, $transaction, $sandbox, $json, $api_key ) {
		$data = array( 'email' => $email, 'transaction_no' => $transaction, 'sandbox' => $sandbox,
			'request_time' => (string) time(), 'shipment' => $json );
		$data['sign'] = hash( 'sha256', $api_key . $data['request_time'] . $email . $sandbox . $transaction . hash( 'sha256', $json ) );
		return $data;
	}

	private static function send( $order, array $item, array $settings ) {
		$environment = $order->get_meta( '_beyounger_environment', true );
		$email = $order->get_meta( '_beyounger_trade_email', true ) ?: ( $settings['trade_email'] ?? '' );
		$transaction = (string) $order->get_meta( '_beyounger_transaction_no', true );
		// Historical orders without a saved environment default to live.
		$env = 'sandbox' === $environment ? 'sandbox' : 'live';
		$json = wp_json_encode( $item );
		if ( ! $json || ! $email ) throw new RuntimeException( 'Missing merchant email or invalid shipment JSON.' );
		$key = $settings[ $env . '_api_key' ] ?? '';
		if ( '' === $key ) throw new RuntimeException( 'No usable signing key for the order environment.' );
		$payload = self::payload( $email, $transaction, 'sandbox' === $env ? '1' : '0', $json, $key );
		$url = 'sandbox' === $env ? 'https://cashier-sandbox.beyounger.com/gateway/service/shipment' : 'https://cashier.beyounger.com/gateway/service/shipment';
		$response = wp_remote_post( $url, array( 'timeout' => 30, 'redirection' => 0,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ), 'body' => $payload ) );
		if ( is_wp_error( $response ) ) throw new RuntimeException( 'Shipment request failed: ' . $response->get_error_code() );
		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $status && isset( $body['code'], $body['data']['id'] ) && 200 === (int) $body['code'] ) {
			$order->update_meta_data( '_beyounger_environment', $env );
			$order->update_meta_data( '_beyounger_trade_email', $email );
			return;
		}
		throw new RuntimeException( 'Shipment rejected (HTTP ' . $status . ', code ' . (int) ( $body['code'] ?? 0 ) . ').' );
	}

	public static function run( $id, $attempt = 0 ) {
		$id = (int) $id;
		$token = false;
		try {
			$token = self::lock( $id );
			if ( ! $token ) { self::schedule( $id, $attempt, 60 ); return; }
			$order = wc_get_order( $id );
			if ( ! self::eligible( $order ) ) return;
			if ( ! $order->get_meta( '_beyounger_transaction_no', true ) ) {
				// A later transaction-meta event resumes the pending order; do not scan history.
				return;
			}
			delete_option( 'by_shipment_pending_' . $id );
			$items = self::items( $order );
			$settings = get_option( 'woocommerce_beyounger_settings', array() );
			$sent = (array) $order->get_meta( '_beyounger_shipment_sent', true );
			$processed = 0;
			foreach ( $items as $tracking_id => $item ) {
				$hash = hash( 'sha256', $order->get_meta( '_beyounger_transaction_no', true ) . wp_json_encode( $item ) );
				if ( isset( $sent[ $tracking_id ] ) && $sent[ $tracking_id ] === $hash ) continue;
				if ( $processed >= 5 ) { self::schedule( $id, 0, 10 ); return; }
				$item['source_version'] = sprintf( '%.0f', microtime( true ) * 1000000 );
				self::send( $order, $item, $settings );
				$sent[ $tracking_id ] = $hash;
				$order->update_meta_data( '_beyounger_shipment_sent', $sent );
				$order->save_meta_data();
				$processed++;
			}
		} catch ( Throwable $e ) {
			self::log( $id, $e->getMessage() );
			if ( $attempt < 8 ) self::schedule( $id, $attempt + 1, min( 21600, 60 * pow( 3, $attempt ) ) );
			else self::log( $id, 'Automatic retries exhausted. A new shipment change will trigger another attempt.' );
		} finally {
			if ( $token ) {
				try { self::unlock( $id, $token ); }
				catch ( Throwable $e ) { self::log( $id, 'Unable to release shipment lock; it will expire automatically.' ); }
			}
		}
	}
}
