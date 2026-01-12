<?php
/*
Bitcoin SV Payments for WooCommerce
https://github.com/mboyd1/bitcoin-sv-payments-for-woocommerce
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
            $this->id				= 'bitcoin';
            $this->icon 			= plugins_url('/images/bsv_buyitnow_32x.png', __FILE__);	// 32 pixels high
            $this->has_fields 		= false;
            $this->method_title     = __('Bitcoin SV', 'woocommerce');

            // Load BWWC settings.
            $bwwc_settings = BWWC__get_settings();
            $this->service_provider = $bwwc_settings['service_provider']; // This need to be before $this->init_settings otherwise it generate PHP Notice: "Undefined property: BWWC_Bitcoin::$service_provider" down below.

            // Load the form fields.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title', __('Bitcoin SV Payment', 'woocommerce'));
            $this->bitcoin_addr_merchant = $this->get_option('bitcoin_addr_merchant', '');
            $this->confs_num = $bwwc_settings['confs_num'];  //$this->settings['confirmations'];
            $this->description = $this->get_option('description', __('Please proceed to the next screen to see necessary payment details.', 'woocommerce'));	// Short description about the gateway which is shown on checkout.
            $this->instructions = $this->get_option('instructions', ''); // Detailed payment instructions for the buyer.
            $this->instructions_multi_payment_str  = __('You may send payments from multiple accounts to reach the total required.', 'woocommerce');
            $this->instructions_single_payment_str = __('You must pay in a single payment in full.', 'woocommerce');
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
                $reason_message = __("Bitcoin SV Service Provider is not selected", 'woocommerce');
                $valid = false;
            } elseif ($this->service_provider=='blockchain_info') {
                if ($this->bitcoin_addr_merchant == '') {
                    $reason_message = __("Your personal Bitcoin SV address is not selected", 'woocommerce');
                    $valid = false;
                } elseif ($this->bitcoin_addr_merchant == '18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj') {
                    $reason_message = __("Your personal Bitcoin SV address is invalid. The address specified is the donation address :)", 'woocommerce');
                    $valid = false;
                }
            } elseif ($this->service_provider=='electrum_wallet') {
                $mpk = BWWC__get_next_available_mpk();
                if (!$mpk) {
                    $reason_message = __("Please specify ElectrumSV Master Public Key (MPK) in Bitcoin SV plugin settings. <br />To retrieve MPK: launch your ElectrumSV wallet, select: Wallet->Information", 'woocommerce');
                    $valid = false;
                } elseif (!preg_match('/^[a-f0-9]{128}$/', $mpk) && !preg_match('/^xpub[a-zA-Z0-9]{107}$/', $mpk)) {
                    $reason_message = __("ElectrumSV Master Public Key is invalid. Must be 128 or 111 characters long, consisting of digits and letters.", 'woocommerce');
                    $valid = false;
                } elseif (!extension_loaded('gmp') && !extension_loaded('bcmath')) {
                    $reason_message = __("ERROR: neither 'bcmath' nor 'gmp' math extensions are loaded For ElectrumSV wallet options to function. Contact your hosting company and ask them to enable either 'bcmath' or 'gmp' extensions. 'gmp' is preferred (much faster)! \nAlternatively you may choose another 'Bitcoin SV Service Provider' option.", 'woocommerce');
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
            //  $reason_message = __("Store currency is set to unsupported value", 'woocommerce') . "('{$currency_code}'). " . __("Valid currencies: ", 'woocommerce') . implode ($supported_currencies_arr, ", ");
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
                $exchange_rate_display .= '<strong>' . __('Current Exchange Rate:', 'woocommerce') . '</strong> ';
                $exchange_rate_display .= '1 BSV = ' . number_format((float)$currency_ticker, 2) . ' ' . esc_html($currency_code);
                $exchange_rate_display .= ' <span style="color: #666; font-size: 12px;">(' . __('via CoinGecko', 'woocommerce') . ')</span>';
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
    <td colspan="2">' . __('Please send your Bitcoin SV payment as follows:', 'woocommerce') . '</td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-amount">
      ' . __('Amount', 'woocommerce') . ' (<strong>BSV</strong>):
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
	    QR Code:
    </td>
    <td class="bpit-td-value bpit-td-value-qr">
      <div style="border:1px solid #FCCA09;padding:5px;margin:2px;background-color:#FCF8E3;border-radius:4px;">
        <a href="bitcoin:{{{BITCOINS_ADDRESS}}}?sv&amount={{{BITCOINS_AMOUNT}}}"><img src="https://api.qrserver.com/v1/create-qr-code/?color=000000&amp;bgcolor=FFFFFF&amp;data=bitcoin%3A{{{BITCOINS_ADDRESS}}}%3Fsv%26amount%3D{{{BITCOINS_AMOUNT}}}&amp;qzone=1&amp;margin=0&amp;size=180x180&amp;ecc=L" style="vertical-align:middle;border:1px solid #888;" /></a>
      </div>
    </td>
  </tr>
</table>

' . __('Please note:', 'woocommerce') . '
<ol class="bpit-instructions">
    <li>' . __('We ONLY accept Bitcoin SV (BSV). Any other payments (BTC/BCH) will not process and the money will be lost!', 'woocommerce') . '</li>
    <li>' . __('We are not responsible for lost funds if you send BTC or BCH instead of BSV', 'woocommerce') . '</li>
    <li>' . sprintf(__('You must make a payment within %s hours, or your order may be cancelled', 'woocommerce'), $payment_timeout_display) . '</li>
    <li>' . __('As soon as your payment is received in full you will receive email confirmation with order delivery details.', 'woocommerce') . '</li>
    <li>{{{EXTRA_INSTRUCTIONS}}}</li>
</ol>
';
            $payment_instructions = trim($payment_instructions);

            $payment_instructions_description = '
						  <p class="description" style="width:50%;float:left;width:49%;">
					    	' . __('Specific instructions given to the customer to complete Bitcoins payment.<br />You may change it, but make sure these tags will be present: <b>{{{BITCOINS_AMOUNT}}}</b>, <b>{{{BITCOINS_ADDRESS}}}</b> and <b>{{{EXTRA_INSTRUCTIONS}}}</b> as these tags will be replaced with customer - specific payment details.', 'woocommerce') . '
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
                                'title' => __('Enable/Disable', 'woocommerce'),
                                'type' => 'checkbox',
                                'label' => __('Enable Bitcoin SV Payments', 'woocommerce'),
                                'default' => 'yes'
                            ),
                'exchange_rate_info' => array(
                                'title' => __('Exchange Rate Status', 'woocommerce'),
                                'type' => 'title',
                                'description' => $exchange_rate_display,
                            ),
                'title' => array(
                                'title' => __('Title', 'woocommerce'),
                                'type' => 'text',
                                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                                'default' => __('Bitcoin SV Payment', 'woocommerce')
                            ),

                'bitcoin_addr_merchant' => array(
                                'title' => __('Your personal Bitcoin SV address', 'woocommerce'),
                                'type' => 'text',
                                'css'     => $this->service_provider!='blockchain_info'?'display:none;':'',
                                'disabled' => $this->service_provider!='blockchain_info'?true:false,
                                'description' => $this->service_provider!='blockchain_info'?__('Not used with current address generation method. This plugin uses BIP32/BIP44 HD Wallet (xPub) for secure per-order address derivation. Configure your Master Public Key in the BSV Plugin settings page.', 'woocommerce'):__('Your own bitcoin address (such as: 18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj) - where you would like the payment to be sent. When customer sends you payment for the product - it will be automatically forwarded to this address by blockchain.info APIs.', 'woocommerce'),
                                'default' => '',
                            ),


                'description' => array(
                                'title' => __('Customer Message', 'woocommerce'),
                                'type' => 'text',
                                'description' => __('Initial instructions for the customer at checkout screen', 'woocommerce'),
                                'default' => __('Please proceed to the next screen to see necessary payment details.', 'woocommerce')
                            ),
                'instructions' => array(
                                'title' => __('Payment Instructions (HTML)', 'woocommerce'),
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
                                            '	<tr><td colspan="2">' . __('Please send your Bitcoin SV  payment as follows:', 'woocommerce' ) . '</td></tr>' .
                                            '	<tr><td>Amount (฿): </td><td><div style="border:1px solid #CCC;padding:2px 6px;margin:2px;background-color:#FEFEF0;border-radius:4px;color:#CC0000;">{{{BITCOINS_AMOUNT}}}</div></td></tr>' .
                                            '	<tr><td>Address: </td><td><div style="border:1px solid #CCC;padding:2px 6px;margin:2px;background-color:#FEFEF0;border-radius:4px;color:blue;">{{{BITCOINS_ADDRESS}}}</div></td></tr>' .
                                            '</table>' .
                                            __('Please note:', 'woocommerce' ) .
                                            '<ol>' .
                                            '   <li>' . __('You must make a payment within 8 hours, or your order will be cancelled', 'woocommerce' ) . '</li>' .
                                            '   <li>' . __('As soon as your payment is received in full you will receive email confirmation with order delivery details.', 'woocommerce' ) . '</li>' .
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
	    	<h3><?php _e('Bitcoin SV Payment', 'woocommerce'); ?></h3>
	    	<p>
	    		<?php _e(
                'Allows to accept payments in Bitcoin SV. <a href="https://bitcoinsv.io" target="_blank">Bitcoin SV</a> is peer-to-peer electronic cash that enables instant payments from anyone to anyone, anywhere in the world',
                        'woocommerce'
            ); ?>
	    	</p>
	    	<?php
                echo $store_valid ? ('<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#004400;background-color:#CCFFCC;">' .
            __('Bitcoin SV payment gateway is operational', 'woocommerce') .
            '</p>') : ('<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#EE0000;background-color:#FFFFAA;">' .
            __('Bitcoin SV payment gateway is not operational (try to re-enter and save Bitcoinway Plugin settings): ', 'woocommerce') . $validation_msg . '</p>'); ?>
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

     //    // Born from __(..., 'woocommerce') + '$this->id'
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
                $user_msg .= '<h2 style="color: #dc3545; margin-top: 0;">' . __('Payment Processing Error', 'woocommerce') . '</h2>';
                $user_msg .= '<p>' . __('We\'re unable to process Bitcoin SV payments at this time due to a temporary issue fetching exchange rates.', 'woocommerce') . '</p>';
                $user_msg .= '<p><strong>' . __('What you can do:', 'woocommerce') . '</strong></p>';
                $user_msg .= '<ul>';
                $user_msg .= '<li>' . __('Try again in a few minutes', 'woocommerce') . '</li>';
                $user_msg .= '<li>' . __('Contact the store owner for assistance', 'woocommerce') . '</li>';
                $user_msg .= '<li>' . __('Choose an alternative payment method', 'woocommerce') . '</li>';
                $user_msg .= '</ul>';
                $user_msg .= '<p style="margin-top: 20px;"><a href="' . esc_url(wc_get_checkout_url()) . '" style="display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px;">' . __('Return to Checkout', 'woocommerce') . '</a></p>';
                $user_msg .= '</div>';
                exit($user_msg);
            }

            $order_total_in_btc   = ($order->get_total() / $exchange_rate);
            if (get_woocommerce_currency() != 'BTC') {
                // Apply exchange rate multiplier only for stores with non-bitcoin default currency.
                $order_total_in_btc = $order_total_in_btc;
            }

            $order_total_in_btc   = sprintf("%.8f", $order_total_in_btc);

            $bitcoins_address = false;

            $order_info =
            array(
                'order_meta'							=> $order_meta,
                'order_id'								=> $order_id,
                'order_total'			    	 	=> $order_total_in_btc,  // Order total in BTC
                'order_datetime'  				=> date('Y-m-d H:i:s T'),
                'requested_by_ip'					=> isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                'requested_by_ua'					=> isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                'requested_by_srv'				=> BWWC__base64_encode(serialize($_SERVER)),
                );

            $ret_info_array = array();

            if ($this->service_provider == 'blockchain_info') {
                $bitcoin_addr_merchant = $this->bitcoin_addr_merchant;
                $secret_key = substr(md5(microtime()), 0, 16);	# Generate secret key to be validate upon receiving IPN callback to prevent spoofing.
                $callback_url = trailingslashit(home_url()) . "?wc-api=BWWC_Bitcoin&secret_key={$secret_key}&bitcoinway=1&src=bcinfo&order_id={$order_id}"; // http://www.example.com/?bitcoinway=1&order_id=74&src=bcinfo
            BWWC__log_event(__FILE__, __LINE__, "Calling BWWC__generate_temporary_bitcoin_address__blockchain_info(). Payments to be forwarded to: '{$bitcoin_addr_merchant}' with callback URL: '{$callback_url}' ...");

                // This function generates temporary bitcoin address and schedules IPN callback at the same
                $ret_info_array = BWWC__generate_temporary_bitcoin_address__blockchain_info($bitcoin_addr_merchant, $callback_url);
    
                /*
            $ret_info_array = array (
               'result'                      => 'success', // OR 'error'
               'message'										 => '...',
               'host_reply_raw'              => '......',
               'generated_bitcoin_address'   => '18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj', // or false
               );
                */
                $bitcoins_address = @$ret_info_array['generated_bitcoin_address'];
            } elseif ($this->service_provider == 'electrum_wallet') {
                // Generate bitcoin address for ElectrumSV wallet provider.
                /*
            $ret_info_array = array (
               'result'                      => 'success', // OR 'error'
               'message'										 => '...',
               'host_reply_raw'              => '......',
               'generated_bitcoin_address'   => '18vzABPyVbbia8TDCKDtXJYXcoAFAPk2cj', // or false
               );
                */
                $ret_info_array = BWWC__get_bitcoin_address_for_payment__electrum(BWWC__get_next_available_mpk(), $order_info);
                $bitcoins_address = @$ret_info_array['generated_bitcoin_address'];
            }

            if (!$bitcoins_address) {
                $msg = "ERROR: cannot generate Bitcoin SV address for the order: '" . @$ret_info_array['message'] . "'";
                BWWC__log_event(__FILE__, __LINE__, $msg);
                
                // User-friendly error message
                $user_msg = '<div style="max-width: 600px; margin: 50px auto; padding: 30px; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
                $user_msg .= '<h2 style="color: #dc3545; margin-top: 0;">' . __('Payment Address Generation Error', 'woocommerce') . '</h2>';
                $user_msg .= '<p>' . __('We\'re unable to generate a payment address for your order at this time.', 'woocommerce') . '</p>';
                $user_msg .= '<p><strong>' . __('What you can do:', 'woocommerce') . '</strong></p>';
                $user_msg .= '<ul>';
                $user_msg .= '<li>' . __('Try placing your order again', 'woocommerce') . '</li>';
                $user_msg .= '<li>' . __('Contact the store owner for assistance', 'woocommerce') . '</li>';
                $user_msg .= '<li>' . __('Choose an alternative payment method', 'woocommerce') . '</li>';
                $user_msg .= '</ul>';
                $user_msg .= '<p style="margin-top: 20px;"><a href="' . esc_url(wc_get_checkout_url()) . '" style="display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px;">' . __('Return to Checkout', 'woocommerce') . '</a></p>';
                $user_msg .= '</div>';
                exit($user_msg);
            }

            BWWC__log_event(__FILE__, __LINE__, "     Generated unique Bitcoin SV address: '{$bitcoins_address}' for order_id " . $order_id);

            if ($this->service_provider == 'blockchain_info') {
                update_post_meta(
                 $order_id, 			// post id ($order_id)
                 'secret_key', 	// meta key
                 $secret_key 		// meta value. If array - will be auto-serialized
                 );
            }

            update_post_meta(
             $order_id, 			// post id ($order_id)
             'order_total_in_btc', 	// meta key
             $order_total_in_btc 	// meta value. If array - will be auto-serialized
             );
            update_post_meta(
             $order_id, 			// post id ($order_id)
             'bitcoins_address',	// meta key
             $bitcoins_address 	// meta value. If array - will be auto-serialized
             );
            update_post_meta(
             $order_id, 			// post id ($order_id)
             'bitcoins_paid_total',	// meta key
             "0" 	// meta value. If array - will be auto-serialized
             );
            update_post_meta(
             $order_id, 			// post id ($order_id)
             'bitcoins_refunded',	// meta key
             "0" 	// meta value. If array - will be auto-serialized
             );
             update_post_meta(
              $order_id, 			// post id ($order_id)
              'bsv_exchange_rate',	// meta key
              $exchange_rate 	// meta value. If array - will be auto-serialized
              );
            update_post_meta(
             $order_id, 				// post id ($order_id)
             '_incoming_payments',	// meta key. Starts with '_' - hidden from UI.
             array()					// array (array('datetime'=>'', 'from_addr'=>'', 'amount'=>''),)
             );
            update_post_meta(
             $order_id, 				// post id ($order_id)
             '_payment_completed',	// meta key. Starts with '_' - hidden from UI.
             0					// array (array('datetime'=>'', 'from_addr'=>'', 'amount'=>''),)
             );
            //-----------------------------------


            // The bitcoin gateway does not take payment immediately, but it does need to change the orders status to on-hold
            // (so the store owner knows that bitcoin payment is pending).
            // We also need to tell WooCommerce that it needs to redirect to the thankyou page – this is done with the returned array
            // and the result being a success.
            //
            global $woocommerce;

            //	Updating the order status:

            // Mark as on-hold (we're awaiting for bitcoins payment to arrive)
            $order->update_status('on-hold', __('Awaiting Bitcoin SV payment to arrive', 'woocommerce'));

            /*
                        ///////////////////////////////////////
                        // timbowhite's suggestion:
                        // -----------------------
                        // Mark as pending (we're awaiting for bitcoins payment to arrive), not 'on-hold' since
                  // woocommerce does not automatically cancel expired on-hold orders. Woocommerce handles holding the stock
                  // for pending orders until order payment is complete.
                        $order->update_status('pending', __('Awaiting bitcoin payment to arrive', 'woocommerce'));
            
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
            if (!$order) {
                return;
            }

            // Render modern payment console
            BWWC__render_payment_console($order);
            
            // Add order note
            $order_total_in_btc = get_post_meta($order->get_id(), 'order_total_in_btc', true);
            $bitcoins_address = get_post_meta($order->get_id(), 'bitcoins_address', true);
            $order->add_order_note(__("Order instructions: price=&#3647;{$order_total_in_btc}, incoming account:{$bitcoins_address}", 'woocommerce'));
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
            if ($order->get_payment_method() !== 'bitcoin') {
                return;
            }

            // Check if email instructions are enabled
            $bwwc_settings = BWWC__get_settings();
            if (isset($bwwc_settings['email_instructions_enabled']) && !$bwwc_settings['email_instructions_enabled']) {
                return;
            }

            // Get payment details
            $order_id = $order->get_id();
            $order_total_in_btc = get_post_meta($order_id, 'order_total_in_btc', true);
            $bitcoins_address = get_post_meta($order_id, 'bitcoins_address', true);
            $expected_sats = get_post_meta($order_id, 'expected_sats', true);
            $expires_at = get_post_meta($order_id, 'address_expires_at', true);
            
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
                : __('Complete your Bitcoin SV payment using the details below:', 'bitcoin-sv-payments-for-woocommerce');

            // Generate QR code URL
            $bip21_uri = 'bitcoin:' . $bitcoins_address . '?amount=' . $order_total_in_btc;
            $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($bip21_uri);

            // Check if QR should be included
            $include_qr = !isset($bwwc_settings['email_instructions_include_qr']) || $bwwc_settings['email_instructions_include_qr'];

            // Build email content
            echo '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">';
            echo '<h3 style="margin-top: 0; color: #333;">' . esc_html__('Bitcoin SV Payment Instructions', 'bitcoin-sv-payments-for-woocommerce') . '</h3>';
            echo '<p style="color: #555; line-height: 1.6;">' . esc_html($intro_text) . '</p>';
            
            // Payment amount
            echo '<div style="background: white; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #FCCA09;">';
            echo '<p style="margin: 0 0 8px 0; font-size: 13px; color: #666; font-weight: 600;">' . esc_html__('Amount to Send', 'bitcoin-sv-payments-for-woocommerce') . '</p>';
            echo '<p style="margin: 0; font-size: 24px; font-weight: 700; color: #333;">' . esc_html(number_format($expected_sats, 0, '.', ',')) . ' <span style="font-size: 14px; font-weight: 600; color: #666;">sats</span></p>';
            echo '<p style="margin: 8px 0 0 0; font-size: 13px; color: #888;">≈ ' . esc_html(sprintf('%s %s', number_format($order_total, 2), $store_currency)) . '</p>';
            echo '</div>';
            
            // Payment address
            echo '<div style="background: white; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #FCCA09;">';
            echo '<p style="margin: 0 0 8px 0; font-size: 13px; color: #666; font-weight: 600;">' . esc_html__('Payment Address', 'bitcoin-sv-payments-for-woocommerce') . '</p>';
            echo '<p style="margin: 0; font-family: monospace; font-size: 13px; color: #333; word-break: break-all;">' . esc_html($bitcoins_address) . '</p>';
            echo '</div>';
            
            // QR Code
            if ($include_qr) {
                echo '<div style="text-align: center; margin: 20px 0;">';
                echo '<p style="margin: 0 0 10px 0; font-size: 13px; color: #666;">' . esc_html__('Scan to Pay', 'bitcoin-sv-payments-for-woocommerce') . '</p>';
                echo '<img src="' . esc_url($qr_url) . '" alt="QR Code" style="max-width: 200px; border: 8px solid white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);" />';
                echo '</div>';
            }
            
            // Pay link button
            echo '<div style="text-align: center; margin: 20px 0;">';
            echo '<a href="' . esc_url($pay_link) . '" style="display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px;">' . esc_html__('Complete Payment Online', 'bitcoin-sv-payments-for-woocommerce') . '</a>';
            echo '</div>';
            
            // Expiration notice
            if ($expires_at) {
                $payment_timeout_mins = $bwwc_settings['assigned_address_expires_in_mins'];
                $payment_timeout_hours = $payment_timeout_mins / 60;
                $payment_timeout_display = ($payment_timeout_hours == floor($payment_timeout_hours)) ? (int)$payment_timeout_hours : number_format($payment_timeout_hours, 1);
                echo '<p style="font-size: 12px; color: #888; text-align: center; margin: 15px 0 0 0;">';
                echo esc_html(sprintf(__('⏱ Please complete payment within %s hours', 'bitcoin-sv-payments-for-woocommerce'), $payment_timeout_display));
                echo '</p>';
            }
            
            // Important notes
            echo '<div style="background: #fff3cd; padding: 12px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #ffc107;">';
            echo '<p style="margin: 0; font-size: 12px; color: #856404; line-height: 1.5;"><strong>' . esc_html__('Important:', 'bitcoin-sv-payments-for-woocommerce') . '</strong> ' . esc_html__('Only send Bitcoin SV (BSV) to this address. Other cryptocurrencies (BTC, BCH, etc.) will be lost.', 'bitcoin-sv-payments-for-woocommerce') . '</p>';
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
            // If example.com/?bitcoinway=1 is present - it is callback URL.
            if (isset($_REQUEST['bitcoinway']) && $_REQUEST['bitcoinway'] == '1') {
                BWWC__log_event(__FILE__, __LINE__, "BWWC__maybe_bitcoin_ipn_callback () called and 'bitcoinway=1' detected. REQUEST  =  " . serialize(@$_REQUEST));

                if (!isset($_GET['src']) || $_GET['src'] != 'bcinfo') {
                    $src = isset($_GET['src']) ? $_GET['src'] : 'unknown';
                    BWWC__log_event(__FILE__, __LINE__, "Warning: received IPN notification with 'src'= '{$src}', which is not matching expected: 'bcinfo'. Ignoring ...");
                    exit();
                }

                // Processing IPN callback from blockchain.info ('bcinfo')


                $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

                $secret_key = get_post_meta($order_id, 'secret_key', true);
                $secret_key_sent = isset($_GET['secret_key']) ? sanitize_text_field($_GET['secret_key']) : '';
                // Check the Request secret_key matches the original one (blockchain.info sends all params back)
                if ($secret_key_sent != $secret_key) {
                    BWWC__log_event(__FILE__, __LINE__, "Warning: secret_key does not match! secret_key sent: '{$secret_key_sent}'. Expected: '{$secret_key}'. Processing aborted.");
                    exit('Invalid secret_key');
                }

                $confirmations = isset($_GET['confirmations']) ? intval($_GET['confirmations']) : 0;


                if ($confirmations >= $this->confs_num) {

                    // The value of the payment received in satoshi (not including fees). Divide by 100000000 to get the value in BTC.
                    $value_in_btc 		= isset($_GET['value']) ? intval($_GET['value']) / 100000000 : 0;
                    $txn_hash 			= isset($_GET['transaction_hash']) ? sanitize_text_field($_GET['transaction_hash']) : '';
                    $txn_confirmations 	= isset($_GET['confirmations']) ? intval($_GET['confirmations']) : 0;

                    //---------------------------
                    // Update incoming payments array stats
                    $incoming_payments = get_post_meta($order_id, '_incoming_payments', true);
                    $incoming_payments[$txn_hash] =
                        array(
                            'txn_value' 		=> $value_in_btc,
                            'dest_address' 		=> isset($_GET['address']) ? sanitize_text_field($_GET['address']) : '',
                            'confirmations' 	=> $txn_confirmations,
                            'datetime'			=> date("Y-m-d, G:i:s T"),
                            );

                    update_post_meta($order_id, '_incoming_payments', $incoming_payments);
                    //---------------------------

                    //---------------------------
                    // Recalc total amount received for this order by adding totals from uniquely hashed txn's ...
                    $paid_total_so_far = 0;
                    foreach ($incoming_payments as $k => $txn_data) {
                        $paid_total_so_far += $txn_data['txn_value'];
                    }

                    update_post_meta($order_id, 'bitcoins_paid_total', $paid_total_so_far);
                    //---------------------------

                    $order_total_in_btc = get_post_meta($order_id, 'order_total_in_btc', true);
                    if ($paid_total_so_far >= $order_total_in_btc) {
                        BWWC__process_payment_completed_for_order($order_id, false);
                    } else {
                        BWWC__log_event(__FILE__, __LINE__, "NOTE: Payment received (for BSV {$value_in_btc}), but not enough yet to cover the required total. Will be waiting for more. Bitcoin SV: now/total received/needed = {$value_in_btc}/{$paid_total_so_far}/{$order_total_in_btc}");
                    }

                    // Reply '*ok*' so no more notifications are sent
                    exit('*ok*');
                } else {
                    // Number of confirmations are not there yet... Skip it this time ...
                    // Don't print *ok* so the notification resent again on next confirmation
                    BWWC__log_event(__FILE__, __LINE__, "NOTE: Payment notification received (for BSV {$value_in_btc}), but number of confirmations is not enough yet. Confirmations received/required: {$confirmations}/{$this->confs_num}");
                    exit();
                }
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
        $currencies['BSV'] = __('Bitcoin SV (฿)', 'woocommerce');
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
    if ($bitcoins_paid) {
        update_post_meta($order_id, 'bitcoins_paid_total', $bitcoins_paid);
    }

    // Payment completed
    // Make sure this logic is done only once, in case customer keep sending payments :)
    if (!get_post_meta($order_id, '_payment_completed', true)) {
        update_post_meta($order_id, '_payment_completed', '1');

        BWWC__log_event(__FILE__, __LINE__, "Success: order '{$order_id}' paid in full. Processing and notifying customer ...");

        // Instantiate order object.
        $order = new WC_Order($order_id);
        $order->add_order_note(__('Order paid in full', 'woocommerce'));

        $order->payment_complete();

        $bwwc_settings = BWWC__get_settings();
        if ($bwwc_settings['autocomplete_paid_orders']) {
            // Ensure order is completed.
            $order->update_status('completed', __('Order marked as completed according to Bitcoin SV plugin settings', 'woocommerce'));
        }

        // Notify admin about payment processed
        $email = get_settings('admin_email');
        if (!$email) {
            $email = get_option('admin_email');
        }
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
}
//===========================================================================
