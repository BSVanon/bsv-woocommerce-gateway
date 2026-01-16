<?php
/**
 * WhatsOnChain Provider - Primary blockchain data provider
 * 
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get address balance from WhatsOnChain
 * 
 * @param string $address BSV address
 * @return array|false Array with 'balance' and 'confirmed' keys, or false on failure
 */
function BWWC__whatsonchain_get_balance($address)
{
    $url = 'https://api.whatsonchain.com/v1/bsv/main/address/' . $address . '/balance';
    
    $response = BWWC__http_get($url, 30);
    
    if ($response === false) {
        BWWC__log_provider_failure('WhatsOnChain', 'get_balance', 'HTTP request failed for address: ' . $address);
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['confirmed']) || !isset($data['unconfirmed'])) {
        BWWC__log_provider_failure('WhatsOnChain', 'get_balance', 'Invalid response format');
        return false;
    }
    
    // Convert satoshis to BSV
    $confirmed = (float) $data['confirmed'] / 100000000;
    $unconfirmed = (float) $data['unconfirmed'] / 100000000;
    $total = $confirmed + $unconfirmed;
    
    return array(
        'balance' => $total,
        'confirmed' => $confirmed,
        'unconfirmed' => $unconfirmed,
    );
}

/**
 * Get address transactions from WhatsOnChain
 * 
 * @param string $address BSV address
 * @return array|false Array of transactions, or false on failure
 */
function BWWC__whatsonchain_get_transactions($address)
{
    $url = 'https://api.whatsonchain.com/v1/bsv/main/address/' . $address . '/history';
    
    $response = BWWC__http_get($url, 30);
    
    if ($response === false) {
        BWWC__log_provider_failure('WhatsOnChain', 'get_transactions', 'HTTP request failed for address: ' . $address);
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!is_array($data)) {
        BWWC__log_provider_failure('WhatsOnChain', 'get_transactions', 'Invalid response format');
        return false;
    }
    
    return $data;
}

/**
 * Get current blockchain height from WhatsOnChain
 * 
 * @return int|false Block height, or false on failure
 */
function BWWC__whatsonchain_get_height()
{
    // Check cache first (cache for 30 seconds)
    $cache_key = 'bwwc_whatsonchain_height';
    $cached_height = get_transient($cache_key);
    
    if ($cached_height !== false) {
        return (int) $cached_height;
    }
    
    $url = 'https://api.whatsonchain.com/v1/bsv/main/chain/info';
    
    $response = BWWC__http_get($url, 15);
    
    if ($response === false) {
        BWWC__log_provider_failure('WhatsOnChain', 'get_height', 'HTTP request failed');
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['blocks'])) {
        BWWC__log_provider_failure('WhatsOnChain', 'get_height', 'Invalid response format');
        return false;
    }
    
    $height = (int) $data['blocks'];
    
    // Cache for 30 seconds
    set_transient($cache_key, $height, 30);
    
    return $height;
}

/**
 * Get transaction details from WhatsOnChain
 * 
 * @param string $txid Transaction ID
 * @return array|false Transaction data, or false on failure
 */
function BWWC__whatsonchain_get_transaction($txid)
{
    $url = 'https://api.whatsonchain.com/v1/bsv/main/tx/hash/' . $txid;
    
    $response = BWWC__http_get($url, 30);
    
    if ($response === false) {
        BWWC__log_provider_failure('WhatsOnChain', 'get_transaction', 'HTTP request failed for txid: ' . $txid);
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['txid'])) {
        BWWC__log_provider_failure('WhatsOnChain', 'get_transaction', 'Invalid response format');
        return false;
    }
    
    return $data;
}
