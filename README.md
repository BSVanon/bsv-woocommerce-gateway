# Bitcoin SV Payments for WooCommerce

**Version:** 5.0.0-beta  
**Status:** Ready for Testing  
**Requires:** WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+

Accept Bitcoin SV (BSV) payments directly to your wallet. Self-custody, no third-party processor required. Modern, maintained fork with PHP 8+ and WooCommerce HPOS support.

## 🚀 Features

- **Direct Payments**: Funds go straight to your ElectrumSV or BIP32-compatible wallet
- **Per-Order Addresses**: Automatic unique address derivation from Master Public Key (xpub/MPK)
- **Real-Time Exchange Rates**: CoinGecko integration with configurable markup
- **Payment Detection**: WhatsOnChain blockchain API integration
- **QR Codes**: Easy mobile payments
- **Modern Stack**: PHP 8.0-8.3, WordPress 6.7, WooCommerce 9.5
- **HPOS Compatible**: High-Performance Order Storage ready

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

### Important: Classic Checkout Required

**WooCommerce Blocks Checkout is not yet supported.** You must use the classic checkout:

1. Create a new page (e.g., "Checkout")
2. Add the shortcode: `[woocommerce_checkout]`
3. Go to **WooCommerce → Settings → Advanced**
4. Set **Checkout page** to your new classic checkout page
5. Save changes

The Bitcoin SV payment option will now appear correctly at checkout.

### Optional Settings
- **Exchange Rate Multiplier**: Add markup/markdown percentage
- **Gateway Title**: Customize payment method name
- **Description**: Instructions shown to customers
- **Checkout Icon**: Select from available BSV icons

## 🧪 Testing

See `docs/TESTING.md` for comprehensive test procedures.

**Quick Test:**
1. Add a product to cart
2. Proceed to classic checkout page
3. Select "Bitcoin SV Payment"
4. Complete order
5. Verify payment instructions with QR code appear

## 📚 Documentation

- **[TESTING.md](docs/TESTING.md)** - Test suite and procedures
- **[UPGRADE.md](docs/UPGRADE.md)** - Migration guide from v4.x
- **[RELEASE_NOTES.md](docs/RELEASE_NOTES.md)** - v5.0.0 changes
- **[DEV_NOTES.md](docs/DEV_NOTES.md)** - Development environment setup
- **[CAPABILITIES.md](docs/CAPABILITIES.md)** - Feature specification

## ⚠️ Known Limitations (v5.0.0-beta)

- **WooCommerce Blocks**: Not yet supported (use classic checkout with `[woocommerce_checkout]` shortcode)
- **Provider Configuration**: API endpoints are hardcoded (customization UI planned for v5.1)
- **Rate Limiting**: CoinGecko free tier has 50 calls/min limit

## 🗺️ Roadmap

### v5.1 (Planned)
- WooCommerce Blocks checkout support
- Provider configuration UI (custom API endpoints)
- Admin diagnostics dashboard improvements

### v5.2 (Planned)
- Webhook support for instant payment notifications
- Enhanced address pool management
- Performance optimizations

## 🐛 Troubleshooting

### Payment Gateway Not Showing
- Ensure you're using **classic checkout** (not Blocks)
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

- **Issues**: [GitHub Issues](https://github.com/BSVanon/bitcoin-sv-payments-for-woocommerce/issues)
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

### v5.0.0 Modernization
- BSVanon (2026 modernization and maintenance)

### Special Thanks
- ElectrumSV team
- WhatsOnChain
- CoinGecko
- WooCommerce community

---

**Ready for Beta Testing** - Please report issues on GitHub!
