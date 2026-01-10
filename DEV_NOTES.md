## Development Notes

### Local WordPress / WooCommerce environment (Docker Compose)
This project uses a PHP 7.2 era WordPress stack to mirror the plugin’s original runtime.

1. Install Docker + Docker Compose v2.
2. From the repo root run `docker compose up -d`.
3. WordPress will be available at http://localhost:8080 with `wp-content/plugins/bitcoin-sv-payments-for-woocommerce` bind-mounted from this repository.
4. Default MySQL credentials are defined in `docker-compose.yml` (user: `wordpress`, password: `wordpress`, database: `wordpress`).

### Installing & activating WooCommerce + this plugin
1. Log into `http://localhost:8080/wp-admin` (create the WP admin account during first-boot setup).
2. Install WooCommerce 3.5.7:
   ```bash
   docker compose run --rm wordpress wp plugin install woocommerce --version=3.5.7 --activate
   ```
3. Activate the Bitcoin SV plugin from **Plugins → Installed Plugins** (it appears as “Bitcoin SV Payments for WooCommerce”).

### Key plugin components
| Area | File(s) | Notes |
| --- | --- | --- |
| Gateway registration, checkout + thank-you UI | `bwwc-bitcoin-gateway.php` | Hooks into `woocommerce_payment_gateways`, renders the payment form, email instructions, and thank-you screen. |
| Admin settings UI | `bwwc-admin.php`, `bwwc-render-settings.php` | Adds plugin settings pages, including ElectrumSV MPK, rate multipliers, and checkout icon selection. |
| MPK derivation utilities | `bwwc-mpkgen.php`, `libs/` & `phpecc/` | Implements ElectrumSV/XPUB child address derivation rooted in merchant-provided MPK/xpub. |
| Payment monitoring & cron | `bwwc-cron.php`, `bwwc-utils.php` | Schedules WP-Cron tasks, polls exchange rates/block explorers, updates order metadata. |
| Shared helpers / bootstrap | `bwwc-include-all.php`, `bitcoinway-woocommerce.php` | Primary plugin bootstrap file that loads all modules and shared utility functions. |

### How to reproduce the legacy UI
1. Follow the Docker setup above and finish WP onboarding.
2. Add a simple product priced at $1 from **Products → Add New**.
3. Enable the “Bitcoin SV” payment method inside **WooCommerce → Settings → Payments**.
4. Place an order through the classic checkout and choose “Bitcoin SV”.
5. The thank-you page displays the derived BSV address, amount, and QR code (rendered by `BWWC__thankyou_page()` in `bwwc-bitcoin-gateway.php`).
6. Use browser screenshots or screen recording tools to capture the checkout selection, thank-you page, and QR display for regression reference.
