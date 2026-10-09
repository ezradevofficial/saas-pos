# Payment and tax authority integrations

What the platform needs from the owner (and from each business) to switch
on M-Pesa through Safaricom Daraja, KRA eTIMS and the DRC DGI. Concept note
sections 7.1 and 7.2; code in `api/app/Core/Payments` and
`api/app/Core/Fiscal`. Every integration sits behind an adapter with a fake
driver; the fake drivers work only in `local` and `testing` (NFR-06: a real
environment refuses to start with `PAYMENTS_ALLOW_FAKE`,
`PAYMENTS_DRIVER_*=fake` or `FISCAL_ALLOW_FAKE`).

Credentials of a business are entered in the back office, stored encrypted
and never returned by the API (payment method `secrets`, fiscal settings
`credentials`). Platform-wide values are environment variables.

## M-Pesa (Safaricom Daraja)

### Platform (owner, once per environment)

| Variable | What | Default |
| --- | --- | --- |
| `MPESA_BASE_URL` | Daraja host | `https://sandbox.safaricom.co.ke` (production: `https://api.safaricom.co.ke`, once Safaricom takes the app live) |
| `PAYMENTS_CALLBACK_URL` | The public HTTPS base Safaricom calls back on (the API host behind the NodeBalancer) | `APP_URL` |
| `MPESA_CALLBACK_IPS` | Safaricom's callback addresses, comma-separated. Confirm the current list with Safaricom before go-live. | the published list in `config/payments.php` |
| `MPESA_ENFORCE_CALLBACK_IPS` | Refuse callbacks from other addresses (keep `true` outside local tunnels) | `true` |
| `MPESA_B2C_COMMAND` | B2C command agreed for refunds (`BusinessPayment`, `SalaryPayment`, `PromotionPayment`) | `BusinessPayment` |
| `MPESA_PATH_*` | Daraja paths, if Safaricom versions them | v1 paths |

**Client address.** The callback allowlist and the callback rate limit use
the real client address. Set `TRUSTED_PROXIES` to the load balancer's
backend range only (Linode NodeBalancer: `192.168.255.0/24`); its
`X-Forwarded-For` then names the client, and a caller's own
`X-Forwarded-For` is ignored. Empty (the default) trusts no proxy. Never
`*`.

**Callback tokens in logs.** The 48-character token in callback URLs names
the payment method; keep it out of access logs. nginx:

```nginx
map $request_uri $redacted_uri {
    ~^(?<head>/api/v1/payments/callbacks/)[A-Za-z0-9]{48}(?<tail>/.*)$ "${head}[token]${tail}";
    default $request_uri;
}
log_format redacted '$remote_addr - [$time_local] "$request_method $redacted_uri" $status $body_bytes_sent';
access_log /var/log/nginx/api.access.log redacted;
```

### Per business (back office: Payment methods → M-Pesa)

1. **Daraja app.** On the Daraja portal (developer.safaricom.co.ke), create
   an app with the products *Lipa na M-Pesa Online* (STK push), *M-Pesa
   Express Query*, *C2B*, and, for refunds and checks of typed codes, *B2C*,
   *Transaction Status* and *Reversal*. Enter its **consumer key** and
   **consumer secret** (secrets).
2. **Shortcode.** The **Paybill** number, or for a **Till** (Buy Goods) the
   store number (head office shortcode) as `shortcode`, `transaction_type =
   till` and the Till as `till_number`.
3. **Passkey.** The Lipa na M-Pesa Online passkey Safaricom issues for the
   shortcode (sandbox: the test passkey on the portal) (secret).
4. **Refunds and checks (optional).** The B2C shortcode refunds are paid
   from (`b2c_shortcode`), the **initiator name** (`initiator_name`) and its
   **security credential** (`security_credential`, secret): the initiator
   password encrypted with Safaricom's certificate, generated on the portal.
   Without them refunds are paid back another way and typed codes are only
   verified by C2B confirmations.
5. **Callback URLs.** `GET /api/v1/payment-methods/{id}/callbacks` (needs
   `core.payment_method.configure`) lists the method's URLs. Each holds a
   48-character token that names the method: treat them as secrets.
   `POST .../callbacks/rotate` replaces the token (the old URLs stop at
   once; register again).
6. **C2B registration.** `POST /api/v1/payment-methods/{id}/c2b/register`
   registers the confirmation and validation URLs for the Paybill or Till.
   Safaricom must turn on *external validation* for the validation URL to
   be called (optional; confirmations work without it). The URLs never
   contain the words Safaricom refuses ("mpesa", "safaricom", "sql",
   "query", ...).
7. **Go-live.** Safaricom's go-live process for the production app
   (business documents, test results), then switch `MPESA_BASE_URL`.

### How it behaves

