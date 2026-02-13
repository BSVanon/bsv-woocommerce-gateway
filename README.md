# SendBSV BSV Payments for WooCommerce

**Version:** 7.0.0  
**Status:** Production Ready - WordPress.org Compliance Release  
**Tested up to:** WordPress 6.9 / WooCommerce 10.4  
**Requires:** WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+

Accept Bitcoin SV (BSV) payments directly to your wallet. Self-custody, no third-party processor required. Modern, maintained fork with PHP 8+ and WooCommerce HPOS support.

## 🚀 Features

- **Dual Processing Modes**: Choose between **Standalone xPub** (free) or **Hosted Invoicing** (paid service)
- **Direct Payments**: Funds go straight to your ElectrumSV or BIP32-compatible wallet in standalone mode
- **Professional Invoicing**: SPV-first checkout and hosted payment pages via SendBSV Invoicing service
- **Per-Order Addresses**: Automatic unique address derivation from Master Public Key (xpub/MPK)
- **Priority Exchange Rates**: SendBSV rates (hosted mode) → CoinGecko → CoinPaprika automatic fallback
- **Payment Detection**: WhatsOnChain + Bitails API with automatic fallback
- **Multi-Format Payment Console**: BIP21 payment links plus downloadable BIP270-style invoice payloads with tab switching
- **Payment State Machine**: Canonical state tracking (waiting, detected, verified, expired, underpaid, overpaid)
- **Expiry Enforcement**: Automatic payment window enforcement with late payment monitoring
- **Local QR Generation**: Client-side QR codes (no external services)
- **Aggregate Payments**: Automatically handles multiple transactions to same address
- **Modern Stack**: PHP 8.0-8.3, WordPress 6.9, WooCommerce 10.4
- **HPOS Compatible**: High-Performance Order Storage ready
- **Self-Custody Focused**: All funds settle directly to wallets you control in standalone mode
- **Checkout Options**: Works with both WooCommerce Blocks and classic shortcode checkout
- **Security Hardened**: TLS verification enforced, no unauthenticated triggers, WooCommerce logger integration
- **Hosted Connection**: Connect SendBSV Invoicing with a connector key from your merchant setup

## 🧩 BRC-100 Upgrades in v6.1

- **Richer wallet requests**: `createAction` payloads now enumerate satoshis, locking scripts, and contextual metadata so BRC-100 wallets know exactly what to build.
- **Instant receipts**: Client-side responses can submit txid, raw hex, and optional BEEF blobs which we store on the WooCommerce order for audits and future SPV workflows.
- **Immediate state progress**: As soon as a BRC-100 wallet hands back a receipt we mark the payment `detected`, keeping the checkout stepper responsive even before confirmations land.
- **Hardened messaging**: Updated postMessage handlers validate origin/source and bind to the order nonce to stop spoofed payment events.
- **Order binding**: Return data is validated against order ID, key, expected sats, and nonce so receipts cannot be replayed across orders.

## 🚀 v7.0 Upgrade: Hosted Invoicing Mode

### New Dual-Mode Architecture
**v7.0 introduces two processing modes for merchants to choose from:**

#### **Standalone xPub Mode (Free)**
- **What it is**: Traditional self-custody mode where you manage your own keys
- **How it works**: Plugin generates unique payment addresses from your Master Public Key
- **Funds go to**: Your ElectrumSV/BIP32-compatible wallet directly
- **Best for**: Technical users who want full control, no service fees

#### **Hosted Invoicing Mode (Paid Service)**
- **What it is**: Professional SPV-first checkout via SendBSV Invoicing service
- **How it works**: Plugin connects to SendBSV Invoicing service for hosted payment pages
- **Features**: Professional invoicing, SPV payment detection, enhanced UX
- **Best for**: Merchants who want premium features and simplified setup

### Key Benefits of Hosted Invoicing
- **Professional Payment Pages**: Branded hosted checkout experience
- **SPV-First Detection**: Faster payment confirmation via Simplified Payment Verification
- **Priority Exchange Rates**: SendBSV rates prioritized over public APIs
- **Simple Connection**: Open SendBSV setup, issue connector key, paste key once
- **Automatic Settlements**: Webhook-based payment notifications
- **Enhanced Reporting**: Detailed payment analytics and reporting

### How to Switch Modes
1. Go to **WooCommerce → Settings → Payments → Bitcoin SV**
2. Select "Processing Mode" dropdown
3. Choose between "Standalone xPub" or "Hosted Invoicing"
4. For Hosted Invoicing: Click "Connect Hosted Invoicing", generate a WooCommerce connector key in SendBSV setup, paste key once, then save + test connection
5. Save settings and test checkout

## 📦 Installation

### Requirements
- WordPress 5.8 or higher
- WooCommerce 6.0 or higher
- PHP 7.4 or higher (8.x recommended)
- PHP extensions: `gmp` (preferred) or `bcmath`
- **For Hosted Invoicing**: Active SendBSV Invoicing service account

### Steps
1. Download or clone this repository
2. Upload to `/wp-content/plugins/bitcoin-sv-payments-for-woocommerce/`
3. Activate through WordPress admin → Plugins
4. Choose your processing mode and configure accordingly

## ⚙️ Configuration

### Required Settings
1. Navigate to **WooCommerce → Settings → Payments → Bitcoin SV**
2. Enable the gateway
3. Enter your **ElectrumSV/BIP32 Master Public Key** (xpub format)
4. Set **Number of Confirmations** (recommended: 1-6)
5. Save changes

### Checkout Compatibility

