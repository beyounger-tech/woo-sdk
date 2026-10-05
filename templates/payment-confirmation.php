<?php
/** Browser return while BeyoungerPay's webhook may still be in flight. */
defined( 'ABSPATH' ) || exit;

$item_count = 0;
foreach ( $order->get_items( 'line_item' ) as $item ) {
	$item_count += (int) $item->get_quantity();
}
$payment_method = $order->get_payment_method_title();
if ( '' === $payment_method ) {
	$payment_method = __( 'Online payment', 'woo-beyounger-payment' );
}

get_header( 'shop' );
?>
<main class="woocommerce beyounger-payment-confirmation" data-state="checking">
	<div class="beyounger-confirmation-card">
		<section class="beyounger-confirmation-intro" aria-labelledby="beyounger-confirmation-title">
			<div class="beyounger-confirmation-status-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
				<span class="beyounger-confirmation-status-symbol"></span>
			</div>
			<span class="beyounger-confirmation-status-label" id="beyounger-confirmation-label"><?php esc_html_e( 'Payment check in progress', 'woo-beyounger-payment' ); ?></span>
			<h1 id="beyounger-confirmation-title"><?php esc_html_e( 'Confirming your payment', 'woo-beyounger-payment' ); ?></h1>
			<p id="beyounger-confirmation-message"><?php esc_html_e( 'We are checking the latest status of your order. Please keep this page open and avoid paying again while we confirm it.', 'woo-beyounger-payment' ); ?></p>
		</section>

		<section class="beyounger-confirmation-details" aria-labelledby="beyounger-confirmation-details-title">
			<h2 id="beyounger-confirmation-details-title"><?php esc_html_e( 'Order summary', 'woo-beyounger-payment' ); ?></h2>
			<dl>
				<div><dt><?php esc_html_e( 'Order number', 'woo-beyounger-payment' ); ?></dt><dd><?php echo esc_html( $order->get_order_number() ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Total', 'woo-beyounger-payment' ); ?></dt><dd><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Items', 'woo-beyounger-payment' ); ?></dt><dd><?php echo esc_html( (string) $item_count ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Payment method', 'woo-beyounger-payment' ); ?></dt><dd><?php echo esc_html( $payment_method ); ?></dd></div>
			</dl>
		</section>

		<section class="beyounger-confirmation-result" aria-labelledby="beyounger-confirmation-result-title">
			<h2 id="beyounger-confirmation-result-title"><?php esc_html_e( 'Payment status', 'woo-beyounger-payment' ); ?></h2>
			<p id="beyounger-confirmation-progress" role="status" aria-live="polite"><?php esc_html_e( 'Checking order status…', 'woo-beyounger-payment' ); ?></p>
			<div class="beyounger-confirmation-actions">
				<button type="button" class="button" id="beyounger-confirmation-recheck" hidden><?php esc_html_e( 'Check again', 'woo-beyounger-payment' ); ?></button>
				<a class="button" id="beyounger-confirmation-retry" hidden><?php esc_html_e( 'Try payment again', 'woo-beyounger-payment' ); ?></a>
			</div>
		</section>

		<div class="beyounger-confirmation-help">
			<strong><?php esc_html_e( 'What happens next?', 'woo-beyounger-payment' ); ?></strong>
			<p><?php esc_html_e( 'Once payment is confirmed, you will be taken to your order confirmation. If the status does not update, use “Check again”.', 'woo-beyounger-payment' ); ?></p>
		</div>
	</div>
</main>
<script>
(function () {
	var config = <?php echo wp_json_encode( array(
		'url' => admin_url( 'admin-ajax.php' ),
		'orderId' => $order->get_id(),
		'key' => $order->get_order_key(),
		'checking' => __( 'Checking order status…', 'woo-beyounger-payment' ),
		'failed' => __( 'Payment was not completed. You can try again.', 'woo-beyounger-payment' ),
		'unavailable' => __( 'This order can no longer be paid. Please contact the store for help.', 'woo-beyounger-payment' ),
		'unknown' => __( 'We cannot confirm the payment yet. Please check again before trying to pay.', 'woo-beyounger-payment' ),
		'paid' => __( 'Payment confirmed. Redirecting to your order…', 'woo-beyounger-payment' ),
		'labels' => array(
			'checking' => __( 'Payment check in progress', 'woo-beyounger-payment' ),
			'failed' => __( 'Payment not completed', 'woo-beyounger-payment' ),
			'unavailable' => __( 'Order unavailable', 'woo-beyounger-payment' ),
			'unknown' => __( 'Still confirming', 'woo-beyounger-payment' ),
			'paid' => __( 'Payment confirmed', 'woo-beyounger-payment' ),
		),
		'titles' => array(
			'checking' => __( 'Confirming your payment', 'woo-beyounger-payment' ),
			'failed' => __( 'Payment not completed', 'woo-beyounger-payment' ),
			'unavailable' => __( 'This order is unavailable', 'woo-beyounger-payment' ),
			'unknown' => __( 'Confirmation is taking longer', 'woo-beyounger-payment' ),
			'paid' => __( 'Payment confirmed', 'woo-beyounger-payment' ),
		),
		'messages' => array(
			'checking' => __( 'We are checking the latest status of your order. Please keep this page open and avoid paying again while we confirm it.', 'woo-beyounger-payment' ),
			'failed' => __( 'Your order has not been paid. You can safely start another payment attempt.', 'woo-beyounger-payment' ),
			'unavailable' => __( 'This order cannot accept another payment. Please contact the store if you need help.', 'woo-beyounger-payment' ),
			'unknown' => __( 'We are still waiting for the final payment result. Check again before starting another payment.', 'woo-beyounger-payment' ),
			'paid' => __( 'Your payment is complete. We are taking you to your order confirmation.', 'woo-beyounger-payment' ),
		),
	), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
	var page = document.querySelector('.beyounger-payment-confirmation');
	var label = document.getElementById('beyounger-confirmation-label');
	var title = document.getElementById('beyounger-confirmation-title');
	var message = document.getElementById('beyounger-confirmation-message');
	var progress = document.getElementById('beyounger-confirmation-progress');
	var recheck = document.getElementById('beyounger-confirmation-recheck');
	var retry = document.getElementById('beyounger-confirmation-retry');
	var started = Date.now();
	var timer;

	function setState(state) {
		page.setAttribute('data-state', state);
		label.textContent = config.labels[state];
		title.textContent = config.titles[state];
		message.textContent = config.messages[state];
		progress.textContent = config[state];
		recheck.hidden = state !== 'unknown';
		retry.hidden = state !== 'failed';
	}

	function check() {
		var body = new URLSearchParams({ action: 'beyounger_order_status', order_id: String(config.orderId), key: config.key });
		fetch(config.url, { method: 'POST', credentials: 'same-origin', body: body, cache: 'no-store' })
			.then(function (response) { return response.json(); })
			.then(function (response) {
				if (!response.success || !response.data) { throw new Error('Status unavailable'); }
				if (response.data.status === 'paid') {
					setState('paid');
					window.location.replace(response.data.redirect);
					return;
				}
				if (response.data.status === 'failed') {
					if (response.data.redirect) {
						retry.href = response.data.redirect;
						setState('failed');
					} else {
						setState('unavailable');
					}
					return;
				}
				if (response.data.status === 'unavailable') {
					setState('unavailable');
					return;
				}
				schedule();
			}).catch(schedule);
	}

	function schedule() {
		if (Date.now() - started < 60000) {
			timer = window.setTimeout(check, 3000);
			return;
		}
		setState('unknown');
	}

	recheck.addEventListener('click', function () {
		started = Date.now();
		window.clearTimeout(timer);
		setState('checking');
		check();
	});
	check();
}());
</script>
<?php get_footer( 'shop' ); ?>
