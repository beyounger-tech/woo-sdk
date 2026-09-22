<?php
/**
 * WooCommerce Blocks integration for BeyoungerPay.
 *
 * @package WooBeyoungerPayment
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the gateway in WooCommerce Checkout Blocks.
 */
final class WC_Gateway_Beyounger_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = 'beyounger';

	/**
	 * Gateway instance.
	 *
	 * @var WC_Gateway_Beyounger|null
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param string $name Payment method name.
	 */
	public function __construct( $name = 'beyounger' ) {
		$this->name = $name;
	}

	/**
	 * Initialize.
	 */
	public function initialize() {
		$gateways      = WC()->payment_gateways()->payment_gateways();
		$this->gateway = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}

	/**
	 * Whether this payment method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	/**
	 * Script handles.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_path = WOO_BEYOUNGER_PAYMENT_PATH . 'assets/blocks.js';
		$script_url  = plugins_url( 'assets/blocks.js', WOO_BEYOUNGER_PAYMENT_FILE );

		wp_register_script(
			'woo-beyounger-payment-blocks',
			$script_url,
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : WOO_BEYOUNGER_PAYMENT_VERSION,
			true
		);

		return array( 'woo-beyounger-payment-blocks' );
	}

	/**
	 * Data exposed to the frontend block checkout script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$data = array(
			'title'       => $this->gateway ? $this->gateway->get_title() : __( 'Credit card', 'woo-beyounger-payment' ),
			'description' => $this->gateway ? $this->gateway->get_description() : '',
			'icon'        => $this->gateway ? $this->gateway->get_icon_url() : '',
			'icons'       => $this->gateway ? $this->gateway->get_icon_urls() : array(),
			'orderLimits' => $this->gateway && method_exists( $this->gateway, 'get_order_amount_limits' ) ? $this->gateway->get_order_amount_limits() : array(),
			'supports'    => array_filter( $this->gateway ? $this->gateway->supports : array( 'products' ) ),
		);

		if ( 'beyounger' === $this->name && $this->gateway && method_exists( $this->gateway, 'get_card_form_context' ) ) {
			$data['description'] = '';
			$data['cardForm'] = $this->gateway->get_card_form_context();
		}

		return $data;
	}
}
