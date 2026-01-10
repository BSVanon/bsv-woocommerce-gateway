## Project Status – Legacy WooCommerce BSV Gateway (Jan 9, 2026)

### Repo / Branch
- Repo: `https://github.com/BSVanon/bitcoin-sv-payments-for-woocommerce`
- Local path: `/home/robert/Documents/BSV-woocommerce/bitcoin-sv-payments-for-woocommerce`
- Branch: `legacy-bootstrap`

### Phase Progress
| Phase | Status | Notes |
| --- | --- | --- |
| Phase 0 – Bootstrap | ✅ | Repo cloned, branch created, file layout verified. |
| Phase 1 – Legacy environment | ✅ | Docker stack (WP 5.0.2 + Woo 3.5.7) running; checkout + thank-you UI verified with screenshots. |
| Phase 2 – Capabilities freeze | ⚠ Partially done | CAPABILITIES.md drafted and aligned with legacy behavior; STATUS.md still to create. |
| Phase 3+ | ⏳ | Pending (modernization, providers, HPOS, Blocks). |

### Key Files Added/Changed
- `DEV_NOTES.md`: how to run Docker stack, install Woo 3.5.7, activate plugin, reproduce legacy UI.
- `CAPABILITIES.md`: frozen list of BSV-only capabilities to preserve.
- `docker-compose.yml` + `docker/wp/`: WordPress 5.0.2 + MySQL 5.7 stack with plugin mount.
- `bwwc-utils.php`: temporary patch to use WhatsOnChain for address balance lookup when legacy explorers fail (lines ~491-528).
- Screenshots saved outside repo: `/home/robert/Documents/BSV-woocommerce/cart payment selection.png`, `.../order placed.png`.

### Environment Notes
1. **Docker stack**
   ```bash
   cd /home/robert/Documents/BSV-woocommerce/bitcoin-sv-payments-for-woocommerce
   docker compose up -d
   ```
   - WP admin at http://localhost:8080
   - DB creds: wordpress / wordpress
2. **WooCommerce install**
   ```bash
   docker compose exec --user www-data wordpress wp plugin install woocommerce --version=3.5.7 --activate
   ```
   (Needed after running `wp core install` through the browser; ensure `/usr/local/bin/wp` exists or reinstall WP-CLI via `curl`.)
3. **PHP extensions**
   ```bash
   docker compose exec -u root wordpress bash
   # inside container
   apt-get update (using archive.debian.org mirrors)
   apt-get install -y --allow-unauthenticated libgmp-dev
   docker-php-ext-install gmp bcmath
   docker-php-ext-enable gmp bcmath
   exit
   docker compose restart wordpress
   ```
4. **Woo settings for legacy smoke test**
   - Store currency temporarily set to **Bitcoin (BTC)** to bypass broken fiat rate checks.
   - BSV gateway enabled with ElectrumSV xpub in **BitcoinWay → Settings**.
   - Cron currently relies on WP-Cron (no real cron configured in Docker).

### Current Behavior
- Classic checkout now shows “Bitcoin SV Payment” option.
- Thank-you page renders amount/address/QR (see screenshots).
- Address generation uses WhatsOnChain fallback (no API key required yet).
- Exchange rate fetching is disabled unless store currency is BTC; USD/EUR still broken.

### Outstanding TODOs
1. **Phase 2 wrap-up**
   - Create/update `STATUS.md` with top tasks + current blockers.
   - Reference legacy screenshots in docs.
2. **Phase 3 – Modern baseline**
   - Run plugin on WP 6.x + Woo 8.x in a second Docker stack.
   - Fix PHP 8 warnings, deprecated Woo hooks, sanitize inputs, add nonce/cap checks.
   - Add composer/phpcs or a simple `php -l` CI step (GitHub Actions).
3. **Phase 4 – Providers**
   - Design rate provider + chain provider interfaces.
   - Ship defaults (e.g., CoinGecko for rates, WhatsOnChain for chain lookup).
   - Add admin settings for merchant-supplied API keys/URLs to avoid using shared free tiers.
4. **Phase 5 – Modern Woo requirements**
   - HPOS compatibility declaration + migrate direct postmeta reads.
   - Decide on Woo Blocks support (if skipping initially, document it).
5. **Documentation**
   - Expand DEV_NOTES with troubleshooting (e.g., WP-CLI install, PHP extension steps, Docker permission fixes).
   - Capture reproduction steps for “How to reproduce legacy UI” (already there but can link to screenshots).

### Risks / Considerations
- Current rate logic still references dead endpoints; enabling USD pricing will fail until Phase 4.
- Docker container uses archived Debian Stretch repos; commands require `--allow-unauthenticated` (documented above).
- WP-CLI is not baked into the WordPress image; needs manual install or a Dockerfile change if we want persistence.
- WhatsOnChain fallback is unauthenticated; heavy usage may require an API key (settings UI needed).

### Next Steps for New Contributor
1. Read `DEV_NOTES.md` and `CAPABILITIES.md`.
2. Bring up Docker stack (`docker compose up -d`) and confirm admin access at http://localhost:8080.
3. Switch store currency back to USD, observe rate failure, and start Phase 4 design to fix it.
4. Begin Phase 3 hardening on a WP 6.x test stack once rate provider plan is ready.
