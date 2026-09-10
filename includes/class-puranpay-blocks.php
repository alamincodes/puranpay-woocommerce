<?php
/**
 * WooCommerce Checkout Blocks integration.
 *
 * @package PuranPay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_PuranPay_Blocks extends AbstractPaymentMethodType {

	protected $name = 'puranpay';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_puranpay_settings', array() );
	}

	public function is_active() {
		$enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
		if ( 'yes' !== $enabled ) {
			return false;
		}
		if ( function_exists( 'get_woocommerce_currency' ) && 'BDT' !== get_woocommerce_currency() ) {
			return false;
		}
		$key = isset( $this->settings['secret_key'] ) ? $this->settings['secret_key'] : '';
		return '' !== $key;
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'wc-puranpay-blocks',
			WC_PURANPAY_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			WC_PURANPAY_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-puranpay-blocks', 'puranpay-for-woocommerce', WC_PURANPAY_PATH . 'languages' );
		}

		return array( 'wc-puranpay-blocks' );
	}

	public function get_payment_method_data() {
		$title = isset( $this->settings['title'] ) ? $this->settings['title'] : '';
		$desc  = isset( $this->settings['description'] ) ? $this->settings['description'] : '';

		return array(
			'title'       => $title ? $title : __( 'Mobile banking (bKash, Nagad, Rocket, Upay)', 'puranpay-for-woocommerce' ),
			'description' => $desc,
			'icon'        => WC_PURANPAY_URL . 'assets/images/icon.svg',
			'supports'    => array( 'products' ),
		);
	}
}
