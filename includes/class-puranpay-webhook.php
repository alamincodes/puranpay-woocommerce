<?php
/**
 * HMAC webhook + order fulfill. Fulfill only after signature + payment.verified.
 *
 * @package PuranPay
 */

defined( 'ABSPATH' ) || exit;

class WC_PuranPay_Webhook {

	/**
	 * Public REST route merchants paste in PuranPay Settings.
	 *
	 * @return string
	 */
	public static function rest_url() {
		return rest_url( 'puranpay/v1/webhook' );
	}

	public static function register_rest_route() {
		register_rest_route(
			'puranpay/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_rest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * WooCommerce wc-api fallback: https://shop/?wc-api=puranpay
	 */
	public static function handle_wc_api() {
		$raw       = (string) file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_PURANPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PURANPAY_SIGNATURE'] ) ) : '';
		$result    = self::process_raw( $raw, $signature );

		status_header( (int) $result['status'] );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		exit;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_rest( $request ) {
		$raw       = (string) $request->get_body();
		$signature = (string) $request->get_header( WC_PuranPay_API::SIGNATURE_HEADER );
		$result    = self::process_raw( $raw, $signature );
		return new WP_REST_Response( null, (int) $result['status'] );
	}

	/**
	 * @param string $raw       Raw JSON bytes.
	 * @param string $signature HMAC hex.
	 * @return array{status:int}
	 */
	public static function process_raw( $raw, $signature ) {
		$gateway = self::gateway();
		if ( ! $gateway ) {
			return array( 'status' => 503 );
		}

		$secret = (string) $gateway->get_option( 'webhook_secret' );
		if ( ! WC_PuranPay_API::verify_signature( $raw, $signature, $secret ) ) {
			return array( 'status' => 401 );
		}

		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) ) {
			return array( 'status' => 400 );
		}

		self::handle_event( $gateway, $event );
		return array( 'status' => 200 );
	}

	/**
	 * Thank-you fallback if the webhook is late. Uses secret-key retrieve.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function sync_order_from_api( $order ) {
		$gateway = self::gateway();
		if ( ! $gateway || ! $order instanceof WC_Order ) {
			return;
		}

		$payment_id = isset( $_GET['payment'] ) ? sanitize_text_field( wp_unslash( $_GET['payment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $payment_id ) {
			$payment_id = (string) $order->get_meta( '_puranpay_payment_id' );
		}
		if ( '' === $payment_id || $order->is_paid() ) {
			return;
		}

		$api     = $gateway->api_client();
		$payment = $api->retrieve_payment( $payment_id );
		if ( is_wp_error( $payment ) || ! is_array( $payment ) ) {
			return;
		}

		$status = isset( $payment['status'] ) ? (string) $payment['status'] : '';
		if ( 'VERIFIED' === $status ) {
			self::fulfill_payment( $gateway, $order, $payment, ( isset( $payment['mode'] ) && 'test' === $payment['mode'] ) ? false : true );
			return;
		}

		if ( in_array( $status, array( 'EXPIRED', 'CANCELLED' ), true ) && $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
			$order->update_status(
				'failed',
				sprintf(
					/* translators: 1: payment id, 2: status */
					__( 'PuranPay checkout %1$s is %2$s.', 'puranpay-for-woocommerce' ),
					sanitize_text_field( (string) ( $payment['id'] ?? '' ) ),
					$status
				)
			);
		}
	}

	/**
	 * @param WC_PuranPay_Gateway  $gateway Gateway.
	 * @param array<string,mixed>  $event   Webhook JSON.
	 */
	private static function handle_event( $gateway, array $event ) {
		$type = isset( $event['event'] ) ? (string) $event['event'] : '';
		if ( 'payment.verified' !== $type ) {
			return;
		}

		$payment = isset( $event['payment'] ) && is_array( $event['payment'] ) ? $event['payment'] : array();
		$order   = self::find_order( $payment );
		if ( ! $order ) {
			return;
		}

		$livemode = ! empty( $event['livemode'] );
		self::fulfill_payment( $gateway, $order, $payment, $livemode );
	}

	/**
	 * @param WC_PuranPay_Gateway $gateway  Gateway.
	 * @param WC_Order            $order    Order.
	 * @param array<string,mixed> $payment  Payment object.
	 * @param bool                $livemode True when live SMS match.
	 */
	public static function fulfill_payment( $gateway, $order, array $payment, $livemode ) {
		if ( $order->get_payment_method() && 'puranpay' !== $order->get_payment_method() ) {
			return;
		}

		$testmode = $gateway->is_test_mode();
		if ( $testmode && $livemode ) {
			return;
		}
		if ( ! $testmode && ! $livemode ) {
			$order->add_order_note( __( 'Ignored a sandbox PuranPay webhook on a live store. Turn on Test mode to accept sk_test_ payments.', 'puranpay-for-woocommerce' ) );
			$order->save();
			return;
		}

		$payment_id = isset( $payment['id'] ) ? sanitize_text_field( (string) $payment['id'] ) : '';
		$order_id   = isset( $payment['orderId'] ) ? (string) $payment['orderId'] : '';
		if ( $order_id && (string) $order->get_id() !== $order_id ) {
			return;
		}

		$paid   = isset( $payment['amount'] ) ? round( (float) $payment['amount'], 2 ) : 0.0;
		$expect = round( (float) $order->get_total(), 2 );
		if ( abs( $paid - $expect ) > 0.009 ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: paid amount, 2: order total */
					__( 'PuranPay amount mismatch: paid %1$s BDT, order %2$s BDT. Order left unpaid.', 'puranpay-for-woocommerce' ),
					wc_format_decimal( $paid, 2 ),
					wc_format_decimal( $expect, 2 )
				)
			);
			$order->save();
			return;
		}

		if ( $order->is_paid() ) {
			return;
		}

		$trx = isset( $payment['trxId'] ) ? sanitize_text_field( (string) $payment['trxId'] ) : '';
		if ( $payment_id ) {
			$order->update_meta_data( '_puranpay_payment_id', $payment_id );
		}
		if ( $trx ) {
			$order->update_meta_data( '_puranpay_trx_id', $trx );
		}
		$order->update_meta_data( '_puranpay_livemode', $livemode ? 'yes' : 'no' );
		$order->payment_complete( $trx );
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment id, 2: trx id */
				__( 'PuranPay verified %1$s. TrxID %2$s.', 'puranpay-for-woocommerce' ),
				$payment_id ? $payment_id : 'pay_?',
				$trx ? $trx : '—'
			)
		);
		$order->save();
	}

	/**
	 * @param array<string,mixed> $payment Payment object.
	 * @return WC_Order|null
	 */
	private static function find_order( array $payment ) {
		$order_id = isset( $payment['orderId'] ) ? absint( $payment['orderId'] ) : 0;
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		$payment_id = isset( $payment['id'] ) ? sanitize_text_field( (string) $payment['id'] ) : '';
		if ( '' === $payment_id ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'        => 1,
				'return'       => 'objects',
				'payment_method' => 'puranpay',
				'meta_key'     => '_puranpay_payment_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => $payment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( empty( $orders ) || ! $orders[0] instanceof WC_Order ) {
			return null;
		}
		return $orders[0];
	}

	/**
	 * @return WC_PuranPay_Gateway|null
	 */
	private static function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = isset( $gateways['puranpay'] ) ? $gateways['puranpay'] : null;
		return $gateway instanceof WC_PuranPay_Gateway ? $gateway : null;
	}
}
