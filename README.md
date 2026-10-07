# GetBD — WiseCP Domain Registrar Module

WiseCP registrar module for **.bd domains** via the [Get BD](https://get.bd) partner API
(`https://api.get.bd/api/v1/external`, sandbox: `https://sandbox-api.get.bd/api/v1/external`).

Ported from the `get_bd` WHMCS module in this repository, adapted to WiseCP's
`RegistrarModule` class contract (mirrors `coremio/modules/Registrars/Freenom` / `Namecheap`).

## Why .bd is special

.bd domains are governed by BTCL (Bangladesh Telecommunication Company Limited) rules:

1. The registrant must supply a **NID number** (10, 13 or 17 digits).
2. The registry requires **supporting documents** (trade license, certificates, etc. —
   depends on the TLD). The Get BD order is only processed once **at least 2 documents
   are APPROVED**.
3. Until then the domain stays **inactive at the registry**. This module syncs the real
   activation state so WiseCP never shows the domain as live prematurely.

## Installation

1. Copy this folder to `coremio/modules/Registrars/GetBD/` in your WiseCP install.
2. Admin area → Registrars → **GetBD** → enter your API Key (and Sandbox key if used),
   toggle Sandbox Mode as needed, save (a connection test runs automatically).
3. Products → Domain Extensions: create the .bd TLDs you sell (`bd`, `com.bd`, `net.bd`,
   `org.bd`, `edu.bd`, `info.bd`, `biz.bd`, `ac.bd`, `gov.bd`, `mil.bd`, `tv.bd`, `id.bd`)
   and assign the **GetBD** module to each.

## How the flow works

```
Checkout ──► service created (inprocess) ──► ModuleQueue: register()
                                                │
                                                ├─ NID/doc-fields already submitted?
                                                │    ├─ yes ─► POST /orders → POST /orders/{id}/process
                                                │    │          (documents-pending failure expected → SUCCESS,
                                                │    │           order id stored in options.config.id)
                                                │    └─ no  ─► register fails with a clear message
                                                │              (queue retries 3×, then waits)
                                                ▼
Client submits NID + documents in the domain manager
(WiseCP native doc-fields verification — action:domain.verification_submitted)
                                                │
                                                ├─ hook re-queues register() if it was blocked
                                                ▼
Admin verifies documents in WiseCP  ──►  cron polls get.bd every 15 min per service:
   • order not processed yet + docs verified  → POST /orders/{id}/process (retry)
   • staff approves documents in the Get BD partner portal
     (partner.get.bd/orders/{id}) — order id is in options.config.id
                                                │
                                                ▼
Registry activates the domain (localDomain.isActive = true)
   → module cron immediately writes the real expiry date into the WiseCP
     service (duedate) and flags it activated — no waiting on WiseCP's
     daily DomainStatusSync (which has a 7-day cooldown for pending domains)
   → WiseCP's own DomainStatusSync cron remains the long-term backstop
     for expiry-date corrections
```

### Notes on the document lifecycle

- The **NID**, **Registration Type** and **Verification Documents** file are collected
  through WiseCP's built-in doc-fields system (`doc-fields` in `config.php`) and stored in
  `users_products_docs`. The module reads them via `$this->docs`.
- The module does **not** upload documents to the Get BD API. The Get BD API has a
  `/documents/upload` endpoint, but this integration (matching the WHMCS module) leaves
  document upload/approval to staff in the partner portal. The WiseCP doc record is the
  local audit trail and gates the processing retries.
- If registration was attempted before the client submitted the NID, the queue item fails
  after 3 attempts (expected). Once documents are submitted, the
  `action:domain.verification_submitted` hook re-queues registration automatically; the
  `PerMinuteCronJob` hook is the safety net (re-queue throttled to 1/hour).

## Supported operations

| Operation | Support | Details |
|---|---|---|
| Availability check | ✅ | `GET /domains/search` |
| Register | ✅ | `POST /orders` + `POST /orders/{id}/process` |
| Renew | ✅ | `POST /domains/renew` |
| Nameservers | ✅ | `PUT /domains/update` (max 3, per BTCL rules) |
| Sync status/expiry | ✅ | `GET /domains/info` → `localDomain.isActive` / `expiryDate` |
| Domain import | ✅ | `GET /domains` |
| Transfer | ❌ | Not supported by the .bd registry — clear error, `transfer_sync()` stays pending |
| EPP / auth code | ❌ | No transfer codes for .bd |
| WHOIS contacts edit | ❌ | Registrant data is set at registration and read-only at the API |
| DNS zone editor / transfer lock / WHOIS privacy | ❌ | Not offered by the Get BD API (UIs auto-hide) |

## Files

| File | Purpose |
|---|---|
| `GetBD.php` | Module class (`WISECP\Modules\Registrars\GetBD extends RegistrarModule`) + hook registrations |
| `ApiClient.php` | JSON cURL client for the Get BD API (live/sandbox, logging into the WiseCP module log) |
| `config.php` | Module meta + settings + per-TLD `doc-fields` definitions |
| `lang/en.php` | Language strings |
| `logo.png`, `index.html` | Module logo, directory guard |

## Caveats

- Registration requires the registrant contact (whois) collected at checkout
  (`whois-types => true`) — name, e-mail, address and a Bangladeshi phone number
  (normalized to `+880XXXXXXXXXX`, falls back to the client profile).
- The exact response shape of `GET /domains` (list endpoint) is parsed defensively
  (`data[]`, `data.items[]`, `data.domains[]`); verify the import tool against your API
  account once.
