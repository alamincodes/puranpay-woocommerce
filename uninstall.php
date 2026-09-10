<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package PuranPay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_puranpay_settings' );
