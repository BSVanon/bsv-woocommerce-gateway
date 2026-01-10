# Testing Guide - Bitcoin SV Payments for WooCommerce v5.0.0

## Prerequisites
- Docker and Docker Compose installed
- Git repository cloned
- Basic familiarity with WordPress/WooCommerce admin

## Test Environment Setup

### Option 1: Modern Stack (WP 6.7 + Woo 9.x + PHP 8.2)
**Recommended for production testing**

```bash
# Start modern environment
docker compose -f docker-compose.modern.yml up -d

# Access WordPress at http://localhost:8081
# Complete WordPress installation wizard
# Username: admin, Password: (choose secure password)

# Install WooCommerce (latest version)
docker compose -f docker-compose.modern.yml exec wordpress-modern bash
apt-get update && apt-get install -y wget
wget https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip
unzip woocommerce.latest-stable.zip -d /var/www/html/wp-content/plugins/
chown -R www-data:www-data /var/www/html/wp-content/plugins/woocommerce
exit

# Activate WooCommerce via wp-admin or WP-CLI
```

### Option 2: Legacy Stack (WP 5.0.2 + Woo 3.5.7 + PHP 7.2)
**For regression testing only**

```bash
# Start legacy environment (already documented in DEV_NOTES.md)
docker compose up -d
# Access at http://localhost:8080
```

## PHP Extension Requirements

The plugin requires either `gmp` or `bcmath` for cryptographic operations:

```bash
# Install in modern container
docker compose -f docker-compose.modern.yml exec wordpress-modern bash
apt-get update
apt-get install -y libgmp-dev
docker-php-ext-install gmp bcmath
exit

# Restart container to apply
docker compose -f docker-compose.modern.yml restart wordpress-modern
```

## Test Cases

### 1. Plugin Activation
**Objective**: Verify plugin activates without errors on modern WP/Woo

- [ ] Navigate to Plugins → Installed Plugins
- [ ] Activate "Bitcoin SV Payments for WooCommerce"
- [ ] Verify no PHP errors/warnings in debug.log
- [ ] Check that "Bitcoin SV" menu appears in admin sidebar

**Expected**: Plugin activates cleanly, admin menu visible

### 2. Gateway Configuration
**Objective**: Configure BSV payment gateway with ElectrumSV xpub

- [ ] Navigate to WooCommerce → Settings → Payments
- [ ] Enable "Bitcoin SV" payment method
- [ ] Click "Manage" to access settings
- [ ] Enter valid ElectrumSV Master Public Key (xpub)
  - Example test xpub: `xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8`
- [ ] Set "Number of confirmations" to 1 (for testing)
- [ ] Save settings

**Expected**: Settings save successfully, gateway shows as "operational"

### 3. Exchange Rate Fetching
**Objective**: Verify CoinGecko API integration works

- [ ] In gateway settings, verify exchange rate displays
- [ ] Should show: "According to your settings... current calculated rate for 1 Bitcoin SV (in USD)=X.XX"
- [ ] Rate should be non-zero and reasonable (check coingecko.com for current BSV/USD)

**Expected**: Live exchange rate fetched and displayed

### 4. Product Creation & Checkout
**Objective**: Test full checkout flow with BSV payment

- [ ] Create test product: Products → Add New
  - Title: "Test Product"
  - Price: $10.00 USD
  - Publish
- [ ] Add product to cart, proceed to checkout
- [ ] Fill in billing details (use fake data for testing)
- [ ] Select "Bitcoin SV" as payment method
- [ ] Click "Place Order"

**Expected**: Order placed successfully, redirected to payment page

### 5. Payment Page Display
**Objective**: Verify BSV address generation and QR code display

On the order received / payment page, verify:
- [ ] Unique BSV address displayed (starts with `1`)
- [ ] Exact BSV amount shown (converted from USD)
- [ ] QR code image rendered
- [ ] Payment instructions clear
- [ ] Order status: "Pending payment"

**Expected**: All payment details display correctly

### 6. Payment Detection (Manual Test)
**Objective**: Verify blockchain monitoring detects payments

