=== Bitcoin SV Payments for WooCommerce ===
Contributors: BSVanon
Tags: bitcoin sv, bsv, payment gateway, woocommerce, cryptocurrency
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 5.1.0
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
* Payment detection via blockchain APIs (WhatsOnChain, Blockchair)
* QR code generation for easy mobile payments
* WooCommerce HPOS (High-Performance Order Storage) compatible
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

**Important for WooCommerce 8.3+**: This plugin requires classic checkout. If using WooCommerce Blocks:
1. Create a new page with the shortcode: [woocommerce_checkout]
2. Go to WooCommerce → Settings → Advanced
3. Set the new page as your Checkout page
4. Save changes

WooCommerce Blocks support is planned for v5.1.



== Screenshots ==

1. Checkout with option for Bitcoin SV payment.
2. Order received screen, including QR code of Bitcoin SV address and payment amount.
3. Bitcoin SV Gateway settings screen.


== Remove plugin ==

1. Deactivate plugin through the 'Plugins' menu in WordPress
2. Delete plugin through the 'Plugins' menu in WordPress


== Supporters ==

* mboyd1:  https://bitcoincloud.net
* sanchaz: http://sanchaz.net
* Yifu Guo: http://bitsyn.com/
* Bitcoin Grants: http://bitcoingrant.org/
* Chris Savery: https://github.com/bkkcoins/misc
* lowcostego: http://wordpress.org/support/profile/lowcostego
* WebDesZ: http://wordpress.org/support/profile/webdesz
* ninjastik: http://wordpress.org/support/profile/ninjastik
* timbowhite: https://github.com/timbowhite
* devlinfox: http://wordpress.org/support/profile/devlinfox


== Changelog ==

= 5.1.0 - 2026-01-10 =
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
