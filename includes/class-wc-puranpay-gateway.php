<?php
/**
 * WooCommerce payment gateway: create checkout, redirect, fulfill on webhook.
 *
 * @package PuranPay
 */

defined( 'ABSPATH' ) || exit;

class WC_PuranPay_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'puranpay';
		$this->method_title       = __( 'PuranPay', 'puranpay-for-woocommerce' );
		$this->method_description = __( 'Redirect customers to PuranPay hosted checkout (bKash, Nagad, Rocket, Upay). Orders are marked paid only after a signed webhook.', 'puranpay-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = WC_PURANPAY_URL . 'assets/images/icon.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Mobile banking (bKash, Nagad, Rocket, Upay)', 'puranpay-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'You will be redirected to PuranPay to Send Money and enter your TrxID.', 'puranpay-for-woocommerce' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_puranpay', array( 'WC_PuranPay_Webhook', 'handle_wc_api' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'thankyou_page' ) );
		add_action( 'template_redirect', array( $this, 'maybe_sync_order_received' ) );
	}

	/**
	 * @return bool
	 */
	public function is_test_mode() {
		return 'yes' === $this->get_option( 'testmode', 'no' );
	}

	/**
	 * @return WC_PuranPay_API
	 */
	public function api_client() {
		$url = $this->get_option( 'api_url' );
		if ( ! $url ) {
			$url = WC_PuranPay_API::DEFAULT_API_URL;
		}
		return new WC_PuranPay_API(
			$this->get_option( 'secret_key' ),
			$url,
			WC_PuranPay_API::shop_domain()
		);
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'puranpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PuranPay', 'puranpay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'puranpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Shown at checkout.', 'puranpay-for-woocommerce' ),
				'default'     => __( 'Mobile banking (bKash, Nagad, Rocket, Upay)', 'puranpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'puranpay-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'You will be redirected to PuranPay to Send Money and enter your TrxID.', 'puranpay-for-woocommerce' ),
			),
			'testmode'       => array(
				'title'       => __( 'Test mode', 'puranpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use sandbox keys (sk_test_). Do not fulfill live payments.', 'puranpay-for-woocommerce' ),
				'default'     => 'no',
			),
			'secret_key'     => array(
				'title'       => __( 'Secret key', 'puranpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Dashboard → API keys. sk_test_ while integrating, sk_live_ in production. Never a pk_ key.', 'puranpay-for-woocommerce' ),
				'default'     => '',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook secret', 'puranpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Dashboard → Settings → Webhook. Used to verify x-puranpay-signature.', 'puranpay-for-woocommerce' ),
				'default'     => '',
			),
			'api_url'        => array(
				'title'       => __( 'API URL', 'puranpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Leave blank for the hosted API.', 'puranpay-for-woocommerce' ),
				'default'     => WC_PuranPay_API::DEFAULT_API_URL,
				'placeholder' => WC_PuranPay_API::DEFAULT_API_URL,
			),
		);
	}

	public function admin_options() {
		parent::admin_options();

		$webhook = WC_PuranPay_Webhook::rest_url();
		$domain  = WC_PuranPay_API::shop_domain();
		$wc_api  = home_url( '/?wc-api=puranpay' );

		echo '<h3>' . esc_html__( 'Connect this shop', 'puranpay-for-woocommerce' ) . '</h3>';
		echo '<ol style="max-width:40rem">';
		echo '<li>' . esc_html__( 'Buy a plan in PuranPay Billing, then add this exact shop domain in Settings:', 'puranpay-for-woocommerce' ) . ' <code>' . esc_html( $domain ) . '</code></li>';
		echo '<li>' . esc_html__( 'Paste this HTTPS webhook URL in PuranPay Settings:', 'puranpay-for-woocommerce' ) . ' <code>' . esc_html( $webhook ) . '</code></li>';
		echo '<li>' . esc_html__( 'Copy the secret key and webhook secret into the fields above. Pair the phone app so official SMS can match TrxIDs.', 'puranpay-for-woocommerce' ) . '</li>';
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'Fallback webhook (same handler):', 'puranpay-for-woocommerce' ) . ' <code>' . esc_html( $wc_api ) . '</code></p>';
	}

	public function process_admin_options() {
		$prev_secret  = $this->get_option( 'secret_key' );
		$prev_webhook = $this->get_option( 'webhook_secret' );
		parent::process_admin_options();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce settings nonce is checked above.
		$secret = isset( $_POST['woocommerce_puranpay_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_puranpay_secret_key'] ) ) : '';
		$hook   = isset( $_POST['woocommerce_puranpay_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_puranpay_webhook_secret'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $secret ) {
			$this->update_option( 'secret_key', $prev_secret );
		}
		if ( '' === $hook ) {
			$this->update_option( 'webhook_secret', $prev_webhook );
		}

		$saved = $this->get_option( 'secret_key' );
		if ( $saved && 0 !== strpos( $saved, 'sk_' ) ) {
			$this->add_error( __( 'Secret key must start with sk_live_ or sk_test_.', 'puranpay-for-woocommerce' ) );
		}

		$api_url = $this->get_option( 'api_url' );
		if ( $api_url ) {
			$this->update_option( 'api_url', untrailingslashit( esc_url_raw( $api_url ) ) );
		}
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( 'BDT' !== get_woocommerce_currency() ) {
			return false;
		}
		if ( ! $this->get_option( 'secret_key' ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Access: once per Place order. Same idempotency key reuses an open checkout.
	 *
	 * @param int $order_id Order id.
	 * @return array{result:string,redirect?:string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Order not found.', 'puranpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$amount = round( (float) $order->get_total(), 2 );
		if ( $amount <= 0 ) {
			wc_add_notice( __( 'PuranPay cannot charge a zero-amount order.', 'puranpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$customer = array(
			'name' => substr( $order->get_formatted_billing_full_name(), 0, 80 ),
		);
		$email = $order->get_billing_email();
		if ( $email && is_email( $email ) ) {
			$customer['email'] = $email;
		}
		$phone = $this->billing_phone( $order );
		if ( $phone ) {
			$customer['phone'] = $phone;
		}

		$body = array(
			'amount'     => $amount,
			'orderId'    => (string) $order->get_id(),
			'note'       => substr(
				sprintf(
					/* translators: %s order number */
					__( 'Order #%s', 'puranpay-for-woocommerce' ),
					$order->get_order_number()
				),
				0,
				200
			),
			'customer'   => $customer,
			'successUrl' => $order->get_checkout_order_received_url(),
			'failUrl'    => $order->get_checkout_payment_url( false ),
			'metadata'   => array(
				'platform'  => 'woocommerce',
				'order_key' => $order->get_order_key(),
			),
		);

		$ref = $order->get_customer_id();
		if ( $ref ) {
			$body['customerRef'] = substr( (string) $ref, 0, 80 );
		}

		$result = $this->api_client()->create_payment( $body, $this->idempotency_key( $order ) );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'fail' );
		}

		$payment = isset( $result['payment'] ) && is_array( $result['payment'] ) ? $result['payment'] : array();
		$url     = isset( $result['checkoutUrl'] ) ? esc_url_raw( (string) $result['checkoutUrl'] ) : '';
		$id      = isset( $payment['id'] ) ? sanitize_text_field( (string) $payment['id'] ) : '';

		if ( '' === $id ) {
			wc_add_notice( __( 'PuranPay did not return a payment id.', 'puranpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$order->update_meta_data( '_puranpay_payment_id', $id );
		$order->save();

		if ( isset( $payment['status'] ) && 'VERIFIED' === $payment['status'] ) {
			WC_PuranPay_Webhook::fulfill_payment(
				$this,
				$order,
				$payment,
				! ( isset( $payment['mode'] ) && 'test' === $payment['mode'] )
			);
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		if ( '' === $url ) {
			wc_add_notice( __( 'PuranPay did not return a checkout URL.', 'puranpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s payment id */
				__( 'Customer redirected to PuranPay %s.', 'puranpay-for-woocommerce' ),
				$id
			)
		);

		return array(
			'result'   => 'success',
			'redirect' => $url,
		);
	}

	/**
	 * Block checkout thank-you often skips woocommerce_thankyou_{gateway}.
	 * Sync on the order-received URL itself so a late webhook still fulfills.
	 */
	public function maybe_sync_order_received() {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}
		$order_id = absint( get_query_var( 'order-received' ) );
		if ( $order_id ) {
			$this->thankyou_page( $order_id );
		}
	}

	/**
	 * @param int $order_id Order id.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( $order->get_payment_method() && 'puranpay' !== $order->get_payment_method() ) {
			return;
		}
		WC_PuranPay_Webhook::sync_order_from_api( $order );
	}

	/**
	 * 8–64 chars, [A-Za-z0-9._:-]. Backend prefixes test: for sandbox keys.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function idempotency_key( $order ) {
		return 'wc-order-' . $order->get_id();
	}

	/**
	 * Optional BD mobile for the payment object (not expectedSender — that would lock SMS).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function billing_phone( $order ) {
		$digits = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
		if ( ! is_string( $digits ) ) {
			return '';
		}
		if ( preg_match( '/^880(1[3-9]\d{8})$/', $digits, $match ) ) {
			$digits = $match[1];
		}
		if ( preg_match( '/^01[3-9]\d{8}$/', $digits ) ) {
			return $digits;
		}
		return '';
	}
}
