<?php
/*
Bitcoin SV Payments for WooCommerce
https://github.com/mboyd1/bsvanon-bitcoin-sv-payments
*/

if ( ! defined( 'ABSPATH' ) ) exit;


//---------------------------------------------------------------------------
add_action('plugins_loaded', 'BWWC__plugins_loaded__load_bitcoin_gateway', 0);
//---------------------------------------------------------------------------

//###########################################################################
// Hook payment gateway into WooCommerce

function BWWC__plugins_loaded__load_bitcoin_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        // Nothing happens here is WooCommerce is not loaded
        return;
    }

    //=======================================================================
    /**
     * Bitcoin SV Payment Gateway
     *
     * Provides a Bitcoin SV Payment Gateway
     *
     * @class 		BWWC_Bitcoin
     * @extends		WC_Payment_Gateway
     * @version
     * @package
     * @author 		mboyd1
     */
    class BWWC_Bitcoin extends WC_Payment_Gateway
    {
        /**
         * Woo gateway public properties to avoid PHP 8.2 dynamic property notices.
         */
        public $service_provider;
        public $bitcoin_addr_merchant;
        public $confs_num;
        public $instructions;
        public $instructions_multi_payment_str;
        public $instructions_single_payment_str;

        //-------------------------------------------------------------------
        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct()
        {
            $this->id				= 'bitcoin_sv';
            $this->icon 			= plugins_url('/images/bsv_buyitnow_32x.png', __FILE__);	// 32 pixels high
            $this->has_fields 		= false;
            $this->method_title     = __('Bitcoin SV', 'bsvanon-bitcoin-sv-payments');
            $this->method_description = __('Accept Bitcoin SV payments with live blockchain verification', 'bsvanon-bitcoin-sv-payments');
            
            // Declare feature support for WooCommerce Blocks
            $this->supports = array(
                'products'
            );

            // Load BWWC settings.
            $bwwc_settings = BWWC__get_settings();
            $this->service_provider = $bwwc_settings['service_provider']; // This need to be before $this->init_settings otherwise it generate PHP Notice: "Undefined property: BWWC_Bitcoin::$service_provider" down below.

            // Load the form fields.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title', __('Bitcoin SV Payment', 'bsvanon-bitcoin-sv-payments'));
            $this->bitcoin_addr_merchant = $this->get_option('bitcoin_addr_merchant', '');
            $this->confs_num = $bwwc_settings['confs_num'];  //$this->settings['confirmations'];
            $this->description = $this->get_option('description', __('Please proceed to the next screen for payment details.', 'bsvanon-bitcoin-sv-payments') . "\n\n" . __('* BRC-100 Payment Button & Legacy Payments Supported', 'bsvanon-bitcoin-sv-payments') . "\n" . __('* Variety of QR Codes Styles for any wallet', 'bsvanon-bitcoin-sv-payments') . "\n" . __('* Live Payment Confirmation Tracker & Blockchain Explorer Link', 'bsvanon-bitcoin-sv-payments'));	// Short description about the gateway which is shown on checkout.
            $this->instructions = $this->get_option('instructions', ''); // Detailed payment instructions for the buyer.
            $this->instructions_multi_payment_str  = __('You may send payments from multiple accounts to reach the total required.', 'bsvanon-bitcoin-sv-payments');
            $this->instructions_single_payment_str = __('You must pay in a single payment in full.', 'bsvanon-bitcoin-sv-payments');
             if (isset($bwwc_settings['selected_checkout_icon']) && $bwwc_settings['selected_checkout_icon'] != "") {
                 $this->icon = plugins_url($bwwc_settings['selected_checkout_icon'], __FILE__);
             }

            // Actions
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
            } // hook into this action to save options in the backend

            add_action('woocommerce_thankyou_' . $this->id, array($this, 'BWWC__thankyou_page')); // hooks into the thank you page after payment

            // Customer Emails
        add_action('woocommerce_email_before_order_table', array($this, 'BWWC__email_instructions'), 10, 2); // hooks into the email template to show additional details

            // Hook IPN callback logic
            if (version_compare(WOOCOMMERCE_VERSION, '2.0', '<')) {
                add_action('init', array($this, 'BWWC__maybe_bitcoin_ipn_callback'));
            } else {
                add_action('woocommerce_api_' . strtolower(get_class($this)), array($this,'BWWC__maybe_bitcoin_ipn_callback'));
            }

            // Validate currently set currency for the store. Must be among supported ones.
            if (!BWWC__is_gateway_valid_for_use()) {
                $this->enabled = false;
            }
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Check if this gateway is enabled and available for the store's default currency
         *
         * @access public
         * @return bool
         */
        public function is_gateway_valid_for_use(&$ret_reason_message=null)
        {
            $valid = true;

            //----------------------------------
            // Validate settings
            if (!$this->service_provider) {
                $reason_message = __("Bitcoin SV Service Provider is not selected", 'bsvanon-bitcoin-sv-payments');
                $valid = false;
            } elseif ($this->service_provider=='blockchain_info') {
                if ($this->bitcoin_addr_merchant == '') {
                    $reason_message = __("Your personal Bitcoin SV address is not selected", 'bsvanon-bitcoin-sv-payments');
                    $valid = false;
                } elseif ($this->bitcoin_addr_merchant == '18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj') {
                    $reason_message = __("Your personal Bitcoin SV address is invalid. The address specified is the donation address :)", 'bsvanon-bitcoin-sv-payments');
                    $valid = false;
                }
            } elseif ($this->service_provider=='electrum_wallet') {
                $mpk = BWWC__get_next_available_mpk();
                if (!$mpk) {
                    $reason_message = __("Please specify ElectrumSV Master Public Key (MPK) in Bitcoin SV plugin settings. <br />To retrieve MPK: launch your ElectrumSV wallet, select: Wallet->Information", 'bsvanon-bitcoin-sv-payments');
                    $valid = false;
                } elseif (!preg_match('/^[a-f0-9]{128}$/', $mpk) && !preg_match('/^xpub[a-zA-Z0-9]{107}$/', $mpk)) {
                    $reason_message = __("ElectrumSV Master Public Key is invalid. Must be 128 or 111 characters long, consisting of digits and letters.", 'bsvanon-bitcoin-sv-payments');
                    $valid = false;
                } elseif (!extension_loaded('gmp') && !extension_loaded('bcmath')) {
                    $reason_message = __("ERROR: neither 'bcmath' nor 'gmp' math extensions are loaded For ElectrumSV wallet options to function. Contact your hosting company and ask them to enable either 'bcmath' or 'gmp' extensions. 'gmp' is preferred (much faster)! \nAlternatively you may choose another 'Bitcoin SV Service Provider' option.", 'bsvanon-bitcoin-sv-payments');
                    $valid = false;
                }
            }

            if (!$valid) {
                if ($ret_reason_message !== null) {
                    $ret_reason_message = $reason_message;
                }
                return false;
            }
            //----------------------------------

            //----------------------------------
            // Validate connection to exchange rate services

            $store_currency_code = get_woocommerce_currency();
            if ($store_currency_code != 'BTC') {
                $currency_rate = BWWC__get_exchange_rate_per_bitcoin($store_currency_code, 'getfirst', false);
                if (!$currency_rate) {
                    $valid = false;

                    // Assemble error message.
                    $error_msg = "ERROR: Cannot determine exchange rates (for '$store_currency_code')! {{{ERROR_MESSAGE}}} Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.";
                    $extra_error_message = "";
                    $fns = array('file_get_contents', 'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec');
                    $fns = array_filter($fns, 'BWWC__function_not_exists');
                    $extra_error_message = "";
                    if (count($fns)) {
                        $extra_error_message = "The following PHP functions are disabled on your server: " . implode(", ", $fns) . ".";
                    }

                    $reason_message = str_replace('{{{ERROR_MESSAGE}}}', $extra_error_message, $error_msg);

                    if ($ret_reason_message !== null) {
                        $ret_reason_message = $reason_message;
                    }
                    return false;
                }
            }
            //----------------------------------

            //----------------------------------
            // NOTE: currenly this check is not performed.
            //  		Do not limit support with present list of currencies. This was originally created because exchange rate APIs did not support many, but today
            //			they do support many more currencies, hence this check is removed for now.

            // Validate currency
            // $currency_code            = get_woocommerce_currency();
            // $supported_currencies_arr = BWWC__get_settings ('supported_currencies_arr');

            // if ($currency_code != 'BTC' && !@in_array($currency_code, $supported_currencies_arr))
            // {
            //  $reason_message = __("Store currency is set to unsupported value", 'bsvanon-bitcoin-sv-payments') . "('{$currency_code}'). " . __("Valid currencies: ", 'bsvanon-bitcoin-sv-payments') . implode ($supported_currencies_arr, ", ");
            // 	if ($ret_reason_message !== NULL)
            // 		$ret_reason_message = $reason_message;
            // return false;
            // }

            return true;
            //----------------------------------
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        public function init_form_fields()
        {
            // This defines the settings we want to show in the admin area.
            // This allows user to customize payment gateway.
            // Add as many as you see fit.
            // See this for more form elements: http://wcdocs.woothemes.com/codex/extending/settings-api/

            //-----------------------------------
            // Assemble currency ticker.
            $store_currency_code = get_woocommerce_currency();
            if ($store_currency_code == 'BTC') {
                $currency_code = 'USD';
            } else {
                $currency_code = $store_currency_code;
            }

            $currency_ticker = BWWC__get_exchange_rate_per_bitcoin($currency_code, 'getfirst', true);
            
            // Format exchange rate display - only show if successfully fetched
            $exchange_rate_display = '';
            if ($currency_ticker && is_numeric($currency_ticker) && $currency_ticker > 0) {
                $exchange_rate_display = '<div style="padding: 10px; background: #e7f7e7; border-left: 4px solid #46b450; margin: 10px 0;">';
                $exchange_rate_display .= '<strong>' . __('Current Exchange Rate:', 'bsvanon-bitcoin-sv-payments') . '</strong> ';
                $exchange_rate_display .= '1 BSV = ' . number_format((float)$currency_ticker, 2) . ' ' . esc_html($currency_code);
                $exchange_rate_display .= ' <span style="color: #666; font-size: 12px;">(' . __('via CoinGecko', 'bsvanon-bitcoin-sv-payments') . ')</span>';
                $exchange_rate_display .= '</div>';
            }
            // Note: If rate fetch fails, we simply don't display anything rather than showing an error
            // Exchange rates are fetched in real-time during checkout, so this is just informational
            //-----------------------------------

            //-----------------------------------
            // Payment instructions
            // Get settings for payment timeout
            $bwwc_settings = BWWC__get_settings();
            $payment_timeout_mins = $bwwc_settings['assigned_address_expires_in_mins'];
            $payment_timeout_hours = $payment_timeout_mins / 60;
            // Format hours: show 1 decimal if needed, otherwise show whole number
            $payment_timeout_display = ($payment_timeout_hours == floor($payment_timeout_hours)) ? (int)$payment_timeout_hours : number_format($payment_timeout_hours, 1);
            
            $payment_instructions = '
<table class="bwwc-payment-instructions-table" id="bwwc-payment-instructions-table">
  <tr class="bpit-table-row">
    <td colspan="2">' . __('Please send your Bitcoin SV payment as follows:', 'bsvanon-bitcoin-sv-payments') . '</td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-amount">
      ' . __('Amount', 'bsvanon-bitcoin-sv-payments') . ' (<strong>BSV</strong>):
    </td>
    <td class="bpit-td-value bpit-td-value-amount">
      <div style="border:1px solid #FCCA09;padding:2px 6px;margin:2px;background-color:#FCF8E3;border-radius:4px;color:#CC0000;font-weight: bold;font-size: 120%;">
      	{{{BITCOINS_AMOUNT}}}
      </div>
    </td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-btcaddr">
      Address:
    </td>
    <td class="bpit-td-value bpit-td-value-btcaddr">
      <div style="border:1px solid #FCCA09;padding:2px 6px;margin:2px;background-color:#FCF8E3;border-radius:4px;color:#555;font-weight: bold;font-size: 120%;">
        {{{BITCOINS_ADDRESS}}}
      </div>
    </td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-qr">
	    Payment Link:
    </td>
    <td class="bpit-td-value bpit-td-value-qr">
      <div style="border:1px solid #FCCA09;padding:5px;margin:2px;background-color:#FCF8E3;border-radius:4px;">
        <a href="bitcoin:{{{BITCOINS_ADDRESS}}}?sv&amount={{{BITCOINS_AMOUNT}}}" style="color:#0073aa;text-decoration:none;font-weight:bold;">bitcoin:{{{BITCOINS_ADDRESS}}}?sv&amount={{{BITCOINS_AMOUNT}}}</a>
      </div>
      <p style="margin:5px 0;font-size:90%;color:#666;">Click the link above or scan the QR code on the payment page</p>
    </td>
  </tr>
</table>

' . __('Please note:', 'bsvanon-bitcoin-sv-payments') . '
<ol class="bpit-instructions">
    <li>' . __('We ONLY accept Bitcoin SV (BSV). Any other payments (BTC/BCH) will not process and the money will be lost!', 'bsvanon-bitcoin-sv-payments') . '</li>
    <li>' . __('We are not responsible for lost funds if you send BTC or BCH instead of BSV', 'bsvanon-bitcoin-sv-payments') . '</li>
    <li>' . sprintf(
        /* translators: %s: payment timeout in hours */
        __('You must make a payment within %s hours, or your order may be cancelled', 'bsvanon-bitcoin-sv-payments'), 
        $payment_timeout_display
    ) . '</li>
    <li>' . __('As soon as your payment is received in full you will receive email confirmation with order delivery details.', 'bsvanon-bitcoin-sv-payments') . '</li>
    <li>{{{EXTRA_INSTRUCTIONS}}}</li>
</ol>
';
            $payment_instructions = trim($payment_instructions);

            $payment_instructions_description = '
						  <p class="description" style="width:50%;float:left;width:49%;">
					    	' . __('Specific instructions given to the customer to complete Bitcoins payment.<br />You may change it, but make sure these tags will be present: <b>{{{BITCOINS_AMOUNT}}}</b>, <b>{{{BITCOINS_ADDRESS}}}</b> and <b>{{{EXTRA_INSTRUCTIONS}}}</b> as these tags will be replaced with customer - specific payment details.', 'bsvanon-bitcoin-sv-payments') . '
						  </p>
						  <p class="description" style="width:50%;float:left;width:49%;">
					    	Payment Instructions, original template (for reference):<br />
					    	<textarea rows="2" onclick="this.focus();this.select()" readonly="readonly" style="width:100%;background-color:#f1f1f1;height:4em">' . $payment_instructions . '</textarea>
						  </p>
					';
            $payment_instructions_description = trim($payment_instructions_description);
            //-----------------------------------

            $this->form_fields = array(
                'enabled' => array(
                                'title' => __('Enable/Disable', 'bsvanon-bitcoin-sv-payments'),
                                'type' => 'checkbox',
                                'label' => __('Enable Bitcoin SV Payments', 'bsvanon-bitcoin-sv-payments'),
                                'default' => 'yes'
                            ),
                'exchange_rate_info' => array(
                                'title' => __('Exchange Rate Status', 'bsvanon-bitcoin-sv-payments'),
                                'type' => 'title',
                                'description' => $exchange_rate_display,
                            ),
                'title' => array(
                                'title' => __('Title', 'bsvanon-bitcoin-sv-payments'),
                                'type' => 'text',
                                'description' => __('This controls the title which the user sees during checkout.', 'bsvanon-bitcoin-sv-payments'),
                                'default' => __('Bitcoin SV Payment', 'bsvanon-bitcoin-sv-payments')
                            ),

                'bitcoin_addr_merchant' => array(
                                'title' => __('Your personal Bitcoin SV address', 'bsvanon-bitcoin-sv-payments'),
                                'type' => 'text',
                                'css'     => $this->service_provider!='blockchain_info'?'display:none;':'',
                                'disabled' => $this->service_provider!='blockchain_info'?true:false,
                                'description' => $this->service_provider!='blockchain_info'?__('Not used with current address generation method. This plugin uses BIP32/BIP44 HD Wallet (xPub) for secure per-order address derivation. Configure your Master Public Key in the BSV Plugin settings page.', 'bsvanon-bitcoin-sv-payments'):__('Your own bitcoin address (such as: 18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj) - where you would like the payment to be sent. When customer sends you payment for the product - it will be automatically forwarded to this address by blockchain.info APIs.', 'bsvanon-bitcoin-sv-payments'),
                                'default' => '',
                            ),


                'description' => array(
                                'title' => __('Customer Message', 'bsvanon-bitcoin-sv-payments'),
                                'type' => 'textarea',
                                'description' => __('Initial instructions for the customer at checkout screen', 'bsvanon-bitcoin-sv-payments'),
                                'default' => __('Please proceed to the next screen for payment details.', 'bsvanon-bitcoin-sv-payments') . "\n\n" . __('* BRC-100 Payment Button & Legacy Payments Supported', 'bsvanon-bitcoin-sv-payments') . "\n" . __('* Variety of QR Codes Styles for any wallet', 'bsvanon-bitcoin-sv-payments') . "\n" . __('* Live Payment Confirmation Tracker & Blockchain Explorer Link', 'bsvanon-bitcoin-sv-payments')
                            ),
                'instructions' => array(
                                'title' => __('Payment Instructions (HTML)', 'bsvanon-bitcoin-sv-payments'),
                                'type' => 'textarea',
                                'description' => $payment_instructions_description,
                                'default' => $payment_instructions,
                            ),
                );
        }
        //-------------------------------------------------------------------
        /*
        ///!!!
                                            '<table>' .
                                            '	<tr><td colspan="2">' . __('Please send your Bitcoin SV  payment as follows:', 'bsvanon-bitcoin-sv-payments' ) . '</td></tr>' .
                                            '	<tr><td>Amount (฿): </td><td><div style="border:1px solid #CCC;padding:2px 6px;margin:2px;background-color:#FEFEF0;border-radius:4px;color:#CC0000;">{{{BITCOINS_AMOUNT}}}</div></td></tr>' .
                                            '	<tr><td>Address: </td><td><div style="border:1px solid #CCC;padding:2px 6px;margin:2px;background-color:#FEFEF0;border-radius:4px;color:blue;">{{{BITCOINS_ADDRESS}}}</div></td></tr>' .
                                            '</table>' .
                                            __('Please note:', 'bsvanon-bitcoin-sv-payments' ) .
                                            '<ol>' .
                                            '   <li>' . __('You must make a payment within 8 hours, or your order will be cancelled', 'bsvanon-bitcoin-sv-payments' ) . '</li>' .
                                            '   <li>' . __('As soon as your payment is received in full you will receive email confirmation with order delivery details.', 'bsvanon-bitcoin-sv-payments' ) . '</li>' .
                                            '   <li>{{{EXTRA_INSTRUCTIONS}}}</li>' .
                                            '</ol>'
        
        */

        //-------------------------------------------------------------------
        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @access public
         * @return void
         */
        public function admin_options()
        {
            $validation_msg = "";
            $store_valid    = BWWC__is_gateway_valid_for_use($validation_msg);

            // After defining the options, we need to display them too; thats where this next function comes into play: ?>
	    	<h3><?php esc_html_e('Bitcoin SV Payment', 'bsvanon-bitcoin-sv-payments'); ?></h3>
	    	<p>
	    		<?php esc_html_e(
                'Allows to accept payments in Bitcoin SV. Bitcoin SV is peer-to-peer electronic cash that enables instant payments from anyone to anyone, anywhere in the world',
                        'bsvanon-bitcoin-sv-payments'
            ); ?>
	    	</p>
	    	<?php if ($store_valid): ?>
                <p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#004400;background-color:#CCFFCC;">
                    <?php esc_html_e('Bitcoin SV payment gateway is operational', 'bsvanon-bitcoin-sv-payments'); ?>
                </p>
            <?php else: ?>
                <p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#EE0000;background-color:#FFFFAA;">
                    <?php
                    /* translators: %s: validation error message */
                    echo esc_html(sprintf(__('Bitcoin SV payment gateway is not operational (try to re-enter and save Bitcoinway Plugin settings): %s', 'bsvanon-bitcoin-sv-payments'), $validation_msg));
                    ?>
                </p>
            <?php endif; ?>
	    	<table class="form-table">
	    	<?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html(); ?>
			</table><!--/.form-table-->
	    	<?php
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        // Hook into admin options saving.
        public function process_admin_options()
        {
            // Call parent
            parent::process_admin_options();

            return;

            // Not needed as all bitcoinway's settings are now inside BWWC plugin.
      //
        // if (isset($_POST) && is_array($_POST))
        // {
            // $bwwc_settings = BWWC__get_settings ();
            // if (!isset($bwwc_settings['gateway_settings']) || !is_array($bwwc_settings['gateway_settings']))
            // 	$bwwc_settings['gateway_settings'] = array();

     //    // Born from __(..., 'bsvanon-bitcoin-sv-payments') + '$this->id'
        // 	$prefix        = 'woocommerce_bitcoin_';
        // 	$prefix_length = strlen($prefix);

        // 	foreach ($_POST as $varname => $varvalue)
        // 	{
        // 		if (strpos($varname, $prefix) === 0)
        // 		{
        // 			$trimmed_varname = substr($varname, $prefix_length);
        // 			if ($trimmed_varname != 'description' && $trimmed_varname != 'instructions')
        // 				$bwwc_settings['gateway_settings'][$trimmed_varname] = $varvalue;
        // 		}
        // 	}

            // // Update gateway settings within BWWC own settings for easier access.
        //   BWWC__update_settings ($bwwc_settings);
        // }
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            $bwwc_settings = BWWC__get_settings();
            $order = new WC_Order($order_id);

            // Check processing mode
            $processing_mode = isset($bwwc_settings['processing_mode']) ? $bwwc_settings['processing_mode'] : 'standalone_xpub';
            
            // TODO: Implement CRM features within store admin dashboard
            $order_meta = array();
            $order_meta['bw_order'] = $order;
            $order_meta['bw_items'] = $order->get_items();
            $order_meta['bw_b_addr'] = $order->get_formatted_billing_address();
            $order_meta['bw_s_addr'] = $order->get_formatted_shipping_address();
            $order_meta['bw_b_email'] = $order->get_billing_email();
            $order_meta['bw_currency'] = $order->get_currency();
            $order_meta['bw_settings'] = $bwwc_settings;
            $order_meta['bw_store'] = plugins_url('', __FILE__);


            //-----------------------------------
            // Save bitcoin payment info together with the order.
            // Note: this code must be on top here, as other filters will be called from here and will use these values ...
            //
            // Calculate realtime bitcoin price (if exchange is necessary)

            $exchange_rate = BWWC__get_exchange_rate_per_bitcoin(get_woocommerce_currency(), 'getfirst');
            /// $exchange_rate = BWWC__get_exchange_rate_per_bitcoin (get_woocommerce_currency(), $this->exchange_rate_retrieval_method, $this->exchange_rate_type);
            if (!$exchange_rate) {
                $msg = 'ERROR: Cannot determine Bitcoin SV exchange rate. Possible issues: store server does not allow outgoing connections, exchange rate servers are blocking incoming connections or down. ' .
                       'You may avoid that by setting store currency directly to Bitcoin SV (BSV)';
                BWWC__log_event(__FILE__, __LINE__, $msg);
                
                // User-friendly error message
                $user_msg = '<div style="max-width: 600px; margin: 50px auto; padding: 30px; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
                $user_msg .= '<h2 style="color: #dc3545; margin-top: 0;">' . esc_html__('Payment Processing Error', 'bsvanon-bitcoin-sv-payments') . '</h2>';
                $user_msg .= '<p>' . esc_html__('We\'re unable to process Bitcoin SV payments at this time due to a temporary issue fetching exchange rates.', 'bsvanon-bitcoin-sv-payments') . '</p>';
                $user_msg .= '<p><strong>' . esc_html__('What you can do:', 'bsvanon-bitcoin-sv-payments') . '</strong></p>';
                $user_msg .= '<ul>';
                $user_msg .= '<li>' . esc_html__('Try again in a few minutes', 'bsvanon-bitcoin-sv-payments') . '</li>';
                $user_msg .= '<li>' . esc_html__('Contact the store owner for assistance', 'bsvanon-bitcoin-sv-payments') . '</li>';
                $user_msg .= '<li>' . esc_html__('Choose an alternative payment method', 'bsvanon-bitcoin-sv-payments') . '</li>';
                $user_msg .= '</ul>';
                $user_msg .= '<p style="margin-top: 20px;"><a href="' . esc_url(wc_get_checkout_url()) . '" style="display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px;">' . esc_html__('Return to Checkout', 'bsvanon-bitcoin-sv-payments') . '</a></p>';
                $user_msg .= '</div>';
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                exit($user_msg);
            }

            $order_total_in_btc   = ($order->get_total() / $exchange_rate);
            if (get_woocommerce_currency() != 'BTC') {
                // Apply exchange rate multiplier only for stores with non-bitcoin default currency.
                $order_total_in_btc = $order_total_in_btc;
            }

            $order_total_in_btc   = sprintf("%.8f", $order_total_in_btc);

            $bitcoins_address = false;
            $hosted_session_data = false;

            // Process based on mode
            if ($processing_mode === 'hosted_invoicing') {
                // Hosted Invoicing mode - create hosted session
                $session_result = BWWC__create_hosted_session($order_id, $order->get_total(), get_woocommerce_currency());
                
                if (is_wp_error($session_result) || !$session_result['success']) {
                    $error_msg = is_wp_error($session_result) ? $session_result->get_error_message() : ($session_result['error'] ?? 'Unknown error');
                    $msg = "ERROR: cannot create hosted session for order: '{$error_msg}'";
                    BWWC__log_event(__FILE__, __LINE__, $msg);
                    
                    // User-friendly error message
                    $user_msg = '<div style="max-width: 600px; margin: 50px auto; padding: 30px; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
                    $user_msg .= '<h2 style="color: #dc3545; margin-top: 0;">' . esc_html__('Hosted Payment Error', 'bsvanon-bitcoin-sv-payments') . '</h2>';
                    $user_msg .= '<p>' . esc_html__('We\'re unable to create a hosted payment session at this time.', 'bsvanon-bitcoin-sv-payments') . '</p>';
                    $user_msg .= '<p><strong>' . esc_html__('What you can do:', 'bsvanon-bitcoin-sv-payments') . '</strong></p>';
                    $user_msg .= '<ul>';
                    $user_msg .= '<li>' . esc_html__('Try placing your order again', 'bsvanon-bitcoin-sv-payments') . '</li>';
                    $user_msg .= '<li>' . esc_html__('Contact the store owner for assistance', 'bsvanon-bitcoin-sv-payments') . '</li>';
                    $user_msg .= '<li>' . esc_html__('Choose an alternative payment method', 'bsvanon-bitcoin-sv-payments') . '</li>';
                    $user_msg .= '</ul>';
                    $user_msg .= '<p style="margin-top: 20px;"><a href="' . esc_url(wc_get_checkout_url()) . '" style="display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px;">' . esc_html__('Return to Checkout', 'bsvanon-bitcoin-sv-payments') . '</a></p>';
                    $user_msg .= '</div>';
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    exit($user_msg);
                }
                
                $hosted_session_data = $session_result['data'];
                $bitcoins_address = $hosted_session_data['payment_address'] ?? '';
                
                BWWC__log_event(__FILE__, __LINE__, "     Created hosted session for order_id {$order_id}. Session ID: " . ($hosted_session_data['session_id'] ?? 'unknown'));
                
            } else {
                // Standalone xPub mode - generate local address
                $order_info = array(
                    'order_meta'        => $order_meta,
                    'order_id'          => $order_id,
                    'order_total'       => $order_total_in_btc,  // Order total in BTC
                    'order_datetime'    => gmdate('Y-m-d H:i:s T'),
                    'requested_by_ip'   => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
                );

                // Only electrum_wallet provider is supported in v6.0.0+
                // Legacy blockchain_info provider removed (security: logged secrets in URLs)
                $ret_info_array = BWWC__get_bitcoin_address_for_payment__electrum(BWWC__get_next_available_mpk(), $order_info);
                $bitcoins_address = @$ret_info_array['generated_bitcoin_address'];

                if (!$bitcoins_address) {
                    $msg = "ERROR: cannot generate Bitcoin SV address for the order: '" . @$ret_info_array['message'] . "'";
                    BWWC__log_event(__FILE__, __LINE__, $msg);
                    
                    // User-friendly error message
                    $user_msg = '<div style="max-width: 600px; margin: 50px auto; padding: 30px; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
                    $user_msg .= '<h2 style="color: #dc3545; margin-top: 0;">' . esc_html__('Payment Address Generation Error', 'bsvanon-bitcoin-sv-payments') . '</h2>';
                    $user_msg .= '<p>' . esc_html__('We\'re unable to generate a payment address for your order at this time.', 'bsvanon-bitcoin-sv-payments') . '</p>';
                    $user_msg .= '<p><strong>' . esc_html__('What you can do:', 'bsvanon-bitcoin-sv-payments') . '</strong></p>';
                    $user_msg .= '<ul>';
                    $user_msg .= '<li>' . esc_html__('Try placing your order again', 'bsvanon-bitcoin-sv-payments') . '</li>';
                    $user_msg .= '<li>' . esc_html__('Contact the store owner for assistance', 'bsvanon-bitcoin-sv-payments') . '</li>';
                    $user_msg .= '<li>' . esc_html__('Choose an alternative payment method', 'bsvanon-bitcoin-sv-payments') . '</li>';
                    $user_msg .= '</ul>';
                    $user_msg .= '<p style="margin-top: 20px;"><a href="' . esc_url(wc_get_checkout_url()) . '" style="display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px;">' . esc_html__('Return to Checkout', 'bsvanon-bitcoin-sv-payments') . '</a></p>';
                    $user_msg .= '</div>';
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    exit($user_msg);
                }

                BWWC__log_event(__FILE__, __LINE__, "     Generated unique Bitcoin SV address: '{$bitcoins_address}' for order_id " . $order_id);
            }

            // Store payment details based on mode
            if ($processing_mode === 'hosted_invoicing' && $hosted_session_data) {
                // Store hosted session data
                $order->update_meta_data('_bwwc_hosted_session_id', $hosted_session_data['session_id'] ?? '');
                $order->update_meta_data('_bwwc_hosted_intent_id', $hosted_session_data['intent_id'] ?? '');
                $order->update_meta_data('_bwwc_hosted_invoice_id', $hosted_session_data['invoice_id'] ?? '');
                $order->update_meta_data('_bwwc_hosted_payment_url', $hosted_session_data['payment_url'] ?? '');
                $order->update_meta_data('_bwwc_hosted_status_url', $hosted_session_data['status_url'] ?? '');
                $order->update_meta_data('_bwwc_hosted_recheck_url', $hosted_session_data['recheck_url'] ?? '');
                $order->update_meta_data('_bwwc_hosted_internalize_url', $hosted_session_data['internalize_url'] ?? '');
                $order->update_meta_data('_bwwc_hosted_qr_code', $hosted_session_data['qr_code'] ?? '');
                $order->update_meta_data('_bwwc_hosted_expires_at', $hosted_session_data['expires_at'] ?? '');
                $order->update_meta_data('_bwwc_hosted_raw', $hosted_session_data['raw'] ?? array());
                $order->update_meta_data('_bwwc_processing_mode', 'hosted_invoicing');
                
                // Also store address for backward compatibility
                $order->update_meta_data('_bwwc_address', $bitcoins_address);
            } else {
                // Store standalone xPub data
                $order->update_meta_data('_bwwc_address', $bitcoins_address);
                $order->update_meta_data('_bwwc_processing_mode', 'standalone_xpub');
            }
            
            // Common metadata for both modes
            $order->update_meta_data('_bwwc_order_total_in_btc', $order_total_in_btc);
            $order->update_meta_data('_bwwc_paid_total', 0);
            $order->update_meta_data('_bwwc_refunded', 0);
            $order->update_meta_data('_bwwc_exchange_rate', $exchange_rate);
            
            // Store expected amount in satoshis for UI
            $expected_sats = intval(round($order_total_in_btc * 100000000));
            if ($processing_mode === 'hosted_invoicing' && !empty($hosted_session_data['expected_sats'])) {
                $expected_sats = intval($hosted_session_data['expected_sats']);
            }
            $order->update_meta_data('_bwwc_expected_sats', $expected_sats);
            
            // Store expiration timestamp
            $bwwc_settings = BWWC__get_settings();
            $expires_at = time() + ($bwwc_settings['assigned_address_expires_in_mins'] * 60);
            $order->update_meta_data('_bwwc_expires_at', $expires_at);
            
            // Initialize payment state
            BWWC__set_payment_state($order_id, BWWC_PAYMENT_STATE_WAITING, 'Payment initialized');
            $order->update_meta_data('_bwwc_received_sats', 0);
            $order->update_meta_data('_bwwc_confirmed_sats', 0);
            $order->update_meta_data('_bwwc_incoming_payments', array());
            $order->update_meta_data('_bwwc_payment_completed', 0);
            $order->save();
            //-----------------------------------

            // The bitcoin gateway does not take payment immediately, but it does need to change the orders status to on-hold
            // (so the store owner knows that bitcoin payment is pending).
            // We also need to tell WooCommerce that it needs to redirect to the thankyou page – this is done with the returned array
            // and the result being a success.
            //
            global $woocommerce;

            //	Updating the order status:

            // Mark as on-hold (we're awaiting for bitcoins payment to arrive)
            $order->update_status('on-hold', __('Awaiting Bitcoin SV payment to arrive', 'bsvanon-bitcoin-sv-payments'));

            /*
                        ///////////////////////////////////////
                        // timbowhite's suggestion:
                        // -----------------------
                        // Mark as pending (we're awaiting for bitcoins payment to arrive), not 'on-hold' since
                  // woocommerce does not automatically cancel expired on-hold orders. Woocommerce handles holding the stock
                  // for pending orders until order payment is complete.
                        $order->update_status('pending', __('Awaiting bitcoin payment to arrive', 'bsvanon-bitcoin-sv-payments'));
            
                        // Me: 'pending' does not trigger "Thank you" page and neither email sending. Not sure why.
                        //			Also - I think cancellation of unpaid orders needs to be initiated from cron job, as only we know when order needs to be cancelled,
                        //			by scanning "on-hold" orders through 'assigned_address_expires_in_mins' timeout check.
                        ///////////////////////////////////////
            */
            // Remove cart
            $woocommerce->cart->empty_cart();

            // Empty awaiting payment session
            unset($_SESSION['order_awaiting_payment']);

            // Return thankyou redirect
            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
                return array(
                    'result' 	=> 'success',
                    'redirect'	=> add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks'))))
                );
            } else {
                return array(
                        'result' 	=> 'success',
                        'redirect'	=> add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, $this->get_return_url($order)))
                    );
            }
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        public function BWWC__thankyou_page($order_id)
        {
            // BWWC__thankyou_page is hooked into the "thank you" page and in the simplest case can just echo’s the description.

            // Get order object.
            // http://wcdocs.woothemes.com/apidocs/class-WC_Order.html
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== 'bitcoin_sv') {
                return;
            }

            // Render modern payment console
            BWWC__render_payment_console($order);
            
            $order_total_in_btc = $order->get_meta('_bwwc_order_total_in_btc', true);
            if (empty($order_total_in_btc)) {
                $order_total_in_btc = get_post_meta($order->get_id(), 'order_total_in_btc', true);
            }
            $bitcoins_address = $order->get_meta('_bwwc_address', true);
            if (empty($bitcoins_address)) {
                $bitcoins_address = get_post_meta($order->get_id(), 'bitcoins_address', true);
            }
            $order->add_order_note(
                sprintf(
                    /* translators: 1: price in BSV, 2: Bitcoin address */
                    __('Order instructions: price=&#3647;%1$s, incoming account:%2$s', 'bsvanon-bitcoin-sv-payments'),
                    $order_total_in_btc,
                    $bitcoins_address
                )
            );
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @return void
         */
        public function BWWC__email_instructions($order, $sent_to_admin)
        {
            if ($sent_to_admin) {
                return;
            }
            if (!in_array($order->get_status(), array('pending', 'on-hold'), true)) {
                return;
            }
            if ($order->get_payment_method() !== 'bitcoin_sv') {
                return;
            }

            // Check if email instructions are enabled
            $bwwc_settings = BWWC__get_settings();
            if (isset($bwwc_settings['email_instructions_enabled']) && !$bwwc_settings['email_instructions_enabled']) {
                return;
            }

            // Get payment details
            $order_id = $order->get_id();
            $order_total_in_btc = $order->get_meta('_bwwc_order_total_in_btc', true);
            if (empty($order_total_in_btc)) {
                $order_total_in_btc = get_post_meta($order_id, 'order_total_in_btc', true);
            }
            $bitcoins_address = $order->get_meta('_bwwc_address', true);
            if (empty($bitcoins_address)) {
                $bitcoins_address = get_post_meta($order_id, 'bitcoins_address', true);
            }
            $expected_sats = $order->get_meta('_bwwc_expected_sats', true);
            if (empty($expected_sats)) {
                $expected_sats = get_post_meta($order_id, 'expected_sats', true);
            }
            $expires_at = $order->get_meta('_bwwc_expires_at', true);
            if (empty($expires_at)) {
                $expires_at = get_post_meta($order_id, 'address_expires_at', true);
            }
            
            if (!$bitcoins_address || !$order_total_in_btc) {
                return;
            }

            // Calculate sats if not stored
            if (!$expected_sats) {
                $expected_sats = intval(round($order_total_in_btc * 100000000));
            }

            // Get store currency
            $store_currency = get_woocommerce_currency();
            $order_total = $order->get_total();

            // Generate pay link
            $pay_link = $order->get_checkout_payment_url();

            // Email intro text (customizable in settings)
            $intro_text = isset($bwwc_settings['email_instructions_intro']) && !empty($bwwc_settings['email_instructions_intro']) 
                ? $bwwc_settings['email_instructions_intro'] 
                : __('Complete your Bitcoin SV payment using the details below:', 'bsvanon-bitcoin-sv-payments');

            // Build email content
            echo '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">';
            echo '<h3 style="margin-top: 0; color: #333;">' . esc_html__('Bitcoin SV Payment Instructions', 'bsvanon-bitcoin-sv-payments') . '</h3>';
            echo '<p style="color: #555; line-height: 1.6;">' . esc_html($intro_text) . '</p>';
            
            // Payment amount
            echo '<div style="background: white; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #FCCA09;">';
            echo '<p style="margin: 0 0 8px 0; font-size: 13px; color: #666; font-weight: 600;">' . esc_html__('Amount to Send', 'bsvanon-bitcoin-sv-payments') . '</p>';
            echo '<p style="margin: 0; font-size: 24px; font-weight: 700; color: #333;">' . esc_html(number_format($expected_sats, 0, '.', ',')) . ' <span style="font-size: 14px; font-weight: 600; color: #666;">sats</span></p>';
            echo '<p style="margin: 8px 0 0 0; font-size: 13px; color: #888;">≈ ' . esc_html(sprintf('%s %s', number_format($order_total, 2), $store_currency)) . '</p>';
            echo '</div>';
            
            // Payment address
            echo '<div style="background: white; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #FCCA09;">';
            echo '<p style="margin: 0 0 8px 0; font-size: 13px; color: #666; font-weight: 600;">' . esc_html__('Payment Address', 'bsvanon-bitcoin-sv-payments') . '</p>';
            echo '<p style="margin: 0; font-family: monospace; font-size: 13px; color: #333; word-break: break-all;">' . esc_html($bitcoins_address) . '</p>';
            echo '</div>';
            
            // QR Code note (local generation on payment page)
            echo '<div style="background: #fff3cd; padding: 12px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #ffc107;">';
            echo '<p style="margin: 0; font-size: 13px; color: #856404; line-height: 1.5;">';
            echo '<strong>' . esc_html__('QR Code Available:', 'bsvanon-bitcoin-sv-payments') . '</strong> ';
            echo esc_html__('Click the payment link below to view a scannable QR code and complete your payment.', 'bsvanon-bitcoin-sv-payments');
            echo '</p>';
            echo '</div>';
            
            // Pay link button
            echo '<div style="text-align: center; margin: 20px 0;">';
            echo '<a href="' . esc_url($pay_link) . '" style="display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px;">' . esc_html__('Complete Payment Online', 'bsvanon-bitcoin-sv-payments') . '</a>';
            echo '</div>';
            
            // Expiration notice
            if ($expires_at) {
                $payment_timeout_mins = $bwwc_settings['assigned_address_expires_in_mins'];
                $payment_timeout_hours = $payment_timeout_mins / 60;
                $payment_timeout_display = ($payment_timeout_hours == floor($payment_timeout_hours)) ? (int)$payment_timeout_hours : number_format($payment_timeout_hours, 1);
                echo '<p style="font-size: 12px; color: #888; text-align: center; margin: 15px 0 0 0;">';
                /* translators: %s: payment timeout in hours */
                echo esc_html(sprintf(__('⏱ Please complete payment within %s hours', 'bsvanon-bitcoin-sv-payments'), $payment_timeout_display));
                echo '</p>';
            }
            
            // Important notes
            echo '<div style="background: #fff3cd; padding: 12px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #ffc107;">';
            echo '<p style="margin: 0; font-size: 12px; color: #856404; line-height: 1.5;"><strong>' . esc_html__('Important:', 'bsvanon-bitcoin-sv-payments') . '</strong> ' . esc_html__('Only send Bitcoin SV (BSV) to this address. Other cryptocurrencies (BTC, BCH, etc.) will be lost.', 'bsvanon-bitcoin-sv-payments') . '</p>';
            echo '</div>';
            
            echo '</div>';
        }
        //-------------------------------------------------------------------

        //-------------------------------------------------------------------
        /**
         * Check for Bitcoin-related IPN callabck
         *
         * @access public
         * @return void
         */
        public function BWWC__maybe_bitcoin_ipn_callback()
        {
            // DISABLED: Legacy blockchain.info IPN callback (security vulnerability A0.6)
            // This callback path is no longer used as the plugin uses direct blockchain monitoring
            // Reasons for removal:
            // - Logs full $_REQUEST including secrets (catastrophic if logs accessible)
            // - Uses non-constant-time secret comparison (timing attack)
            // - No rate limiting (DoS vector)
            // - Legacy blockchain.info service no longer relevant for BSV
            
            if (isset($_REQUEST['bitcoinway']) && $_REQUEST['bitcoinway'] == '1') {
                BWWC__log_event(__FILE__, __LINE__, "Legacy IPN callback disabled. Plugin now uses direct blockchain monitoring.", 'warning');
                exit('Legacy IPN callback disabled');
            }
        }
        //-------------------------------------------------------------------
    }
    //=======================================================================


    //-----------------------------------------------------------------------
    // Hook into WooCommerce - add necessary hooks and filters
    add_filter('woocommerce_payment_gateways', 'BWWC__add_bitcoin_gateway');
    
    // Register WooCommerce Blocks support
    add_action('woocommerce_blocks_loaded', 'BWWC__register_blocks_support');

    // Disable unnecessary billing fields.
    /// Note: it affects whole store.
    /// add_filter ('woocommerce_checkout_fields' , 	'BWWC__woocommerce_checkout_fields' );

    add_filter('woocommerce_currencies', 'BWWC__add_btc_currency');
    add_filter('woocommerce_currency_symbol', 'BWWC__add_btc_currency_symbol', 10, 2);

    // Change [Order] button text on checkout screen.
    /// Note: this will affect all payment methods.
    /// add_filter ('woocommerce_order_button_text', 	'BWWC__order_button_text');
    //-----------------------------------------------------------------------

    //=======================================================================
    /**
     * Add the gateway to WooCommerce
     *
     * @access public
     * @param array $methods
     * @package
     * @return array/

     */
    function BWWC__add_bitcoin_gateway($methods)
    {
        $methods[] = 'BWWC_Bitcoin';
        return $methods;
    }
    //=======================================================================

    //=======================================================================
    /**
     * Register WooCommerce Blocks integration
     */
    function BWWC__register_blocks_support()
    {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        require_once dirname(__FILE__) . '/includes/class-bsv-blocks-integration.php';

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function($payment_method_registry) {
                $payment_method_registry->register(new BWWC_WC_Gateway_Blocks_Support());
            }
        );
    }
    //=======================================================================

    //=======================================================================
    // Our hooked in function - $fields is passed via the filter!
    function BWWC__woocommerce_checkout_fields($fields)
    {
        unset($fields['order']['order_comments']);
        unset($fields['billing']['billing_first_name']);
        unset($fields['billing']['billing_last_name']);
        unset($fields['billing']['billing_company']);
        unset($fields['billing']['billing_address_1']);
        unset($fields['billing']['billing_address_2']);
        unset($fields['billing']['billing_city']);
        unset($fields['billing']['billing_postcode']);
        unset($fields['billing']['billing_country']);
        unset($fields['billing']['billing_state']);
        unset($fields['billing']['billing_phone']);
        return $fields;
    }
    //=======================================================================

    //=======================================================================
    function BWWC__add_btc_currency($currencies)
    {
        $currencies['BSV'] = __('Bitcoin SV (฿)', 'bsvanon-bitcoin-sv-payments');
        return $currencies;
    }
    //=======================================================================

    //=======================================================================
    function BWWC__add_btc_currency_symbol($currency_symbol, $currency)
    {
        switch ($currency) {
            case 'BTC':
                $currency_symbol = '฿';
                break;
        }

        return $currency_symbol;
    }
    //=======================================================================

    //=======================================================================
    function BWWC__order_button_text()
    {
        return 'Continue';
    }
    //=======================================================================
}
//###########################################################################

