=== Bitcoin SV Payments for WooCommerce ===
Contributors: BSVanon
Tags: bitcoin sv, bsv, payment gateway, woocommerce, cryptocurrency
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 6.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 6.0
WC tested up to: 9.5


Accept Bitcoin SV payments directly to your wallet. Self-custody, no third-party processor. Modern fork with PHP 8+ and WooCommerce HPOS support.

== Description ==

This plugin enables your WooCommerce store to accept Bitcoin SV (BSV) payments directly to your wallet using BIP32 address derivation. No third-party payment processors, no monthly fees, complete self-custody.

**Key Features:**
* Direct payments to your ElectrumSV or BIP32-compatible wallet
* Automatic per-order address derivation from your Master Public Key (xpub/MPK)
* Real-time exchange rate conversion with configurable markup
* Payment detection via blockchain APIs (WhatsOnChain + Bitails fallback)
* Modern payment console with live status updates and countdown timer
* Aggregate payment support (handles multiple transactions to same address)
* QR code generation for easy mobile payments
* Wallet top-up links for customer convenience
* WooCommerce HPOS (High-Performance Order Storage) compatible
* WooCommerce Blocks checkout support
* PHP 8+ compatible
* Modern WordPress 6.x and WooCommerce 9.x support

= Benefits =

* Accept payment directly into your personal ElectrumSV wallet.
* ElectrumSV wallet payment option completely removes dependency on any third party service and middlemen.
* Accept payment in Bitcoin SV for physical and digital downloadable products.
* Add Bitcoin SV  payments option to your existing online store with alternative main currency.
* Flexible exchange rate calculations fully managed via administrative settings.
* Supports multiple currencies, including Bitcoin SV
* Automatic conversion to Bitcoin SV via exchange rate feed and calculations.
* Ability to set exchange rate calculation multiplier to compensate for any possible losses due to bank conversions and funds transfer fees.


== Installation ==

1. Clone the git repo or download the zip and extract.  Move 'bitcoin-sv-payments-for-woocommerce' dir to /wp-content/plugins/
2. Install "Bitcoin SV Payments for WooCommerce" plugin just like any other Wordpress plugin.
3. Activate.
4. Configure plugin with your local ElectrumSV (electrumsv.io) master public key

**Checkout Compatibility**: This plugin supports both classic shortcode checkout `[woocommerce_checkout]` and modern WooCommerce Blocks checkout. No special configuration needed.

== External Services ==

This plugin connects to the following external services to function properly:

