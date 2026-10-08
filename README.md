# GetBD — .bd Registrar Module for WiseCP v5

Registers .bd domains through the **get.bd registry API** with a *verify-to-active* flow:
clients check out with **no document requirement**, the domain stays **pending**, and it
activates automatically only after the client submits verification documents and BTCL
approves them.

## Install

1. Copy this `GetBD/` folder into `coremio/modules/registrars/GetBD/` on the WiseCP v5 install.
   (The `addon-bridge/` subfolder ships with it and is auto-installed into
   `coremio/modules/Addons/GetBDVerify/` on the first client-area page view — keep it.)
2. Admin → Modules → Registrars → **GetBD** → enter the **API Key** → Save → Test Connection.
3. Assign the module to your .bd TLDs (Setup → Domain Pricing → TLD → Registrar = GetBD).
   Leave **transfer** disabled — .bd transfers are not supported.

## Flow

```
checkout ──► service status: inprocess (shows as "pending")
                    │   register() makes NO registry call
                    ▼
   client clicks "Verify to Active"  (injected on domains list + detail page)
                    │   NID, contact info, nameservers, ≥2 documents
                    ▼
   POST /orders  ─► domain reserved at get.bd (7-day window)
   POST /documents/upload ─► documents forwarded (failures are non-fatal)
   POST /orders/{id}/process ─► first attempt; the expected
                                "must have ≥2 APPROVED documents" error is swallowed
                    ▼
   BTCL reviews documents (manual, days)
                    ▼
   module cron polls GET /domains/info ──► localDomain.isActive = true
                    │   service → active, real expiry written to duedate
                    ▼
   native WiseCP DomainStatusSync keeps expiry in sync from here on
   (renew / nameservers work normally; transfers + EPP are not exposed)
```

## Where things live

| Piece | Mechanism |
|---|---|
| Verify endpoint (primary) | **Addons bridge** — the module auto-provisions `coremio/modules/Addons/GetBDVerify/` on every client-area render and settings save. The modal posts `operation=use_addon_method&method=verify_submit` to the addon URL; the addon controller loads the module itself, so this works on every install (no routing-hook timing involved). Ships enabled; appears in the admin Addons list as "GetBD Verify" — do not disable it. |
| Verify endpoint (fallback) | `register:routes` hook → `POST /getbd-verify` — only used on installs where the hook route actually registers; the JS tries the addon first and falls back. |
| "Verify to Active" button + modal | `ui:client.domains_list.modals.end` + `ui:client.domain_detail.modals.end` hooks (works on Basic & WStyle) |
| Activation detection | `PerMinuteCronJob` **and** `action:cron.minute.run` hooks (self-throttled, default every 10 min/service) |
| Order/process retry | every N hours (default 6) while docs await BTCL approval |
| Module logs | Admin module log via `save_log` (all API traffic + a `verify.request` line per submission attempt) |

Endpoint transport notes (learned the hard way):

- **Never assume a URL shape.** WiseCP client URLs change with the `rich-url` setting
  (`/route`, `/index.php?route=`, `/index.php/route`). The modal receives an ordered
  ENDPOINTS list computed server-side and tries each until it gets module JSON.
- **Hook-registered routes are not reliable**: on some installs module files load after
  route collection, so `register:routes` fires too early (result: WiseCP's 404 page).
  That is why the Addons bridge is the primary transport — `controllers/website/addon.php`
  loads the addon on request (`Modules::Load("Addons", ...)` in `addon_ctx()`), needing no
  routing-time registration.
- Standalone PHP files under `coremio/` are NOT viable: both shipped server configs
  deny executing PHP there (`.htaccess` + `nginx.conf.example`).
- The `services/{id}/module-method` client-API bridge is unusable for domains
  (`managedServiceOf()` whitelists hosting/server/special/software and requires status=active).

## Service options (users_products.options JSON)

`getbd_state` = `awaiting_verification | submitting | submitted | active`,
`getbd_order_id`, `getbd_docs` (submitted form + stored files), `getbd_error`
(registrar-side issues: wallet, reservation expired, upload failures — client never
sees these), `getbd_last_cron`, `getbd_last_process_retry`.

## Documents & get.bd contract notes

- The modal collects documents **per the official per-extension requirements table**
  (get.bd enum: NID | TRADE_LICENSE | PASSPORT | OTHER — one document per type per order):

  | Extension | NID | Second document |
  |---|---|---|
  | .bd / .id.bd | required | Passport (optional alternative) |
  | .com.bd / .co.bd | required | Trade licence (TRADE_LICENSE, required) |
  | .net/.info/.ai/.tv.bd / .বাংলা | required | Trade licence (optional) |
  | .org.bd | required | Registration certificate (OTHER, required) |
  | .edu.bd | required | EIIN or UGC approval (OTHER, required) |
  | .sch.bd | required | EIIN certificate (OTHER, required) |

  Accepted files: images except SVG, PDF, DOC/DOCX, max 5 MB (validated module-side;
  get.bd also inspects real file content).
- Each file uploads to `POST /documents/upload` with `orderId`, `documentType`, `file`.
  A `409` (that type already PENDING/APPROVED) is logged as a non-fatal upload failure.
- The create-order `Idempotency-Key` is `wisecp-{serviceId}-{md5(payload)}`: identical
  resubmissions replay the same order; a corrected submission (different payload) gets
  a new key instead of an idempotency conflict.
- Field-validation failures from get.bd (its `errorMessages[]`) are surfaced to the
  client and logged, so e.g. a bad postcode is fixable without support.

## Admin notes

- If `getbd_error` mentions the **wallet** or **RESERVATION_EXPIRED**, fix it in the
  get.bd partner portal (`partner.get.bd/orders/{getbd_order_id}`); the order id is in
  the service options. Reservation expiry = the 7-day window passed without BTCL approval.
- Documents are also stored on the server under
  `resources/uploads/documents/getbd/{serviceId}/` as a fallback if the automatic
  `/documents/upload` forwarding fails (its exact multipart contract should be confirmed
  against the official get.bd OpenAPI spec; the partner portal is the reliable fallback).
- The order is created **at verification time**, so the domain is not reserved at the
  registry between checkout and verification. That is inherent to this flow.

## Development

- `php tests/getbd-wisecp5-test.php` — 53 assertions against stubbed WiseCP core
  (uses the real `RegistrarModule`/`ModuleBaseTrait` from the local WiseCP source tree;
  set `WISECP_SOURCE=/path/to/wisecp` if it is not at the default location).
- `php tests/getbd-wisecp5-test.php --live` — also probes the production API with a
  bogus key (expects a clean auth error).
- API base URLs are overridable in module settings for a self-hosted dev API
  (`http://localhost:4000/api/v1/external`); there is no hosted get.bd sandbox.

## Known limits (by design)

- No transfers, no EPP auth codes, no registrant-contact editing, no DNS records,
  no registrar lock / whois privacy (the get.bd external API does not expose them).
- Nameservers are capped at 3 (registry limit).
- Client-visible submit errors are limited to input validation + "could not reserve";
  registrar-side problems surface only in `getbd_error` + module log.
