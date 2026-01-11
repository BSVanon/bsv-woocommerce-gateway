<?php
/*
Bitcoin SV Payments for WooCommerce
https://github.com/mboyd1/bitcoin-sv-payments-for-woocommerce
*/

// Include everything
include(dirname(__FILE__) . '/bwwc-include-all.php');

//===========================================================================
function BWWC__render_general_settings_page()
{
    BWWC__render_settings_page('general');
}
function BWWC__render_advanced_settings_page()
{
    BWWC__render_settings_page('advanced');
}
//===========================================================================

//===========================================================================
function BWWC__render_settings_page($menu_page_name)
{
    $bwwc_settings = BWWC__get_settings();

    $action_message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            ! isset($_POST['bwwc_settings_nonce']) ||
            ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwwc_settings_nonce'])), 'bwwc_settings_action')
        ) {
            wp_die(__('Security check failed. Please try again.', 'woocommerce'));
        }

        if (isset($_POST['button_update_bwwc_settings'])) {
            BWWC__update_settings("", false);
            $action_message = __('Settings updated!', 'woocommerce');
        } elseif (isset($_POST['button_reset_bwwc_settings'])) {
            BWWC__reset_all_settings(false);
            $action_message = __('All settings reverted to defaults.', 'woocommerce');
        } elseif (isset($_POST['button_reset_partial_bwwc_settings'])) {
            BWWC__reset_partial_settings(false);
            $action_message = __('Settings on this page reverted to defaults.', 'woocommerce');
        } elseif (isset($_POST['validate_bwwc-license'])) {
            BWWC__update_settings("", false);
            $action_message = __('License validated.', 'woocommerce');
        }
    }

    // Output full admin settings HTML
    $gateway_status_message = "";
    $gateway_valid_for_use = BWWC__is_gateway_valid_for_use($gateway_status_message);
    if (!$gateway_valid_for_use) {
        $gateway_status_message =
    '<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#EE0000;background-color:#FFFFAA;">' .
    "Bitcoin SV Payment Gateway is NOT operational (try to re-enter and save settings): " . $gateway_status_message .
    '</p>';
    } else {
        $gateway_status_message =
    '<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#004400;background-color:#CCFFCC;">' .
    "Bitcoin SV Payment Gateway is operational" .
    '</p>';
    }

    $currency_code = false;
    if (function_exists('get_woocommerce_currency')) {
        $currency_code = @get_woocommerce_currency();
    }
    if (!$currency_code || $currency_code=='BTC') {
        $currency_code = 'USD';
    }

    $exchange_rate_message =
    '<p style="border:1px solid #DDD;padding:5px 10px;background-color:#cceeff;">' .
    BWWC__get_exchange_rate_per_bitcoin($currency_code, 'getfirst', true) .
    '</p>';

    echo '<div class="wrap">';

    if (!empty($action_message)) {
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html($action_message)
        );
    }

    switch ($menu_page_name) {
      case 'general':
        echo  BWWC__GetPluginNameVersionEdition(true);
        echo  $gateway_status_message . $exchange_rate_message;
        BWWC__render_general_settings_page_html();
        break;

      case 'advanced':
        echo  BWWC__GetPluginNameVersionEdition(false);
        echo  $gateway_status_message . $exchange_rate_message;
        BWWC__render_advanced_settings_page_html();
        break;

      default:
        break;
      }

    echo '</div>'; // wrap
}
//===========================================================================

