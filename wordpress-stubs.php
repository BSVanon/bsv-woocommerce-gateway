<?php
/**
 * WordPress and WooCommerce stubs for PHPStan
 * 
 * Minimal stubs to prevent PHPStan from complaining about WordPress/WooCommerce functions
 */

// WordPress core functions
function wp_remote_get($url, $args = []) {}
function wp_remote_post($url, $args = []) {}
function wp_remote_retrieve_body($response) {}
function wp_remote_retrieve_response_code($response) {}
function is_wp_error($thing) {}
function get_option($option, $default = false) {}
function update_option($option, $value) {}
function add_action($hook, $callback, $priority = 10, $args = 1) {}
function add_filter($hook, $callback, $priority = 10, $args = 1) {}
function apply_filters($hook, $value) {}
function __($text, $domain = 'default') {}
function esc_html__($text, $domain = 'default') {}
function esc_attr__($text, $domain = 'default') {}
function esc_html($text) {}
function esc_attr($text) {}
function esc_url($url) {}
function wp_json_encode($data) {}
function wp_hash($data) {}
function plugins_url($path, $plugin = '') {}
function plugin_dir_path($file) {}
function wc_get_order($order_id) {}
function wc_get_logger() {}

// WooCommerce classes
class WC_Payment_Gateway {}
class WC_Order {}
class WC_Logger_Interface {}
class WP_Error {}
