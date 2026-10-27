# Fuel station module spec

Source: concept note section 3 (fuel station / forecourt row: pump and shift
readings, attendant collections, shop sales); platform core spec (TEN-01,
TEN-06, RBAC-01 to RBAC-09, RBAC-12, WF-01, WF-10, APR-07, NUM-01, NUM-02,
AUD-01, AUD-02, CP-01, CP-02, EXP-01, MD-03, NFR-04, ONB-02); ADR 002 (RLS),
ADR 003 (money), ADR 004 (offline sync); the POS (`pos.md`), Inventory
(`inventory.md`), Pharmacy (`pharmacy.md`, the write-off flow) and Hotel
(`hotel.md`) module specs. When this file and the core spec disagree, the core
spec wins.

## Scope

Fuel station is a module (`api/modules/FuelStation`, permissions `fuel.*`,
catalogue key `fuel`, tables `fuel_*`), sold and switched on per tenant
(RBAC-08). It works with only the core and POS installed and adds the
forecourt layer of the POS `fuel_station` mode: tanks, pumps and nozzles,
regulated prices, attendant shifts with meter readings, collections and
variance, tank dips, deliveries and loss or gain. Inventory is optional
(FUEL-30); with it, fuel is a stock item at the station location and its
book stock follows the till and the deliveries.

- **A station is a location.** `fuel_stations` marks a location as a
  forecourt (company, branch, location, currency). Tanks, pumps, nozzles,
  prices, shifts and dips belong to a station. Shop (non-fuel) items are sold
  through the normal POS and are not touched here.
- **A fuel sale is a normal POS sale.** The till rings fuel as a sale whose
  lines are fuel items (quantity = litres, unit price = price per litre) and
  carries `extensions.fuel`, so payments, M-Pesa, fiscal (KRA eTIMS, DRC DGI),
  Loyalty credit, Accounting and Inventory stock use the existing path. Fuel
  station never reads or writes POS tables; it learns of a sale from
  `SaleCompleted`, `SaleVoided` and `SaleRefunded`.
- **Money** is `bigint` minor units with a currency (ADR 003); a price is the
  minor units **per litre**. **Litres** are `numeric(14,3)` (meters
  `numeric(16,3)`), exact decimals handled with brick/math, never floats.
  Ids are UUID v7; the device makes the id of a shift, a dip and a reset.
- **Nothing statutory is invented** (CP-01, CP-02). VAT on fuel is the tax
  category of the fuel item (a country-pack tax code); excise, levies and
  the regulator's maximum prices are tenant data or **to confirm** below.
- **Price to set:** the catalogue row is a placeholder equal to Inventory's
  (KES 1,500.00 and USD 12.00 per outlet per month) until the owner sets it.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| FUEL-01 | **Module gate.** Routes, menus, permissions, sync sources, tender, listeners and jobs exist only while the tenant has the module (RBAC-08). It works with only the core and POS; Inventory and Accounting switch their parts on when active. | Must |
