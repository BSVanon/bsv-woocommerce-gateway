# Upgrade Guide: v4.x → v5.0.0

## Overview

Version 5.0.0 is a major modernization release that brings PHP 8+ compatibility, modern WordPress/WooCommerce support, and improved reliability. This guide helps you upgrade safely from v4.x.

## Breaking Changes

### Minimum Requirements Increased
| Component | v4.x | v5.0.0 |
|-----------|------|--------|
| WordPress | 3.0.1+ | 5.8+ |
| WooCommerce | 2.0+ | 6.0+ |
| PHP | 5.3+ | 7.4+ |

**Action Required**: Verify your hosting meets the new requirements before upgrading.

### API Changes
- **Exchange Rate Provider**: BitcoinAverage API removed (defunct), replaced with CoinGecko
- **Blockchain API**: Added WhatsOnChain as primary provider for payment detection
- **HPOS**: Plugin now declares compatibility with WooCommerce High-Performance Order Storage

## Pre-Upgrade Checklist

### 1. Backup Everything
```bash
# Backup WordPress files
tar -czf wp-backup-$(date +%Y%m%d).tar.gz /path/to/wordpress

# Backup database
mysqldump -u username -p database_name > wp-db-backup-$(date +%Y%m%d).sql
```

### 2. Check Current Configuration
Before upgrading, document your current settings:
- [ ] WooCommerce → Settings → Payments → Bitcoin SV → Manage
- [ ] Note your Master Public Key (xpub/MPK)
- [ ] Note exchange rate multiplier
- [ ] Note number of confirmations required
- [ ] Screenshot all settings pages

### 3. Verify Pending Orders
- [ ] Check for any pending BSV payments
- [ ] Allow them to complete before upgrading
- [ ] Or note addresses to manually monitor

### 4. Test Environment (Recommended)
- [ ] Set up staging site with copy of production
- [ ] Test upgrade on staging first
- [ ] Verify checkout flow works
- [ ] Only then upgrade production

## Upgrade Steps

### Method 1: Via WordPress Admin (Recommended)
1. Navigate to Plugins → Installed Plugins
2. Deactivate "Bitcoin SV Payments for WooCommerce"
3. Delete the plugin (settings are preserved in database)
4. Upload v5.0.0 zip file via Plugins → Add New → Upload Plugin
5. Activate the plugin
6. Verify settings at WooCommerce → Settings → Payments → Bitcoin SV

### Method 2: Manual File Replacement
```bash
# SSH into your server
cd /path/to/wordpress/wp-content/plugins

# Backup current version
mv bitcoin-sv-payments-for-woocommerce bitcoin-sv-payments-for-woocommerce.v4.backup

# Clone v5.0.0
git clone https://github.com/BSVanon/bitcoin-sv-payments-for-woocommerce.git
cd bitcoin-sv-payments-for-woocommerce
git checkout v5.0.0  # Or main branch for latest

# Set permissions
chown -R www-data:www-data /path/to/wordpress/wp-content/plugins/bitcoin-sv-payments-for-woocommerce
```

### Method 3: Git Pull (For Developers)
```bash
cd /path/to/wordpress/wp-content/plugins/bitcoin-sv-payments-for-woocommerce
git fetch origin
git checkout v5.0.0
# Or: git pull origin main
```

## Post-Upgrade Verification

### 1. Check Plugin Status
- [ ] Navigate to Plugins → Installed Plugins
- [ ] Verify version shows "5.0.0"
- [ ] Plugin should be active

### 2. Verify Gateway Operational
- [ ] Go to WooCommerce → Settings → Payments
- [ ] Click "Manage" on Bitcoin SV payment method
- [ ] Top of page should show: "Bitcoin SV Payment Gateway is operational" (green box)
- [ ] Exchange rate should display correctly

### 3. Test Checkout Flow
- [ ] Create test product
- [ ] Add to cart, proceed to checkout
- [ ] Select Bitcoin SV payment
- [ ] Place order
- [ ] Verify BSV address and QR code display
- [ ] Check order appears in WooCommerce → Orders

### 4. Check for PHP Errors
```bash
# Enable debug mode in wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

# Check debug log
tail -f /path/to/wordpress/wp-content/debug.log
```

## What's New in v5.0.0

### Exchange Rate Improvements
- **New**: CoinGecko API integration (free, reliable)
- **Removed**: BitcoinAverage API (service discontinued)
- **Fallback**: Blockchair API for redundancy
- **Result**: More reliable USD/EUR pricing

