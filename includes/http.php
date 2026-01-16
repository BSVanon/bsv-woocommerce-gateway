<?php
/**
 * HTTP Wrapper - Secure WordPress HTTP API wrapper
 * 
 * Replaces BWWC__file_get_contents() with proper TLS verification
 * and WordPress HTTP API best practices.
 * 
 * @package BSV_WooCommerce_Gateway
 * @since 6.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Secure HTTP GET request using WordPress HTTP API
 * 
 * @param string $url Target URL (must be HTTPS for external calls)
 * @param int $timeout Timeout in seconds (default: 30)
 * @param array $headers Optional additional headers
 * @return string|false Response body on success, false on failure
 */
function BWWC__http_get($url, $timeout = 30, $headers = array())
{
    $args = array(
        'timeout'     => $timeout,
        'redirection' => 5,
        'httpversion' => '1.1',
        'user-agent'  => 'BSV-WooCommerce-Gateway/' . BWWC_VERSION,
        'sslverify'   => true, // CRITICAL: Always verify SSL certificates
        'headers'     => $headers,
    );

    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        BWWC__log_event(__FILE__, __LINE__, 'HTTP GET failed: ' . $response->get_error_message() . ' | URL: ' . $url);
        return false;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        BWWC__log_event(__FILE__, __LINE__, 'HTTP GET returned non-200 status: ' . $http_code . ' | URL: ' . $url);
        return false;
    }

    return wp_remote_retrieve_body($response);
}

/**
 * Secure HTTP POST request using WordPress HTTP API
 * 
 * @param string $url Target URL
 * @param array|string $data POST data
 * @param int $timeout Timeout in seconds (default: 30)
 * @param array $headers Optional additional headers
 * @return string|false Response body on success, false on failure
 */
function BWWC__http_post($url, $data, $timeout = 30, $headers = array())
{
    $args = array(
        'timeout'     => $timeout,
        'redirection' => 5,
        'httpversion' => '1.1',
        'user-agent'  => 'BSV-WooCommerce-Gateway/' . BWWC_VERSION,
        'sslverify'   => true, // CRITICAL: Always verify SSL certificates
        'headers'     => $headers,
        'body'        => $data,
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        BWWC__log_event(__FILE__, __LINE__, 'HTTP POST failed: ' . $response->get_error_message() . ' | URL: ' . $url);
        return false;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        BWWC__log_event(__FILE__, __LINE__, 'HTTP POST returned non-200 status: ' . $http_code . ' | URL: ' . $url);
        return false;
    }

    return wp_remote_retrieve_body($response);
}

/**
 * Validate URL is HTTPS (for external API calls)
 * 
 * @param string $url URL to validate
 * @return bool True if HTTPS, false otherwise
 */
function BWWC__is_secure_url($url)
{
    return strpos($url, 'https://') === 0;
}

/**
 * Allowlist of permitted external API hosts
 * 
 * @return array List of allowed hostnames
 */
function BWWC__get_allowed_api_hosts()
{
    return array(
        'api.coingecko.com',
        'api.whatsonchain.com',
        'api.bitails.io',
        'api.coinpaprika.com', // Fallback rate provider
    );
}

/**
 * Validate URL is from an allowed API host
 * 
 * @param string $url URL to validate
 * @return bool True if allowed, false otherwise
 */
function BWWC__is_allowed_api_host($url)
{
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) {
        return false;
    }

    $allowed_hosts = BWWC__get_allowed_api_hosts();
    return in_array($parsed['host'], $allowed_hosts, true);
}