| FUEL-02 | **Stations** (`fuel_stations`): a location of a branch marked as a forecourt, with the currency prices and shifts use. A paired till at a location that is not a station gets no fuel data and its fuel uploads are refused `not_a_station`. Archived, never deleted (TEN-06). | Must |
| FUEL-03 | **Settings per company** (`fuel_settings`): cash variance tolerance (minor units), litre tolerance of a shift nozzle (meter against recorded sales), tank loss and gain tolerance as a percent of throughput and in litres, delivery shortage tolerance as a percent. All default to 0 (every difference needs approval) and are never built in as constants. Edits are audited. | Must |
| FUEL-04 | **Fuel products** (`fuel_products`): a core stock item marked as fuel with a type (`petrol`, `diesel`, `kerosene`, `lpg`, `other`) and an optional grade (Super, V-Power). The item's base unit must be a volume unit. Fuel is sold by the litre with up to 3 decimals. Archived, never deleted. | Must |
| FUEL-05 | **Tanks** (`fuel_tanks`): code, name, product, capacity in litres, optional litre tolerance, at a station. A tank holds one product. Archived, never deleted; a tank with a nozzle on an open shift cannot be archived. | Must |
| FUEL-06 | **Calibration (strapping) charts** (`fuel_strapping_charts`, `fuel_strapping_points`): per tank, an effective-dated, immutable table of `(dip in cm, litres)` points, strictly rising in both columns, at least 2 points, litres never above capacity. A dip in cm converts to litres by linear interpolation between the two nearest points, rounded half up to 3 decimals; a dip outside the chart is refused `dip_out_of_range`; the chart in force at the dip time is the one used and is stored with the dip. A new chart is a new row (the old one stays); `GET fuel/tanks/{id}/dip-convert` previews a conversion. Temperature compensation is not built (to confirm). | Must |
| FUEL-07 | **Pumps and nozzles** (`fuel_pumps`, `fuel_nozzles`): a pump has a code and name; a nozzle (code unique per pump) is linked to one tank of the same station, so its product is its tank's. A nozzle keeps its last accepted meter reading (FUEL-16). Archived, never deleted; a nozzle on an open shift cannot be archived or re-linked. | Must |
| FUEL-08 | **Prices** (`fuel_prices`): per station and product, an amount per litre in minor units, a currency, an effective-from time and an optional reference (for example the regulator's notice). Rows are immutable and effective-dated: the price of a sale is the latest row with `effective_from <= sold_at`. A new price cannot start in the past (more than 5 minutes ago) unless the station and product have none; a scheduled price may be archived before it starts. Every create and archive is audited with who entered it (AUD-01). Prices are entered by the tenant; none is built in (in Kenya the regulator's maximum retail prices are typed from its notice, **to confirm** the source and cadence). | Must |
| FUEL-09 | **Shifts** (`fuel_shifts`, `fuel_shift_nozzles`). A shift is opened at a station by the till with the opener, the time, and per nozzle in use the **opening meter reading** and the **attendant** assigned to it. A nozzle is on at most one open shift at a time. Each attendant needs `fuel.sale.create` at the station (else the shift is stored and flagged `attendant_not_permitted`, the device wins, NFR-04). Opening and closing carry the till's sign-in attestation (`actor_proof`, AUTH-07), checked by POS Authority; an unproved person is flagged `actor_unverified`. | Must |
| FUEL-10 | **Close.** A shift closes only with a **closing reading for every nozzle** and the **tenders declared by every attendant** (cash at least, zero allowed); otherwise the close is refused `close_incomplete`, naming what is missing, and the shift stays open. Closing is idempotent by shift id and payload hash and runs under a row lock on the shift and its nozzles (concurrent closes cannot double-count). | Must |
| FUEL-11 | **Meter maths** (server-side, exact). Per shift nozzle: `litres sold = closing − opening − pump tests + approved reset adjustments` (FUEL-17); pump tests are litres poured back to the tank and entered at close. `recorded litres` = litres of the live fuel sales on that nozzle and shift; `unrecorded litres` = `max(0, litres sold − recorded)`; `expected value` = recorded sales value + unrecorded litres × the price per litre in force at close (the station's price for the nozzle's product). A litre difference above the litre tolerance is flagged `litres_differ` (and `sold_over_meter` when recorded exceeds the meter). | Must |
| FUEL-12 | **Collections by tender.** Each attendant declares what they hand in per tender (`cash`, `mobile_money`, `card`, `credit`, `voucher`). The server does not trust declarations for non-cash money: **cash collected** = the declared counted cash; **other tenders collected** = what the POS sales of that attendant and shift actually recorded for the tender (fuel share of each payment, net of change and refunds). A declared non-cash amount that differs from the recorded one is kept and flagged `tender_declared_differs`. `credit` covers credit and fleet accounts, `voucher` fuel vouchers (FUEL-27). | Must |
| FUEL-13 | **Variance per attendant** (`fuel_settlements`, one per shift and attendant): `variance = collected − expected` (negative = short) with the litre difference. Within the tolerances (FUEL-03) it is `auto_accepted`; otherwise it is `pending_approval` and a `fuel.shift_variance` flow starts (FUEL-14). Settlement numbers `FS-{BRANCH}-{YYYY}-{00001}` (NUM-01). | Must |
| FUEL-14 | **Variance approval through the workflow engine.** The `fuel.shift_variance` document type ships the default flow of one approval by a Station manager at the station (APR-07: never the requester), escalating to the Admin after two business days; a tenant may copy and change it. **Nothing the device sends is trusted for approvals**: the till can only submit; approving is a person's action in the back office. The attendant, the person who closed the shift and the person who opened it can never approve it (`mayAct`), whatever roles they hold. An approval settles it `approved`, a rejection `rejected` (the variance stands, for HR or the owner); both are audited with the approver. | Must |
| FUEL-15 | **Late uploads reopen.** A fuel sale, void or refund that reaches the server after its shift closed changes the recorded sales; the settlement is recomputed and, if its figures changed, an approved, auto-accepted or rejected settlement is **reopened** (`reopened`, version up, flagged `reopened`), a new flow starts when it is now outside tolerance, and the change is audited. A settlement still pending keeps its flow, whose document values are read live. | Must |
| FUEL-16 | **Monotonic meters.** A nozzle's readings never go down. An **opening** reading below the nozzle's last accepted reading is refused `meter_decreased` unless an approved reset (FUEL-17) set the baseline; above it is stored and flagged `meter_gap` with the litres of the gap (unrecorded dispensing between shifts). A **closing** reading below the opening is refused `meter_decreased` unless an approved reset covers the shift nozzle. Accepted closing readings become the nozzle's last reading under a row lock. | Must |
| FUEL-17 | **Meter resets** (`fuel_meter_resets`): when a meter is replaced or rolls over, a supervisor requests a reset with the reading before, the reading after and a reason (till or back office). A different person with `fuel.meter.approve_reset` approves it (never the requester; audited). Approval sets the nozzle's baseline to the reading after; if it names an open shift it adds `reading_before − reading_after` to that shift nozzle's litres (FUEL-11). A rejected reset changes nothing. | Must |
| FUEL-18 | **Every sale joins a shift.** A fuel sale (a POS sale with a line of a fuel product at a station) is linked to the shift named in `extensions.fuel.shift_id` and to the shift nozzle named per line. A sale that names no shift, an unknown shift, a shift of another station, a nozzle not on the shift or sold outside the shift's open period is stored as a `fuel_sales` row **flagged** (`no_shift`, `shift_unknown`, `nozzle_unknown`, `outside_shift`) and listed for review (`GET fuel/sales?flagged=1`), never refused (device wins). A sale that arrives before its shift waits flagged `shift_unknown` and is linked when the shift arrives. | Must |
| FUEL-19 | **Quick sale by nozzle.** The till picks a nozzle, enters litres or an amount, and rings the sale at the nozzle's current price. By amount, litres = amount ÷ price rounded **down** to 3 decimals; the line total is price × litres rounded half up to the minor unit (POS Amounts rule), so the charged amount can be a unit below the typed amount. The till prices from the synced price (FUEL-08); the server compares each line with the price in force and flags `price_differs` (server wins prices; the sale is kept). | Must |
| FUEL-20 | **Fleet and credit sales.** A sale to a customer (a core customer party) paid on `credit` carries `extensions.fuel.vehicle` (`registration`, optional `odometer`). Vehicles (`fuel_vehicles`) belong to a customer with a registration (unique per company), an optional allowed fuel type and an optional daily litre limit; a blocked vehicle or an unknown registration on a credit sale is flagged `vehicle_blocked`, `vehicle_unknown`; a credit sale without a vehicle `vehicle_required`; litres over the daily limit `vehicle_limit`. Credit limits and statements of account belong to Loyalty's credit tender; the fleet statement here lists litres and value per customer and vehicle (FUEL-38). | Must |
| FUEL-21 | **Fuel vouchers** (`fuel_vouchers`, `fuel_voucher_redemptions`): prepaid value in the voucher's currency, a code (unique per company), optional fuel type, customer and expiry. Core payment method type `fuel_voucher` is provided through the tender seam: when a sale is uploaded the server flags a payment with an unknown, void, expired, wrong-currency or overdrawn voucher (`tender_flagged`), and on `SaleCompleted` records the redemption idempotently per sale and payment, reducing the balance under a row lock; a void or refund of the sale gives the value back. A voucher cannot be redeemed above its balance (an offline overdraw is flagged `voucher_overdrawn`, the money has changed hands). Vouchers are issued and voided by people with `fuel.voucher.manage`; void keeps the redemptions. | Must |
| FUEL-22 | **Offline (NFR-04).** The till keeps the station's nozzles with last readings, prices (current and scheduled), tanks with their strapping tables, vehicles and active vouchers, and sells, opens and closes shifts and takes dips for 7 days without a connection. Receipts use number ranges allocated to the device (NUM-02). Shifts, dips and meter reset requests upload idempotently by UUID in order (`POST fuel/device/shifts`, `.../meter-resets`); sales use `pos/sales`. The server wins for prices and master data, the device for completed sales. | Must |
| FUEL-23 | **Tank dips** (`fuel_dips`): a dip in cm (and optional water in cm) of a tank at a time, converted to litres by the chart in force (FUEL-06), by the server. Kinds `routine`, `shift_open`, `shift_close` (from a shift upload or the back office), and `delivery_before`, `delivery_after` (made by FUEL-25). Dips are append-only. | Must |
| FUEL-24 | **Tank reconciliation** (`fuel_reconciliations`). A `routine`, `shift_open` or `shift_close` dip reconciles the tank: `expected = baseline + deliveries received since the baseline − fuel sold since the baseline` (live fuel sales on the tank's nozzles and unconsumed deliveries, so a late sale is still counted once), `variance = dip − expected` (negative = loss, positive = gain). The tank's first dip only sets the baseline (`baseline`). A dip that does not follow the previous one in time is refused `dip_out_of_order`. Reconciliation numbers `FR-{BRANCH}-{YYYY}-{00001}`. | Must |
| FUEL-25 | **Deliveries** (`fuel_deliveries`): litres received against the delivery note, into one tank, with a dip **before** and a dip **after** (cm, converted by the chart), litres sold from the tank while offloading (default 0), the supplier (a vendor party, optional), the delivery note number (unique per tank, ignoring case), the unit cost per litre and the currency. `received = after − before + sold during`; `shortage = note litres − received` (positive = short), flagged `delivery_short` above the delivery tolerance. The server posts what was **measured**, not what the note says. Deliveries are immutable; a mistake is corrected by an Inventory adjustment. Numbers `FD-{BRANCH}-{YYYY}-{00001}`. | Must |
| FUEL-26 | **Loss and gain tolerance and approval.** Each reconciliation compares `|variance|` with the tank's litre tolerance, else the larger of the company's litre tolerance and its percent of the throughput (sales since baseline). Within it the reconciliation is `accepted`; outside it is `pending_approval` and a `fuel.tank_variance` flow starts (default: one approval by a Station manager, never the person who took the dip; the dip taker can never approve it, `mayAct`). Approved or accepted: the tank's baseline moves to the dip, the sales and deliveries it counted are marked consumed, and the loss or gain is posted to Inventory (FUEL-30). Rejected: the baseline still moves (the dip is a fact), nothing is posted, and the case is reported. | Must |
| FUEL-27 | **Voucher and fleet tenders** are part of the shift's collections as `voucher` and `credit` (FUEL-12). | Must |
| FUEL-28 | **Server-side checks for money and stock.** Litres, prices, totals, tenders, readings, dips and conversions are computed or re-checked on the server; the device's totals are never taken as the collected amount. Ids of tanks, nozzles, vehicles and users are looked up under row-level security and the station's place; another tenant's or another station's id is refused. | Must |
| FUEL-29 | **Fiscal.** Every fuel sale goes through the existing POS fiscal path (KRA eTIMS, DRC DGI) with the item's tax category. The module adds no fiscal code. A fuel item whose tax rate is "Rate needed" cannot be sold (POS-11). Excise duty and levies are **to confirm** (see below). | Must |
| FUEL-30 | **Inventory.** With Inventory active, the fuel product is a stock item at the station location: a sale leaves through the POS sale movement, a delivery enters through the existing `GoodsReceived` contract (measured litres at the delivery cost), a loss through `StockWrittenOff` (INV-26) and a gain through the new `StockGained` contract (INV-28, `adjustment_in` at the estimated cost). The module never reads Inventory tables. With Inventory off nothing is posted and nothing breaks. | Must |
| FUEL-31 | **Attendant on a phone or till.** An attendant signs in to the POS app as themselves; the shift screen lists their nozzles, takes readings and shows their running litres and value. | Must |
| FUEL-32 | **Roles and presets.** Permissions `fuel.resource.action`; role templates `forecourt_attendant`, `shift_supervisor` and `station_manager` (RBAC-03); the onboarding preset `fuel_station` enables the module and offers those roles (ONB-02). | Must |
| FUEL-33 | **Audit and events.** Settings, products, tanks, charts, pumps, nozzles, prices, vouchers, vehicles, shift opens and closes, readings, resets and their decisions, settlements and their decisions and reopenings, dips, reconciliations, deliveries and postings are audited (AUD-01, AUD-02). Events after commit: `ShiftOpened`, `ShiftClosed`, `SettlementReopened`, `DeliveryRecorded`, `TankReconciled`. | Must |
| FUEL-34 | **Reports** (`GET fuel/reports/*`, scoped to the reader's stations, a period): shift summary (litres, value, tenders, variance per shift), attendant variance (per attendant over a period), nozzle meter history (every opening and closing reading, resets and gaps), tank reconciliation and loss/gain (per tank and period), sales by product (litres and value per product and station), fleet statement (per customer and vehicle). Money per currency with the currency code first. | Must |
| FUEL-35 | **Numbering** (NUM-01): settlements, reconciliations and deliveries as above, per branch; receipts are the POS's. | Must |
| FUEL-36 | **Personal data.** Fleet vehicles reference a customer party; attendants are users. No national ID or phone is stored here. Erasure of a customer (NFR-08) keeps the fuel sales as business records (the party is referenced by id). | Must |
| FUEL-37 | **Archive, not delete** (TEN-06): stations, products, tanks, pumps, nozzles, vehicles are archived; prices, charts, shifts, dips, reconciliations, deliveries and sales are append-only records. | Must |
| FUEL-38 | **Fleet statement.** Per customer and vehicle: sales (date, station, product, litres, price, total, odometer) and totals per currency in a period. Reads only `fuel_sales`. | Must |

## Review rules (security review, 2026-10-27)

These tighten the requirements above; where they differ, they win.

- **Gap, tests, flags force approval (FUEL-11, FUEL-13).** An opening reading above the nozzle's last close is a `meter_gap`: its litres are
  charged to the new shift as unrecorded, at the server price at close. A `meter_gap`, pump tests above the company's per-nozzle
  `test_tolerance_litres` (default 0), a missing price, or **any live sale of the shift carrying a review flag** (voucher invalid, `vehicle_*`,
  `outside_shift`, `price_differs`, a discount, a POS flag such as `actor_unverified` or `tender_flagged`) makes every settlement of the shift
  `pending_approval`, whatever the amounts.
- **Server prices (FUEL-28).** Recorded sales are valued at the station price in force at each sale's time, never the till's.
- **Late changes (FUEL-15).** A reopened settlement is always `pending_approval` with a new flow, never `auto_accepted`.
- **Named actors (FUEL-14, FUEL-17).** Attendants are attested with `attendant_proof` (AUTH-07). When the opener, the closer or an attendant is
  unverified, everyone signed in on that till from a session before the shift to its close is excluded from approving; the exclusion covers
  approve, move and cancel. The same applies to a meter reset requested from an unproved till.
- **Stale resets (FUEL-17).** An approval is refused `reset_stale` unless the nozzle's last reading and open shift still match the request; a
  shift cannot open on a nozzle with a pending reset (`reset_pending`, retryable).
- **Vouchers (FUEL-21).** A redemption stores `applied_minor` (0 for a void, expired or other-currency voucher; the balance at most): the
  shift counts and a void or refund gives back that, once per refund. `POST` refunds of a voucher payment return value (`refund_ids`).
- **POS hook (FUEL-18).** `fuel.sale.create` is a core `SaleExtensionGrants` entry: it stands in for `pos.sale.create` only on lines that are
  fuel products, only with a non-empty `extensions.fuel`, only while the module is on. Other lines still need `pos.sale.create`.
- **Tanks (FUEL-23, FUEL-25, FUEL-26).** Accepted variances of the previous 24 hours use up the tolerance (and add their throughput), so
  splitting a loss over several dips cannot avoid approval. The fuel in a dip is the level less the water's litres by the same chart
  (`water_above_dip`). Litres sold while offloading come from the fuel sales between the two dips; a typed figure is ignored.
- **Cancelled approvals (FUEL-14, FUEL-26).** A cancelled flow sets the settlement or reconciliation to `cancelled`;
  `POST fuel/settlements/{id}/resubmit` and `POST fuel/reconciliations/{id}/resubmit` (`fuel.variance.view`) start a new flow. The Admin template
  holds `fuel.variance.approve` so the default flow's escalation works.

## Data model

Every table has `tenant_id` under forced RLS (TEN-01), UUID v7 keys, money as
`bigint` minor units with a currency, litres `numeric(14,3)` (meters
`numeric(16,3)`); users are referenced by `(tenant_id, id)`. Sales, payments,
items, parties and locations are named by id only; POS tables are never
referenced.

| Table | Holds |
| --- | --- |
| `fuel_settings` | Per company, FUEL-03 |
| `fuel_stations` | FUEL-02 |
| `fuel_products` | FUEL-04 |
| `fuel_tanks` | FUEL-05, with the baseline litres and time (FUEL-24) |
| `fuel_strapping_charts`, `fuel_strapping_points` | FUEL-06 |
| `fuel_pumps`, `fuel_nozzles` | FUEL-07, last reading (FUEL-16) |
| `fuel_prices` | FUEL-08 |
| `fuel_shifts`, `fuel_shift_nozzles` | FUEL-09 to FUEL-11 |
| `fuel_settlements`, `fuel_settlement_tenders` | FUEL-12 to FUEL-15 |
| `fuel_meter_resets` | FUEL-17 |
| `fuel_sales`, `fuel_sale_tenders` | One row per fuel sale line; the fuel share of each sale's payments by tender kind (FUEL-12, FUEL-18) |
| `fuel_dips`, `fuel_reconciliations` | FUEL-23, FUEL-24, FUEL-26 |
| `fuel_deliveries` | FUEL-25 |
| `fuel_vehicles` | FUEL-20 |
| `fuel_vouchers`, `fuel_voucher_redemptions` | FUEL-21 |

## Permissions

| Permission | What it allows |
| --- | --- |
| `fuel.settings.view`, `.edit` | A company's fuel settings |
| `fuel.setup.view`, `.manage` | Stations, products, tanks, strapping charts, pumps and nozzles |
| `fuel.price.view`, `.manage` | Fuel prices |
| `fuel.sale.view`, `.create` | Read fuel sales; sell fuel at the till (an attendant) |
| `fuel.shift.view`, `.open`, `.close` | Read shifts and settlements; open and close at the till |
| `fuel.variance.view`, `.approve` | Read variances; approve or reject a shift or tank variance (never one's own) |
| `fuel.meter.request_reset`, `.approve_reset` | Ask for a meter reset; approve one (never one's own) |
| `fuel.dip.view`, `.record` | Read dips and reconciliations; record a dip |
| `fuel.delivery.view`, `.record` | Read deliveries; record one |
| `fuel.vehicle.view`, `.manage` | Fleet vehicles |
| `fuel.voucher.view`, `.manage`, `.redeem` | Read vouchers; issue and void them; redeem at the till |
| `fuel.report.view` | Reports |

Role templates (RBAC-03): **Forecourt attendant** signs in at the till,
sells fuel, redeems vouchers, opens and closes their own shift and takes
dips; **Shift supervisor** also sees all of the station's shifts, records
deliveries and dips, requests meter resets and reads the variance and reports
but **cannot approve**; **Station manager** holds `fuel.*` at the station
(approves variances and resets, sets prices and fleet, issues vouchers,
reads reports). **Admin** configures (settings, setup, prices) and reads,
without approving variances; **Branch manager** holds `fuel.*`; **Accountant**
and **Read-only auditor** read. **Cashier** holds none. Which of the three
roles may do what is the permission table above; the shop is the Cashier's.

## API (`/api/v1`, module `fuel`)

**Back office (people's tokens, checked at the station, RBAC-04).**
Settings `GET|PUT companies/{company}/fuel-settings`. Setup:
`GET|POST fuel/stations`, `PATCH fuel/stations/{id}`, `POST .../archive`;
`GET|POST fuel/products`, `PATCH`, `POST .../archive`; `GET|POST fuel/tanks`,
`GET|PATCH fuel/tanks/{id}`, `POST .../archive`, `GET|POST fuel/tanks/{id}/strapping`,
`GET fuel/tanks/{id}/dip-convert?dip_cm=` (without `dip_cm`: the range the chart covers); `GET|POST fuel/pumps`, `PATCH`,
`POST .../archive`; `GET|POST fuel/nozzles`, `PATCH`, `POST .../archive`.
Prices: `GET|POST fuel/prices`, `POST fuel/prices/{id}/archive`. Shifts:
`GET fuel/shifts`, `GET fuel/shifts/{id}`, `GET fuel/settlements`,
`GET fuel/settlements/{id}` (approval is the core approvals inbox / workflow
routes), `GET fuel/sales`. Resets: `GET|POST fuel/meter-resets`,
`POST fuel/meter-resets/{id}/approve|reject`. Tanks: `GET|POST fuel/dips`,
`GET fuel/reconciliations`, `GET fuel/reconciliations/{id}`,
`GET|POST fuel/deliveries`, `GET fuel/deliveries/{id}`. Fleet and vouchers:
`GET|POST fuel/vehicles`, `PATCH`, `POST .../archive`; `GET|POST fuel/vouchers`,
`GET fuel/vouchers/{id}`, `POST .../void`. Reports:
`GET fuel/reports/shift-summary|attendant-variance|nozzle-meter-history|tank-reconciliation|sales-by-product|fleet-statement`.

**Till (device token; place from the device).**

| Route | Notes |
| --- | --- |
| `POST fuel/device/shifts` | `{shifts: [{id, opening: {opened_by_id, opened_at, actor_proof?, nozzles: [{nozzle_id, attendant_id, reading}], dips?: [{id, tank_id, dip_cm, water_cm?, taken_at}]}, closing?: {closed_by_id, closed_at, actor_proof?, nozzles: [{nozzle_id, reading, test_litres?}], tenders: [{attendant_id, tenders: [{kind, amount_minor}]}], dips?: [...], note?}]}` at most 10; one result per shift: `{id, status: stored, state: open\|closed, flags}` or `{id, status: rejected, error: {code, message, field, retryable}}` |
| `POST fuel/device/meter-resets` | `{resets: [{id, nozzle_id, shift_id?, reading_before, reading_after, reason, requested_by_id, actor_proof?, requested_at}]}` |
| Pull (core sync API) | `fuel_nozzles`, `fuel_prices`, `fuel_tanks` (with strapping), `fuel_vehicles`, `fuel_vouchers`, `fuel_station` |
| `pos/sales` | Fuel sales carry `extensions.fuel = {shift_id, lines: [{line_id, nozzle_id}], vehicle?: {registration, odometer?}}` |

Review flags (on a sale, shift nozzle, settlement or delivery) are objects `{code, ...detail}`.
A till's tank dips travel inside its shift uploads (`opening.dips`, `closing.dips`); routine dips are
recorded in the back office (`POST fuel/dips`).

Errors: `not_a_station`, `nozzle_unknown`, `nozzle_in_use`, `meter_decreased`,
`close_incomplete`, `payload_mismatch`, `id_conflict`, `attendant_unknown`,
`user_unknown`, `dip_out_of_range`, `dip_out_of_order`, `tank_unknown`,
`chart_missing`, `price_missing`, `voucher_unknown`, `upload_rejected`.

## Integrations

- **POS** (existing, unchanged but for one hook): the core `TenderProviders`
  seam for the payment method type `fuel_voucher`, the sale's `extensions`,
  and the events. The one hook is in the POS sale path: a cashier may ring a
  sale carrying `extensions.fuel` with `fuel.sale.create` instead of
  `pos.sale.create` (as `restaurant.order.close` is accepted for
  `extensions.restaurant`, REST-19).
- **Inventory**: the new `StockGained` contract and `RecordStockGained`
  listener (INV-28); `GoodsReceived` and `StockWrittenOff` as they are.
- **Core approvals**: document types `fuel.shift_variance` and
  `fuel.tank_variance` (WF-01).
- **Core payments**: payment method type `fuel_voucher`.
- **Accounting, CRM**: nothing is posted or read directly; Loyalty's credit
  tender and CRM customers are used through the sale's payment and customer.

## To confirm (statutory or business decisions, not invented here)

1. Excise duty, road maintenance and other levies on fuel: whether they are
   itemised on the receipt or part of the pump price, and whether they are
   tax codes of the country pack (a specific amount per litre is not a
   percentage tax code). No rate is built in; VAT is the item's tax category.
2. The regulator's maximum retail prices (EPRA in Kenya): the source, how
   often it changes and whether the tenant must keep a copy of the notice.
   Prices are typed by the tenant with a reference.
3. Tolerances: loss allowance for evaporation and measurement (percent of
   throughput) and delivery shortage allowance. All default to 0.
4. Temperature compensation (litres at 15 degrees) of dips and deliveries.
5. Voucher scope: value vouchers only; litre vouchers and per-product
   restrictions beyond the fuel type are not built.
6. Fleet credit limits and statements of account belong to Loyalty credit;
   they are not duplicated here.
7. Shifts run in the station's currency; foreign cash handed in at the pump
   is converted by the till when the sale is rung (POS-03), not at shift
   close.
8. The module's price in the catalogue (a placeholder equal to Inventory's).
9. Payments of a sale that mixes fuel and shop items are attributed to the
   fuel share pro rata (to the attendant of the first fuel line).
10. Tank dips from a till travel only with a shift upload (open or close); a routine dip outside a shift is recorded in the back office. A device endpoint for them is not built.
11. A sale that is voided before its completion reaches Fuel station is recorded as live (events are handled in order, so this needs a void that overtakes its own sale).
12. A shortage found at a delivery is recorded and reported; claiming it
    from the supplier is outside this module.

## Deferred

Pump automation (a forecourt controller or ATG feed), card-reader and
fuel-card networks, loyalty points per litre rules, dispenser price-sign
updates, temperature compensation, litre vouchers, supplier claims, fleet
card issuing, and payroll deductions for rejected variances.
