# Pharmacy and agrovet module spec

Source: concept note section 3 (pharmacy / agrovet row: batch and expiry
tracking, prescriptions, controlled items); platform core spec (TEN-01,
TEN-06, RBAC-01 to RBAC-09, RBAC-12, WF-01, WF-02, APR-07, NUM-01, NOT-02,
AUD-01, AUD-02, EXP-01, NFR-04, NFR-08, ONB-02); ADR 002 (RLS), ADR 004
(sync), ADR 008 (lists); the Inventory (`inventory.md`) and POS (`pos.md`)
module specs. When this file and the core spec disagree, the core spec wins.

## Scope

Pharmacy is a module (`api/modules/Pharmacy`, permissions `pharmacy.*`,
catalogue key `pharmacy`), sold and switched on per tenant (RBAC-08). It
works with only the core installed (architecture rule 2): it builds on core
items, units, locations and parties, and keeps every pharmacy fact in its
own tables. It never reads another module's tables and changes no other
module's schema. It talks to POS through POS events and the sale
`extensions` seam (LOY-27), and to Inventory through Inventory's existing
`GoodsReceived` contract plus one new contract, `StockWrittenOff` (see
Integrations). With Inventory or POS off, Pharmacy still keeps lots,
prescriptions and the controlled register; it just receives no sales.

- **Batch stock is Pharmacy's own.** A lot is a batch of an item with a
  batch number, manufacture date and expiry; stock is held per lot per
  location, in the item's base unit (`numeric(20,6)`). Inventory keeps its
  own totals independently; the two are reconciled by the events below,
  never by sharing tables.
- **Tracked items.** A core item with a Pharmacy profile that has
  `batch_tracked` on is sold, received and written off by lot. Items
  without a profile behave as before (a plain retail item).
- **Regulator rules are not invented.** Every number that a regulator
  (in Kenya the Pharmacy and Poisons Board, "PPB"; in DR Congo the national
  pharmacy authority) would set is a tenant setting with a neutral default
  and is marked **to confirm** below. No schedule list, licence format,
  retention period or validity period is hard-coded.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| PHA-01 | **Module and standalone use.** Routes, menus, permissions, listeners, sync entities and the nightly job load only for tenants that subscribed (RBAC-08). Everything works with only the core; POS and Inventory integrations switch on when those modules are also active. | Must |
