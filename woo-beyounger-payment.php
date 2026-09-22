<?php
/**
 * Plugin Name: Woo Beyounger Payment
 * Plugin URI: https://beyounger.com/
 * Description: BeyoungerPay tokenized direct payment gateway for WooCommerce.
 * Version: 1.0.11
 * Author: Carter Chen
 * Text Domain: woo-beyounger-payment
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package WooBeyoungerPayment
 */

defined( 'ABSPATH' ) || exit;

define( 'WOO_BEYOUNGER_PAYMENT_VERSION', '1.0.11' );
define( 'WOO_BEYOUNGER_PAYMENT_FILE', __FILE__ );
define( 'WOO_BEYOUNGER_PAYMENT_PATH', plugin_dir_path( __FILE__ ) );

require_once WOO_BEYOUNGER_PAYMENT_PATH . 'includes/class-beyounger-shipment-sync.php';
add_action( 'plugins_loaded', array( 'Beyounger_Shipment_Sync', 'init' ), 20 );

/**
 * Enqueue compact frontend styles for hosted payment fields.
 */
function woo_beyounger_payment_enqueue_styles() {
	$style_path = WOO_BEYOUNGER_PAYMENT_PATH . 'assets/frontend.css';
	wp_enqueue_style(
		'woo-beyounger-payment-frontend',
		plugins_url( 'assets/frontend.css', WOO_BEYOUNGER_PAYMENT_FILE ),
		array(),
		file_exists( $style_path ) ? (string) filemtime( $style_path ) : WOO_BEYOUNGER_PAYMENT_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'woo_beyounger_payment_enqueue_styles' );

/**
 * Capture UTM parameters so checkout can still evaluate traffic after redirects.
 */
function woo_beyounger_payment_capture_utm() {
	$utm = woo_beyounger_payment_get_utm_from_request();

	if ( empty( $utm ) || headers_sent() ) {
		return;
	}

	$cookie_path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
	$cookie_domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
	$settings      = get_option( 'woocommerce_beyounger_settings', array() );
	$cookie_value  = rawurlencode( wp_json_encode( $utm ) );

	setrawcookie( 'woo_beyounger_utm_raw', $cookie_value, time() + MONTH_IN_SECONDS, $cookie_path, $cookie_domain, is_ssl(), true );
	$_COOKIE['woo_beyounger_utm_raw'] = $cookie_value;

	if ( ! woo_beyounger_payment_utm_is_whitelisted( $utm, isset( $settings['utm_whitelist'] ) ? $settings['utm_whitelist'] : '' ) ) {
		setrawcookie( 'woo_beyounger_utm', '', time() - HOUR_IN_SECONDS, $cookie_path, $cookie_domain, is_ssl(), true );
		unset( $_COOKIE['woo_beyounger_utm'] );
		return;
	}

	setrawcookie( 'woo_beyounger_utm', $cookie_value, time() + MONTH_IN_SECONDS, $cookie_path, $cookie_domain, is_ssl(), true );
	$_COOKIE['woo_beyounger_utm'] = $cookie_value;
}
add_action( 'init', 'woo_beyounger_payment_capture_utm' );

/**
 * Capture the latest external referrer so the order can keep the original traffic context.
 */
function woo_beyounger_payment_capture_referer() {
	if ( headers_sent() || empty( $_SERVER['HTTP_REFERER'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		return;
	}

	$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	if ( '' === $referer || ! woo_beyounger_payment_is_external_url( $referer ) ) {
		return;
	}

	$cookie_path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
	$cookie_domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

	setrawcookie( 'woo_beyounger_referer', rawurlencode( $referer ), time() + MONTH_IN_SECONDS, $cookie_path, $cookie_domain, is_ssl(), true );
	$_COOKIE['woo_beyounger_referer'] = rawurlencode( $referer );
}
add_action( 'init', 'woo_beyounger_payment_capture_referer', 9 );

/**
 * Whether a URL points to a different host than the current WordPress site.
 *
 * @param string $url URL.
 *
 * @return bool
 */
function woo_beyounger_payment_is_external_url( $url ) {
	$url_host  = wp_parse_url( $url, PHP_URL_HOST );
	$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

	if ( ! $url_host || ! $site_host ) {
		return false;
	}

	return strtolower( $url_host ) !== strtolower( $site_host );
}

/**
 * Get the current external referrer, preferring the live request and falling back to the captured cookie.
 *
 * @return string
 */
function woo_beyounger_payment_get_current_referer() {
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( '' !== $referer && woo_beyounger_payment_is_external_url( $referer ) ) {
			return $referer;
		}
	}

	if ( empty( $_COOKIE['woo_beyounger_referer'] ) ) {
		return '';
	}

	$referer = esc_url_raw( rawurldecode( sanitize_text_field( wp_unslash( $_COOKIE['woo_beyounger_referer'] ) ) ) );

	return '' !== $referer && woo_beyounger_payment_is_external_url( $referer ) ? $referer : '';
}

/**
 * Repair Beyounger browser callbacks when an upstream redirect drops Woo's order key.
 */
function woo_beyounger_payment_repair_order_pay_callback() {
	if ( is_admin() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}

	$merchant_order_id = isset( $_GET['order_id'] ) ? wc_clean( wp_unslash( $_GET['order_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $merchant_order_id || ! preg_match( '/^WC(\d+)(?:A\d+)?$/', $merchant_order_id, $matches ) ) {
		return;
	}

	$order_id = absint( $matches[1] );
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$endpoint_order_id = absint( get_query_var( 'order-pay' ) );
	if ( $endpoint_order_id && $endpoint_order_id !== $order_id ) {
		return;
	}

	$settings = get_option( 'woocommerce_beyounger_settings', array() );
	if ( ! empty( $settings['trade_email'] ) ) {
		$trade_email = isset( $_GET['trade_email'] ) ? sanitize_email( wp_unslash( $_GET['trade_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $trade_email && strtolower( $trade_email ) !== strtolower( (string) $settings['trade_email'] ) ) {
			return;
		}
	}

	if ( isset( $_GET['amount'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$callback_amount = wc_format_decimal( wc_clean( wp_unslash( $_GET['amount'] ) ), 2 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $callback_amount !== wc_format_decimal( $order->get_total(), 2 ) ) {
			return;
		}
	}

	if ( $order->is_paid() ) {
		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	if ( isset( $_GET['key'] ) && $order->get_order_key() === (string) wc_clean( wp_unslash( $_GET['key'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$payment_url = wc_get_endpoint_url( 'order-pay', $order->get_id(), wc_get_checkout_url() );
	$redirect    = add_query_arg(
		array(
			'pay_for_order' => 'true',
			'key'           => $order->get_order_key(),
		),
		$payment_url
	);

	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'template_redirect', 'woo_beyounger_payment_repair_order_pay_callback', 1 );

/**
 * Get UTM values from the current request.
 *
 * @return array
 */
function woo_beyounger_payment_get_utm_from_request() {
	$utm = array();

	foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
		if ( isset( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$utm[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	return $utm;
}

/**
 * Get current or previously captured UTM values.
 *
 * @return array
 */
function woo_beyounger_payment_get_current_utm() {
	$utm = woo_beyounger_payment_get_utm_from_request();

	if ( ! empty( $utm ) ) {
		return $utm;
	}

	if ( empty( $_COOKIE['woo_beyounger_utm'] ) ) {
		return array();
	}

	$decoded = json_decode( rawurldecode( sanitize_text_field( wp_unslash( $_COOKIE['woo_beyounger_utm'] ) ) ), true );

	return is_array( $decoded ) ? array_map( 'sanitize_text_field', $decoded ) : array();
}

/**
 * Get current or previously captured raw UTM values, including non-whitelisted sources.
 *
 * @return array
 */
function woo_beyounger_payment_get_current_raw_utm() {
	$utm = woo_beyounger_payment_get_utm_from_request();

	if ( ! empty( $utm ) ) {
		return $utm;
	}

	if ( empty( $_COOKIE['woo_beyounger_utm_raw'] ) ) {
		return array();
	}

	$decoded = json_decode( rawurldecode( sanitize_text_field( wp_unslash( $_COOKIE['woo_beyounger_utm_raw'] ) ) ), true );

	return is_array( $decoded ) ? array_map( 'sanitize_text_field', $decoded ) : array();
}

/**
 * Check whether a UTM set matches the configured whitelist.
 *
 * @param array  $utm Current UTM values.
 * @param string $raw_rules Whitelist text.
 *
 * @return bool
 */
function woo_beyounger_payment_utm_is_whitelisted( array $utm, $raw_rules ) {
	foreach ( woo_beyounger_payment_parse_utm_rules( $raw_rules ) as $rule ) {
		$matched = true;

		foreach ( $rule as $key => $expected ) {
			if ( ! isset( $utm[ $key ] ) || ! woo_beyounger_payment_utm_value_matches( $expected, $utm[ $key ] ) ) {
				$matched = false;
				break;
			}
		}

		if ( $matched ) {
			return true;
		}
	}

	return false;
}

/**
 * Parse UTM whitelist rules.
 *
 * @param string $raw_rules Whitelist text.
 *
 * @return array
 */
function woo_beyounger_payment_parse_utm_rules( $raw_rules ) {
	$rules = array();
	$lines = preg_split( '/\r\n|\r|\n/', (string) $raw_rules );

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}

		$conditions = array();
		$parts      = preg_split( '/[&,]/', $line );

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			if ( false === strpos( $part, '=' ) ) {
				$key   = 'utm_source';
				$value = $part;
			} else {
				list( $key, $value ) = array_map( 'trim', explode( '=', $part, 2 ) );
			}

			$key                 = strtolower( sanitize_key( $key ) );

			if ( 0 === strpos( $key, 'utm_' ) && '' !== $value ) {
				$conditions[ $key ] = sanitize_text_field( $value );
			}
		}

		if ( ! empty( $conditions ) ) {
			$rules[] = $conditions;
		}
	}

	return $rules;
}

/**
 * Match a UTM value with optional wildcard support.
 *
 * @param string $expected Expected value.
 * @param string $actual Actual value.
 *
 * @return bool
 */
function woo_beyounger_payment_utm_value_matches( $expected, $actual ) {
	$expected = strtolower( (string) $expected );
	$actual   = strtolower( (string) $actual );

	if ( false === strpos( $expected, '*' ) ) {
		return $expected === $actual;
	}

	$pattern = '/^' . str_replace( '\*', '.*', preg_quote( $expected, '/' ) ) . '$/';

	return 1 === preg_match( $pattern, $actual );
}

/**
 * Register signed BeyoungerPay config sync APIs.
 */
function woo_beyounger_payment_register_config_routes() {
	register_rest_route(
		'beyounger/v1',
		'/config',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'woo_beyounger_payment_rest_get_config',
				'permission_callback' => 'woo_beyounger_payment_rest_verify_signature',
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'woo_beyounger_payment_rest_update_config',
				'permission_callback' => 'woo_beyounger_payment_rest_verify_signature',
			),
		)
	);

}
add_action( 'rest_api_init', 'woo_beyounger_payment_register_config_routes' );

/**
 * Verify Beyounger config API request signature.
 *
 * Required headers:
 * X-Beyounger-Trade-Email, X-Beyounger-Request-Time, X-Beyounger-Nonce, X-Beyounger-Sign.
 *
 * Signature:
 * sha256(secret + METHOD + ROUTE + request_time + nonce + trade_email + sha256(raw_body))
 *
 * @param WP_REST_Request $request Request.
 *
 * @return true|WP_Error
 */
function woo_beyounger_payment_rest_verify_signature( WP_REST_Request $request ) {
	$settings     = get_option( 'woocommerce_beyounger_settings', array() );
	$trade_email  = sanitize_email( (string) $request->get_header( 'x-beyounger-trade-email' ) );
	$request_time = (string) $request->get_header( 'x-beyounger-request-time' );
	$nonce        = sanitize_text_field( (string) $request->get_header( 'x-beyounger-nonce' ) );
	$sign         = strtolower( sanitize_text_field( (string) $request->get_header( 'x-beyounger-sign' ) ) );

	if ( '' === $trade_email || '' === $request_time || '' === $nonce || '' === $sign ) {
		return new WP_Error( 'beyounger_config_auth_missing', 'Missing Beyounger config signature headers.', array( 'status' => 401 ) );
	}

	if ( ! ctype_digit( $request_time ) || abs( time() - (int) $request_time ) > 300 ) {
		return new WP_Error( 'beyounger_config_auth_expired', 'Beyounger config signature timestamp is expired.', array( 'status' => 401 ) );
	}

	if ( ! empty( $settings['trade_email'] ) && strtolower( (string) $settings['trade_email'] ) !== strtolower( $trade_email ) ) {
		return new WP_Error( 'beyounger_config_trade_email_mismatch', 'Beyounger merchant email does not match this site.', array( 'status' => 403 ) );
	}

	$secret = woo_beyounger_payment_get_config_sync_secret( $settings );
	if ( '' === $secret ) {
		return new WP_Error( 'beyounger_config_secret_missing', 'Beyounger config sync secret is not configured.', array( 'status' => 403 ) );
	}

	$nonce_key = 'woo_beyounger_config_nonce_' . md5( $trade_email . '|' . $nonce );
	if ( get_transient( $nonce_key ) ) {
		return new WP_Error( 'beyounger_config_nonce_replayed', 'Beyounger config nonce was already used.', array( 'status' => 401 ) );
	}

	$body_hash = hash( 'sha256', (string) $request->get_body() );
	$expected  = hash( 'sha256', $secret . strtoupper( $request->get_method() ) . $request->get_route() . $request_time . $nonce . $trade_email . $body_hash );

	if ( ! hash_equals( $expected, $sign ) ) {
		return new WP_Error( 'beyounger_config_invalid_signature', 'Invalid Beyounger config signature.', array( 'status' => 401 ) );
	}

	set_transient( $nonce_key, '1', 10 * MINUTE_IN_SECONDS );

	return true;
}

/**
 * Get the config sync secret.
 *
 * @param array $settings Gateway settings.
 *
 * @return string
 */
function woo_beyounger_payment_get_config_sync_secret( array $settings ) {
	if ( ! empty( $settings['config_sync_secret'] ) ) {
		return (string) $settings['config_sync_secret'];
	}

	$environment = isset( $settings['environment'] ) ? (string) $settings['environment'] : 'sandbox';
	if ( 'live' === $environment && ! empty( $settings['live_api_key'] ) ) {
		return (string) $settings['live_api_key'];
	}

	if ( ! empty( $settings['sandbox_api_key'] ) ) {
		return (string) $settings['sandbox_api_key'];
	}

	return ! empty( $settings['live_api_key'] ) ? (string) $settings['live_api_key'] : '';
}

/**
 * Beyounger pulls the current plugin config.
 *
 * @return WP_REST_Response
 */
function woo_beyounger_payment_rest_get_config() {
	$settings = get_option( 'woocommerce_beyounger_settings', array() );

	return rest_ensure_response(
		array(
			'code' => 200,
			'data' => array(
				'plugin_version' => WOO_BEYOUNGER_PAYMENT_VERSION,
				'supported_config_keys' => woo_beyounger_payment_config_update_keys(),
				'site_url'       => home_url( '/' ),
				'config_version' => isset( $settings['config_version'] ) ? (int) $settings['config_version'] : 0,
				'updated_at'     => isset( $settings['config_updated_at'] ) ? (int) $settings['config_updated_at'] : 0,
				'updated_source' => isset( $settings['config_updated_source'] ) ? sanitize_text_field( $settings['config_updated_source'] ) : '',
				'config'         => woo_beyounger_payment_export_config( $settings ),
			),
		)
	);
}

/**
 * Beyounger pushes or modifies plugin config.
 *
 * @param WP_REST_Request $request Request.
 *
 * @return WP_REST_Response|WP_Error
 */
function woo_beyounger_payment_rest_update_config( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		return new WP_Error( 'beyounger_config_invalid_json', 'Invalid JSON body.', array( 'status' => 400 ) );
	}

	$config = isset( $params['config'] ) && is_array( $params['config'] ) ? $params['config'] : $params;
	$incoming_version = isset( $params['config_version'] ) ? absint( $params['config_version'] ) : 0;
	$settings         = get_option( 'woocommerce_beyounger_settings', array() );
	$current_version  = isset( $settings['config_version'] ) ? absint( $settings['config_version'] ) : 0;

	if ( isset( $params['site_url'] ) && ! woo_beyounger_payment_site_url_matches( (string) $params['site_url'] ) ) {
		return new WP_Error( 'beyounger_config_site_mismatch', 'Incoming config site_url does not match this site.', array( 'status' => 403 ) );
	}

	if ( $incoming_version > 0 && $incoming_version <= $current_version ) {
		return new WP_Error( 'beyounger_config_stale_version', 'Incoming config version is older than the current config.', array( 'status' => 409 ) );
	}

	$updated = woo_beyounger_payment_merge_config( $settings, $config );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}
	$updated['config_version']        = $incoming_version > 0 ? $incoming_version : $current_version + 1;
	$updated['config_updated_at']     = isset( $params['updated_at'] ) ? absint( $params['updated_at'] ) : time();
	$updated['config_updated_source'] = 'beyounger';

	update_option( 'woocommerce_beyounger_settings', $updated );
	if ( get_option( 'woocommerce_beyounger_settings', array() ) !== $updated ) {
		return new WP_Error( 'beyounger_config_save_failed', 'Configuration could not be verified after saving. Fetch it again before retrying.', array( 'status' => 500 ) );
	}

	return rest_ensure_response(
		array(
			'code' => 200,
			'data' => array(
				'config_version' => (int) $updated['config_version'],
				'plugin_version' => WOO_BEYOUNGER_PAYMENT_VERSION,
				'supported_config_keys' => woo_beyounger_payment_config_update_keys(),
				'updated_at'     => (int) $updated['config_updated_at'],
				'config'         => woo_beyounger_payment_export_config( $updated ),
			),
		)
	);
}

/**
 * Whether an incoming site URL matches this WordPress site.
 *
 * @param string $site_url Site URL.
 *
 * @return bool
 */
function woo_beyounger_payment_site_url_matches( $site_url ) {
	$incoming = untrailingslashit( esc_url_raw( $site_url ) );
	$current  = untrailingslashit( home_url( '/' ) );

	return '' !== $incoming && strtolower( $incoming ) === strtolower( $current );
}

/**
 * Export non-secret plugin config for Beyounger.
 *
 * @param array $settings Settings.
 *
 * @return array
 */
function woo_beyounger_payment_export_config( array $settings ) {
	$settings = array_merge(
		array(
			'channel_card' => 'preferred',
			'supported_card_types_preferred' => array( 'visa', 'mastercard', 'discover', 'amex' ),
			'supported_card_types_standard' => array( 'visa' ),
		),
		$settings
	);
	foreach ( array( 'card', 'paypal', 'google_pay', 'cash_app', 'apple_pay', 'card_to_crypto' ) as $method ) {
		$settings += array( 'successful_payment_limit_standard_' . $method => '' );
	}
	$config = array();
	foreach ( woo_beyounger_payment_config_export_keys() as $key ) {
		if ( array_key_exists( $key, $settings ) ) {
			$config[ $key ] = is_scalar( $settings[ $key ] ) ? (string) $settings[ $key ] : $settings[ $key ];
		}
	}

	$config['has_sandbox_api_key']   = ! empty( $settings['sandbox_api_key'] );
	$config['has_live_api_key']      = ! empty( $settings['live_api_key'] );
	$config['has_config_sync_secret'] = ! empty( $settings['config_sync_secret'] );

	return $config;
}

/**
 * Merge signed remote config into WooCommerce settings.
 *
 * @param array $settings Current settings.
 * @param array $config Incoming config.
 *
 * @return array
 */
function woo_beyounger_payment_merge_config( array $settings, array $config ) {
	foreach ( woo_beyounger_payment_config_update_keys() as $key ) {
		if ( ! array_key_exists( $key, $config ) ) {
			continue;
		}

		$value = woo_beyounger_payment_sanitize_config_value( $key, $config[ $key ] );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$settings[ $key ] = $value;
	}

	return $settings;
}

/**
 * Non-secret config keys that may be pulled through signed sync APIs.
 *
 * @return array
 */
function woo_beyounger_payment_config_export_keys() {
	return array_merge(
		array( 'enabled', 'environment', 'public_base_url', 'utm_whitelist', 'debug' ),
		woo_beyounger_payment_method_config_keys()
	);
}

/**
 * Config keys that may be modified through signed sync APIs.
 *
 * @return array
 */
function woo_beyounger_payment_config_update_keys() {
	return array_merge(
		array( 'enabled', 'utm_whitelist' ),
		woo_beyounger_payment_method_config_keys()
	);
}

/**
 * Per-payment-method config keys.
 *
 * @return array
 */
function woo_beyounger_payment_method_config_keys() {
	$methods = array( 'card', 'paypal', 'google_pay', 'cash_app', 'apple_pay', 'card_to_crypto' );
	$keys    = array( 'supported_card_types_preferred', 'supported_card_types_standard' );

	foreach ( $methods as $method ) {
		foreach ( array( 'enabled', 'channel', 'title', 'description', 'min_order_amount', 'max_order_amount', 'successful_payment_limit', 'successful_payment_limit_standard' ) as $prefix ) {
			$keys[] = $prefix . '_' . $method;
		}
	}

	return $keys;
}

/**
 * Sanitize a signed config value.
 *
 * @param string $key Config key.
 * @param mixed  $value Raw value.
 *
 * @return string
 */
function woo_beyounger_payment_sanitize_config_value( $key, $value ) {
	if ( in_array( $key, array( 'supported_card_types_preferred', 'supported_card_types_standard' ), true ) ) {
		$brands = array( 'visa', 'mastercard', 'discover', 'amex' );
		if ( ! is_array( $value ) || empty( $value ) || array_filter( $value, static function ( $brand ) use ( $brands ) { return ! is_string( $brand ) || ! in_array( $brand, $brands, true ); } ) ) {
			return new WP_Error( 'beyounger_invalid_card_types', 'Select at least one supported card type: Visa, Mastercard, Discover or Amex.', array( 'status' => 400 ) );
		}
		return array_values( array_intersect( $brands, $value ) );
	}
	if ( in_array( $key, array( 'enabled', 'debug', 'enabled_card', 'enabled_paypal', 'enabled_google_pay', 'enabled_cash_app', 'enabled_apple_pay', 'enabled_card_to_crypto' ), true ) ) {
		$value = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value;
		return wc_string_to_bool( $value ) ? 'yes' : 'no';
	}

	if ( 0 === strpos( $key, 'channel_' ) ) {
		return 'standard' === $value ? 'standard' : 'preferred';
	}

	if ( 'environment' === $key ) {
		return 'live' === (string) $value ? 'live' : 'sandbox';
	}

	if ( 'public_base_url' === $key ) {
		return '' === (string) $value ? '' : esc_url_raw( (string) $value );
	}

	if ( 'trade_email' === $key ) {
		return sanitize_email( (string) $value );
	}

	if ( 0 === strpos( $key, 'min_order_amount_' ) || 0 === strpos( $key, 'max_order_amount_' ) || 0 === strpos( $key, 'successful_payment_limit_' ) ) {
		$value = trim( (string) $value );
		return '' === $value ? '' : wc_format_decimal( $value, 2 );
	}

	if ( 'utm_whitelist' === $key || 0 === strpos( $key, 'description_' ) ) {
		return sanitize_textarea_field( (string) $value );
	}

	return sanitize_text_field( (string) $value );
}

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>';
						echo esc_html__( 'Woo Beyounger Payment requires WooCommerce to be installed and active.', 'woo-beyounger-payment' );
						echo '</p></div>';
					}
				}
			);
			return;
		}

		require_once WOO_BEYOUNGER_PAYMENT_PATH . 'includes/class-wc-gateway-beyounger.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'WC_Gateway_Beyounger';
				$gateways[] = 'WC_Gateway_Beyounger_PayPal';
				$gateways[] = 'WC_Gateway_Beyounger_Google_Pay';
				$gateways[] = 'WC_Gateway_Beyounger_Cash_App';
				$gateways[] = 'WC_Gateway_Beyounger_Apple_Pay';
				$gateways[] = 'WC_Gateway_Beyounger_Card_To_Crypto';
				return $gateways;
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			static function ( $links ) {
				$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=beyounger' );
				$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'woo-beyounger-payment' ) . '</a>';
				array_unshift( $links, $settings_link );
				return $links;
			}
		);

		add_action(
			'woocommerce_blocks_loaded',
			static function () {
				if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
					return;
				}

				require_once WOO_BEYOUNGER_PAYMENT_PATH . 'includes/class-wc-gateway-beyounger-blocks.php';

				add_action(
					'woocommerce_blocks_payment_method_type_registration',
					static function ( $payment_method_registry ) {
						$payment_method_registry->register( new WC_Gateway_Beyounger_Blocks() );
						$payment_method_registry->register( new WC_Gateway_Beyounger_Blocks( 'beyounger_paypal' ) );
						$payment_method_registry->register( new WC_Gateway_Beyounger_Blocks( 'beyounger_google_pay' ) );
						$payment_method_registry->register( new WC_Gateway_Beyounger_Blocks( 'beyounger_cash_app' ) );
						$payment_method_registry->register( new WC_Gateway_Beyounger_Blocks( 'beyounger_apple_pay' ) );
						$payment_method_registry->register( new WC_Gateway_Beyounger_Blocks( 'beyounger_card_to_crypto' ) );
					}
				);
			}
		);
	}
);
