# BIP270 Testing & Verification Guide

## Overview
This document provides testing guidance for the BIP270 Direct Payment Protocol implementation in v6.1.

## Manual Testing Checklist

### 1. Invoice Endpoint Testing
**Endpoint:** `GET /wc-api/bsv_invoice?order_id=X&key=Y&sig=Z`

**Test with curl:**
```bash
# Replace with actual order details
curl -H "Accept: application/payment-terms+json" \
  "https://yoursite.com/wc-api/bsv_invoice?order_id=123&key=wc_order_xxx&sig=xxx"
```

**Expected Response:**
- Status: 200 OK
- Content-Type: `application/payment-terms+json`
- Body includes:
  - `network: "bitcoin-sv"`
  - `version: "1.0"`
  - `paymentUrl` (HTTPS)
  - `modes` array with `HybridPaymentMode`
  - `outputs` array with required satoshis and script

**Validation:**
- ✅ Signature validation works (invalid sig returns 403)
- ✅ Order key validation works (wrong key returns 403)
- ✅ Expired orders handled appropriately
- ✅ HTTPS enforced (unless BWWC_ALLOW_HTTP_INVOICE defined)

### 2. Payment Receiver Testing
**Endpoint:** `POST /wc-api/bsv_payment?order_id=X&key=Y&sig=Z`

**Test with real wallet:**
1. Create test order in WooCommerce
2. Use BIP270-compatible wallet (HandCash, etc.)
3. Scan invoice QR code: `pay:?r=https://...`
4. Complete payment in wallet
5. Verify order state updates

**Expected Behavior:**
- Wallet fetches invoice successfully
- Wallet broadcasts transaction
- Server receives payment POST
- Server validates transaction outputs
- Server broadcasts (best-effort)
- Server returns PaymentACK
- Order state changes to `detected`
- Cron confirms and moves to `confirmed`

**Validation:**
- ✅ Raw hex transaction parsing works
- ✅ JSON transaction payload parsing works
- ✅ Output validation matches invoice requirements
- ✅ Broadcast to WoC succeeds (or Bitails fallback)
- ✅ "Already in mempool" treated as success
- ✅ PaymentACK includes memo and merchantData
- ✅ Idempotency: resubmitting same tx returns cached ACK

### 3. Idempotency Testing
**Test scenario:**
1. Submit valid payment transaction
2. Resubmit exact same transaction
3. Verify second submission returns ACK without error
4. Verify no duplicate state changes

**Validation:**
- ✅ `_bwwc_bip270_submitted_txids` stores txid
- ✅ Duplicate submission detected
- ✅ Cached ACK returned
- ✅ No duplicate broadcasts attempted

### 4. Error Handling Testing
**Test scenarios:**
- Invalid signature → 403 Forbidden
- Wrong order key → 403 Forbidden
- Missing order → 404 Not Found
- Invalid transaction hex → 400 Bad Request
- Insufficient payment amount → 400 Bad Request
- Network errors during broadcast → Still records payment

### 5. Integration with Payment State Machine
**Verify:**
- ✅ BIP270 payment sets state to `detected`
- ✅ Cron job confirms and moves to `confirmed`
- ✅ Order completion triggers on required confirmations
- ✅ Webhook fires on state change to `confirmed` (if configured)

## Wallet Compatibility Matrix

| Wallet | BIP270 Support | Tested | Notes |
|--------|----------------|--------|-------|
| HandCash | ✅ Yes | ⚠️ Pending | Primary test target |
| Money Button | ✅ Yes | ⚠️ Pending | Legacy support |
| DotWallet | ❓ Unknown | ❌ No | Check compatibility |
| Electrum SV | ❌ No | N/A | BIP21 only |

## Known Limitations

1. **Single transaction payments only**: Multi-transaction payments not yet supported
2. **HTTPS required**: HTTP only allowed with `BWWC_ALLOW_HTTP_INVOICE` constant
3. **No BEEF validation**: BEEF payloads accepted but not validated (future enhancement)

## Recommended Test Wallets

### HandCash (Primary)
- Download: https://handcash.io
- Supports: BIP270 invoice scanning
- Test network: Mainnet only

### Money Button (Legacy)
- Supports: BIP270 via API
- Test network: Mainnet

## Debugging Tips

### Enable Debug Logging
```php
// In wp-config.php
define('BWWC_DEBUG', true);
```

### Check Logs
- WooCommerce → Status → Logs
- Look for entries with `[BIP270]` prefix

### Common Issues

**Issue:** Invoice returns 403
- **Fix:** Verify signature generation matches between invoice URL and payment URL

**Issue:** Payment receiver returns 400
- **Fix:** Check transaction hex format (must be lowercase, even length)

**Issue:** Broadcast fails but payment valid
- **Fix:** This is expected behavior - wallet may have already broadcast

## Production Readiness Checklist

- [ ] Test with real BIP270 wallet on staging
- [ ] Verify HTTPS certificate valid
- [ ] Confirm webhook URL configured (if using webhooks)
- [ ] Test idempotency with duplicate submissions
- [ ] Verify cron job running and confirming payments
- [ ] Check order completion workflow end-to-end
- [ ] Monitor logs for errors during test period
- [ ] Document any wallet-specific quirks discovered

## Next Steps

1. **Immediate:** Test with HandCash on staging environment
2. **Before production:** Complete full payment flow test with real funds (small amount)
3. **Post-launch:** Monitor first 10 BIP270 payments closely
4. **Future:** Add automated integration tests for BIP270 flow

## Support Resources

- BIP270 Spec: https://github.com/moneybutton/bips/blob/master/bip-0270.mediawiki
- BSV TSC DPP: https://tsc.bsvblockchain.org/standards/direct-payment-protocol/
- HandCash Developer Docs: https://docs.handcash.io