| PHA-02 | **Settings per company** (`pharmacy_settings`): `near_expiry_days` (the warning window, default 90 **to confirm**), `min_sale_days` (a lot with fewer days to expiry than this is refused at sale, default 0: only expired stock is refused), `prescription_classes` and `pharmacist_classes` (which item classes need a prescription, which need a pharmacist, defaults below), `prescription_valid_days` (null: not checked, **to confirm**), `quarantine_expired` (move expired stock to quarantine nightly, default on). Edits are audited (AUD-01). | Must |
| PHA-03 | **Item profile** (`pharmacy_item_profiles`): the item's `class` (`otc`, `prescription_only`, `controlled`, `poison`, `agrochemical`), `product_kind` (`human`, `veterinary`, `agrochemical`, `other`), `batch_tracked`, `restricted_sale`, free-text `usage_notes` and `schedule_note` (the regulator's schedule or category in words, **to confirm**: never validated), and for veterinary and agrochemical items an optional `withdrawal_days`. Defaults: prescription for `prescription_only` and `controlled`; pharmacist for `prescription_only`, `controlled`, `poison` and any `restricted_sale` item. Audited. | Must |
| PHA-04 | **Lots.** A lot has a batch number (unique per item in the company, ignoring case), manufacture date (optional) and expiry date (required), an optional supplier, and a status: `active` or `quarantined`; a lot under an open recall is blocked as well. A lot is never deleted. | Must |
| PHA-05 | **Stock per lot per location**, in `pharmacy_lot_balances`, changed only by the append-only `pharmacy_lot_movements` ledger in the same transaction (kinds `receipt`, `sale`, `sale_void`, `sale_return`, `transfer_out`, `transfer_in`, `write_off`, `adjustment`). The database refuses updates and deletes of movements. Sales never fail for lack of stock: a movement that takes a lot below zero is flagged `negative_stock` (as INV-13). | Must |
| PHA-06 | **Batch receiving.** The back office receives lots at a location with batch number, expiry, manufacture date, quantity (in any unit of the item), unit cost and currency (`POST pharmacy/receipts`). It creates the lots and balances, and raises Inventory's `GoodsReceived` with the batch and expiry so Inventory posts the receipt and cost when active. Receipts that arrive from Procurement's `GoodsReceived` for tracked items create lots the same way (idempotent per receipt and line). Receiving an already-expired lot is refused (`lot_expired`). | Must |
| PHA-07 | **First-expiry-first-out.** `GET pharmacy/fefo?item_id&location_id&qty` suggests the sellable lots in order: earliest expiry first, then oldest receipt; quarantined, recalled and expired lots and lots below `min_sale_days` are never suggested. The till, batch transfers and the write-off form use the same order. A user may pick another lot; picking one that expires later than another sellable lot is allowed and recorded (`not_fefo`). | Must |
| PHA-08 | **Expired stock is refused.** The till will not allow an expired, quarantined or recalled lot on a sale; the API refuses it for transfers and manual moves (`lot_expired`, `lot_quarantined`, `lot_recalled`). When an uploaded sale names such a lot anyway (device clock, stale data), the sale is kept (device wins for completed sales, NFR-04) and flagged for review (PHA-26). | Must |
| PHA-09 | **Near-expiry warning.** Selling a lot that expires within `near_expiry_days` shows a warning at the till and in the API's FEFO answer (`near_expiry` with the days left); it does not block. | Must |
| PHA-10 | **Alerts.** A nightly job (`pharmacy:expiry-alerts`) tells holders of `pharmacy.lot.view` at each location, once per lot and stage, about lots that entered the near-expiry window or expired with stock on hand, through the core notifier (NOT-02, `pharmacy.stock.near_expiry`, `pharmacy.stock.expired`; in-app and email by default). Expired lots with stock are quarantined when `quarantine_expired` is on. The near-expiry report (PHA-24) is the same data, on demand. | Must |
| PHA-11 | **Quarantine.** `quarantine` and `release` change a lot's status with a reason (audited). A quarantined lot is not sellable anywhere; its stock stays on the books until written off. A lot under recall cannot be released until the recall is lifted. | Must |
| PHA-12 | **Write-off with approval.** A write-off (`expired`, `damaged`, `recalled`, `other`; lines of lot, quantity) is a workflow document `pharmacy.write_off` (WF-01): default flow one approval by a Branch Manager at the location, never the requester (APR-07). On approval the lots are reduced (`write_off` movements), the controlled register is written for controlled items, `StockWrittenOff` is raised for Inventory, and everything is audited. Numbered `WO-{BRANCH}-{YYYY}-{00001}` (NUM-01). | Must |
| PHA-13 | **Batch transfers.** `POST pharmacy/transfers` moves lots between two locations of one company in one step (`transfer_out` and `transfer_in`), naming lots or leaving them out to take them in FEFO order. Blocked or expired lots are refused. Controlled items write a register entry at both ends. | Must |
| PHA-14 | **Prescriptions** (`pharmacy_prescriptions`): prescriber name, prescriber licence number and facility; patient name (and optionally phone, birth date and a link to a customer); prescribed-on date; notes; status (`valid`, `cancelled`). Captured at the till (with the sale, offline included) or in the back office. A prescription's `prescribed_on` may not be in the future. When `prescription_valid_days` is set, an older prescription is flagged `prescription_stale`. The licence number format is not validated (**to confirm**). | Must |
| PHA-15 | **Prescription image.** Optional, one per prescription (JPEG, PNG, WebP or PDF, up to 10 MB), stored through `TenantFiles` on the tenant files disk (Object Storage in production) at `tenants/{tenant}/pharmacy/prescriptions/{uuid}.{ext}`, never public. Readers get a short-lived signed link bound to one user; every download is audited. | Must |
| PHA-16 | **Prescription gate.** A sale line of an item whose class is in `prescription_classes` needs a prescription on the sale. The till will not allow payment without one. The server records the dispensing against the prescription; a sale that arrives without one is kept and flagged `prescription_missing` (PHA-26). | Must |
| PHA-17 | **Pharmacist approval.** A line of an item whose class is in `pharmacist_classes` needs someone who holds `pharmacy.dispense.approve` at the location: the signed-in cashier if they hold it, otherwise a pharmacist's PIN override on the till (AUTH-08, offline signed form), redeemed once for the sale. Unproven approval is kept and flagged `pharmacist_unverified`; a sale with none is flagged `pharmacist_missing`. The approver is stored on the dispense record. | Must |
| PHA-18 | **Controlled-drug register.** An append-only ledger (`pharmacy_register_entries`) per controlled item and location: receipts, dispenses, returns, transfers, write-offs and corrections, each with a signed quantity, the running balance after it, lot and batch number, the prescription, prescriber, patient and pharmacist at the time, and the source document. Entry numbers run 1, 2, 3 per item and location. The database refuses updates and deletes (also for the owner role); a correction is a new `adjustment` entry that needs `pharmacy.register.adjust` and a reason. | Must |
| PHA-19 | **Register printing and export.** The register of one item at one location prints as a page of its own (black on white, with the pharmacy's name, item, period, opening and closing balance and signature lines) and exports to CSV, XLSX or PDF (EXP-01). Registers are never filtered by a user's field rules, only by their locations. | Must |
| PHA-20 | **Agrovet.** Pesticide and agrochemical items carry the `agrochemical` class (or `poison`), `usage_notes` shown at the till when the item is added, and `restricted_sale`, which requires a pharmacist or authorised person (PHA-17) and the buyer to be named (a customer or a free-text name on the sale). | Must |
| PHA-21 | **Veterinary drugs** follow exactly the same lot, expiry, FEFO, quarantine, recall and prescription rules (`product_kind = veterinary`); a veterinary item may be `prescription_only` with the prescriber a veterinarian (the same fields). `withdrawal_days` is shown at the till and on the prescription log. | Must |
| PHA-22 | **Recalls.** A recall names an item and a batch number (and a reason and an optional regulator reference). It blocks every lot of that item and batch number in the tenant at once, at every location and every till (the next sync; the server flags any sale of it that still arrives), quarantines their stock, and notifies holders of `pharmacy.recall.view` (`pharmacy.recall.issued`). Lifting it (reason, audited) releases the lots unless they were quarantined for another reason. | Must |
| PHA-23 | **Recall customers.** `GET pharmacy/recalls/{id}/customers` lists who bought the recalled lots: customer, phone, sale, receipt number, quantity, date and place, from the sale ledger (refunded quantities netted). Sales without a named customer are counted as `unattributed`. | Must |
| PHA-24 | **Reports** (lists with sort, filters and export, ADR 008): expiry (lots with stock expiring within N days or expired, with value-free quantities), slow movers per batch (days since the lot last sold and stock on hand), the controlled-drug register, and the prescription log (dispensing against prescriptions, with flags). Scoped by location (RBAC-04). | Must |
| PHA-25 | **Offline till.** Sync entities `pharmacy_settings` (snapshot), `pharmacy_items` (incremental profiles) and `pharmacy_lots` (incremental: each lot with stock at the till's location with batch number, dates, quantity and blocked state). Batch picking, expiry checks, the warning, prescription capture and pharmacist override all work offline for at least 7 days (NFR-04). Staff rows carry `pharmacy.` permissions. | Must |
| PHA-26 | **Server re-check on upload.** For each uploaded sale Pharmacy re-checks every line against the lots as the server holds them: expired, quarantined or recalled lot, `near_expiry`, `not_fefo`, `batch_not_chosen` (the server allocated FEFO itself), `negative_stock`, `prescription_missing`, `prescription_stale`, `pharmacist_missing`, `pharmacist_unverified`. Lines are never refused (money was taken). Flags are stored on the dispense record, listed for review (`pharmacy.dispense.review`) and the reviewers are notified (`pharmacy.dispense.flagged`). | Must |
| PHA-27 | **Voids and refunds.** A voided sale brings its lots back; a refund brings the refunded quantity back into the lots it came from, first-sold first. Controlled items write `return` register entries. Idempotent per sale, void or refund, whatever order the events arrive in. | Must |
| PHA-28 | **Personal data** (NFR-08). Patient name, phone and birth date, prescriber details and the image are personal data: they are registered with the privacy module (access report, erasure rules), appear in no sync entity except the prescription number on the till, are hidden from users without `pharmacy.prescription.view`, and prescriptions are archived, never deleted (TEN-06). The retention period of prescriptions and register entries is **to confirm** and no erasure removes register entries that a regulator requires. | Must |
| PHA-29 | **Roles and presets.** Permissions `pharmacy.resource.action` (below); role templates `pharmacist` and `pharmacy_technician` (RBAC-03); the onboarding preset `pharmacy` enables the module and offers those roles (ONB-02). | Must |
| PHA-30 | **Audit and events.** Every settings change, profile change, receipt, quarantine, release, write-off request and decision, transfer, recall and lift, prescription create, cancel and image download, register correction, dispense approval and review is audited (AUD-01, AUD-02). Events raised after commit: `LotReceived`, `LotBlocked`, `RecallIssued`, `StockWrittenOff` (the Inventory contract), `DispenseFlagged`. | Must |

## Data model

Every table has `tenant_id` under forced RLS (TEN-01) and UUID v7 keys.
Quantities `numeric(20,6)` in the item's base unit; costs `bigint` minor
units with a `currency`.

| Table | Holds |
| --- | --- |
| `pharmacy_settings` | Per company, the PHA-02 settings |
| `pharmacy_item_profiles` | Per item, the PHA-03 profile; stamped for sync |
| `pharmacy_lots` | Company, item, `batch_number`, `manufactured_on`, `expires_on`, supplier, `status`, quarantine reason and time, `recall_id` (open recall); stamped for sync |
| `pharmacy_lot_balances` | Lot, location, item, company, `qty`; unique per lot and location; stamped for sync |
| `pharmacy_lot_movements` | Append-only ledger of lot stock (PHA-05), with source, customer (by id), prescription, flags |
| `pharmacy_receipts`, `pharmacy_receipt_lines` | Back-office receipts (PHA-06): location, supplier, reference, lines with lot, quantity, cost |
| `pharmacy_write_offs`, `pharmacy_write_off_lines` | PHA-12 documents and lines |
| `pharmacy_prescriptions` | PHA-14, PHA-15 |
| `pharmacy_dispenses` | One row per dispensed sale line that needed a prescription, a pharmacist or sits in a controlled class: sale and line ids, item, quantity, prescription, pharmacist, approval mode, flags, review state |
| `pharmacy_register_entries`, `pharmacy_register_heads` | The controlled-drug register (append-only) and its running balance per item and location |
| `pharmacy_recalls` | PHA-22 |
| `pharmacy_expiry_notices` | Which lot, location and stage was already announced (PHA-10) |

Sales are named by id only; POS tables are never referenced.

## Permissions

| Permission | What it allows |
| --- | --- |
| `pharmacy.settings.view`, `.edit` | Read and change a company's settings |
| `pharmacy.item.view`, `.configure` | Read and set item profiles |
| `pharmacy.lot.view`, `.receive`, `.quarantine`, `.transfer` | See lots and stock by lot; receive; quarantine and release; move between locations. `.view` also receives expiry alerts |
| `pharmacy.writeoff.view`, `.create`, `.approve`, `.cancel` | Write-offs; `.approve` acts on the flow (WF-08) |
| `pharmacy.recall.view`, `.issue`, `.lift` | Recalls and their customer list |
| `pharmacy.prescription.view`, `.create`, `.cancel`, `.image` | Prescriptions (personal data); `.image` downloads the image |
| `pharmacy.dispense.approve`, `.review` | Approve a restricted dispense at the till or in the back office; review flagged dispenses |
| `pharmacy.register.view`, `.print`, `.adjust` | The controlled-drug register |
| `pharmacy.report.view` | Expiry, slow-mover and prescription log reports |

Role templates: **Pharmacist** holds all of the above except
`pharmacy.settings.edit` and `pharmacy.recall.lift` is included; **Pharmacy
technician** receives, transfers, views lots, captures prescriptions and
views reports, but cannot approve a dispense, quarantine, issue a recall,
read the register's corrections or adjust it. Branch Manager holds
everything; Admin configures (settings and item profiles); Cashier holds
`pharmacy.prescription.create` (captures a prescription at the till);
Storekeeper, Accountant and Read-only auditor view.

## API (`/api/v1`, user tokens, module `pharmacy`)

`GET|PUT companies/{company}/pharmacy-settings`; `GET pharmacy/items`,
`GET|PUT pharmacy/items/{item}/profile`; `GET pharmacy/lots`,
`GET pharmacy/lots/{lot}`, `POST pharmacy/lots/{lot}/quarantine`,
`POST pharmacy/lots/{lot}/release`; `GET pharmacy/fefo`;
`POST pharmacy/receipts`; `GET|POST pharmacy/write-offs`,
`GET pharmacy/write-offs/{id}`, `POST .../cancel`; `POST pharmacy/transfers`;
`GET|POST pharmacy/recalls`, `GET pharmacy/recalls/{id}`,
`GET .../customers`, `POST .../lift`; `GET|POST pharmacy/prescriptions`,
`GET pharmacy/prescriptions/{id}`, `POST .../cancel`, `POST .../image`,
`GET .../image-link`, signed `GET pharmacy-files/{path}`;
`GET pharmacy/register`, `GET pharmacy/register/print`,
`POST pharmacy/register/adjustments`; `GET pharmacy/dispenses`,
`POST pharmacy/dispenses/{id}/review`; reports `GET pharmacy/reports/expiry`,
`.../slow-movers`, `.../prescriptions`. Tills pull the three sync entities
and upload through the ordinary sale upload (`extensions.pharmacy`).

### Sale extension (till to server)

```
extensions.pharmacy = {
  lines: [{ line_id, lots: [{ lot_id, qty }] }],   // base-unit quantities per lot
  prescription: { id, prescriber_name, prescriber_licence, facility, patient_name,
                  patient_phone?, patient_birth_date?, prescribed_on, notes? },
  approval: { ...override }                          // a pharmacist's signed override, permission pharmacy.dispense.approve, reference = sale id
}
```

## Screens

Web: Lots and expiry (filters, quarantine, release, receive), Receive stock,
Write-offs (with the flow's approval), Recalls (issue, customers, lift),
Prescriptions (log, detail, image), Controlled-drug register (item and
location picker, running balance, print), Reports, Settings (thresholds)
and an item profile section. POS: lot picking on the sale panel with FEFO
default and expiry warning, prescription capture dialog, pharmacist
approval (PIN override), usage notes on agrochemicals.

## Integrations

- **Inventory** (existing, unchanged schema): Pharmacy dispatches
  `Modules\Inventory\Contracts\GoodsReceived` for back-office receipts and
  listens to it for Procurement receipts of tracked items. New, backward
  compatible, no schema change: `Modules\Inventory\Contracts\StockWrittenOff`
  (Pharmacy raises it on an approved write-off) with the listener
  `RecordStockWrittenOff`, which posts `adjustment_out` movements at the
  company's cost; and `Modules\Inventory\Contracts\TransferLines` (a reader
  of a transfer's lines) so Pharmacy can move lots when an Inventory
  transfer is received (`TransferReceived`, first-expiry-first-out).
- **POS** (existing): `SaleCompleted`, `SaleVoided`, `SaleRefunded`, the sale
  `extensions` seam, `SaleStockLines`, the override verifier and the staff
  permission prefix. The till gets generic seams (a cart adjuster that can
  block a line, a user in the adjuster context) and no Pharmacy code in POS.
- **Core**: notifier, audit, workflow engine, numbering, TenantFiles,
  privacy subjects, list and export framework, sync framework.

## To confirm (statutory or business decisions, not invented here)

1. The near-expiry window (90 days default) and any minimum remaining
   shelf-life the regulator requires at dispensing.
2. Which items are controlled, prescription-only or poisons: the
   regulator's schedules. Pharmacy stores the class a pharmacy sets, never a list.
3. Licence number formats of prescribers and pharmacies; the prescription's
   validity period; whether a repeat dispense needs a new prescription.
4. How long prescriptions and register entries must be kept, and what a
   data-subject erasure may remove.
5. The controlled-drug register's required columns and signature rules for
   KE (PPB) and CD; the printed layout follows common practice until then.
6. Whether returned medicine may be restocked (this module restocks, as
   Inventory does; the pharmacist can write it off).
7. The `pharmacy` module's price in the catalogue (a placeholder equal to
   Inventory's).
8. Whether a completed sale with an expired lot should be refused at upload
   (this module keeps and flags it, since the device wins for completed sales).