WooCommerce Blocks checkout **and** the classic `[woocommerce_checkout]` shortcode are both supported. No special action is required—just add the standard WooCommerce Checkout block/page during onboarding. If you prefer the legacy experience, you can still create a classic checkout page with the shortcode.

### Optional Settings
- **Exchange Rate Multiplier**: Add markup/markdown percentage
- **Gateway Title**: Customize payment method name
- **Description**: Instructions shown to customers
- **Checkout Icon**: Select from available BSV icons

## 🧪 Testing

**Quick Test:**
1. Add a product to cart
2. Proceed to your checkout page (Blocks or classic shortcode)
3. Select "Bitcoin SV Payment"
4. Complete order
5. Verify payment instructions with QR code appear

**Full Testing:**
1. Test with different product prices
2. Verify exchange rate conversion (if not using BTC as store currency)
3. Send actual BSV payment to generated address
4. Confirm payment detection and order status update
5. Verify order completion email is sent

## ✅ Highlights from the v6.0 Security Release

### 🔒 Security & Compliance
- **TLS Verification**: All external API calls now enforce SSL certificate verification
- **Gateway ID Migration**: Changed from 'bitcoin' to 'bitcoin_sv' to prevent plugin collisions (auto-migrates existing installations)
- **Local QR Generation**: Removed external QR services, all QR codes generated client-side
- **Unauthenticated Triggers Removed**: Eliminated legacy hardcron and IPN callback vulnerabilities
- **WooCommerce Logger**: Integrated with WooCommerce logging system, removed file-based logging
- **Serialization Hardening**: All `unserialize()` calls use `['allowed_classes' => false]`
- **External Services Disclosure**: Full transparency in readme.txt per WP.org guidelines

### 🏗️ Architecture & Maintainability
- **Modularization**: Core utilities file reduced from 1,215 LOC to 75 LOC (94% reduction)
- **Focused Modules**: 12 new modules in `includes/` directory:
  - `address-generation.php` - BIP32 address derivation
  - `blockchain-api.php` - WhatsOnChain/Bitails integration
  - `exchange-rates.php` - CoinGecko/CoinPaprika with caching
  - `payment-state.php` - Canonical state machine
  - `expiry.php` - Payment window enforcement
  - `http.php` - Secure WordPress HTTP API wrapper
  - `logging.php` - WooCommerce logger integration
  - `providers/*` - Modular API provider system
  - And more...
- **Provider System**: Pluggable architecture for blockchain and rate providers

### 💎 Features
- **Payment State Machine**: 7 canonical states with idempotent transitions
- **Expiry Enforcement**: Scheduled sweep finds and expires unpaid orders
- **Late Payment Monitoring**: 7-30 day watch window for payments after expiry
- **Multi-Format Console**: Single QR code with BIP21 payment links plus optional BIP270-style invoice tab (pay:?r=) for compatible wallets
- **Email Improvements**: Payment instructions with address, amount, and pay link
- **Admin Metabox**: Order details with payment state, expected/received amounts, force recheck
- **Chain Height Caching**: 60-second static cache reduces API calls
- **Settings Unification**: Standardized on `confs_num` with legacy compatibility
- **0-Conf Protection**: Minimum 1 confirmation enforced by default

### 🐛 Fixes
- **CoinGecko ID**: Verified correct BSV identifier (bitcoin-cash-sv)
- **Dangerous Defaults**: Auto-complete, auto-delete, address reuse all OFF by default
- **Date Functions**: All `date()` calls replaced with `gmdate()` for WP.org compliance

## ⚠️ Known Limitations

- **Rate Limiting**: CoinGecko free tier has 50 calls/min limit

## 🗺️ Roadmap

### v6.2+ (in Planning)
- Admin diagnostics panel UI (provider health monitoring) so merch dashboards surface provider health at a glance
- Automated PHPCS/PHPStan CI gating to keep every commit linted before release
- HandCash-ready BIP270 enhancements (formal QR toggle + richer callbacks)
- Enhanced address pool management (pre-generation + smarter reuse policies)
- Multi-currency support improvements beyond WooCommerce base currency
- Additional performance profiling, caching, and API rate optimizations

## 🐛 Troubleshooting

### Payment Gateway Not Showing
- Ensure your checkout page contains either the WooCommerce Checkout block or the `[woocommerce_checkout]` shortcode
- Verify Master Public Key is entered correctly
- Check PHP extensions: `gmp` or `bcmath` must be enabled
- Review WooCommerce → Status → Logs for errors

### Exchange Rate Errors
- Verify server can make outbound HTTPS connections
- Check `debug.log` for API errors
- Temporarily set store currency to "BTC" to bypass conversion

### Address Generation Issues
- Confirm xpub format is correct (111 characters starting with "xpub")
- Verify `gmp` or `bcmath` PHP extension is loaded
- Check error logs for math library warnings

## 🤝 Support

- **Issues**: [GitHub Issues](https://github.com/BSVanon/bsv-woocommerce-gateway/issues)
- **Questions**: Open a GitHub issue with your question
- **Testing**: See Testing section above

## 📜 License

GPL-2.0-or-later  
https://www.gnu.org/licenses/gpl-2.0.html

## 🙏 Credits

### Original Authors
- mboyd1 (original Bitcoin plugin)
- sanchaz (Bitcoin Cash fork)
- gesman (Bitcoin SV adaptation)

### v5.x-6.x Modernization & Maintenance
- BSVanon (2026 WordPress.org submission, v6.0 security hardening, and ongoing maintenance)

### Special Thanks
- WooBSV @WooBSV
- ElectrumSV team
- WhatsOnChain
- CoinGecko
- WooCommerce community

---

**Report issues on GitHub!**
