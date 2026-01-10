# Current Status & Next Tasks

## Completed ✅
- **Phase 0**: Repo cloned, `legacy-bootstrap` branch created
- **Phase 1**: Docker environment running, legacy UI verified with screenshots
- WhatsOnChain fallback implemented in `bwwc-utils.php` for address balance checks
- Documentation: `DEV_NOTES.md`, `CAPABILITIES.md`, `PROJECT_STATUS.md` created

## In Progress 🔄
- **Phase 2**: Documentation (this file completes it)

## Top 3 Immediate Tasks 🎯

### 1. Set up modern test environment (Phase 3 start)
Create a second Docker stack with:
- WordPress 6.7+ 
- WooCommerce 9.x
- PHP 8.2+

This will expose compatibility issues without breaking the legacy demo.

### 2. Fix exchange rate providers (Phase 4 critical)
Current blockers:
- USD/EUR pricing fails (dead API endpoints)
- Store must use BTC currency as workaround

Action items:
- Replace `BWWC__get_exchange_rate_from_bitcoinaverage()` with working provider
- Add CoinGecko as default (no API key required)
- Create settings UI for merchant API keys

### 3. Audit PHP 8 compatibility (Phase 3)
Known risks from legacy code patterns:
- Direct array access without `isset()` checks
- Deprecated WooCommerce hooks/functions
- Missing input sanitization/nonce validation

## Current Blockers 🚫
1. **Exchange rates broken** - prevents USD pricing (workaround: use BTC currency)
2. **No modern WP/Woo testing** - unknown compatibility issues lurking
3. **Provider APIs hardcoded** - merchants can't supply their own keys

## Timeline Estimate ⏱️
- Phase 2 completion: ✅ Done (this file)
- Phase 3 (modern baseline): 1-1.5 days
- Phase 4 (providers): 0.5-1 day  
- Phase 5 (HPOS/Blocks): 0.5-1 day
- **Total to v1 release**: ~3-4 days

## Notes 📝
- Screenshots saved: `/home/robert/Documents/BSV-woocommerce/cart payment selection.png` & `order placed.png`
- Legacy stack uses archived Debian repos (requires `--allow-unauthenticated`)
- WP-CLI must be manually installed in container (not persistent across rebuilds)
