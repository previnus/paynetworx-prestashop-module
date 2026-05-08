# Paynetworx — PrestaShop Payment Module

A production-ready payment gateway integration for **PrestaShop 8.x and 9.x**. Accepts credit and debit card payments via the Paynetworx API with a modern, secure checkout form embedded directly on the payment page.

> **PCI DSS scope:** SAQ D (card data transits the server over TLS). For a SAQ A-eligible hosted-iframe variant see the companion `paynetworxhosted` module.

---

## Features

- **PrestaShop 8 & 9** compatible (`PaymentOption` hook, `ModuleFrontController`)
- **Modern card form** — consolidated MM/YY expiry input, real-time brand detection (Visa, Mastercard, Amex, Discover, JCB, Diners), Luhn validation
- **Double-submit protection** — CSPRNG nonce stored in the encrypted PS cookie; `hash_equals()` timing-safe comparison
- **Idempotency** — dedicated `paynetworx_transactions` table with a unique cart key; concurrent duplicate POSTs are rejected at the DB level
- **Orphaned-charge recovery** — if `validateOrder()` fails after a successful charge, a severity-4 CRITICAL log entry captures the TransactionID for manual reconciliation
- **Security hardened** — TLS 1.2 minimum, SSL peer verification, 10 s connect / 30 s read timeouts, rejection-sampling KSUID generation, no card data in logs

---

## Requirements

- PrestaShop 8.0.0 or higher
- PHP 7.4+ (uses `random_bytes()`)
- cURL extension with TLS 1.2 support
- Paynetworx merchant account with Access Token credentials

---

## Installation

1. Upload the `paynetworx` folder to your store's `/modules/` directory.
2. In the Back Office go to **Module Manager**, search for **Paynetworx**, and click **Install**.
3. Click **Configure** and enter:
   - **Environment** — Test / Sandbox or Live / Production
   - **Access Token User** — provided by Paynetworx during onboarding
   - **Access Token Password** — provided by Paynetworx during onboarding
4. Click **Save**.

---

## Test Environment Notes

- **Currency:** The Paynetworx QA sandbox requires **USD**. Other currencies return `currency_error`.
- **Amounts:** Keep test transactions under $15.00.
- **Test cards:** Request specific test card numbers from your Paynetworx account manager. Generic cards often return "No such issuer."

---

## Logs

Go to **Advanced Parameters → Logs** in the Back Office. Filter by object type `Cart` to see charge attempts, gateway responses, and any decline details.

---

## Upgrade / Reset

After upgrading the module ZIP, click **Reset** in the Module Manager so that hook registrations are refreshed.

---

## Author

**ArcPro Media Inc.**  
[www.arcpromedia.com](https://www.arcpromedia.com)
