# Bitcoin SV Payments for WooCommerce

**Version:** 5.3.2  
**Status:** Production Ready - UI/UX Polished  
**Requires:** WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+

Accept Bitcoin SV (BSV) payments directly to your wallet. Self-custody, no third-party processor required. Modern, maintained fork with PHP 8+ and WooCommerce HPOS support.

## 🚀 Features

- **Direct Payments**: Funds go straight to your ElectrumSV or BIP32-compatible wallet
- **Per-Order Addresses**: Automatic unique address derivation from Master Public Key (xpub/MPK)
- **Real-Time Exchange Rates**: CoinGecko integration with configurable markup
- **Payment Detection**: WhatsOnChain + Bitails API with automatic fallback
- **Modern Payment UI**: Real-time status updates, QR codes, countdown timers
- **Aggregate Payments**: Automatically handles multiple transactions to same address
- **QR Codes**: Easy mobile payments
- **Modern Stack**: PHP 8.0-8.3, WordPress 6.9, WooCommerce 10.4
- **HPOS Compatible**: High-Performance Order Storage ready
- **Self-Custody Focused**: All funds settle directly to wallets you control
- **Checkout Options**: Works with both WooCommerce Blocks and classic shortcode checkout

## 📦 Installation

### Requirements
- WordPress 5.8 or higher
- WooCommerce 6.0 or higher
- PHP 7.4 or higher (8.x recommended)
- PHP extensions: `gmp` (preferred) or `bcmath`

### Steps
1. Download or clone this repository
2. Upload to `/wp-content/plugins/bitcoin-sv-payments-for-woocommerce/`
3. Activate through WordPress admin → Plugins
4. Configure with your ElectrumSV Master Public Key

## ⚙️ Configuration

### Required Settings
1. Navigate to **WooCommerce → Settings → Payments → Bitcoin SV**
2. Enable the gateway
3. Enter your **ElectrumSV Master Public Key** (xpub format)
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

See `docs/TESTING.md` for comprehensive test procedures.

**Quick Test:**
1. Add a product to cart
2. Proceed to your checkout page (Blocks or classic shortcode)
3. Select "Bitcoin SV Payment"
4. Complete order
5. Verify payment instructions with QR code appear

## 📚 Documentation

These guides live inside the `docs/` directory of this repository:

- **[TESTING.md](docs/TESTING.md)** - Test suite and procedures
- **[RELEASE_NOTES.md](docs/RELEASE_NOTES.md)** - Latest release details
- **[DEV_NOTES.md](docs/DEV_NOTES.md)** - Development environment setup
- **[CAPABILITIES.md](docs/CAPABILITIES.md)** - Feature specification

## ✅ Recent Fixes (v5.3.1)

- **CRITICAL**: Fixed payment detection - now works correctly
- **Fixed**: Blockchain explorer URLs (was missing /address/ path)
- **Fixed**: Transaction IDs now stored and displayed
- **Fixed**: "I've Paid" button triggers immediate check
- **Added**: Bitails API fallback for reliability
- **Added**: Wallet top-up links for customer convenience
- **Improved**: Underpaid/overpaid payment handling with clear UI feedback
- **Improved**: Confirmation time estimates shown to customers

## ⚠️ Known Limitations

- **Rate Limiting**: CoinGecko free tier has 50 calls/min limit

## 🗺️ Roadmap

### v5.3 (Planned)
- Provider configuration UI (custom API endpoints)
- Webhook support for instant payment notifications
- Enhanced address pool management

### v6.0 (Future)
- Multi-currency support improvements
- Admin diagnostics dashboard enhancements
- Performance optimizations

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
- **Documentation**: See `docs/` folder
- **Testing**: Follow `docs/TESTING.md`

## 📜 License

GPL-2.0-or-later  
https://www.gnu.org/licenses/gpl-2.0.html

## 🙏 Credits

### Original Authors
- mboyd1 (original Bitcoin plugin)
- sanchaz (Bitcoin Cash fork)
- gesman (Bitcoin SV adaptation)

### v5.x Modernization & Maintenance
- BSVanon (2026 WordPress.org submission and ongoing maintenance)

### Special Thanks
- Bitcoin Dictionary @BitcoinDict
- ElectrumSV team
- WhatsOnChain
- CoinGecko
- WooCommerce community

---

**v5.2.0 - Blocks Ready Release** - Report issues on GitHub!