//===========================================================================
function BWWC__render_general_settings_page_html()
{
    $bwwc_settings = BWWC__get_settings();
    global $g_BWWC__cron_script_url; ?>

    <form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
      <?php wp_nonce_field('bwwc_settings_action', 'bwwc_settings_nonce'); ?>
      <p class="submit">
        <input type="submit" class="button-primary"    name="button_update_bwwc_settings"        value="<?php esc_attr_e('Save Changes'); ?>"             />
        <input type="submit" class="button-secondary"  style="color:red;" name="button_reset_partial_bwwc_settings" value="<?php esc_attr_e('Reset settings'); ?>" onClick="return confirm('<?php echo esc_js(__('Are you sure you want to reset settings on this page?', 'woocommerce')); ?>');" />
      </p>
      <table class="form-table">


        <tr valign="top">
          <th scope="row">Delete all plugin-specific settings, database tables and data on uninstall:</th>
          <td>
            <input type="hidden" name="delete_db_tables_on_uninstall" value="0" /><input type="checkbox" name="delete_db_tables_on_uninstall" value="1" <?php checked($bwwc_settings['delete_db_tables_on_uninstall'], 1); ?> />
            <p class="description">If checked - all plugin-specific settings, database tables and data will be removed from Wordpress database upon plugin uninstall (but not upon deactivation or upgrade).</p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">Bitcoin SV Address Generation:</th>
          <td>
            <select name="service_provider" class="select ">
              <option <?php selected($bwwc_settings['service_provider'], 'electrum_wallet'); ?> value="electrum_wallet">BIP32/BIP44 HD Wallet (xPub)</option>
            </select>
            <p class="description">
              Use any BIP32/BIP44 compatible HD wallet (ElectrumSV, Electrum, HandCash, etc.). The plugin generates unique payment addresses from your Master Public Key (xPub).
            </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">Master Public Key (xPub):</th>
          <td>
            <textarea style="width:75%;" name="electrum_mpk_saved"><?php echo esc_textarea($bwwc_settings['electrum_mpk_saved']); ?></textarea>
            <p class="description">
              <strong>How to get your Master Public Key (xPub):</strong>
              <ol class="description">
                <li>
                  Open your BIP32-compatible wallet (ElectrumSV, Electrum, HandCash, etc.)
                </li>
                <li>
                  Locate your Master Public Key (xPub). This is typically found in:
                  <ul style="margin-top: 5px;">
                    <li>Wallet Information or Wallet Details menu</li>
                    <li>May be labeled as "Master Public Key", "xPub", or "Extended Public Key"</li>
                    <li>Starts with "xpub" (111 characters) or is a 128-character hex string</li>
                  </ul>
                </li>
                <li>
                  Copy the entire Master Public Key string and paste it into the field above.
                </li>
                <li>
                  <strong>Important:</strong> Ensure your wallet's "gap limit" is set to at least 100 to properly detect all incoming payments. Consult your wallet's documentation for instructions on adjusting this setting.
                </li>
              </ol>
              <p style="margin-top: 10px;"><em>Note: The Master Public Key allows address generation but cannot spend funds. It is safe to use on your web server.</em></p>
            </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">Number of confirmations required before accepting payment:</th>
          <td>
            <input type="text" name="confs_num" value="<?php echo esc_attr($bwwc_settings['confs_num']); ?>" size="4" />
            <p class="description">
              After a transaction is broadcast to the Bitcoin SV network, it may be included in a block that is published
              to the network. When that happens it is said that one <a href="https://en.bitcoin.it/wiki/Confirmation"><b>confirmation</b></a> has occurred for the transaction.
              With each subsequent block that is found, the number of confirmations is increased by one. To protect against double spending, a transaction should not be considered as confirmed until a certain number of blocks confirm, or verify that transaction.
              6 is considered very safe number of confirmations, although it takes longer to confirm.
            </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">Stale Exchange Rate Handling:</th>
          <td>
            <select name="exchange_rate_type" class="select ">
              <option <?php selected($bwwc_settings['exchange_rate_type'], 'vwap'); ?> value="vwap">Use last available rate (default)</option>
              <option <?php selected($bwwc_settings['exchange_rate_type'], 'realtime'); ?> value="realtime">Disable gateway if rate older than 1 hour</option>
              <option <?php selected($bwwc_settings['exchange_rate_type'], 'bestrate'); ?> value="bestrate">Disable gateway if rate older than 6 hours</option>
            </select>
            <p class="description">
              <strong>Use last available rate (recommended):</strong> Always use the most recent exchange rate, regardless of age. Gateway remains available even if API is temporarily down.
              <br /><strong>Disable if stale:</strong> Hide BSV payment option from customers if exchange rate data is too old. Prevents orders with outdated pricing.
              <br /><em>Note: Exchange rates are fetched from CoinGecko API and cached for the duration set in Advanced settings.</em>
            </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row">Exchange rate multiplier:</th>
          <td>
            <input type="text" name="exchange_multiplier" value="<?php echo esc_attr($bwwc_settings['exchange_multiplier']); ?>" size="4" />
            <p class="description">
              Extra multiplier to apply to convert store default currency to Bitcoin SV price.
              <br />Example: 1.05 - will add extra 5% to the total price in Bitcoin SV.
              May be useful to compensate for market volatility or for merchant's loss to fees when converting Bitcoin SV to local currency,
              or to encourage customer to use Bitcoin SV for purchases (by setting multiplier to < 1.00 values).
            </p>
          </td>
        </tr>

        <tr valign="top">
            <th scope="row">Extreme privacy mode enabled?</th>
            <td>


              <select name="reuse_expired_addresses" class="select">
                <option <?php selected($bwwc_settings['reuse_expired_addresses'], 1); ?> value="1">No (default)</option>
                <option <?php selected($bwwc_settings['reuse_expired_addresses'], 0); ?> value="0">Yes</option>
              </select>

              <p class="description">
                <b>No</b> (default, recommended) - will allow to recycle Bitcoin SV addresses that been generated for each placed order but never received any payments. The drawback of this approach is that potential snoop can generate many fake (never paid for) orders to discover sequence of Bitcoin SV addresses that belongs to the wallet of this store and then track down sales through blockchain analysis. The advantage of this option is that it very efficiently reuses empty (zero-balance) Bitcoin SV addresses within the wallet, allowing only 1 sale per address without growing the wallet size (ElectrumSV "gap" value).
                <br />
                <b>Yes</b> - This will guarantee to generate unique Bitcoin SV address for every order (real, accidental or fake). This option will provide the most anonymity and privacy to the store owner's wallet. The drawback is that it will likely leave a number of addresses within the wallet never used (and hence will require setting very high 'gap limit' within the ElectrumSV wallet much sooner).
                <br />It is recommended to regenerate new wallet after number of used Bitcoin SV addresses reaches 1000. Wallets with very high gap limits (>1000) are very slow to sync with blockchain and they put an extra load on the network. <br />
                Extreme privacy mode offers the best anonymity and privacy to the store albeit with the drawbacks of potentially flooding your ElectrumSV wallet with expired and zero-balance addresses. Hence, for vast majority of cases (where you just need a secure way to operate Bitcoin SV based store) it is suggested to set this option to 'No').<br />
              </p>
            </td>
        </tr>

        <tr valign="top">
          <th scope="row">Auto-complete paid orders:</th>
          <td>
            <input type="hidden" name="autocomplete_paid_orders" value="0" /><input type="checkbox" name="autocomplete_paid_orders" value="1" <?php checked($bwwc_settings['autocomplete_paid_orders'], 1); ?> />
            <p class="description">If checked - fully paid order will be marked as 'completed' and '<i>Your order is complete</i>' email will be immediately delivered to customer.
            	<br />If unchecked: store admin will need to mark order as completed manually - assuming extra time needed to ship physical product after payment is received.
            	<br />Note: virtual/downloadable products will automatically complete upon receiving full payment (so this setting does not have effect in this case).
            </p>
          </td>
        </tr>

        <tr valign="top">
            <th scope="row">Cron job type:</th>
            <td>
              <select name="enable_soft_cron_job" class="select ">
                <option <?php selected($bwwc_settings['enable_soft_cron_job'], '1'); ?> value="1">Soft Cron (Wordpress-driven)</option>
                <option <?php selected($bwwc_settings['enable_soft_cron_job'], '0'); ?> value="0">Hard Cron (Cpanel-driven)</option>
              </select>
              <p class="description">
                <?php if ($bwwc_settings['enable_soft_cron_job'] != '1') {
        echo '<p style="background-color:#FFC;color:#2A2;"><b>NOTE</b>: Hard Cron job is enabled: make sure to follow instructions below to enable hard cron job at your hosting panel.</p>';
    } ?>
                Cron job will take care of all regular Bitcoin SV payment processing tasks, like checking if payments are made and automatically completing the orders.<br />
                <b>Soft Cron</b>: WordPress-driven (runs on behalf of a random site visitor).
                <br />
                <b>Hard Cron</b>: Cron job driven by the website hosting system/server (usually via cPanel). <br />
                When enabling Hard Cron job - make this script to run every 5 minutes at your hosting panel cron job scheduler:<br />
                <?php echo '<tt style="background-color:#FFA;color:#B00;padding:0px 6px;">wget -O /dev/null ' . esc_url($g_BWWC__cron_script_url . '?hardcron=1') . '</tt>'; ?>
                <br /><b style="color:red;">NOTE:</b> Cron jobs <b>might not work</b> if your site is password protected with HTTP Basic authentication or other methods. This will result in WooCommerce store not seeing received payments (even though funds will arrive correctly to your Bitcoin SV addresses).
                <br /><u>Note:</u> You will need to deactivate/reactivate plugin after changing this setting for it to have effect.<br />
                "Hard" cron jobs may not be properly supported by all hosting plans (many shared hosting plans have restrictions in place).               
              </p>
            </td>
        </tr>
        <tr valign="top">
          <th scope="row">Checkout Icon:</th>
          <td>
            <fieldset>
              <p>
                <?php
                $plugin_root = dirname(__FILE__);
                $icon_dir = '/images/checkout-icons/';
                $icons = scandir($plugin_root . $icon_dir);
                foreach($icons as $icon) {
                    if (!is_file($plugin_root . $icon_dir . $icon)) {
                        continue;
                    }
                    $icon_rel_path = $icon_dir . $icon;
                    $icon_url = plugins_url($icon_rel_path, __FILE__);
                    $checked = "";
                    if ($bwwc_settings['selected_checkout_icon'] == $icon_rel_path) {
                        $checked = 'checked';
                    }
                    echo '<input type="radio" name="selected_checkout_icon" id="' . esc_attr($icon) . '" value="' . esc_attr($icon_rel_path) . '" ' . $checked . '/>';
                    echo '<label for="' . esc_attr($icon) . '"><img src="' . esc_url($icon_url) . '" height="32" alt="Checkout icon" /></label><br />';
                }
                ?>
              </p>
              </fieldset>
              <p class="description">
                Icon displayed to users when choosing the payment method.<br />
                You can upload new icons to: <?php echo esc_html(str_replace(ABSPATH, "", $plugin_root . $icon_dir)); ?><br />
                Make sure to scale the image to a height of 32px.
              </p>
          </td>
        </tr>
 
      </table>

      <p class="submit">
          <input type="submit" class="button-primary"    name="button_update_bwwc_settings"        value="<?php _e('Save Changes') ?>"             />
          <input type="submit" class="button-secondary"  style="color:red;" name="button_reset_partial_bwwc_settings" value="<?php _e('Reset settings') ?>" onClick="return confirm('Are you sure you want to reset settings on this page?');" />
      </p>
    </form>
