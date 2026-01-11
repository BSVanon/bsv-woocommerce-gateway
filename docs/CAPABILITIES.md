## Target Capabilities (BSV-only, self-custody)

1. **Merchant-supplied MPK/xpub**
   - Accept BIP32 master public keys from ElectrumSV or compatible wallets.
   - Validate format and store securely in plugin options.

2. **Per-order address derivation**
   - Derive a unique receive address for each WooCommerce order (or optional pre-generated pool).
   - Allow re-using expired addresses if the merchant enables it.

3. **Checkout UI**
   - Display BSV address, exact amount, and QR code on both the pay page and thank-you page.
   - Include QR/payment instructions in customer order emails.

4. **Exchange rate handling**
   - Fetch fiat→BSV exchange rates from a configurable provider.
   - Support merchant-defined multipliers/markups.
   - Persist the exchange rate used into Woo order meta for later audits.

5. **Gateway presentation**
   - Provide custom gateway title/description and allow selecting a checkout icon within admin settings.

6. **Payment detection**
   - Poll a chain data provider for received amount + tx confirmations per derived address.
   - Record expected amount, received amount, txid, confirmations, and timestamps for diagnostics.
   - Enforce secret-key validation on any inbound notification endpoints.

7. **Cron / scheduling**
   - Support both WP-Cron and real cron invocations (honor `DISABLE_WP_CRON`).
   - Ensure catch-up logic when cron events are delayed.

8. **Admin diagnostics**
   - Provide an admin page summarizing per-order payment details, logs, and troubleshooting hints.

9. **Woo compatibility**
   - Register as a standard WooCommerce payment gateway using supported APIs.
   - Classic checkout must work end-to-end before considering Blocks integration.