//===========================================================================
function BWWC__process_payment_completed_for_order($order_id, $bitcoins_paid=false)
{
    $order = wc_get_order($order_id);
    if ($bitcoins_paid) {
        $order->update_meta_data('_bwwc_paid_total', $bitcoins_paid);
    }

    // Payment completed
    // Make sure this logic is done only once, in case customer keep sending payments :)
    if (!$order->get_meta('_bwwc_payment_completed', true)) {
        $order->update_meta_data('_bwwc_payment_completed', 1);

        BWWC__log_event(__FILE__, __LINE__, "Success: order '{$order_id}' paid in full. Processing and notifying customer ...");

        // Instantiate order object.
        // $order = new WC_Order($order_id); // Deprecated, using wc_get_order above

        $order->add_order_note(__('Order paid in full', 'bsvanon-bitcoin-sv-payments'));

        $order->payment_complete();

        $bwwc_settings = BWWC__get_settings();
        if ($bwwc_settings['autocomplete_paid_orders']) {
            // Ensure order is completed.
            $order->update_status('completed', __('Order marked as completed according to Bitcoin SV plugin settings', 'bsvanon-bitcoin-sv-payments'));
        }

        // Notify admin about payment processed
        $email = get_option('admin_email');
        if ($email) {
            // Send email from admin to admin
            BWWC__send_email(
                $email,
                $email,
                "Full payment received for order ID: '{$order_id}'",
                "Order ID: '{$order_id}' paid in full. <br />Received BSV: '$bitcoins_paid'.<br />Please process and complete order for customer."
                );
        }
    }
    $order->save();
}
//===========================================================================

//===========================================================================
// v6.0.0: Removed BWWC__add_wallet_topup_link() function (A0.3)
// Top-up link now only appears on payment console page, not checkout
// This addresses merchant trust concerns and WP.org promotional content guidelines
//===========================================================================