- STK push from the till (`POST /api/v1/payments/intents`), whole
  shillings, KES only. The till polls `GET /api/v1/payments/intents/{id}`.
  On the till (`pos/src/pos/payments/stkPush.js`): offered when the synced
  method has `capabilities.stk` and the till is online; the intent id is
  the sale payment's id and `user_id` the signed-in cashier; the till polls
  every 3 seconds, treats `pending` and `unknown` as still checking, adds
  the payment (confirmed, the M-Pesa receipt as its reference) once
  `succeeded`, and says so on `failed`, `cancelled` or `timeout`. A typed
  code is registered as a `manual` intent when online (a used code is
  refused at once) and uploaded `pending` with the sale either way. The
  receipt shows the sale's fiscal state from its upload answer and, online,
  `GET /api/v1/pos/sales/{id}/fiscal` (waiting to upload, pending,
  accepted with the invoice number, refused, not transmitted).
- No answer after `PAYMENTS_STK_TIMEOUT` seconds (90): the server asks
  Daraja (STK query); still nothing after `PAYMENTS_STK_GIVE_UP` (300): the
  intent times out. A late C2B confirmation with the push's account
  reference still completes it.
- Offline or failed push: the cashier types the M-Pesa code (`mode:
  manual`). It is verified by the matching C2B confirmation, or after
  `PAYMENTS_MANUAL_VERIFY_AFTER` minutes (30) by a transaction status query;
  a different amount or an unknown code is flagged `mismatch`.
- Money received that matches nothing waits in
  `GET /api/v1/companies/{id}/payment-receipts` for matching by hand
  (`core.payment.match`).

## KRA eTIMS (Kenya), OSCU

### Platform (owner)

| Variable | What | Default |
| --- | --- | --- |
| `ETIMS_BASE_URL` | OSCU API base KRA gives with integrator onboarding | `https://etims-api-sbx.kra.go.ke/etims-api` (sandbox; confirm with KRA) |
| `ETIMS_PATH_*` | Endpoint paths (`selectInitOsdcInfo`, `saveTrnsSalesOsdc`, ...) | as in the OSCU specification |
| `ETIMS_QR_PREFIX` | Receipt verification URL the QR code starts with (followed by PIN, branch id and receipt signature). Confirm with KRA; empty stores no QR content. | sandbox receipt link |
| `ETIMS_RETRYABLE_CODES` | KRA result codes that mean "try later" (others are rejections a person handles) | none |
| `ETIMS_DUPLICATE_CODES` | KRA result code(s) meaning "invoice number already stored". When the stored request is the one sent before (same body hash, answer lost), the submission is accepted; otherwise it is held as needs attention. **Confirm the code with KRA**; none is assumed. | none |
| `ETIMS_REFUND_REASON_CODE` | `rfdRsnCd` used for refunds and voids | `06` |
| `FISCAL_KE_ALERT_AFTER_HOURS` | Alert fiscal administrators when a document is still not accepted after this many hours | `6` |
| `FISCAL_KE_DEADLINE_HOURS` | KRA's transmission deadline for documents made offline | not set: **confirm with KRA** |

The platform itself must be approved by KRA as an eTIMS integrator (OSCU
system-to-system integration: application, test cases on the sandbox,
certification) before production credentials are issued.

### Per business (back office: company fiscal settings)

1. **KRA PIN** (`tin`) and the **branch id** KRA registered (`branch_code`,
   `00` for the head office).
2. **Device serial** (`device_serial`): the OSCU device serial KRA issues
   when the business registers for eTIMS OSCU with this integrator on the
   eTIMS portal.
3. **Initialise** (`POST /api/v1/companies/{id}/fiscal-settings/initialize`,
   `core.fiscal.configure`):
   calls `selectInitOsdcInfo` once; KRA returns the communication key
   (`cmcKey`, stored encrypted, never returned), the control unit id and the
   MRC number. Changing the PIN, branch id or serial drops the key: initialise
   again.
4. **Tax bands.** Each tax code's `fiscal_code` is its eTIMS band (A to E)
   from KRA's code list. The KE country pack has none yet (pack `todo`);
   a sale line whose tax code has no band is rejected locally with the item
   named, never guessed. Rates are the ones the till applied; bands with
   no lines are sent at 0.
5. **Item codes.** eTIMS lines need the item classification (`itemClsCd`)
   and unit codes; until items carry them, set company defaults
   (`default_item_class_code`, `default_packaging_unit_code`,
   `default_quantity_unit_code`). Item registration with KRA (`saveItem`)
   is not built yet (see open questions).
6. Switch transmission on (`enabled`).

### How it behaves