**CoinGecko API** (https://www.coingecko.com)
- Purpose: Fetches BSV exchange rates for currency conversion
- Data transmitted: None (public API, no personal data sent)
- Privacy policy: https://www.coingecko.com/en/privacy
- Terms of service: https://www.coingecko.com/en/terms

**CoinPaprika API** (https://coinpaprika.com)
- Purpose: Fallback exchange rate provider if CoinGecko is unavailable
- Data transmitted: None (public API, no personal data sent)
- Privacy policy: https://coinpaprika.com/privacy-policy
- Terms of service: https://coinpaprika.com/terms-of-use

**WhatsOnChain API** (https://whatsonchain.com)
- Purpose: Primary blockchain data provider for transaction verification
- Data transmitted: Your server IP address and BSV addresses/transaction IDs for lookup
- Privacy policy: https://whatsonchain.com/privacy
- Terms of service: https://whatsonchain.com/terms

**Bitails API** (https://bitails.io)
- Purpose: Backup blockchain data provider for transaction verification
- Data transmitted: Your server IP address and BSV addresses/transaction IDs for lookup
- Privacy policy: https://bitails.io/privacy
- Terms of service: https://bitails.io/terms

**Important Notes:**
- All API calls are made server-side only (your server to the API)
- No customer personal information is transmitted to these services
- Only your server IP address and BSV payment addresses are sent for blockchain lookups
- No tracking or analytics data is collected by this plugin

== Screenshots ==

1. Checkout with option for Bitcoin SV payment.
2. Order received screen, including QR code of Bitcoin SV address and payment amount.
3. Bitcoin SV Gateway settings screen.


== Remove plugin ==

1. Deactivate plugin through the 'Plugins' menu in WordPress
2. Delete plugin through the 'Plugins' menu in WordPress


== Support Development ==

If you find this plugin useful, please consider supporting its development with a BSV donation:

**Paymail:** BSVanon@paymail.us
**BSV Address:** 1KcwesgbcSWE8BGUdceSBezsyPxGU7Bruk

Your support helps maintain and improve this plugin for the entire BSV community!

== Changelog ==

= 6.0.0 - 2026-01-16 =
**Major security and architecture update**

**SECURITY FIXES:**
* CRITICAL: Enabled TLS verification for all external API calls (fixes MITM vulnerability)
* CRITICAL: Removed public hardcron trigger (DoS vulnerability)
* CRITICAL: Disabled legacy IPN callback (security risk - logged secrets)
* Hardened unserialize() calls with allowed_classes restriction
* Removed insecure HTTP fallbacks (bchsvexplorer.com)

**ARCHITECTURE:**
* New modular provider system with failover support
* Secure HTTP wrapper using WordPress HTTP API
* WooCommerce logger integration with proper log levels and redaction
* Canonical payment state machine (waiting/detected/verified/expired/underpaid/overpaid/conflict)
* Payment check module with transaction aggregation
* Expiry enforcement and late payment monitoring

**RATE PROVIDERS:**
* Replaced broken Blockchair with CoinPaprika fallback
* Removed legacy providers (CoinMarketCap, BitPay placeholders)
* CoinGecko primary, CoinPaprika fallback with caching

**SETTINGS:**
* Unified settings keys (confirmations → confs_num)
* Safe defaults: autocomplete OFF, delete expired OFF, reuse addresses OFF
* Added External Services disclosure section

**REMOVED:**
* Legacy XXXbitcoinway.com dead code
* Public unauthenticated endpoints
* Insecure cURL with disabled TLS verification

= 5.3.4 - 2026-01-14 =
* WordPress.org submission fixes
* All escaping, sanitization, and security issues resolved
* Plugin-check compliant

= 5.3.3 - 2026-01-13 =
* CRITICAL FIX: Infinite page reload loop eliminated using sessionStorage persistence
* FIXED: Stepper UI now appears immediately when payment detected (dynamic creation)
* FIXED: Polling correctly stops when order status reaches completed/processing
* IMPROVED: Frontend/backend state synchronization for payment confirmation flow
* IMPROVED: Console logging for debugging payment state transitions

= 5.3.2 - 2026-01-13 =
* FIXED: AJAX polling now stops on final payment states (no more screen flickering)
* FIXED: Cron job skips completed/cancelled orders to reduce API calls
* ENHANCED: Stepper UI now works across all payment states with visual feedback
* ADDED: Pending state shows pulsing yellow dot while awaiting confirmations
* ADDED: Underpaid state shows orange dot for partial payments
* ADDED: Expired/failed state shows red dots
* IMPROVED: Payment console messaging for all scenarios (success, late payment, expiration, failure)
* IMPROVED: Page reload only triggers once when order completes
* DOCUMENTED: WP-Cron Docker networking fix in DOCKER RULE.md

= 5.3.1 - 2026-01-12 =
* CRITICAL FIX: Payment detection now works correctly
* FIXED: Blockchain explorer URLs now generate correctly (was missing /address/ and /tx/ paths)
* FIXED: Transaction IDs are now stored and displayed in payment console
* FIXED: "I've Paid" button now triggers immediate payment check
* ADDED: Bitails API as fallback provider for improved reliability
* FIXED: Order meta fields (expected_sats, address_expires_at, payment_state) now initialized on order creation
* FIXED: Countdown timer now displays correctly

= 5.3.0 - 2025-01-11 =
* Added: Modern payment console UI with QR code, copy buttons, and live countdown
* Added: Real-time payment status updates via AJAX polling
* Added: Enhanced email payment instructions with QR codes and styled blocks
* Added: Payment state tracking (waiting/detected/confirmed/expired/underpaid/overpaid)
* Added: "I've Paid" force recheck button with cooldown protection
* Added: Admin settings for email instructions and status polling interval
* Added: Dark mode support for payment console
* Improved: Mobile-responsive payment interface
* Fixed: Type safety for number_format() calls in payment console
* Security: Nonce-protected AJAX endpoints with order key validation

= 5.2.0 - 2026-01-10 =
**Blocks-ready release**

* **NEW:** WooCommerce Blocks checkout support (modern block-based checkout)
* **IMPROVED:** Payment timeout display now shows fractional hours (e.g., 90 mins = 1.5 hours)
* **IMPROVED:** Readme and metadata updated for WordPress 6.9 / WooCommerce 10.4
* **IMPROVED:** Documentation reflects simultaneous support for Blocks and classic checkout
* **FIXED:** Ensured gateway instructions reference dynamic timeout text without rounding

= 5.1.0 - 2026-01-09 =
**WordPress.org submission release**

* **IMPROVED:** All PHPECC cryptography classes prefixed with BWWC_PhpEcc_ for WordPress naming compliance
* **IMPROVED:** Removed debug code from PHPECC library
* **IMPROVED:** Updated Bitcoin SV description to "electronic cash" terminology
* **IMPROVED:** Modernized personal address availability text for BIP32/BIP44 HD Wallet
* **SECURITY:** Added direct file access protection to all PHP files
* **SECURITY:** Secured all database queries with proper $wpdb->prepare() usage
* **FIXED:** Global constant and variable naming compliance (USE_EXT, WP_USE_THEMES)
* **FIXED:** Updated readme.txt for WordPress 6.9 compatibility
* **ADDED:** Woo-BSV branding image for plugin icon

= 5.0.0 - 2026-01-09 =
**Major modernization release**

* **BREAKING:** Bumped minimum requirements to WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+
* **NEW:** Full PHP 8.0+ compatibility with proper type safety and input validation
* **NEW:** WooCommerce HPOS (High-Performance Order Storage) compatibility declared
* **NEW:** Modern exchange rate provider using CoinGecko API (free, no API key required)
* **NEW:** WhatsOnChain blockchain API integration for payment detection
* **FIXED:** All undefined array access warnings (PHP 8 deprecations)
* **FIXED:** Replaced defunct BitcoinAverage API with working providers
* **IMPROVED:** Input sanitization and security hardening throughout
* **IMPROVED:** Updated plugin metadata for WordPress.org compatibility
* **IMPROVED:** Comprehensive documentation (DEV_NOTES.md, CAPABILITIES.md)
* **IMPROVED:** Docker-based development environment for testing

= 4.20 =
* Bitcoin SV support. Use Weighted Average exchange rate calculation. ElectrumSV wallet is compatible with this plugin. Previous wallet, Electron Cash version 3.3.2 is last compatible version with BSV.

= 4.19 =
* Rebase from sanchaz's fork, minus cashaddr

= 4.18 =
* Made the gateway payment icon selectable. (Adding new ones is possible by uploading it to /images/checkout-icons, make sure to scale the image to a height of 32px). Changed the defaut icon to a new orange icon.

= 4.17 =
* Hardcron behaviour now also happens if soft_cron is set and DISABLE_WP_CRON = true, ie the user is running it manually or through real cron
* The template now features the amount after the address and a message.

= 4.16 =
* Added reuse_expired_addresses option in the menus for everyone

= 4.15 =
* Added exchange rate to order metadata

= 4.14 =
* Changed qr code to cashaddr. Only one qr is displayed to avoid clutter and encourage use of cashaddr.

= 4.13 =
* Added simple casdddr support. This means displays cashaddr on pay page, also adds it to the post metadata for easier search. (Does not use it to query apis, will be added later)
* Fixed styling issues using php cs fixer v2 using PSR2 rules
* Remove donation address and plea

= 4.12 =
* Fixed multiple currency.
* Added new price provider.

= 3.03 =
* Forked original bitcoin payment plugin, modified for Bitcoin Cash.  Supports Electron Cash wallet's Master Public Key

= 3.02 =
* Upgraded to support WooCommerce 2.1+
* Upgraded to support Wordpress 3.9
* Fixed bug in cron forcing excessive generation of new bitcoin addresses.
* Fixed bug disallowing finding of new bitcoin addresses to use for orders.
* Fixed buggy SQL query causing issues with delayed order processing even when desired number of confirmations achieved.
* Added support for many more currencies.
* Corrected bitcoin exchange rate calculation using: bitcoinaverage.com, bitcoincharts.com and bitpay.com
* MtGox APIs, services and references completely eliminated from consideration.

= 2.12 =
* Added 'bitcoins_refunded' field to order to input refunded value for tracking.

= 2.11 =
* Minor upgrade - screenshots fix.

= 2.10 =
* Added support for much faster GMP math library to generate bitcoin addresses. This improves performance of checkout 3x - 4x times.
  Special thanks to Chris Savery: https://github.com/bkkcoins/misc
* Improved compatibility with older versions of PHP now allowing to use plugin in wider range of hosting services.

= 2.04 =
* Improved upgradeability from older versions.

= 2.02 =
* Added full support for Electrum Wallet's Master Public Key - the math algorithms allowing for the most reliable, anonymous and secure way to accept online payments in bitcoins.
* Improved overall speed and responsiveness due to multilevel caching logic.

= 1.28 =
* Added QR code image to Bitcoin checkout screen and email.
  Credits: WebDesZ: http://wordpress.org/support/profile/webdesz

= 1.27 =
* Fixed: very slow loading due to MtGox exchange rate API issues.

= 1.26 =
* Fixed PHP warnings for repeated 'define's within bwwc-include-all.php

= 1.25 =
* Implemented security check (secret_key validation logic) to prevent spoofed IPN requests.

= 1.24 =
* Fixed IPN callback notification invocation specific to WC 2.x

= 1.23 =
* Fixed incoming IP check logic for IPN (payment notification) requests.

= 1.22 =
* Fixed inability to save settings bug.
* Added compatibility with both WooCommmerce 1.x and 2.x

== Upgrade Notice ==

soon

== Frequently Asked Questions ==

= Why doesn't the Bitcoin SV payment option appear at checkout? =

If you're using WooCommerce 8.3+ with the new Blocks-based checkout, you need to switch to classic checkout:

1. Create a new page with the shortcode: [woocommerce_checkout]
2. Go to WooCommerce → Settings → Advanced → Page setup
3. Set your new page as the Checkout page
4. Save changes

The plugin currently requires classic checkout. Blocks support is coming in v5.1.

= What are the minimum requirements? =

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher (8.x recommended)
* PHP extension: gmp (preferred) or bcmath

= Do I need an API key? =

No! v5.0.0 uses CoinGecko's free API for exchange rates and WhatsOnChain for blockchain lookups. No API keys required.

= Is my Master Public Key (xpub) safe? =

Yes. The xpub only allows *receiving* payments. It cannot be used to spend funds. Your private keys remain secure in your ElectrumSV wallet.

= How do I get my Master Public Key from ElectrumSV? =

1. Open ElectrumSV wallet
2. Go to Wallet → Information
3. Copy the Master Public Key (xpub format, 111 characters)
4. Paste into plugin settings

= What happens if exchange rates fail? =

As a workaround, you can temporarily set your WooCommerce store currency to "BTC" for 1:1 pricing. Check your server's ability to make outbound HTTPS connections.

= How long does payment detection take? =

The plugin checks for payments via WP-Cron (default: every 2.5 minutes). For faster detection, configure a real cron job. Payments show as confirmed after your configured number of blockchain confirmations (recommended: 1-6).
