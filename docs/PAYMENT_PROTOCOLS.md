# Payment Protocol Implementation

## Three-Toggle System

The plugin now supports three distinct payment QR code formats:

### 1. Standard (BIP21)
**Toggle Label:** "Standard"  
**Format:** `bitcoin:<address>?sv&amount=<bsv_amount>`  
**Use Case:** Basic BSV payment requests with amount included  
**Wallet Support:** Most BSV wallets that support BIP21 URI scheme  
**Example:**
```
bitcoin:1Dg2vPLio5caJshVsfHdhWpWAeTmaw8sB?sv&amount=0.00054795
```

### 2. Invoice (BIP270 DPP)
**Toggle Label:** "Invoice"  
**Format:** `pay:?r=<HTTPS_URL_TO_PAYMENTTERMS>`  
**Use Case:** Modern Direct Payment Protocol with invoice fetching  
**Wallet Support:** Wallets implementing BSV TSC DPP standard  
**Specification:** [BSV TSC Direct Payment Protocol](https://tsc.bsvblockchain.org/standards/direct-payment-protocol/)

**QR Payload Example:**
```
pay:?r=https://testing.sendbsv.com/wc-api/bsv_invoice?order_id=175&key=wc_order_f9dltp8oJpAQL&sig=4a6db93e7a8771467492898575126261a5cb190af637f294f7b8f137bc85b5ed
```

**PaymentTerms Response (BSV TSC DPP compliant):**
```json
{
  "network": "bitcoin-sv",
  "version": "1.0",
  "creationTimestamp": 1768951786,
  "expirationTimestamp": 1768952355,
  "memo": "Payment for WooCommerce Order #175",
  "paymentUrl": "https://testing.sendbsv.com/wc-api/bsv_payment?order_id=175&key=...",
  "merchantData": "eyJvcmRlcklkIjoxNzUsIm9yZGVyS2V5Ijoid2Nfb3JkZXJ...",
  "modes": [
    {
      "mode": "HybridPaymentMode",
      "brfcId": "ef63d9775da5",
      "outputs": [
        {
          "amount": 54795,
          "script": "76a9140265932243efbdfacbc0d232a06a1b25ac2b5d7288ac",
          "description": "Order #175 payment"
        }
      ]
    }
  ],
  "outputs": [
    {
      "script": "76a9140265932243efbdfacbc0d232a06a1b25ac2b5d7288ac",
      "amount": 54795,
      "description": "Order #175 payment"
    }
  ]
}
```

**Required Fields:**
- `network`: "bitcoin-sv"
- `version`: Protocol version
- `creationTimestamp`: Unix timestamp
- `paymentUrl`: HTTPS URL for payment submission
- `modes`: Array with HybridPaymentMode (BRFCID: ef63d9775da5)

**Backward Compatibility:**
- `outputs` array included for older wallet implementations (deprecated in spec)

### 3. Address-only (HandCash-safe)
**Toggle Label:** "Address-only"  
**Format:** Raw BSV address (no URI scheme, no parameters)  
**Use Case:** Maximum compatibility with wallets that only scan addresses  
**Wallet Support:** HandCash and other wallets with limited QR parsing  
**Example:**
```
1Dg2vPLio5caJshVsfHdhWpWAeTmaw8sB
```

**Important:** User must manually enter the payment amount after scanning.

## Implementation Details

### Invoice Endpoint
- **URL:** `/wc-api/bsv_invoice`
- **Method:** GET
- **Parameters:**
  - `order_id`: WooCommerce order ID
  - `key`: Order key for validation
  - `sig`: HMAC signature to prevent tampering
- **Response:** `application/payment-terms+json`
- **Spec Compliance:** BSV TSC Direct Payment Protocol v1.0

### Payment Receiver Endpoint
- **URL:** `/wc-api/bsv_payment`
- **Method:** POST
- **Content-Type:** `application/payment` or `application/bitcoinsv-payment`
- **Response:** `application/payment-ack+json`

### Security
- HTTPS required for BIP270 DPP endpoints
- HMAC signature validation on all invoice/payment requests
- Order key verification
- Payment state validation

## Wallet Compatibility

| Wallet | Standard (BIP21) | Invoice (BIP270 DPP) | Address-only |
|--------|------------------|----------------------|--------------|
| HandCash | ❌ | ❌ | ✅ |
| DPP-compliant wallets | ✅ | ✅ | ✅ |
| Basic BSV wallets | ✅ | ❌ | ✅ |
| BRC-100 compatible wallets | ✅ | ❌ | ✅ |
| MNEE hosted | ✅ | ❌ | ✅ |

## References

- [BSV TSC Direct Payment Protocol](https://tsc.bsvblockchain.org/standards/direct-payment-protocol/)
- [BRC-27 Payment Protocol](https://github.com/bitcoin-sv/BRCs/blob/master/payments/0027.md)
- [BIP21 URI Scheme](https://github.com/bitcoin/bips/blob/master/bip-0021.mediawiki)
