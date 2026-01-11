<?php
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
final class WC_Gateway_Bitcoin_Blocks_Support extends AbstractPaymentMethodType {
    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'bitcoin';

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_bitcoin_settings', array());
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        return isset($payment_gateways['bitcoin']) && $payment_gateways['bitcoin']->is_available();
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

        wp_register_script(
            'wc-bitcoin-blocks-integration',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        return array('wc-bitcoin-blocks-integration');
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        $gateway = isset($payment_gateways['bitcoin']) ? $payment_gateways['bitcoin'] : null;

        if (!$gateway) {
            return array();
        }

        return array(
            'title' => $gateway->get_option('title'),
            'description' => $gateway->get_option('description'),
            'supports' => array_filter($gateway->supports, array($gateway, 'supports')),
            'icon' => plugins_url('/images/checkout-icons/bsv_2.png', dirname(__FILE__)),
        );
    }
}
