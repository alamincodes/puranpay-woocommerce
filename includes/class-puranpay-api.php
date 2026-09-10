<?php
/**
 * PuranPay HTTP client (same contract as the Node SDK).
 *
 * @package PuranPay
 */

defined( 'ABSPATH' ) || exit;

class WC_PuranPay_API {

	const DEFAULT_API_URL = 'https://api.puranpay.com';

	const API_KEY_HEADER     = 'x-api-key';
	const SHOP_DOMAIN_HEADER = 'x-shop-domain';
	const IDEMPOTENCY_HEADER = 'Idempotency-Key';
	const SIGNATURE_HEADER   = 'x-puranpay-signature';
	const EVENT_ID_HEADER    = 'x-puranpay-event-id';

	/** @var string */
	private $secret_key;

	/** @var string */
	private $base_url;

	/** @var string */
	private $shop_domain;

	/**
	 * @param string $secret_key   sk_live_ or sk_test_.
	 * @param string $base_url     API origin, no trailing slash.
	 * @param string $shop_domain  Host on the merchant plan (www stripped).
	 */
	public function __construct( $secret_key, $base_url, $shop_domain ) {
		$this->secret_key  = trim( (string) $secret_key );
		$this->base_url    = untrailingslashit( trim( (string) $base_url ) ?: self::DEFAULT_API_URL );
		$this->shop_domain = trim( (string) $shop_domain );
	}

	/**
	 * Host WooCommerce should send as x-shop-domain (matches backend normalizeDomain).
	 *
	 * @return string
	 */
	public static function shop_domain() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	/**
	 * HMAC-SHA256 hex of the raw webhook body.
	 *
	 * @param string $raw    Exact request bytes.
	 * @param string $secret Webhook secret from the dashboard.
	 * @return string
	 */
	public static function sign_webhook( $raw, $secret ) {
		return hash_hmac( 'sha256', $raw, $secret );
	}

	/**
	 * Constant-time compare of x-puranpay-signature.
	 *
	 * @param string $raw       Exact request bytes — do not json_encode a parsed body.
	 * @param string $signature Header value.
	 * @param string $secret    Webhook secret.
	 * @return bool
	 */
	public static function verify_signature( $raw, $signature, $secret ) {
		if ( '' === $secret || '' === $signature ) {
			return false;
		}
		$expected = self::sign_webhook( $raw, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * POST /api/payments
	 *
	 * @param array  $body            Create payload.
	 * @param string $idempotency_key 8–64 chars [A-Za-z0-9._:-].
	 * @return array|WP_Error { payment, checkoutUrl, methods, payTo }
	 */
	public function create_payment( array $body, $idempotency_key ) {
		return $this->request( 'POST', '/api/payments', $body, $idempotency_key );
	}

	/**
	 * GET /api/payments/:id — secret key only.
	 *
	 * @param string $payment_id pay_...
	 * @return array|WP_Error { payment: {...} } unwrapped to payment array on success.
	 */
	public function retrieve_payment( $payment_id ) {
		$id = rawurlencode( ltrim( (string) $payment_id, '/' ) );
		$result = $this->request( 'GET', '/api/payments/' . $id, null, '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['payment'] ) && is_array( $result['payment'] ) ) {
			return $result['payment'];
		}
		return $result;
	}

	/**
	 * @param string               $method          GET or POST.
	 * @param string               $path            Starts with /api/.
	 * @param array<string,mixed>|null $body        JSON body or null.
	 * @param string               $idempotency_key Retry-safe key for POST.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body, $idempotency_key ) {
		if ( '' === $this->secret_key ) {
			return new WP_Error( 'puranpay_no_key', __( 'PuranPay secret key is missing.', 'puranpay-for-woocommerce' ) );
		}

		$can_retry = ( 'GET' === $method ) || ( '' !== $idempotency_key );
		$attempts  = $can_retry ? 3 : 1;
		$last      = new WP_Error( 'puranpay_http', __( 'PuranPay request failed.', 'puranpay-for-woocommerce' ) );

		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			if ( $attempt > 0 ) {
				usleep( ( 200 * ( 2 ** $attempt ) + wp_rand( 0, 80 ) ) * 1000 );
			}

			$headers = array(
				'Accept'     => 'application/json',
				'User-Agent' => 'PuranPay-WooCommerce/' . WC_PURANPAY_VERSION,
				self::API_KEY_HEADER => $this->secret_key,
			);
			if ( $this->shop_domain ) {
				$headers[ self::SHOP_DOMAIN_HEADER ] = $this->shop_domain;
			}
			if ( $idempotency_key ) {
				$headers[ self::IDEMPOTENCY_HEADER ] = $idempotency_key;
			}

			$args = array(
				'method'      => $method,
				'timeout'     => 20,
				'redirection' => 0,
			);
			if ( null !== $body ) {
				$encoded = wp_json_encode( $body );
				if ( false === $encoded ) {
					return new WP_Error( 'puranpay_json', __( 'Could not encode the PuranPay request.', 'puranpay-for-woocommerce' ) );
				}
				$headers['Content-Type'] = 'application/json';
				$args['body']            = $encoded;
			}
			$args['headers'] = $headers;

			$response = wp_remote_request( $this->base_url . $path, $args );
			if ( is_wp_error( $response ) ) {
				$last = $response;
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $json ) ) {
				$json = array();
			}

			if ( $status >= 200 && $status < 300 ) {
				return $json;
			}

			$message = $this->error_message( $json, $status );
			$last    = new WP_Error( 'puranpay_http_' . $status, $message, array( 'status' => $status, 'body' => $json ) );

			if ( $attempt < $attempts - 1 && in_array( $status, array( 408, 429, 500, 502, 503, 504 ), true ) ) {
				continue;
			}
			return $last;
		}

		return $last;
	}

	/**
	 * @param array $json
	 * @param int   $status
	 * @return string
	 */
	private function error_message( array $json, $status ) {
		if ( isset( $json['error'] ) && is_string( $json['error'] ) && '' !== $json['error'] ) {
			return $json['error'];
		}
		if ( isset( $json['error']['message'] ) && is_string( $json['error']['message'] ) ) {
			return $json['error']['message'];
		}
		if ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
			return $json['message'];
		}

		$map = array(
			400 => __( 'PuranPay rejected this checkout (check amount, URLs, and shop domain).', 'puranpay-for-woocommerce' ),
			401 => __( 'PuranPay API key is missing or revoked.', 'puranpay-for-woocommerce' ),
			402 => __( 'PuranPay needs an active plan. Open Billing in the dashboard.', 'puranpay-for-woocommerce' ),
			403 => __( 'This shop domain is not on your PuranPay plan. Add it in Settings.', 'puranpay-for-woocommerce' ),
			404 => __( 'PuranPay payment was not found.', 'puranpay-for-woocommerce' ),
			409 => __( 'This PuranPay checkout is already settled.', 'puranpay-for-woocommerce' ),
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : sprintf(
			/* translators: %d HTTP status */
			__( 'PuranPay request failed (%d).', 'puranpay-for-woocommerce' ),
			$status
		);
	}
}
