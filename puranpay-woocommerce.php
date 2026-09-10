<?php
/**
 * Plugin Name: PuranPay for WooCommerce
 * Plugin URI: https://puranpay.com
 * Description: Accept bKash, Nagad, Rocket, and Upay on WooCommerce via PuranPay hosted checkout.
 * Version: 1.0.0
 * Author: PuranPay
 * Author URI: https://puranpay.com
 * Text Domain: puranpay-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package PuranPay
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_PURANPAY_VERSION', '1.0.0' );
define( 'WC_PURANPAY_FILE', __FILE__ );
define( 'WC_PURANPAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_PURANPAY_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_PURANPAY_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WC_PURANPAY_FILE, true );
	}
);

add_action( 'plugins_loaded', 'wc_puranpay_bootstrap', 11 );
add_action( 'woocommerce_blocks_loaded', 'wc_puranpay_register_blocks' );
add_action( 'rest_api_init', 'wc_puranpay_register_rest' );

/**
 * Load classes once WooCommerce is present.
 */
function wc_puranpay_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'wc_puranpay_missing_wc_notice' );
		return;
	}

	require_once WC_PURANPAY_PATH . 'includes/class-puranpay-api.php';
	require_once WC_PURANPAY_PATH . 'includes/class-puranpay-webhook.php';
	require_once WC_PURANPAY_PATH . 'includes/class-wc-gateway-puranpay.php';

	add_filter( 'woocommerce_payment_gateways', 'wc_puranpay_register_gateway' );
	load_plugin_textdomain( 'puranpay-for-woocommerce', false, dirname( plugin_basename( WC_PURANPAY_FILE ) ) . '/languages' );
}

/**
 * Admin notice when WooCommerce is missing.
 */
function wc_puranpay_missing_wc_notice() {
	echo '<div class="error"><p>';
	esc_html_e( 'PuranPay for WooCommerce requires WooCommerce to be installed and active.', 'puranpay-for-woocommerce' );
	echo '</p></div>';
}

/**
 * @param string[] $gateways Gateway class names.
 * @return string[]
 */
function wc_puranpay_register_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_PuranPay';
	return $gateways;
}

/**
 * Checkout Blocks payment method.
 */
function wc_puranpay_register_blocks() {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once WC_PURANPAY_PATH . 'includes/class-puranpay-api.php';
	require_once WC_PURANPAY_PATH . 'includes/class-puranpay-blocks.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		static function ( $registry ) {
			$registry->register( new WC_PuranPay_Blocks() );
		}
	);
}

/**
 * Webhook: POST /wp-json/puranpay/v1/webhook
 */
function wc_puranpay_register_rest() {
	require_once WC_PURANPAY_PATH . 'includes/class-puranpay-api.php';
	require_once WC_PURANPAY_PATH . 'includes/class-puranpay-webhook.php';
	WC_PuranPay_Webhook::register_rest_route();
}