<?php
}
//===========================================================================

//===========================================================================
function BWWC__render_advanced_settings_page_html()
{
    $bwwc_settings = BWWC__get_settings();
 ?>
 <form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
 <?php wp_nonce_field('bwwc_settings_action', 'bwwc_settings_nonce'); ?>
 <h3>Advanced Configuration</h3>
 <p>These settings control technical aspects of the plugin. Only modify if you understand the implications.</p>
 
 <table class="form-table">
    <tr valign="top">
        <th scope="row">Exchange Rate Cache Duration</th>
        <td>
            <input type="text" name="cache_exchange_rates_for_minutes" value="<?php echo esc_attr($bwwc_settings['cache_exchange_rates_for_minutes']); ?>" size="4" />
            <span class="description">minutes. How long to cache exchange rates before fetching fresh data. Default: 10 minutes.</span>
        </td>
    </tr>
    
    <tr valign="top">
        <th scope="row">API Timeout</th>
        <td>
            <input type="text" name="exchange_rate_api_timeout_secs" value="<?php echo esc_attr($bwwc_settings['exchange_rate_api_timeout_secs']); ?>" size="4" />
            <span class="description">seconds. Maximum time to wait for exchange rate API responses. Default: 20 seconds.</span>
        </td>
    </tr>
    
    <tr valign="top">
        <th scope="row">Cron Job Schedule</th>
        <td>
            <select name="soft_cron_job_schedule_name">
                <option value="minutes_1" <?php selected($bwwc_settings['soft_cron_job_schedule_name'], 'minutes_1'); ?>>Every 1 minute</option>
                <option value="minutes_2.5" <?php selected($bwwc_settings['soft_cron_job_schedule_name'], 'minutes_2.5'); ?>>Every 2.5 minutes (recommended)</option>
                <option value="minutes_5" <?php selected($bwwc_settings['soft_cron_job_schedule_name'], 'minutes_5'); ?>>Every 5 minutes</option>
            </select>
            <span class="description">How often to check for incoming BSV payments. More frequent = faster detection but higher server load.</span>
        </td>
    </tr>
    
    <tr valign="top">
        <th scope="row">Enable Cron Job</th>
        <td>
            <input type="checkbox" name="enable_soft_cron_job" value="1" <?php checked($bwwc_settings['enable_soft_cron_job'], '1'); ?> />
            <span class="description">Enable automatic payment detection via WordPress cron. Disable if using external cron job.</span>
        </td>
    </tr>
    
    <tr valign="top">
        <th scope="row">Delete Data on Uninstall</th>
        <td>
            <input type="checkbox" name="delete_db_tables_on_uninstall" value="1" <?php checked($bwwc_settings['delete_db_tables_on_uninstall'], '1'); ?> />
            <span class="description">Remove all plugin data when uninstalling. <strong>Warning:</strong> This will delete payment history!</span>
        </td>
    </tr>
    
    <tr valign="top">
        <th scope="row">Payment Timeout</th>
        <td>
            <input type="text" name="assigned_address_expires_in_mins" value="<?php echo esc_attr($bwwc_settings['assigned_address_expires_in_mins']); ?>" size="6" />
            <span class="description">minutes. How long customers have to complete payment before order expires. Default: 240 minutes (4 hours).</span>
        </td>
    </tr>
 </table>
 
 <p style="margin-top: 20px; padding: 10px; background: #f0f0f1; border-left: 4px solid #0073aa;">
    <strong>Note:</strong> Changes to cron settings require deactivating and reactivating the plugin to take effect.
 </p>
 
 <p class="submit">
    <input type="submit" class="button-primary" name="button_update_bwwc_settings" value="<?php _e('Save Changes') ?>" />
 </p>
 </form>
<?php
}
//===========================================================================
