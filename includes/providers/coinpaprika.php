<?php
/**
 * CoinPaprika Provider - Exchange rate fallback provider
 * 
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get BSV exchange rate from CoinPaprika
 * 
 * @param string $currency Target currency (e.g., 'USD', 'EUR')
 * @return float|false Exchange rate, or false on failure
 */
function BWWC__coinpaprika_get_rate($currency = 'USD')
{
    $currency = strtoupper($currency);
    
    // CoinPaprika's ID for Bitcoin SV is 'bsv-bitcoin-sv'
    $url = 'https://api.coinpaprika.com/v1/tickers/bsv-bitcoin-sv';
    
    // Check cache first
    $cache_key = 'bwwc_coinpaprika_rate_' . strtolower($currency);
    $cached_rate = get_transient($cache_key);
    
    if ($cached_rate !== false) {
        BWWC__log_debug('CoinPaprika rate cache hit for ' . $currency);
        return (float) $cached_rate;
    }
    
    // Fetch from API
    $response = BWWC__http_get($url, 15);
    
    if ($response === false) {
        BWWC__log_provider_failure('CoinPaprika', 'get_rate', 'HTTP request failed');
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['quotes'][$currency]['price'])) {
        BWWC__log_provider_failure('CoinPaprika', 'get_rate', 'Invalid response format or currency not supported');
        return false;
    }
    
    $rate = (float) $data['quotes'][$currency]['price'];
    
    if ($rate <= 0) {
        BWWC__log_provider_failure('CoinPaprika', 'get_rate', 'Invalid rate value: ' . $rate);
        return false;
    }
    
    // Cache for 5 minutes (300 seconds)
    $settings = BWWC__get_settings();
    $cache_duration = isset($settings['cache_exchange_rates_for_minutes']) 
        ? (int) $settings['cache_exchange_rates_for_minutes'] * 60 
        : 300;
    
    set_transient($cache_key, $rate, $cache_duration);
    
    BWWC__log_debug('CoinPaprika rate fetched: 1 BSV = ' . $rate . ' ' . $currency);
    
    return $rate;
}

/**
 * Clear CoinPaprika rate cache
 * 
 * @param string|null $currency Specific currency to clear, or null for all
 */
function BWWC__coinpaprika_clear_cache($currency = null)
{
    if ($currency) {
        delete_transient('bwwc_coinpaprika_rate_' . strtolower($currency));
    } else {
        // Clear all common currencies
        $currencies = array('usd', 'eur', 'gbp', 'jpy', 'cny', 'aud', 'cad');
        foreach ($currencies as $curr) {
            delete_transient('bwwc_coinpaprika_rate_' . $curr);
        }
    }
}
