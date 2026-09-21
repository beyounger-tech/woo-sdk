<?php
/**
 * BeyoungerPay WooCommerce gateway.
 *
 * @package WooBeyoungerPayment
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce payment gateway for BeyoungerPay cashier.
 */
class WC_Gateway_Beyounger extends WC_Payment_Gateway {

	private const LIVE_BASE_URL     = 'https://cashier.beyounger.com/gateway';
	private const SANDBOX_BASE_URL  = 'https://cashier-sandbox.beyounger.com/gateway';
	private const CARD_FORM_ORIGIN  = 'https://cashier.beyounger.com';
	private const CARD_FORM_PATH    = '/gateway/payment/cardform';

	/**
	 * WooCommerce gateway ID.
	 *
	 * @var string
	 */
	protected $gateway_id = 'beyounger';

	/**
	 * Internal payment method key.
	 *
	 * @var string
	 */
	protected $variant_key = 'card';

	/**
	 * BeyoungerPay method_type value.
	 *
	 * @var string
	 */
	protected $method_type = '1';

	/**
	 * BeyoungerPay request_type value.
	 *
	 * @var string
	 */
	protected $request_type = '3';

	/**
	 * Default customer-facing title.
	 *
	 * @var string
	 */
	protected $default_title = 'Credit card';

	/**
	 * Default customer-facing description.
	 *
	 * @var string
	 */
	protected $default_description = '';

	/**
	 * Logo asset filename.
	 *
	 * @var string
	 */
	protected $icon_file = 'card.svg';

	/**
	 * Logger instance.
	 *
	 * @var WC_Logger|null
	 */
	private $logger;

