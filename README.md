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
Checkout ──► service goes ACTIVE (required: WiseCP only unlocks the client-area
             document upload for active services) — NO order is created yet,
             options.config.awaiting_docs = 1
             │  client sees the verification banner: "documents required"
             ▼
Client submits NID + documents in the domain manager
(WiseCP native doc-fields — action:domain.verification_submitted)
             │  hook re-queues register() (cron = hourly safety net)
             ▼
register(): POST /orders (with NID) → POST /orders/{id}/process
   (documents-pending failure expected) → returns status "inprocess"
             ▼
Service shows PENDING while BTCL reviews the documents
   — cron polls GET /domains/info every 15 min per service:
      • docs verified in WiseCP → retry POST /orders/{id}/process
      • staff approves documents in the Get BD partner portal
        (partner.get.bd/orders/{id}; order id in options.config.id)
             ▼
BTCL activates the domain (localDomain.isActive = true)
   → cron flips the service to ACTIVE via Services::change_status
     (client receives the activation notification), writes the real
     registry expiry date into duedate, and stops polling
   → WiseCP's daily DomainStatusSync cron remains the long-term backstop
```

Status truthfulness: "Active" only ever means the domain is live at the
registry — except the initial doc-collection phase, where the service is
active but the client-area verification banner ("documents required")
explains that the domain is waiting on the client's own documents. If the
client stays silent for 2+ days the module notifies the admin once.

If the client submits an invalid NID (not 10/13/17 digits), registration
fails with a clear admin-visible error so it can be corrected.

### Notes on the document lifecycle

- The **NID**, **Registration Type** and **Verification Documents** file are collected
  through WiseCP's built-in doc-fields system (`doc-fields` in `config.php`) and stored in
  `users_products_docs`. The module reads them via `$this->docs`.
- The module does **not** upload documents to the Get BD API. The Get BD API has a
  `/documents/upload` endpoint, but this integration (matching the WHMCS module) leaves
  document upload/approval to staff in the partner portal. The WiseCP doc record is the
  local audit trail and gates the processing retries.
- Why the service must be active at first: WiseCP's client-area verification submission
  is hard-gated to active services (core limitation). Hence the two-phase status:
  active while collecting documents → pending during BTCL review → active when live.
- If the client never submits documents, the module notifies the admin once after 2 days.
- **Pre-existing / imported domains** (registered outside this flow, no order id and no
  awaiting-docs flag): the cron probes the registry once, marks their doc-fields verified
  (with the real NID from `GET /domains/info` when available) and stops polling — the
  client area never shows a "Verify" prompt for them.
- **Per-TLD document matrix** (in `config.php`): `.bd` and `.id.bd` require NID **or**
  passport; `.com.bd` / `.co.bd` require trade licence **+** NID; `.org.bd` requires a
  registration certificate; `.edu.bd` EIIN/UGC approval; `.sch.bd` EIIN certificate;
  `.net.bd` / `.info.bd` / `.ai.bd` / `.tv.bd` accept NID **or** trade licence (the
  licence upload is optional there). The applicant NID is collected for every TLD
  because the get.bd order API requires an `nid` value.
- The client-area 4th nameserver input is hidden on GetBD domain pages via the
  `ui:client.domain_detail.nameservers.bottom` template hook; the module also drops any
  4th nameserver server-side. No EPP/transfer UI exists — the module has no
  `get_auth_code()` method, which is how WiseCP detects the capability.

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