**Note**: This requires actual BSV testnet/mainnet transaction

- [ ] Send exact BSV amount to displayed address
- [ ] Wait for WP-Cron to run (or trigger manually)
- [ ] Check order status in wp-admin → WooCommerce → Orders
- [ ] After 1 confirmation, order should change to "Processing" or "Completed"

**Expected**: Payment detected, order status updated

### 7. HPOS Compatibility
**Objective**: Verify plugin works with High-Performance Order Storage

- [ ] Navigate to WooCommerce → Settings → Advanced → Features
- [ ] Enable "High-performance order storage"
- [ ] Click "Enable" and confirm
- [ ] Repeat checkout test (Test Case #4)
- [ ] Verify order appears in Orders list
- [ ] Check order meta data preserved

**Expected**: Plugin works seamlessly with HPOS enabled

### 8. PHP 8.x Compatibility
**Objective**: Verify no deprecation warnings on PHP 8.2+

- [ ] Enable WordPress debug mode (wp-config.php):
  ```php
  define('WP_DEBUG', true);
  define('WP_DEBUG_LOG', true);
  ```
- [ ] Perform full checkout flow
- [ ] Check `/var/www/html/wp-content/debug.log` for errors
- [ ] Look for: undefined array access, deprecated function calls

**Expected**: No PHP warnings or errors in debug.log

### 9. Multi-Currency Support
**Objective**: Test exchange rate conversion for different currencies

- [ ] Change WooCommerce currency: Settings → General → Currency
- [ ] Test with: EUR, GBP, CAD, AUD
- [ ] For each currency:
  - [ ] Verify exchange rate fetches correctly
  - [ ] Create order, check BSV amount calculation
  - [ ] Verify conversion is accurate (compare to coingecko.com)

**Expected**: All major currencies convert correctly

### 10. Address Reuse Settings
**Objective**: Test expired address reuse functionality

- [ ] In gateway settings, enable "Reuse expired addresses"
- [ ] Create order, note the BSV address
- [ ] Wait for address to expire (or manually update DB)
- [ ] Create new order
- [ ] Verify address is reused if expired

**Expected**: Expired addresses reused when setting enabled

## Known Issues / Limitations

### Current Version (v5.0.0)
- **Blocks Checkout**: Not yet supported (classic checkout only)
- **Real Cron**: Requires manual setup if `DISABLE_WP_CRON` is true
- **API Rate Limits**: CoinGecko free tier has limits (50 calls/min)

### Workarounds
- If exchange rates fail, temporarily set store currency to "BTC" (1:1 rate)
- For faster payment detection, reduce cron interval in Advanced Settings

## Performance Testing

### Load Test Scenarios
1. **High Order Volume**: Create 100+ orders, verify address generation doesn't slow down
2. **Concurrent Checkouts**: Multiple users checking out simultaneously
3. **Cron Performance**: Monitor cron job execution time with many pending orders

### Monitoring
```bash
# Watch cron job execution
tail -f /var/www/html/wp-content/debug.log | grep BWWC

# Check database performance
docker compose exec mysql mysql -u wordpress -pwordpress -e "SHOW PROCESSLIST;"
```

## Regression Testing (v4.x → v5.0)

### Migration Checklist
- [ ] Backup v4.x database before upgrade
- [ ] Install v5.0 plugin
- [ ] Verify existing settings preserved
- [ ] Check old orders still accessible
- [ ] Test that old addresses still monitored
- [ ] Verify exchange rate cache migrates

### Rollback Procedure
```bash
# If issues found, rollback to v4.x
docker compose exec wordpress bash
cd /var/www/html/wp-content/plugins
rm -rf bitcoin-sv-payments-for-woocommerce
# Restore v4.x from backup
```

## Reporting Issues

When reporting bugs, include:
1. WordPress version
2. WooCommerce version
3. PHP version
4. Plugin version
5. Error message (from debug.log)
6. Steps to reproduce
7. Expected vs actual behavior

Submit issues: https://github.com/BSVanon/bitcoin-sv-payments-for-woocommerce/issues