	/**
	 * Whether debug logging is enabled.
	 *
	 * @var bool
	 */
	private $debug = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = $this->gateway_id;
		$this->icon               = plugins_url( 'assets/' . $this->icon_file, WOO_BEYOUNGER_PAYMENT_FILE );
		$this->has_fields         = 'card' === $this->variant_key;
		$this->method_title       = sprintf(
			/* translators: %s: payment method title */
			__( 'BeyoungerPay - %s', 'woo-beyounger-payment' ),
			$this->get_default_title()
		);
		$this->method_description = __( 'Accept payments through BeyoungerPay. Card payments use a hosted tokenized card form so card numbers are not handled by WordPress.', 'woo-beyounger-payment' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		if ( ! $this->is_primary_gateway() ) {
			$this->settings = get_option( 'woocommerce_beyounger_settings', array() );
		}

		$this->title       = $this->get_option( 'title_' . $this->variant_key, $this->get_default_title() );
		$this->description = $this->get_option( 'description_' . $this->variant_key, $this->get_default_description() );
		$this->enabled     = ( 'yes' === $this->get_option( 'enabled', 'no' ) && 'yes' === $this->get_option( 'enabled_' . $this->variant_key, 'no' ) ) ? 'yes' : 'no';
		$this->debug       = 'yes' === $this->get_option( 'debug', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		if ( $this->is_primary_gateway() ) {
			add_action( 'woocommerce_api_wc_gateway_beyounger', array( $this, 'handle_webhook' ) );
		}
	}

	/**
	 * Admin settings fields.
	 */
	public function init_form_fields() {
		if ( ! $this->is_primary_gateway() ) {
			$this->form_fields = array(
				'managed' => array(
					'title'       => __( 'BeyoungerPay Settings', 'woo-beyounger-payment' ),
					'type'        => 'title',
					'description' => sprintf(
						/* translators: %s: settings URL */
						__( 'This method uses the shared BeyoungerPay settings. Configure enabled methods, titles, merchant email, and API keys in the main BeyoungerPay settings: %s', 'woo-beyounger-payment' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=beyounger' ) ) . '">' . esc_html__( 'Open settings', 'woo-beyounger-payment' ) . '</a>'
					),
				),
			);
			return;
		}

		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'woo-beyounger-payment' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable BeyoungerPay', 'woo-beyounger-payment' ),
				'default' => 'no',
			),
			'environment'     => array(
				'title'       => __( 'Environment', 'woo-beyounger-payment' ),
				'type'        => 'select',
				'description' => __( 'Use sandbox for testing and live for real payments.', 'woo-beyounger-payment' ),
				'default'     => 'sandbox',
				'options'     => array(
					'sandbox' => __( 'Sandbox', 'woo-beyounger-payment' ),
					'live'    => __( 'Live', 'woo-beyounger-payment' ),
				),
				'desc_tip'    => true,
			),
				'webhook_url'     => array(
					'title'       => __( 'Notification URL', 'woo-beyounger-payment' ),
					'type'        => 'title',
					'description' => sprintf(
						/* translators: %s: notification URL */
						__( 'BeyoungerPay asynchronous notifications are handled at: %s', 'woo-beyounger-payment' ),
						'<code>' . esc_html( $this->get_notify_url() ) . '</code>'
					),
				),
				'public_base_url' => array(
					'title'       => __( 'Public Callback Base URL', 'woo-beyounger-payment' ),
					'type'        => 'url',
					'description' => __( 'Optional. Use this when the WordPress site is local but BeyoungerPay needs a public HTTPS callback URL, for example a Cloudflare Tunnel URL. Leave empty on production sites.', 'woo-beyounger-payment' ),
					'default'     => '',
					'placeholder' => 'https://example.trycloudflare.com',
					'desc_tip'    => true,
				),
				'trade_email'     => array(
					'title'       => __( 'Merchant Email', 'woo-beyounger-payment' ),
					'type'        => 'text',
					'description' => __( 'BeyoungerPay merchant email.', 'woo-beyounger-payment' ),
					'default'     => '',
			),
			'sandbox_api_key' => array(
				'title'       => __( 'Sandbox API Key', 'woo-beyounger-payment' ),
				'type'        => 'password',
				'description' => __( 'API key for sandbox requests and sandbox notifications.', 'woo-beyounger-payment' ),
				'default'     => '',
			),
			'live_api_key'    => array(
				'title'       => __( 'Live API Key', 'woo-beyounger-payment' ),
				'type'        => 'password',
				'description' => __( 'API key for live requests and live notifications.', 'woo-beyounger-payment' ),
				'default'     => '',
			),
			'config_sync_secret' => array(
				'title'       => __( 'Config Sync Secret', 'woo-beyounger-payment' ),
				'type'        => 'password',
				'description' => __( 'Optional dedicated secret for BeyoungerPay config sync REST APIs. If empty, the active environment API key is used.', 'woo-beyounger-payment' ),
				'default'     => '',
			),
			'utm_gate'        => array(
				'title'       => __( 'UTM Visibility', 'woo-beyounger-payment' ),
				'type'        => 'title',
				'description' => __( 'Preferred Channel requires whitelisted UTM traffic or an eligible returning customer. Standard Channel also allows other visitors. All other payment method limits still apply.', 'woo-beyounger-payment' ),
			),
				'utm_whitelist'   => array(
					'title'       => __( 'Allowed UTM Sources', 'woo-beyounger-payment' ),
					'type'        => 'textarea',
					'description' => $this->get_utm_whitelist_description(),
					'default'     => '',
					'css'         => 'min-height: 120px;',
				),
				'amount_gate'     => array(
					'title'       => __( 'Amount Limits', 'woo-beyounger-payment' ),
					'type'        => 'title',
					'description' => __( 'Each BeyoungerPay payment method can have its own single-order amount range and daily successful payment limit. Single-order defaults are 0 to 500; daily successful payment limit defaults to 5000. Leave a field empty for no limit.', 'woo-beyounger-payment' ),
				),
				'payment_methods' => array(
					'title'       => __( 'Payment Methods', 'woo-beyounger-payment' ),
					'type'        => 'title',
					'description' => __( 'Choose which BeyoungerPay methods are shown at checkout. All methods share the merchant email and API keys above, but each method has its own visibility limits.', 'woo-beyounger-payment' ),
				),
			'enabled_card'    => array(
				'title'   => __( 'Credit Card', 'woo-beyounger-payment' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Credit Card', 'woo-beyounger-payment' ),
				'default' => 'yes',
			),
			'channel_card' => array(
				'title'   => __( 'Credit Card Channel', 'woo-beyounger-payment' ),
				'type'    => 'select',
				'default' => 'preferred',
				'options' => array(
					'preferred' => __( 'Preferred Channel', 'woo-beyounger-payment' ),
					'standard'  => __( 'Standard Channel', 'woo-beyounger-payment' ),
				),
			),
			'title_card'      => array(
				'title'   => __( 'Credit Card Title', 'woo-beyounger-payment' ),
				'type'    => 'text',
				'default' => __( 'Credit card', 'woo-beyounger-payment' ),
			),
			'description_card' => array(
				'title'   => __( 'Credit Card Description', 'woo-beyounger-payment' ),
				'type'    => 'textarea',
				'default' => '',
			),
			'min_order_amount_card' => array(
				'title'             => __( 'Credit Card Minimum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Credit Card when the current order total is below this amount. Default is 0; empty means no minimum.', 'woo-beyounger-payment' ),
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'max_order_amount_card' => array(
				'title'             => __( 'Credit Card Maximum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Credit Card when the current order total is above this amount. Default is 500; empty means no maximum.', 'woo-beyounger-payment' ),
				'default'           => '500',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'successful_payment_limit_card' => array(
				'title'             => __( 'Credit Card Successful Payment Limit', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Credit Card when Credit Card successful payments exceed this amount today. Default is 5000; empty means no limit.', 'woo-beyounger-payment' ),
				'default'           => '5000',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'enabled_paypal'  => array(
				'title'   => __( 'PayPal', 'woo-beyounger-payment' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show PayPal', 'woo-beyounger-payment' ),
				'default' => 'no',
			),
			'channel_paypal' => array(
				'title'   => __( 'PayPal Channel', 'woo-beyounger-payment' ),
				'type'    => 'select',
				'default' => 'preferred',
				'options' => array(
					'preferred' => __( 'Preferred Channel', 'woo-beyounger-payment' ),
					'standard'  => __( 'Standard Channel', 'woo-beyounger-payment' ),
				),
			),
			'title_paypal'    => array(
				'title'   => __( 'PayPal Title', 'woo-beyounger-payment' ),
				'type'    => 'text',
				'default' => __( 'PayPal', 'woo-beyounger-payment' ),
			),
			'description_paypal' => array(
				'title'   => __( 'PayPal Description', 'woo-beyounger-payment' ),
				'type'    => 'textarea',
				'default' => __( 'Pay via PayPal through BeyoungerPay.', 'woo-beyounger-payment' ),
			),
			'min_order_amount_paypal' => array(
				'title'             => __( 'PayPal Minimum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide PayPal when the current order total is below this amount. Default is 0; empty means no minimum.', 'woo-beyounger-payment' ),
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'max_order_amount_paypal' => array(
				'title'             => __( 'PayPal Maximum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide PayPal when the current order total is above this amount. Default is 500; empty means no maximum.', 'woo-beyounger-payment' ),
				'default'           => '500',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'successful_payment_limit_paypal' => array(
				'title'             => __( 'PayPal Successful Payment Limit', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide PayPal when PayPal successful payments exceed this amount today. Default is 5000; empty means no limit.', 'woo-beyounger-payment' ),
				'default'           => '5000',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'enabled_google_pay' => array(
				'title'   => __( 'Google Pay', 'woo-beyounger-payment' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Google Pay', 'woo-beyounger-payment' ),
				'default' => 'no',
			),
			'channel_google_pay' => array(
				'title'   => __( 'Google Pay Channel', 'woo-beyounger-payment' ),
				'type'    => 'select',
				'default' => 'preferred',
				'options' => array(
					'preferred' => __( 'Preferred Channel', 'woo-beyounger-payment' ),
					'standard'  => __( 'Standard Channel', 'woo-beyounger-payment' ),
				),
			),
			'title_google_pay' => array(
				'title'   => __( 'Google Pay Title', 'woo-beyounger-payment' ),
				'type'    => 'text',
				'default' => __( 'Google Pay', 'woo-beyounger-payment' ),
			),
			'description_google_pay' => array(
				'title'   => __( 'Google Pay Description', 'woo-beyounger-payment' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with Google Pay through BeyoungerPay.', 'woo-beyounger-payment' ),
			),
			'min_order_amount_google_pay' => array(
				'title'             => __( 'Google Pay Minimum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Google Pay when the current order total is below this amount. Default is 0; empty means no minimum.', 'woo-beyounger-payment' ),
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'max_order_amount_google_pay' => array(
				'title'             => __( 'Google Pay Maximum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Google Pay when the current order total is above this amount. Default is 500; empty means no maximum.', 'woo-beyounger-payment' ),
				'default'           => '500',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'successful_payment_limit_google_pay' => array(
				'title'             => __( 'Google Pay Successful Payment Limit', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Google Pay when Google Pay successful payments exceed this amount today. Default is 5000; empty means no limit.', 'woo-beyounger-payment' ),
				'default'           => '5000',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'enabled_cash_app' => array(
				'title'   => __( 'Cash App', 'woo-beyounger-payment' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Cash App', 'woo-beyounger-payment' ),
				'default' => 'no',
			),
			'channel_cash_app' => array(
				'title'   => __( 'Cash App Channel', 'woo-beyounger-payment' ),
				'type'    => 'select',
				'default' => 'preferred',
				'options' => array(
					'preferred' => __( 'Preferred Channel', 'woo-beyounger-payment' ),
					'standard'  => __( 'Standard Channel', 'woo-beyounger-payment' ),
				),
			),
			'title_cash_app'  => array(
				'title'   => __( 'Cash App Title', 'woo-beyounger-payment' ),
				'type'    => 'text',
				'default' => __( 'Cash App', 'woo-beyounger-payment' ),
			),
			'description_cash_app' => array(
				'title'   => __( 'Cash App Description', 'woo-beyounger-payment' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with Cash App through BeyoungerPay.', 'woo-beyounger-payment' ),
			),
			'min_order_amount_cash_app' => array(
				'title'             => __( 'Cash App Minimum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Cash App when the current order total is below this amount. Default is 0; empty means no minimum.', 'woo-beyounger-payment' ),
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'max_order_amount_cash_app' => array(
				'title'             => __( 'Cash App Maximum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Cash App when the current order total is above this amount. Default is 500; empty means no maximum.', 'woo-beyounger-payment' ),
				'default'           => '500',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'successful_payment_limit_cash_app' => array(
				'title'             => __( 'Cash App Successful Payment Limit', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Cash App when Cash App successful payments exceed this amount today. Default is 5000; empty means no limit.', 'woo-beyounger-payment' ),
				'default'           => '5000',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'enabled_apple_pay' => array(
				'title'   => __( 'Apple Pay', 'woo-beyounger-payment' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Apple Pay', 'woo-beyounger-payment' ),
				'default' => 'no',
			),
			'channel_apple_pay' => array(
				'title'   => __( 'Apple Pay Channel', 'woo-beyounger-payment' ),
				'type'    => 'select',
				'default' => 'preferred',
				'options' => array(
					'preferred' => __( 'Preferred Channel', 'woo-beyounger-payment' ),
					'standard'  => __( 'Standard Channel', 'woo-beyounger-payment' ),
				),
			),
			'title_apple_pay' => array(
				'title'   => __( 'Apple Pay Title', 'woo-beyounger-payment' ),
				'type'    => 'text',
				'default' => __( 'Apple Pay', 'woo-beyounger-payment' ),
			),
			'description_apple_pay' => array(
				'title'   => __( 'Apple Pay Description', 'woo-beyounger-payment' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with Apple Pay through BeyoungerPay.', 'woo-beyounger-payment' ),
			),
			'min_order_amount_apple_pay' => array(
				'title'             => __( 'Apple Pay Minimum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Apple Pay when the current order total is below this amount. Default is 0; empty means no minimum.', 'woo-beyounger-payment' ),
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'max_order_amount_apple_pay' => array(
				'title'             => __( 'Apple Pay Maximum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Apple Pay when the current order total is above this amount. Default is 500; empty means no maximum.', 'woo-beyounger-payment' ),
				'default'           => '500',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'successful_payment_limit_apple_pay' => array(
				'title'             => __( 'Apple Pay Successful Payment Limit', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Apple Pay when Apple Pay successful payments exceed this amount today. Default is 5000; empty means no limit.', 'woo-beyounger-payment' ),
				'default'           => '5000',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'enabled_card_to_crypto' => array(
				'title'   => __( 'Card to crypto', 'woo-beyounger-payment' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Card to crypto', 'woo-beyounger-payment' ),
				'default' => 'no',
			),
			'channel_card_to_crypto' => array(
				'title'   => __( 'Card to Crypto Channel', 'woo-beyounger-payment' ),
				'type'    => 'select',
				'default' => 'preferred',
				'options' => array(
					'preferred' => __( 'Preferred Channel', 'woo-beyounger-payment' ),
					'standard'  => __( 'Standard Channel', 'woo-beyounger-payment' ),
				),
			),
			'title_card_to_crypto' => array(
				'title'   => __( 'Card to crypto Title', 'woo-beyounger-payment' ),
				'type'    => 'text',
				'default' => __( 'Card to crypto', 'woo-beyounger-payment' ),
			),
			'description_card_to_crypto' => array(
				'title'   => __( 'Card to crypto Description', 'woo-beyounger-payment' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with Card to crypto through BeyoungerPay.', 'woo-beyounger-payment' ),
			),
			'min_order_amount_card_to_crypto' => array(
				'title'             => __( 'Card to crypto Minimum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Card to crypto when the current order total is below this amount. Default is 0; empty means no minimum.', 'woo-beyounger-payment' ),
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'max_order_amount_card_to_crypto' => array(
				'title'             => __( 'Card to crypto Maximum Order Amount', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Card to crypto when the current order total is above this amount. Default is 500; empty means no maximum.', 'woo-beyounger-payment' ),
				'default'           => '500',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'successful_payment_limit_card_to_crypto' => array(
				'title'             => __( 'Card to crypto Successful Payment Limit', 'woo-beyounger-payment' ),
				'type'              => 'number',
				'description'       => __( 'Hide Card to crypto when Card to crypto successful payments exceed this amount today. Default is 5000; empty means no limit.', 'woo-beyounger-payment' ),
				'default'           => '5000',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),
			'debug'           => array(
				'title'       => __( 'Debug Log', 'woo-beyounger-payment' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'woo-beyounger-payment' ),
				'description' => __( 'Logs are written to WooCommerce > Status > Logs.', 'woo-beyounger-payment' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * Check gateway availability.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( '' === $this->get_trade_email() || '' === $this->get_api_key() ) {
			return false;
		}

			if ( ! $this->is_standard_channel() && ! $this->passes_utm_gate() ) {
				return false;
			}

			if ( ! $this->passes_order_amount_gate() ) {
				return false;
			}

			if ( ! $this->passes_daily_successful_payment_limit() ) {
				return false;
			}

			return parent::is_available();
		}

	/**
	 * Start a BeyoungerPay transaction.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Unable to load order for payment.', 'woo-beyounger-payment' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! $this->passes_order_amount_gate( $order ) ) {
			wc_add_notice( __( 'This payment method is not available for this order amount.', 'woo-beyounger-payment' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! $this->passes_daily_successful_payment_limit() ) {
			wc_add_notice( __( 'This payment method is not available today.', 'woo-beyounger-payment' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$card_token = '';
		if ( $this->is_card_gateway() ) {
			$card_token = $this->get_card_token_from_request();
			if ( '' === $card_token ) {
				wc_add_notice( __( 'Please complete the secure card form before placing the order.', 'woo-beyounger-payment' ), 'error' );
				return array( 'result' => 'failure' );
			}
		}

		$payload  = $this->build_payment_payload( $order, $card_token );
		$response = $this->post_to_beyounger( '/payment/transaction', $payload );

			if ( is_wp_error( $response ) ) {
				$this->log( 'Payment request failed: ' . $response->get_error_message(), 'error' );
				if ( $this->is_card_gateway() ) {
					$this->clear_card_form_order_id();
				}
				wc_add_notice( __( 'Payment service is temporarily unavailable. Please try again.', 'woo-beyounger-payment' ), 'error' );
				return array( 'result' => 'failure' );
			}

		$parsed = $this->parse_response( $response );
			if ( is_wp_error( $parsed ) ) {
				$this->log( 'Payment response parse failed: ' . $parsed->get_error_message(), 'error' );
				if ( $this->is_card_gateway() ) {
					$this->clear_card_form_order_id();
				}
				wc_add_notice( __( 'Payment service returned an invalid response. Please try again.', 'woo-beyounger-payment' ), 'error' );
				return array( 'result' => 'failure' );
			}

			if ( '200' !== (string) $parsed['code'] ) {
				$message = ! empty( $parsed['message'] ) ? $parsed['message'] : __( 'Payment request was declined.', 'woo-beyounger-payment' );
				$this->log( 'Payment declined for order ' . $order->get_id() . ': ' . $message, 'warning' );
				if ( $this->is_card_gateway() ) {
					$this->clear_card_form_order_id();
					$message .= ' ' . __( 'Please refresh checkout and re-enter the card details before trying again.', 'woo-beyounger-payment' );
				}
				wc_add_notice( esc_html( $message ), 'error' );
				return array( 'result' => 'failure' );
			}

		$data = isset( $parsed['data'] ) && is_array( $parsed['data'] ) ? $parsed['data'] : array();
		$this->persist_transaction_data( $order, $payload['order_id'], $data );

		if ( ! empty( $data['pay_url'] ) ) {
			$order->update_status( 'pending', __( 'Awaiting BeyoungerPay payment.', 'woo-beyounger-payment' ) );
			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}
			$this->clear_card_form_order_id();

			return array(
				'result'   => 'success',
				'redirect' => esc_url_raw( $data['pay_url'] ),
			);
		}

		$status = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'pending';
		$this->apply_beyounger_status( $order, $status, $data, 'payment_response' );
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		$this->clear_card_form_order_id();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process WooCommerce refunds through BeyoungerPay.
	 *
	 * @param int        $order_id WooCommerce order ID.
	 * @param float|null $amount Refund amount.
	 * @param string     $reason Refund reason.
	 *
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'beyounger_refund_order_missing', __( 'Order not found.', 'woo-beyounger-payment' ) );
		}

		$transaction_no = $order->get_meta( '_beyounger_transaction_no', true );
		if ( '' === $transaction_no ) {
			return new WP_Error( 'beyounger_refund_transaction_missing', __( 'BeyoungerPay transaction number is missing.', 'woo-beyounger-payment' ) );
		}

		if ( null === $amount || $amount <= 0 ) {
			return new WP_Error( 'beyounger_refund_amount_invalid', __( 'Refund amount must be greater than zero.', 'woo-beyounger-payment' ) );
		}

		$request_time = (string) time();
		$refund       = array(
			'trade_email'    => $this->get_trade_email(),
			'request_time'   => $request_time,
			'sandbox'        => $this->is_sandbox() ? '1' : '0',
			'transaction_no' => $transaction_no,
			'amount'         => wc_format_decimal( $amount, 2 ),
		);
		$refund['sign'] = $this->make_signature(
			array(
				$request_time,
				$refund['trade_email'],
				$refund['transaction_no'],
				$refund['amount'],
			)
		);

		$response = $this->post_to_beyounger( '/payment/refund', $refund );
		if ( is_wp_error( $response ) ) {
			$this->log( 'Refund request failed: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$parsed = $this->parse_response( $response );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( '200' !== (string) $parsed['code'] ) {
			$message = ! empty( $parsed['message'] ) ? $parsed['message'] : __( 'Refund request failed.', 'woo-beyounger-payment' );
			return new WP_Error( 'beyounger_refund_failed', $message );
		}

		$data      = isset( $parsed['data'] ) && is_array( $parsed['data'] ) ? $parsed['data'] : array();
		$refund_id = isset( $data['refund_id'] ) ? wc_clean( wp_unslash( $data['refund_id'] ) ) : '';

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: refund ID, 3: reason */
				__( 'BeyoungerPay refund requested. Amount: %1$s. Refund ID: %2$s. Reason: %3$s', 'woo-beyounger-payment' ),
				wc_price( $amount, array( 'currency' => $order->get_currency() ) ),
				$refund_id ? $refund_id : __( 'N/A', 'woo-beyounger-payment' ),
				$reason ? $reason : __( 'N/A', 'woo-beyounger-payment' )
			)
		);

		return true;
	}

	/**
	 * Handle asynchronous BeyoungerPay notification.
	 */
	public function handle_webhook() {
		$payload = $this->get_request_payload();

		if ( empty( $payload ) ) {
			status_header( 400 );
			echo 'empty payload';
			exit;
		}

		if ( ! $this->verify_notify_signature( $payload ) ) {
			$this->log( 'Invalid notification signature: ' . wp_json_encode( $payload ), 'warning' );
			status_header( 400 );
			echo 'invalid sign';
			exit;
		}

		$order_id = isset( $payload['order_id'] ) ? wc_clean( wp_unslash( $payload['order_id'] ) ) : '';
		$order    = $this->get_order_by_beyounger_order_id( $order_id );

		if ( ! $order ) {
			$this->log( 'Notification order not found: ' . $order_id, 'warning' );
			status_header( 404 );
			echo 'order not found';
			exit;
		}

		$status = isset( $payload['status'] ) ? sanitize_key( $payload['status'] ) : '';
		$this->persist_transaction_data( $order, $order_id, $payload );
		$this->apply_beyounger_status( $order, $status, $payload, 'notify' );

		status_header( 200 );
		echo 'success';
		exit;
	}

	/**
	 * Build request payload for payment creation.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return array
	 */
	private function build_payment_payload( WC_Order $order, $card_token = '' ) {
		$request_time = (string) time();
		$order_id     = $this->is_card_gateway() ? $this->get_submitted_card_form_order_id() : '';
		$order_id     = '' !== $order_id ? $order_id : $this->make_merchant_order_id( $order );
		$amount       = wc_format_decimal( $order->get_total(), 2 );
		$currency     = $order->get_currency();
		$pay_method   = $this->get_pay_method();
		$traffic      = $this->build_traffic_context();

		$payload = array(
			'sandbox'      => $this->is_sandbox() ? '1' : '0',
			'request_time' => $request_time,
			'request_type' => $this->get_request_type(),
			'trade_email'  => $this->get_trade_email(),
			'order_id'     => $order_id,
			'amount'       => $amount,
			'currency'     => $currency,
			'pay_method'   => $pay_method,
			'method_type'  => $this->get_method_type(),
			'3ds_mode'     => $this->get_three_ds_mode(),
			'CUSTOM_FD14'     => $traffic['custom_fd14'],
			'notify_url'   => $this->get_notify_url(),
			'callback_url' => $this->get_callback_url( $order ),
			'customer'     => array(
				'email' => $order->get_billing_email(),
				'ip'    => $order->get_customer_ip_address(),
			),
			'billing'      => $this->build_billing_payload( $order ),
			'product'      => rawurlencode( wp_json_encode( $this->build_product_payload( $order ) ) ),
			'extra'        => wp_json_encode( $this->build_extra_payload( $order, $traffic ) ),
		);

		if ( $this->is_card_gateway() && '' !== $card_token ) {
			$payload['card[token]'] = $card_token;
			if ( '2' === $traffic['custom_fd14'] ) {
				$payload['allowed_card_brand'] = 'visa';
			}
		}

		$payload['sign'] = $this->make_signature(
			array(
				$request_time,
				$payload['trade_email'],
				$order_id,
				$pay_method,
				$amount,
				$currency,
			)
		);

		$order->update_meta_data( '_beyounger_order_id', $order_id );
		$order->update_meta_data( '_beyounger_environment', $payload['sandbox'] === '1' ? 'sandbox' : 'live' );
		$order->update_meta_data( '_beyounger_trade_email', $payload['trade_email'] );
		$order->update_meta_data( '_beyounger_payment_method', $this->variant_key );
		$order->update_meta_data( '_beyounger_request_type', $this->get_request_type() );
		$order->update_meta_data( '_beyounger_method_type', $this->get_method_type() );
		$this->persist_traffic_context( $order, $traffic );
		$order->save();

		return $payload;
	}

	/**
	 * Output payment fields for the hosted card form.
	 */
	public function payment_fields() {
		if ( ! $this->is_card_gateway() ) {
			parent::payment_fields();
			return;
		}

		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$context = $this->get_card_form_context();
		?>
		<div class="beyounger-card-form" data-beyounger-card-form="classic">
			<iframe
				data-beyounger-card-iframe="1"
				src="<?php echo esc_url( $context['url'] ); ?>"
				title="<?php echo esc_attr__( 'Secure card form', 'woo-beyounger-payment' ); ?>"
				style="width:100%;height:96px;border:0;"
				loading="lazy"
				referrerpolicy="no-referrer-when-downgrade"
			></iframe>
			<input type="hidden" name="card[token]" value="">
			<input type="hidden" name="beyounger_card_token" value="">
			<input type="hidden" name="beyounger_card_order_id" value="<?php echo esc_attr( $context['order_id'] ); ?>">
		</div>
		<script>
			(function () {
				if (window.beyoungerCardTokenListenerInstalled) {
					return;
				}
				window.beyoungerCardTokenListenerInstalled = true;
				window.beyoungerCardTokenWaiters = [];
				window.beyoungerCardTokenSubmitting = false;
				window.beyoungerEscapeHtml = function (value) {
					return String(value).replace(/[&<>"']/g, function (char) {
						return {
							'&': '&amp;',
							'<': '&lt;',
							'>': '&gt;',
							'"': '&quot;',
							"'": '&#039;'
						}[char];
					});
				};
				window.beyoungerRequestCardToken = function () {
					var iframe = document.querySelector('[data-beyounger-card-iframe="1"]');
					if (!iframe || !iframe.contentWindow) {
						return Promise.reject(new Error('Secure card form is not ready.'));
					}

					document.querySelectorAll('[name="card[token]"], [name="beyounger_card_token"]').forEach(function (field) {
						field.value = '';
					});

					var targetOrigin = new URL(iframe.src).origin;
					return new Promise(function (resolve, reject) {
						var timeout = window.setTimeout(function () {
							window.beyoungerCardTokenWaiters = window.beyoungerCardTokenWaiters.filter(function (waiter) {
								return waiter.resolve !== resolve;
							});
							reject(new Error('Please complete the secure card form before placing the order.'));
						}, 10000);

						window.beyoungerCardTokenWaiters.push({
							resolve: function (token) {
								window.clearTimeout(timeout);
								resolve(token);
							},
							reject: function (error) {
								window.clearTimeout(timeout);
								reject(error);
							}
						});

						iframe.contentWindow.postMessage({ type: 'beyounger.create_token' }, targetOrigin);
					});
				};
				window.addEventListener('message', function (event) {
					var allowed = <?php echo wp_json_encode( $context['allowed_origins'] ); ?>;
					if (allowed.indexOf(event.origin) === -1) {
						return;
					}
					if (event.data && event.data.type === 'beyounger.cardform_resize') {
						var height = parseInt(event.data.height, 10);
						if (!height || height < 90) {
							height = 90;
						}
						if (height > 420) {
							height = 420;
						}
						document.querySelectorAll('[data-beyounger-card-iframe="1"]').forEach(function (iframe) {
							iframe.style.height = height + 'px';
						});
						return;
					}
					if (event.data && (event.data.type === 'beyounger.card_error' || event.data.type === 'beyounger.cardform_error' || event.data.type === 'beyounger.payment_error')) {
						window.beyoungerCardTokenWaiters.splice(0).forEach(function (waiter) {
							waiter.reject(new Error(event.data.message || 'Unable to tokenize card.'));
						});
						return;
					}
					if (!event.data || event.data.type !== 'beyounger.card_token') {
						return;
					}
					var token = event.data.card && event.data.card.token ? event.data.card.token : event.data.token || '';
					if (!token) {
						return;
					}
					document.querySelectorAll('[name="card[token]"], [name="beyounger_card_token"]').forEach(function (field) {
						field.value = token;
					});
					window.beyoungerCardTokenWaiters.splice(0).forEach(function (waiter) {
						waiter.resolve(token);
					});
					window.dispatchEvent(new CustomEvent('beyounger.card_token_ready', { detail: { token: token } }));
				});
				if (window.jQuery) {
					window.jQuery(function ($) {
						$('form.checkout').on('checkout_place_order_beyounger', function () {
							var form = $(this);
							var token = form.find('[name="beyounger_card_token"]').val();
							if (window.beyoungerCardTokenSubmitting || token) {
								window.beyoungerCardTokenSubmitting = false;
								return true;
							}

							window.beyoungerRequestCardToken().then(function () {
								window.beyoungerCardTokenSubmitting = true;
								form.trigger('submit');
							}).catch(function (error) {
								window.beyoungerCardTokenSubmitting = false;
								$(document.body).trigger('checkout_error', ['<ul class="woocommerce-error" role="alert"><li>' + window.beyoungerEscapeHtml(error.message || 'Please complete the secure card form before placing the order.') + '</li></ul>']);
							});

							return false;
						});
					});
				}
			}());
		</script>
		<?php
	}

	/**
	 * Build billing payload from a WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return array
	 */
	private function build_billing_payload( WC_Order $order ) {
		return array_filter(
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'address1'   => $order->get_billing_address_1(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'country'    => $order->get_billing_country(),
				'zip_code'   => $order->get_billing_postcode(),
				'phone'      => $order->get_billing_phone(),
			),
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
	}

	/**
	 * Build product payload from order line items.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return array
	 */
	private function build_product_payload( WC_Order $order ) {
		$products = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$sku     = $product ? $product->get_sku() : '';

			$products[] = array(
				'sku'      => $sku ? $sku : (string) $item->get_product_id(),
				'name'     => $item->get_name(),
				'price'    => wc_format_decimal( $order->get_item_subtotal( $item, false, false ), 2 ),
				'currency' => $order->get_currency(),
				'quantity' => (string) $item->get_quantity(),
				'image'    => $this->get_product_image_url( $product ),
				'url'      => $product ? get_permalink( $product->get_id() ) : '',
			);
		}

		return $products;
	}

	/**
	 * Build merchant extra payload for BeyoungerPay.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $traffic Traffic context.
	 *
	 * @return array
	 */
	private function build_extra_payload( WC_Order $order, array $traffic ) {
		return array(
			'plugin' => array(
				'name'    => 'woo-beyounger-payment',
				'version' => WOO_BEYOUNGER_PAYMENT_VERSION,
			),
			'wordpress' => array(
				'site_url' => home_url( '/' ),
				'order_id' => (string) $order->get_id(),
			),
			'traffic' => array(
				'source_type'           => $traffic['source_type'],
				'source'                => $traffic['source'],
				'is_returning_customer' => $traffic['is_returning_customer'],
				'utm'                   => $traffic['utm'],
				'raw_utm'               => $traffic['raw_utm'],
				'referer'               => $traffic['referer'],
				'referer_host'          => $traffic['referer_host'],
			),
		);
	}

	/**
	 * Get product image URL.
	 *
	 * @param WC_Product|false $product WooCommerce product.
	 *
	 * @return string
	 */
	private function get_product_image_url( $product ) {
		if ( ! $product ) {
			return '';
		}

		$image_id = $product->get_image_id();
		if ( ! $image_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $image_id, 'full' );
		return $url ? $url : '';
	}

	/**
	 * Send a form-encoded request to BeyoungerPay.
	 *
	 * @param string $path Endpoint path.
	 * @param array  $payload Request payload.
	 *
	 * @return array|WP_Error
	 */
	private function post_to_beyounger( $path, array $payload ) {
		$url = $this->get_base_url() . $path;

		$this->log( 'POST ' . $url . ' payload: ' . wp_json_encode( $this->redact_payload( $payload ) ), 'debug' );

		return wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
				),
				'body'    => $payload,
			)
		);
	}

	/**
	 * Parse a BeyoungerPay JSON response.
	 *
	 * @param array $response WordPress HTTP response.
	 *
	 * @return array|WP_Error
	 */
	private function parse_response( array $response ) {
		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		$this->log( 'HTTP ' . $code . ' response: ' . $body, 'debug' );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'beyounger_http_error', sprintf( 'BeyoungerPay returned HTTP %d.', $code ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'beyounger_invalid_json', 'BeyoungerPay returned invalid JSON.' );
		}

		return $decoded;
	}

	/**
	 * Apply BeyoungerPay status to WooCommerce order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $status BeyoungerPay status.
	 * @param array    $data BeyoungerPay payload.
	 * @param string   $source Update source.
	 */
	private function apply_beyounger_status( WC_Order $order, $status, array $data, $source ) {
		$transaction_no = isset( $data['transaction_no'] ) ? wc_clean( wp_unslash( $data['transaction_no'] ) ) : '';
		$message        = isset( $data['gateway_message'] ) ? wc_clean( wp_unslash( $data['gateway_message'] ) ) : '';

		switch ( $status ) {
			case 'paid':
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $transaction_no );
				}
				$order->add_order_note( __( 'BeyoungerPay payment confirmed.', 'woo-beyounger-payment' ) );
				break;

			case 'failed':
				if ( ! $order->is_paid() ) {
					$order->update_status( 'failed', $message ? $message : __( 'BeyoungerPay payment failed.', 'woo-beyounger-payment' ) );
				}
				break;

			case 'refunding':
				$order->add_order_note( __( 'BeyoungerPay refund is processing.', 'woo-beyounger-payment' ) );
				break;

			case 'refunded':
				$order->update_status( 'refunded', __( 'BeyoungerPay refund completed.', 'woo-beyounger-payment' ) );
				break;

			case 'complaint':
			case 'chargeback':
				$order->update_status( 'on-hold', sprintf( 'BeyoungerPay status: %s.', $status ) );
				break;

			case 'chargeback_reverse':
				$order->add_order_note( __( 'BeyoungerPay chargeback was reversed.', 'woo-beyounger-payment' ) );
				break;

			case 'pending':
			case 'unpaid':
			default:
				if ( ! $order->is_paid() && ! $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
					$order->update_status( 'pending', __( 'Awaiting BeyoungerPay payment confirmation.', 'woo-beyounger-payment' ) );
				}
				break;
		}

		$order->update_meta_data( '_beyounger_last_status', $status );
		$order->update_meta_data( '_beyounger_last_update_source', $source );
		$order->save();
	}

	/**
	 * Persist transaction data on the order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $merchant_order_id Merchant order ID.
	 * @param array    $data BeyoungerPay data.
	 */
	private function persist_transaction_data( WC_Order $order, $merchant_order_id, array $data ) {
		$order->update_meta_data( '_beyounger_order_id', $merchant_order_id );

		if ( ! empty( $data['transaction_no'] ) ) {
			$order->update_meta_data( '_beyounger_transaction_no', wc_clean( wp_unslash( $data['transaction_no'] ) ) );
		}

		if ( ! empty( $data['status'] ) ) {
			$order->update_meta_data( '_beyounger_last_status', sanitize_key( $data['status'] ) );
		}

		$order->save();
	}

	/**
	 * Verify async notification signature.
	 *
	 * @param array $payload Notification payload.
	 *
	 * @return bool
	 */
	private function verify_notify_signature( array $payload ) {
		$required = array( 'request_time', 'trade_email', 'transaction_no', 'status', 'pay_method', 'gateway_amount', 'gateway_currency', 'sign' );
		foreach ( $required as $field ) {
			if ( ! isset( $payload[ $field ] ) || ( 'gateway_currency' !== $field && '' === (string) $payload[ $field ] ) ) {
				return false;
			}
		}

		if ( $this->get_trade_email() !== (string) $payload['trade_email'] ) {
			return false;
		}

		$expected = $this->make_signature(
			array(
				(string) $payload['request_time'],
				(string) $payload['trade_email'],
				(string) $payload['transaction_no'],
				(string) $payload['status'],
				(string) $payload['pay_method'],
				(string) $payload['gateway_amount'],
				(string) $payload['gateway_currency'],
			),
			$this->get_api_key_for_sandbox_flag( isset( $payload['sandbox'] ) ? (string) $payload['sandbox'] : null )
		);

		return hash_equals( strtolower( $expected ), strtolower( (string) $payload['sign'] ) );
	}

	/**
	 * Create a SHA-256 BeyoungerPay signature.
	 *
	 * @param array       $parts Fields after api key.
	 * @param string|null $api_key Optional API key override.
	 *
	 * @return string
	 */
	private function make_signature( array $parts, $api_key = null ) {
		$api_key = null === $api_key ? $this->get_api_key() : $api_key;
		return hash( 'sha256', $api_key . implode( '', $parts ) );
	}

	/**
	 * Read request payload from POST.
	 *
	 * @return array
	 */
	private function get_request_payload() {
		$payload = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Make merchant order ID.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return string
	 */
	private function make_merchant_order_id( WC_Order $order ) {
		$attempt = absint( $order->get_meta( '_beyounger_payment_attempt', true ) );
		$attempt++;
		$order->update_meta_data( '_beyounger_payment_attempt', $attempt );

		return 'WC' . $order->get_id() . 'A' . $attempt;
	}

	/**
	 * Find order by Beyounger merchant order ID.
	 *
	 * @param string $merchant_order_id Merchant order ID.
	 *
	 * @return WC_Order|false
	 */
	private function get_order_by_beyounger_order_id( $merchant_order_id ) {
		if ( preg_match( '/^WC(\d+)(?:A\d+)?$/', $merchant_order_id, $matches ) ) {
			$order = wc_get_order( (int) $matches[1] );
			if ( $order ) {
				return $order;
			}
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_beyounger_order_id',
				'meta_value' => $merchant_order_id,
				'return'     => 'objects',
			)
		);

		return ! empty( $orders ) ? $orders[0] : false;
	}

	/**
	 * Get base API URL.
	 *
	 * @return string
	 */
	private function get_base_url() {
		return $this->is_sandbox() ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
	}

	/**
	 * Whether gateway is in sandbox mode.
	 *
	 * @return bool
	 */
	private function is_sandbox() {
		return 'sandbox' === $this->get_option( 'environment', 'sandbox' );
	}

	/**
	 * Merchant email.
	 *
	 * @return string
	 */
	private function get_trade_email() {
		return sanitize_email( $this->get_option( 'trade_email', '' ) );
	}

	/**
	 * Public base URL override for local tunnel testing.
	 *
	 * @return string
	 */
	private function get_public_base_url() {
		$url = esc_url_raw( trim( (string) $this->get_option( 'public_base_url', '' ) ) );
		if ( '' === $url ) {
			return '';
		}

		return untrailingslashit( $url );
	}

	/**
	 * BeyoungerPay asynchronous notification URL.
	 *
	 * @return string
	 */
	private function get_notify_url() {
		$public_base_url = $this->get_public_base_url();
		if ( '' !== $public_base_url ) {
			return trailingslashit( $public_base_url ) . 'wc-api/wc_gateway_beyounger/';
		}

		return WC()->api_request_url( 'wc_gateway_beyounger' );
	}

	/**
	 * Browser return URL after BeyoungerPay payment handling.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return string
	 */
	private function get_callback_url( WC_Order $order ) {
		$payment_url     = wc_get_endpoint_url( 'order-pay', $order->get_id(), wc_get_checkout_url() );
		$return_url      = add_query_arg(
			array(
				'pay_for_order' => 'true',
				'key'           => $order->get_order_key(),
			),
			$payment_url
		);
		$public_base_url = $this->get_public_base_url();
		if ( '' === $public_base_url ) {
			return $return_url;
		}

		return preg_replace( '#^https?://[^/]+#', $public_base_url, $return_url );
	}

	/**
	 * Hosted card form data for classic checkout and Checkout Blocks.
	 *
	 * @return array
	 */
	public function get_card_form_context() {
		$request_time = (string) time();
		$order_id     = $this->get_or_create_card_form_order_id();
		$amount       = $this->get_checkout_amount();
		$currency     = get_woocommerce_currency();
		$pay_method   = $this->get_pay_method();
		$origin       = $this->get_origin_url();

		$args = array(
			'request_time' => $request_time,
			'trade_email'  => $this->get_trade_email(),
			'order_id'     => $order_id,
			'pay_method'   => $pay_method,
			'amount'       => $amount,
			'currency'     => $currency,
			'origin'       => $origin,
			'sandbox'      => $this->is_sandbox() ? '1' : '0',
		);

		if ( $this->is_card_gateway() && '2' === $this->get_customer_type_flag() ) {
			$args['allowed_card_brand'] = 'visa';
		}

		$args['sign'] = $this->make_signature(
			array(
				$request_time,
				$args['trade_email'],
				$order_id,
				$pay_method,
				$amount,
				$currency,
			)
		);

		return array(
			'url'             => add_query_arg( $args, self::CARD_FORM_ORIGIN . self::CARD_FORM_PATH ),
			'order_id'        => $order_id,
			'allowed_origins' => array_values(
				array_unique(
					array_filter(
						array(
							self::CARD_FORM_ORIGIN,
							'https://cashier-sandbox.beyounger.com',
							$this->get_base_origin(),
						)
					)
				)
			),
		);
	}

	/**
	 * Whether this gateway instance is the credit card method.
	 *
	 * @return bool
	 */
	private function is_card_gateway() {
		return 'card' === $this->variant_key;
	}

	/**
	 * Get the checkout amount for pre-order card tokenization.
	 *
	 * @return string
	 */
	private function get_checkout_amount() {
		if ( WC()->cart ) {
			return wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 );
		}

		return wc_format_decimal( 0, 2 );
	}

	/**
	 * Origin that Beyounger card form may postMessage back to.
	 *
	 * @return string
	 */
	private function get_origin_url() {
		$current_origin = $this->get_current_request_origin();
		if ( '' !== $current_origin ) {
			return $current_origin;
		}

		$url   = home_url( '/' );
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return home_url( '/' );
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	/**
	 * Origin for the browser request currently rendering checkout.
	 *
	 * @return string
	 */
	private function get_current_request_origin() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( '' === $host || ! preg_match( '/^[A-Za-z0-9.-]+(?::[0-9]+)?$/', $host ) ) {
			return '';
		}

		$scheme = is_ssl() ? 'https' : 'http';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			$forwarded_proto = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			$forwarded_proto = trim( explode( ',', $forwarded_proto )[0] );
			if ( in_array( $forwarded_proto, array( 'http', 'https' ), true ) ) {
				$scheme = $forwarded_proto;
			}
		}

		return $scheme . '://' . $host;
	}

	/**
	 * Origin for the current Beyounger API base URL.
	 *
	 * @return string
	 */
	private function get_base_origin() {
		$parts = wp_parse_url( $this->get_base_url() );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		return $parts['scheme'] . '://' . $parts['host'];
	}

	/**
	 * Get or create the merchant order ID used by the card iframe.
	 *
	 * @return string
	 */
	private function get_or_create_card_form_order_id() {
		$order_id = 'WCT' . gmdate( 'YmdHis' ) . strtoupper( wp_generate_password( 8, false, false ) );
		if ( WC()->session ) {
			WC()->session->set( 'beyounger_card_form_order_id', $order_id );
		}

		return $order_id;
	}

	/**
	 * Clear tokenization order ID after a payment attempt leaves checkout.
	 */
	private function clear_card_form_order_id() {
		if ( WC()->session ) {
			WC()->session->__unset( 'beyounger_card_form_order_id' );
		}
	}

	/**
	 * Submitted iframe order ID.
	 *
	 * @return string
	 */
	private function get_submitted_card_form_order_id() {
		$order_id = '';

		if ( isset( $_POST['beyounger_card_order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$order_id = sanitize_text_field( wp_unslash( $_POST['beyounger_card_order_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( '' === $order_id && WC()->session ) {
			$session_order_id = WC()->session->get( 'beyounger_card_form_order_id' );
			$order_id         = is_string( $session_order_id ) ? $session_order_id : '';
		}

		return $this->is_valid_card_form_order_id( $order_id ) ? $order_id : '';
	}

	/**
	 * Validate generated tokenization order IDs.
	 *
	 * @param string $order_id Candidate order ID.
	 *
	 * @return bool
	 */
	private function is_valid_card_form_order_id( $order_id ) {
		return 1 === preg_match( '/^WCT[A-Z0-9]{12,32}$/', (string) $order_id );
	}

	/**
	 * Read tokenized card value from classic or block checkout request data.
	 *
	 * @return string
	 */
	private function get_card_token_from_request() {
		$token = '';

		if ( isset( $_POST['card'] ) && is_array( $_POST['card'] ) && isset( $_POST['card']['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token = sanitize_text_field( wp_unslash( $_POST['card']['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( '' === $token && isset( $_POST['cardtoken'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token = sanitize_text_field( wp_unslash( $_POST['cardtoken'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( '' === $token && isset( $_POST['beyounger_card_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token = sanitize_text_field( wp_unslash( $_POST['beyounger_card_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		return $token;
	}

	/**
	 * Current environment API key.
	 *
	 * @return string
	 */
	private function get_api_key() {
		return $this->is_sandbox() ? $this->get_option( 'sandbox_api_key', '' ) : $this->get_option( 'live_api_key', '' );
	}

	/**
	 * API key selected by notification sandbox flag.
	 *
	 * @param string|null $sandbox Sandbox flag from notification.
	 *
	 * @return string
	 */
	private function get_api_key_for_sandbox_flag( $sandbox ) {
		if ( '1' === $sandbox ) {
			return $this->get_option( 'sandbox_api_key', '' );
		}

		if ( '0' === $sandbox ) {
			return $this->get_option( 'live_api_key', '' );
		}

		return $this->get_api_key();
	}

	/**
	 * BeyoungerPay payment method code.
	 *
	 * @return string
	 */
	private function get_pay_method() {
		return sanitize_text_field( apply_filters( 'woo_beyounger_payment_pay_method', 'C01', $this->variant_key, $this ) );
	}

	/**
	 * BeyoungerPay cashier method type.
	 *
	 * @return string
	 */
	private function get_method_type() {
		return sanitize_text_field( apply_filters( 'woo_beyounger_payment_method_type', $this->method_type, $this->variant_key, $this ) );
	}

	/**
	 * BeyoungerPay request type.
	 *
	 * @return string
	 */
	private function get_request_type() {
		return sanitize_text_field( apply_filters( 'woo_beyounger_payment_request_type', $this->request_type, $this->variant_key, $this ) );
	}

	/**
	 * BeyoungerPay 3DS mode.
	 *
	 * @return string
	 */
	private function get_three_ds_mode() {
		return sanitize_text_field( apply_filters( 'woo_beyounger_payment_three_ds_mode', '1', $this->variant_key, $this ) );
	}

	/**
	 * Icon URL for block checkout integration.
	 *
	 * @return string
	 */
	public function get_icon_url() {
		if ( $this->is_card_gateway() && '2' === $this->get_customer_type_flag() ) {
			return plugins_url( 'assets/visa.svg', WOO_BEYOUNGER_PAYMENT_FILE );
		}
		return plugins_url( 'assets/' . $this->icon_file, WOO_BEYOUNGER_PAYMENT_FILE );
	}

	/**
	 * Resolve the classic checkout icon using the current visitor context.
	 */
	public function get_icon() {
		$this->icon = $this->get_icon_url();
		return parent::get_icon();
	}

	/**
	 * Shared CUSTOM_FD14 classification for icons, card forms and payments.
	 *
	 * @param bool|null $is_returning_customer Previously resolved eligibility, if available.
	 * @return string
	 */
	private function get_customer_type_flag( $is_returning_customer = null ) {
		if ( null === $is_returning_customer ) {
			$is_returning_customer = $this->is_eligible_returning_customer();
		}
		return $is_returning_customer ? '1' : ( $this->is_standard_channel() && ! $this->matches_utm_whitelist() ? '2' : '0' );
	}

	/**
	 * Order amount limits for block checkout.
	 *
	 * @return array
	 */
	public function get_order_amount_limits() {
		return array(
			'min' => $this->get_configured_amount( 'min_order_amount_' . $this->variant_key, '0' ),
			'max' => $this->get_configured_amount( 'max_order_amount_' . $this->variant_key, '500' ),
		);
	}

	/**
	 * Check whether current visitor matches the UTM whitelist.
	 *
	 * @return bool
	 */
	private function passes_utm_gate() {
		if ( $this->is_eligible_returning_customer() ) {
			return true;
		}

		return $this->matches_utm_whitelist();
	}

	/**
	 * Whether this method allows visitors outside the preferred traffic rules.
	 *
	 * @return bool
	 */
	private function is_standard_channel() {
		return 'standard' === $this->get_option( 'channel_' . $this->variant_key, 'preferred' );
	}

	/**
	 * Check UTM whitelist membership independently of customer history.
	 *
	 * @return bool
	 */
	private function matches_utm_whitelist() {
		$rules = $this->parse_utm_whitelist( $this->get_option( 'utm_whitelist', '' ) );
		if ( empty( $rules ) ) {
			return false;
		}

		$utm = function_exists( 'woo_beyounger_payment_get_current_utm' ) ? woo_beyounger_payment_get_current_utm() : array();
		if ( empty( $utm ) ) {
			return false;
		}

		foreach ( $rules as $rule ) {
			if ( $this->utm_rule_matches( $rule, $utm ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the current order amount is inside this method's configured range.
	 *
	 * @return bool
	 */
	private function passes_order_amount_gate( $order = null ) {
		$min = $this->get_configured_amount( 'min_order_amount_' . $this->variant_key, '0' );
		$max = $this->get_configured_amount( 'max_order_amount_' . $this->variant_key, '500' );

		if ( null === $min && null === $max ) {
			return true;
		}

		$total = $this->get_current_order_total( $order );
		if ( null === $total ) {
			return true;
		}

		if ( null !== $min && $total < $min ) {
			return false;
		}

		if ( null !== $max && $total > $max ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether this payment method is inside the daily successful payment limit.
	 *
	 * @return bool
	 */
	private function passes_daily_successful_payment_limit() {
		$limit = $this->get_configured_amount( 'successful_payment_limit_' . $this->variant_key, '5000' );
		if ( null === $limit ) {
			return true;
		}

		return $this->get_method_daily_successful_payment_total() <= $limit;
	}

	/**
	 * Get today's successful paid total for this BeyoungerPay method.
	 *
	 * @return float
	 */
	private function get_method_daily_successful_payment_total() {
		$args = array(
			'limit'      => -1,
			'status'     => array( 'wc-processing', 'wc-completed' ),
			'date_paid'  => $this->get_today_date_range_query(),
			'meta_key'   => '_beyounger_payment_method',
			'meta_value' => $this->variant_key,
			'return'     => 'objects',
		);

		return $this->sum_successful_orders( wc_get_orders( $args ) );
	}

	/**
	 * Current cart total used for checkout visibility decisions.
	 *
	 * @return float|null
	 */
	private function get_current_order_total( $order = null ) {
		if ( $order instanceof WC_Order ) {
			return (float) $order->get_total();
		}

		if ( ! WC()->cart ) {
			return null;
		}

		return (float) wc_format_decimal( WC()->cart->get_total( 'edit' ), wc_get_price_decimals() );
	}

	/**
	 * Read a non-negative configured amount.
	 *
	 * @param string $key Setting key.
	 * @param string $default Default value when the setting has never been saved.
	 *
	 * @return float|null
	 */
	private function get_configured_amount( $key, $default = '' ) {
		$value = trim( (string) $this->get_option( $key, $default ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$amount = (float) $value;
		return $amount >= 0 ? $amount : null;
	}

	/**
	 * Today's local date range for WooCommerce order queries.
	 *
	 * @return string
	 */
	private function get_today_date_range_query() {
		$today = current_time( 'Y-m-d' );

		return $today . ' 00:00:00...' . $today . ' 23:59:59';
	}

	/**
	 * Sum net paid total for WooCommerce orders.
	 *
	 * @param array $orders Orders.
	 *
	 * @return float
	 */
	private function sum_successful_orders( array $orders ) {
		$total = 0.0;

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$total += $this->get_order_net_paid_total( $order );
			}
		}

		return $total;
	}

	/**
	 * Order total after refunds.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return float
	 */
	private function get_order_net_paid_total( WC_Order $order ) {
		return max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
	}

	/**
	 * Returning customers bypass UTM gating after 30 days when the qualifying order has no refunds.
	 *
	 * @return bool
	 */
	private function is_eligible_returning_customer() {
		$args       = array(
			'limit'        => 10,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'status'       => array( 'wc-processing', 'wc-completed' ),
			'date_created' => '<=' . gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			'return'       => 'objects',
		);
		$customer_id = get_current_user_id();
		$email       = $this->get_current_customer_email();

		if ( $customer_id > 0 ) {
			$args['customer_id'] = $customer_id;
		} elseif ( '' !== $email ) {
			$args['billing_email'] = $email;
		} else {
			return false;
		}

		$orders = wc_get_orders( $args );

		if ( empty( $orders ) && $customer_id > 0 && '' !== $email ) {
			unset( $args['customer_id'] );
			$args['billing_email'] = $email;
			$orders                = wc_get_orders( $args );
		}

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order && ! $this->order_has_refund( $order ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the best available customer email during checkout.
	 *
	 * @return string
	 */
	private function get_current_customer_email() {
		if ( isset( $_POST['billing_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_email( wp_unslash( $_POST['billing_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			parse_str( wp_unslash( $_POST['post_data'] ), $post_data ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! empty( $post_data['billing_email'] ) ) {
				return sanitize_email( $post_data['billing_email'] );
			}
		}

		if ( WC()->customer && WC()->customer->get_billing_email() ) {
			return sanitize_email( WC()->customer->get_billing_email() );
		}

		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			$billing_email = get_user_meta( $user->ID, 'billing_email', true );
			return sanitize_email( $billing_email ? $billing_email : $user->user_email );
		}

		return '';
	}

	/**
	 * Whether an order has any refund activity.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return bool
	 */
	private function order_has_refund( WC_Order $order ) {
		if ( (float) $order->get_total_refunded() > 0 ) {
			return true;
		}

		return ! empty( $order->get_refunds() );
	}

	/**
	 * Persist the traffic decision context on the WooCommerce order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function persist_traffic_context( WC_Order $order, array $context ) {
		$order->update_meta_data( '_beyounger_traffic_source_type', $context['source_type'] );
		$order->update_meta_data( '_beyounger_traffic_source', $context['source'] );
		$order->update_meta_data( '_beyounger_is_returning_customer', $context['is_returning_customer'] ? 'yes' : 'no' );
		$order->update_meta_data( '_beyounger_cus_fd14', $context['custom_fd14'] );
		$order->update_meta_data( '_beyounger_has_referer', '' !== $context['referer'] ? 'yes' : 'no' );

		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
			if ( isset( $context['utm'][ $key ] ) && '' !== $context['utm'][ $key ] ) {
				$order->update_meta_data( '_beyounger_' . $key, $context['utm'][ $key ] );
			} else {
				$order->delete_meta_data( '_beyounger_' . $key );
			}
		}

		if ( ! empty( $context['utm'] ) ) {
			$order->update_meta_data( '_beyounger_utm', wp_json_encode( $context['utm'] ) );
		} else {
			$order->delete_meta_data( '_beyounger_utm' );
		}

		if ( ! empty( $context['raw_utm'] ) ) {
			$order->update_meta_data( '_beyounger_raw_utm', wp_json_encode( $context['raw_utm'] ) );
			if ( ! empty( $context['raw_utm']['utm_source'] ) ) {
				$order->update_meta_data( '_beyounger_raw_utm_source', $context['raw_utm']['utm_source'] );
			} else {
				$order->delete_meta_data( '_beyounger_raw_utm_source' );
			}
		} else {
			$order->delete_meta_data( '_beyounger_raw_utm' );
			$order->delete_meta_data( '_beyounger_raw_utm_source' );
		}

		if ( '' !== $context['referer'] ) {
			$order->update_meta_data( '_beyounger_referer', $context['referer'] );
			$order->update_meta_data( '_beyounger_referer_host', $context['referer_host'] );
		} else {
			$order->delete_meta_data( '_beyounger_referer' );
			$order->delete_meta_data( '_beyounger_referer_host' );
		}
	}

	/**
	 * Build the traffic source snapshot for this checkout attempt.
	 *
	 * @return array
	 */
	private function build_traffic_context() {
		$utm                  = function_exists( 'woo_beyounger_payment_get_current_utm' ) ? woo_beyounger_payment_get_current_utm() : array();
		$raw_utm              = function_exists( 'woo_beyounger_payment_get_current_raw_utm' ) ? woo_beyounger_payment_get_current_raw_utm() : $utm;
		$referer              = function_exists( 'woo_beyounger_payment_get_current_referer' ) ? woo_beyounger_payment_get_current_referer() : '';
		$is_returning_customer = $this->is_eligible_returning_customer();
		$custom_fd14          = $this->get_customer_type_flag( $is_returning_customer );
		$source_type          = 'unknown';
		$source               = 'unknown';
		$raw_utm              = array_map( 'sanitize_text_field', is_array( $raw_utm ) ? $raw_utm : array() );
		$utm                  = array_map( 'sanitize_text_field', is_array( $utm ) ? $utm : array() );

		if ( $is_returning_customer ) {
			$source_type = 'returning_customer';
			$source      = ! empty( $raw_utm['utm_source'] ) ? $raw_utm['utm_source'] : 'returning_customer';
		} elseif ( ! empty( $utm['utm_source'] ) ) {
			$source_type = 'utm_source';
			$source      = $utm['utm_source'];
		} elseif ( '' !== $referer ) {
			$source_type = 'referer';
			$source      = (string) wp_parse_url( $referer, PHP_URL_HOST );
		}

		return array(
			'source_type'           => $source_type,
			'source'                => $source,
			'is_returning_customer' => $is_returning_customer,
			'custom_fd14'           => $custom_fd14,
			'utm'                   => $utm,
			'raw_utm'               => $raw_utm,
			'referer'               => $referer,
			'referer_host'          => '' !== $referer ? (string) wp_parse_url( $referer, PHP_URL_HOST ) : '',
		);
	}

	/**
	 * Parse admin UTM whitelist text.
	 *
	 * @param string $raw Raw textarea value.
	 *
	 * @return array
	 */
	private function parse_utm_whitelist( $raw ) {
		$rules = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );

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

				if ( 0 !== strpos( $key, 'utm_' ) || '' === $value ) {
					continue;
				}

				$conditions[ $key ] = sanitize_text_field( $value );
			}

			if ( ! empty( $conditions ) ) {
				$rules[] = $conditions;
			}
		}

		return $rules;
	}

	/**
	 * Build admin help text and test links for UTM settings.
	 *
	 * @return string
	 */
	private function get_utm_whitelist_description() {
		$description = __( 'Enter one source per line. Example: google. The plugin will treat it as utm_source=google. Advanced rules are also supported, for example utm_source=google&utm_campaign=spring or partner-*.', 'woo-beyounger-payment' );
		$rules       = $this->parse_utm_whitelist( $this->get_option( 'utm_whitelist', '' ) );

		if ( empty( $rules ) ) {
			$example = add_query_arg( 'utm_source', 'google', home_url( '/' ) );
			return $description . '<br><br>' . sprintf(
				/* translators: %s: example URL */
				__( 'Example test link: %s', 'woo-beyounger-payment' ),
				'<code>' . esc_html( $example ) . '</code>'
			);
		}

		$links = array();
		foreach ( $rules as $rule ) {
			$url = home_url( '/' );
			foreach ( $rule as $key => $value ) {
				$url = add_query_arg( $key, $value, $url );
			}
			$links[] = '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></li>';
		}

		return $description . '<br><br>' . __( 'Current test links:', 'woo-beyounger-payment' ) . '<ul>' . implode( '', $links ) . '</ul>';
	}

	/**
	 * Match a single UTM rule against captured UTM values.
	 *
	 * @param array $rule Rule conditions.
	 * @param array $utm Current UTM values.
	 *
	 * @return bool
	 */
	private function utm_rule_matches( array $rule, array $utm ) {
		foreach ( $rule as $key => $expected ) {
			if ( ! isset( $utm[ $key ] ) || ! $this->utm_value_matches( $expected, $utm[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Match UTM value with optional wildcard support.
	 *
	 * @param string $expected Expected value.
	 * @param string $actual Actual value.
	 *
	 * @return bool
	 */
	private function utm_value_matches( $expected, $actual ) {
		$expected = strtolower( (string) $expected );
		$actual   = strtolower( (string) $actual );

		if ( false === strpos( $expected, '*' ) ) {
			return $expected === $actual;
		}

		$pattern = '/^' . str_replace( '\*', '.*', preg_quote( $expected, '/' ) ) . '$/';

		return 1 === preg_match( $pattern, $actual );
	}

	/**
	 * Whether this class owns the shared settings screen.
	 *
	 * @return bool
	 */
	private function is_primary_gateway() {
		return 'beyounger' === $this->id;
	}

	/**
	 * Localized default title.
	 *
	 * @return string
	 */
	private function get_default_title() {
		return translate( $this->default_title, 'woo-beyounger-payment' );
	}

	/**
	 * Localized default description.
	 *
	 * @return string
	 */
	private function get_default_description() {
		return translate( $this->default_description, 'woo-beyounger-payment' );
	}

	/**
	 * Redact sensitive payload values before logging.
	 *
	 * @param array $payload Payload.
	 *
	 * @return array
	 */
	private function redact_payload( array $payload ) {
		$redacted = $payload;
		if ( isset( $redacted['sign'] ) ) {
			$redacted['sign'] = '[redacted]';
		}
		if ( isset( $redacted['card[token]'] ) ) {
			$redacted['card[token]'] = '[redacted]';
		}
		if ( isset( $redacted['card'] ) ) {
			$redacted['card'] = '[redacted]';
		}

		return $redacted;
	}

	/**
	 * Log a message when debug is enabled.
	 *
	 * @param string $message Message.
	 * @param string $level Log level.
	 */
	private function log( $message, $level = 'info' ) {
		if ( ! $this->debug && 'error' !== $level && 'warning' !== $level ) {
			return;
		}

		if ( null === $this->logger ) {
			$this->logger = wc_get_logger();
		}

		$this->logger->log(
			$level,
			$message,
			array(
				'source' => 'woo-beyounger-payment',
			)
		);
	}
}

/**
 * PayPal method via BeyoungerPay.
 */
class WC_Gateway_Beyounger_PayPal extends WC_Gateway_Beyounger {

	/**
	 * WooCommerce gateway ID.
	 *
	 * @var string
	 */
	protected $gateway_id = 'beyounger_paypal';

	/**
	 * Internal payment method key.
	 *
	 * @var string
	 */
	protected $variant_key = 'paypal';

	/**
	 * BeyoungerPay method_type value.
	 *
	 * @var string
	 */
	protected $method_type = '2';

	/**
	 * BeyoungerPay request_type value.
	 *
	 * @var string
	 */
	protected $request_type = '1';

	/**
	 * Default customer-facing title.
	 *
	 * @var string
	 */
	protected $default_title = 'PayPal';

	/**
	 * Default customer-facing description.
	 *
	 * @var string
	 */
	protected $default_description = 'Pay via PayPal through BeyoungerPay.';

	/**
	 * Logo asset filename.
	 *
	 * @var string
	 */
	protected $icon_file = 'paypal.svg';
}

/**
 * Google Pay method via BeyoungerPay.
 */
class WC_Gateway_Beyounger_Google_Pay extends WC_Gateway_Beyounger {

	/**
	 * WooCommerce gateway ID.
	 *
	 * @var string
	 */
	protected $gateway_id = 'beyounger_google_pay';

	/**
	 * Internal payment method key.
	 *
	 * @var string
	 */
	protected $variant_key = 'google_pay';

	/**
	 * BeyoungerPay method_type value.
	 *
	 * @var string
	 */
	protected $method_type = '5';

	/**
	 * BeyoungerPay request_type value.
	 *
	 * @var string
	 */
	protected $request_type = '1';

	/**
	 * Default customer-facing title.
	 *
	 * @var string
	 */
	protected $default_title = 'Google Pay';

	/**
	 * Default customer-facing description.
	 *
	 * @var string
	 */
	protected $default_description = 'Pay with Google Pay through BeyoungerPay.';

	/**
	 * Logo asset filename.
	 *
	 * @var string
	 */
	protected $icon_file = 'google-pay.svg';
}

/**
 * Cash App method via BeyoungerPay.
 */
class WC_Gateway_Beyounger_Cash_App extends WC_Gateway_Beyounger {

	/**
	 * WooCommerce gateway ID.
	 *
	 * @var string
	 */
	protected $gateway_id = 'beyounger_cash_app';

	/**
	 * Internal payment method key.
	 *
	 * @var string
	 */
	protected $variant_key = 'cash_app';

	/**
	 * BeyoungerPay method_type value.
	 *
	 * @var string
	 */
	protected $method_type = '8';

	/**
	 * BeyoungerPay request_type value.
	 *
	 * @var string
	 */
	protected $request_type = '1';

	/**
	 * Default customer-facing title.
	 *
	 * @var string
	 */
	protected $default_title = 'Cash App';

	/**
	 * Default customer-facing description.
	 *
	 * @var string
	 */
	protected $default_description = 'Pay with Cash App through BeyoungerPay.';

	/**
	 * Logo asset filename.
	 *
	 * @var string
	 */
	protected $icon_file = 'cash-app.svg';
}

/**
 * Apple Pay method via BeyoungerPay.
 */
class WC_Gateway_Beyounger_Apple_Pay extends WC_Gateway_Beyounger {

	/**
	 * WooCommerce gateway ID.
	 *
	 * @var string
	 */
	protected $gateway_id = 'beyounger_apple_pay';

	/**
	 * Internal payment method key.
	 *
	 * @var string
	 */
	protected $variant_key = 'apple_pay';

	/**
	 * BeyoungerPay method_type value.
	 *
	 * @var string
	 */
	protected $method_type = '9';

	/**
	 * BeyoungerPay request_type value.
	 *
	 * @var string
	 */
	protected $request_type = '1';

	/**
	 * Default customer-facing title.
	 *
	 * @var string
	 */
	protected $default_title = 'Apple Pay';

	/**
	 * Default customer-facing description.
	 *
	 * @var string
	 */
	protected $default_description = 'Pay with Apple Pay through BeyoungerPay.';

	/**
	 * Logo asset filename.
	 *
	 * @var string
	 */
	protected $icon_file = 'apple-pay.svg';
}

/**
 * Card to crypto method via BeyoungerPay.
 */
class WC_Gateway_Beyounger_Card_To_Crypto extends WC_Gateway_Beyounger {

	/**
	 * WooCommerce gateway ID.
	 *
	 * @var string
	 */
	protected $gateway_id = 'beyounger_card_to_crypto';

	/**
	 * Internal payment method key.
	 *
	 * @var string
	 */
	protected $variant_key = 'card_to_crypto';

	/**
	 * BeyoungerPay method_type value.
	 *
	 * @var string
	 */
	protected $method_type = '10';

	/**
	 * BeyoungerPay request_type value.
	 *
	 * @var string
	 */
	protected $request_type = '1';

	/**
	 * Default customer-facing title.
	 *
	 * @var string
	 */
	protected $default_title = 'Card to crypto';

	/**
	 * Default customer-facing description.
	 *
	 * @var string
	 */
	protected $default_description = 'Pay with Card to crypto through BeyoungerPay.';

	/**
	 * Logo asset filename.
	 *
	 * @var string
	 */
	protected $icon_file = 'card.svg';
}