Every sale is an invoice (`rcptTyCd S`); every refund and void a credit note
(`R`) naming the sale's fiscal invoice number, sent only once the sale is
accepted. The queue retries with backoff (1, 5, 15, 30, then every 60
minutes) for ever, alerts after the alert delay, and alerts at once on a
rejection. Accepted documents keep the receipt number, internal data,
receipt signature, control unit id and QR content for the receipt. Only KES
documents are sent; others are held as `needs_attention`. A line without its tax
code or rate (`tax_code_missing`, `tax_rate_missing`) is also held as
`needs_attention` (data to fix, not a refusal); the POS stores the server's
code and rate on lines the till sent without them. Retrying a rejected or
held document rebuilds it from the sale, so fixed data is what is sent.

**Invoice numbers (`invcNo`).** Each company's fiscal invoice numbers are
handed out in queue order when a document is queued. A document that is
retried keeps its number, so after an outage a lower number can reach KRA
after a higher one. **To verify on the KRA sandbox:** whether OSCU
requires `invcNo` to arrive strictly in order. If it does, numbers must be
allocated at send time, in order, with a blocked document holding back
the ones after it.

## DRC DGI normalised invoicing (e-MCF)

Not built: the `dgi_emcf` driver reports itself unavailable, so transmission
cannot be switched on for a Congolese company (the fake driver covers tests
and local work). Needed before it can be built:

- The DGI-approved e-MCF API: base URL, authentication, request and response
  formats, error codes, and how offline documents are handled.
- Per company: NIF (`tin`), the e-MCF unit's serial or ISF number
  (`device_serial`), access credentials (`api_token`).
- The DGI tax groups for each tax code (`fiscal_code` in the CD pack, still
  empty) and the transmission deadline for documents made offline
  (`FISCAL_CD_DEADLINE_HOURS`).
- Whether USD documents are accepted, and the rate to declare.

## Decisions and owner input

Decided (phase 4 Task 3 review):

- **Item registration with KRA** (`saveItem`, KRA item codes): not built
  for the pilot. Items are sent with their own code and the company's
  default classification and unit codes. Follow-up: register items with
  KRA and keep their KRA codes on the item.
- **Non-KES sales in Kenya** are queued but held as `needs_attention`
  ("eTIMS currency handling for USD sales needs confirming"), alerted
  once, never sent until decided; then retried from the fiscal queue.
  Owner question: convert to KES at the sale's rate, or another rule?
- **Empty eTIMS bands** are sent with rate 0 and no amount. Verify the
  inclusive amounts, the empty bands and `pkg` against the KRA sandbox.
- **Deadlines** are per country (`FISCAL_KE_DEADLINE_HOURS`,
  `FISCAL_CD_DEADLINE_HOURS`), unset by default: owner input.
- **Earlier sales** are never sent automatically when transmission is
  switched on. The back office action "Send earlier sales"
  (`POST /api/v1/companies/{id}/fiscal-submissions/send-earlier` with
  `{from, confirm: true}`, `core.fiscal.edit`) queues them from a date;
  documents already queued are left as they are.
- **Callback IPs**: Safaricom's current list is owner input
  (`MPESA_CALLBACK_IPS`).
- **Permissions**: `core.fiscal.configure` (Owner, Admin) is needed for
  the authority's credentials, initialisation, the driver, the identity
  KRA registered (PIN, branch id, device serial) and switching
  transmission on or off; the Accountant holds `core.fiscal.edit`
  (non-secret settings, retries, sending earlier sales).
- **A reused M-Pesa code** flags the uploaded sale `mpesa_code_reused`.
- **Lost STK answers**: a push the provider did not answer is `unknown`,
  still checked, and completed by a late paid result or a C2B
  confirmation; paid results no open intent takes are kept as unmatched
  receipts flagged `late_or_unmatched` for matching or refund.
- **Lost B2C answers**: a refund payout whose request timed out (or got a
  5xx without Daraja's error body) is `unknown`, not failed: the money may
  have gone out. Its id is stored as the request id before the call, so
  the result callback finds it; it times out after
  `payout_give_up_hours`.
- **Till limits**: 10 payment requests a minute per device, 3 pushes per
  phone and method in 5 minutes; the cashier (`user_id`) must be staff of
  the till's location. Refund payouts never exceed the original payment
  and use its currency.
- **Settlements** (core `PaymentIntentSettled`, after commit, ids only)
  update the POS: a paid push or verified code confirms the sale payment,
  a mismatch flags the sale `mpesa_mismatch`; a paid refund confirms the
  refund payment, a failed or timed-out payout flags the refund
  `payout_failed`, and a payout paid after that also flags it
  `payout_recovered` (the money may have gone out twice).
- **Sync**: payment method rows carry `capabilities: {stk, manual_code}`;
  `stk` only for an M-Pesa method on the Daraja adapter with every
  required key (sync entity version 2).