### Payment Detection Improvements
- **New**: WhatsOnChain API for address balance checks
- **Improved**: Faster payment confirmation detection
- **Fixed**: Legacy explorer APIs that returned errors

### PHP 8 Compatibility
- **Fixed**: All undefined array access warnings
- **Improved**: Input sanitization with `sanitize_text_field()`
- **Added**: Proper `isset()` checks throughout
- **Result**: Zero deprecation warnings on PHP 8.0-8.3

### WooCommerce HPOS Support
- **Added**: Compatibility declaration for High-Performance Order Storage
- **Ready**: For WooCommerce 8.0+ HPOS migration
- **Note**: Plugin uses WooCommerce APIs (not direct DB access)

### Security Enhancements
- **Improved**: Input validation on all `$_GET`, `$_POST`, `$_REQUEST`
- **Added**: `intval()` for numeric inputs
- **Added**: `sanitize_text_field()` for text inputs
- **Added**: ABSPATH check to prevent direct file access

## Troubleshooting

### Issue: "Bitcoin SV Payment Gateway is NOT operational"

**Possible Causes**:
1. Missing PHP extensions (gmp or bcmath)
2. Invalid Master Public Key
3. Exchange rate API failure

**Solutions**:
```bash
# Check PHP extensions
php -m | grep -E 'gmp|bcmath'

# Install if missing
apt-get install php-gmp php-bcmath
# Or for specific PHP version:
apt-get install php8.2-gmp php8.2-bcmath

# Restart web server
systemctl restart apache2
# Or: systemctl restart php8.2-fpm
```

### Issue: Exchange rates not loading

**Temporary Workaround**:
- Change WooCommerce currency to "Bitcoin (BTC)"
- This sets 1:1 exchange rate (1 BTC = 1 BSV)
- Useful for testing, not recommended for production

**Permanent Fix**:
- Verify server can make outbound HTTPS requests
- Check firewall allows connections to api.coingecko.com
- Increase API timeout in Advanced Settings

### Issue: Old orders not visible

**Cause**: HPOS migration may have moved order data

**Solution**:
- WooCommerce → Settings → Advanced → Features
- Check "High-performance order storage" status
- If enabled, orders are in custom tables (not wp_posts)
- Plugin is compatible, orders should still appear

### Issue: Payment detection not working

**Check**:
1. WP-Cron is running (or real cron configured)
2. Server can reach api.whatsonchain.com
3. Cron job schedule in Advanced Settings

**Debug**:
```bash
# Manually trigger cron
wp cron event run BWWC_cron_action

# Check cron schedule
wp cron event list
```

## Rollback Procedure

If you encounter critical issues:

```bash
# Deactivate v5.0.0
cd /path/to/wordpress/wp-content/plugins
mv bitcoin-sv-payments-for-woocommerce bitcoin-sv-payments-for-woocommerce.v5

# Restore v4.x backup
mv bitcoin-sv-payments-for-woocommerce.v4.backup bitcoin-sv-payments-for-woocommerce

# Reactivate via wp-admin
# Settings should be preserved in database
```

**Important**: Report the issue on GitHub so it can be fixed:
https://github.com/BSVanon/bitcoin-sv-payments-for-woocommerce/issues

## Migration Notes

### Settings Preserved
All settings from v4.x are automatically preserved:
- Master Public Key (xpub/MPK)
- Exchange rate multiplier
- Number of confirmations
- Address expiration time
- Cron job settings
- Gateway title/description

### Database Schema
No database schema changes in v5.0.0. Existing tables and data are fully compatible.

### Address Pool
Existing generated addresses continue to be monitored. No re-generation needed.

## Getting Help

- **Documentation**: See DEV_NOTES.md, CAPABILITIES.md, TESTING.md
- **Issues**: https://github.com/BSVanon/bitcoin-sv-payments-for-woocommerce/issues
- **Testing**: Follow TESTING.md for comprehensive test cases

## Next Steps After Upgrade

1. **Enable HPOS** (if not already):
   - WooCommerce → Settings → Advanced → Features
   - Enable "High-performance order storage"
   - Improves performance for stores with many orders

2. **Review Advanced Settings**:
   - Consider reducing cron interval for faster payment detection
   - Adjust exchange rate cache duration
   - Configure address reuse policy

3. **Monitor First Few Orders**:
   - Watch debug.log for any warnings
   - Verify payment detection works as expected
   - Confirm order status updates correctly

4. **Update Documentation**:
   - Inform customers about BSV payment option
   - Update checkout instructions if needed
   - Consider adding BSV logo to payment methods
