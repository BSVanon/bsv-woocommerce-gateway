<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * WooCommerce Blocks Integration for Bitcoin SV Payment Gateway
 *
 * @package Bitcoin-SV-Payments-for-WooCommerce
 * @since 5.1.0
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * BSV payment method integration for WooCommerce Blocks
 */
final class BWWC_WC_Gateway_Blocks_Support extends AbstractPaymentMethodType {
    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'bitcoin_sv';

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_bitcoin_sv_settings', array());
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        return isset($payment_gateways['bitcoin_sv']) && $payment_gateways['bitcoin_sv']->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_path = '/assets/js/blocks/bsv-payment-method.js';
        $script_url = plugins_url($script_path, dirname(__FILE__));
        $script_asset_path = dirname(__FILE__, 2) . '/assets/js/blocks/bsv-payment-method.asset.php';
        
        $script_asset = file_exists($script_asset_path)
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version' => BWWC_VERSION
            );

        // Use filemtime for cache busting
        $script_file_path = dirname(__FILE__, 2) . $script_path;
        $script_version = $script_asset['version'];
        if (file_exists($script_file_path)) {
            $script_version .= '.' . filemtime($script_file_path);
        }

        wp_register_script(
            'wc-bitcoin-sv-blocks-integration',
            $script_url,
            $script_asset['dependencies'],
            $script_version,
            true
        );

        return array('wc-bitcoin-sv-blocks-integration');
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        $gateway = isset($payment_gateways['bitcoin_sv']) ? $payment_gateways['bitcoin_sv'] : null;
        
        if (!$gateway) {
            return array();
        }

        // Use the selected checkout icon from core plugin settings
        if (function_exists('BWWC__get_settings')) {
            $bwwc_settings = BWWC__get_settings();
        } else {
            $bwwc_settings = get_option('woocommerce_bitcoin_sv_settings', array());
        }

        $selected_icon = !empty($bwwc_settings['selected_checkout_icon'])
            ? $bwwc_settings['selected_checkout_icon']
            : '/images/checkout-icons/BSV-1.svg';

        return array(
            'title' => $gateway->get_option('title'),
            'description' => $gateway->get_option('description'),
            'supports' => $gateway->supports,
            'icon' => plugins_url($selected_icon, dirname(__FILE__)),
        );
    }
}
