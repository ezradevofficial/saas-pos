# Hotel and lodging module spec

Source: concept note section 3 (hotel / lodge row: room charges to a guest
folio, settle at checkout; restaurant and bar outlets post to rooms);
platform core spec (TEN-01, TEN-06, RBAC-01 to RBAC-09, RBAC-12, NUM-01,
AUD-01, AUD-02, EXP-01, MD-04, CP-01, CP-02, NFR-04, NFR-08, ONB-02);
ADR 002 (RLS), ADR 004 (sync), ADR 008 (lists); the POS (`pos.md`),
Loyalty (`loyalty.md`, the tender seam) and Accounting module specs. When
this file and the core spec disagree, the core spec wins.

## Scope

Hotel is a module (`api/modules/Hotel`, permissions `hotel.*`, catalogue key
`hotel`), sold and switched on per tenant (RBAC-08). It works with only the
core installed (architecture rule 2): it builds on core companies, branches,
items, tax codes, parties and payment methods and keeps every hotel fact in
its own tables. It never reads another module's tables and changes no other
module's schema. It talks to POS through the core tender seam
(`TenderProviders`) and the POS events (`SaleCompleted`, `SaleVoided`,
`SaleRefunded`), to Accounting through a posting event (`HotelPosting`, as
E-commerce does) and to the tax authority through the core fiscal queue.

- **A property is a branch.** Rooms, the business date and the night audit
  belong to a branch; settings belong to a company.
- **A stay is one room for a date range.** A reservation (a booking) holds
  one or more stays: one for a direct or walk-in booking, many for a group.
  A stay has a guest folio; a group reservation may also have a master folio
  that stays route charges to.
- **Folios are ledgers.** Lines are append-only; a mistake is a reversing
  line. The balance is the sum of the lines (charges positive, payments
  negative).
- **Nothing statutory is invented.** Tourism and catering levies, guest
  register retention and any regulator's rule are tenant data (tax codes of
  the country pack, settings) and are marked **to confirm** below.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| HOT-01 | **Module and standalone use.** Routes, menus, permissions, listeners, sync entities and the nightly job load only for tenants that subscribed (RBAC-08). Everything works with only the core; the room-charge tender needs POS, journals need Accounting. | Must |
| HOT-02 | **Settings per company** (`hotel_settings`): check-in and check-out times, early check-in and late check-out fees, weekend days, the cancellation and no-show rules (HOT-15), deposit percentage suggested at booking, the room revenue item, extra levy tax codes (HOT-32), the in-house charge limit, and the room-charge payment method. Edits are audited. | Must |
| HOT-03 | **Room types** (`hotel_room_types`): code, name, description, base and maximum adults and children, maximum occupancy, beds, sort. Archived, never deleted (TEN-06). | Must |
| HOT-04 | **Rooms** (`hotel_rooms`): number (unique per branch, ignoring case), floor, room type, features (a list of short tags such as `balcony`, `sea_view`), notes. Archived, never deleted. | Must |
| HOT-05 | **Room status.** `vacant_clean`, `vacant_dirty`, `occupied`, `out_of_order` (with reason and optional dates). Check-in makes a room `occupied`; check-out makes it `vacant_dirty` and creates a clean task; housekeeping makes it `vacant_clean`. A room out of order cannot be assigned. Every change is audited. | Must |
| HOT-06 | **Rate plans** (`hotel_rate_plans`) per room type: code, name, currency, weekday rate and weekend rate per night, board basis, whether rates include tax, optional own cancellation rule, validity. | Must |
| HOT-07 | **Seasons.** A rate plan has seasons (`hotel_rate_seasons`: dates, weekday and weekend rate, priority). The rate of a night is the highest-priority season covering it, else the plan's base; weekend days come from the settings. | Must |
| HOT-08 | **Board basis**: `room_only`, `bed_breakfast`, `half_board`. It is part of the rate (no separate charge) and shown on the stay, the folio and the housekeeping and kitchen lists. | Must |
| HOT-09 | **Extra person and child pricing.** Per night, each adult above the room type's base adults pays the plan's extra adult rate, each child above the free children count pays the child rate; a maximum occupancy is enforced. | Must |
| HOT-10 | **Rate quote.** `GET hotel/rate-quote` returns the night-by-night rate with the levies and total for a room type, plan, dates and party. The stay stores that quote (its nightly rates) so later rate changes do not change a booked stay; a rate override needs `hotel.rate.override`, a reason, and is audited. | Must |
| HOT-11 | **Availability.** `GET hotel/availability` gives, per room type and night, rooms sellable, booked, free; the grid also names rooms out of order. Calendar and grid views use it. | Must |
| HOT-12 | **Bookings.** Direct reservations (guest, dates, room type, plan, party, source, notes), walk-ins (booking and check-in in one step, with a room) and group reservations (several room lines under one group name and contact, with an optional master folio). A booking has a number (NUM-01) and a status flow `reserved`, `checked_in`, `checked_out`, `cancelled`, `no_show` per stay. | Must |
| HOT-13 | **No overbooking.** Creating, moving and extending stays, and assigning rooms, run under a database lock per branch and room type (rooms: per room), recount availability inside it and refuse (`not_available`) when no room is left. Two requests for the last room cannot both succeed. | Must |
| HOT-14 | **Deposits** through the company's payment methods, including M-Pesa (STK push or a typed receipt) through the core payment intents. A deposit is a payment on the reservation's folio (HOT-24), held as a liability until check-in, cancellation or no-show, when it is applied. | Must |
| HOT-15 | **Cancellation and no-show.** Rules are settings (and per-plan overrides): free cancellation until N days before arrival, then a charge of `none`, a number of `nights`, a `percent` of the stay or a `fixed` amount; the no-show charge is configured alike. The charge is posted to the stay's folio (a fee line), the deposit is applied against it and the rest is left to refund. A staff member may waive the charge (`hotel.fee.waive`, reason, audited). | Must |
| HOT-16 | **Guest records** (`hotel_guests`): name, phone, email, nationality, ID or passport type and number, date of birth, address, notes; each guest is a core customer party. ID number, date of birth and address are personal data: registered with the privacy module (access report, erasure rules, NFR-08), hidden by field rules (RBAC-05, resource `hotel_guest`, fields `identity_document`, `date_of_birth`, `contact`, `address`) and by `hotel.guest.identity`, never in a sync entity. Guests are archived, never deleted. The retention of the guest register is **to confirm**. | Must |
| HOT-17 | **Check-in.** Assign a room of the stay's type (or another room with a recorded upgrade or downgrade), record the guest ID (required by a setting) and key handover (`keys_issued`), convert deposits and make the room `occupied`. A room that is dirty or out of order is refused. A stay may start only on or after its arrival date, unless the user holds `hotel.stay.checkin_early`. | Must |
| HOT-18 | **Early check-in and late check-out fees.** Checking in before the check-in time or out after the check-out time posts the configured fee (or waives it with `hotel.fee.waive`). | Must |
| HOT-19 | **Check-out and settlement.** Check-out shows the folio, takes the payments that clear it (any method), refuses a balance owed unless the user holds `hotel.folio.settle_open` (a balance left on account, flagged), settles the folio (HOT-27), makes the room dirty and creates the clean task. A settled folio takes no more charges. | Must |
| HOT-20 | **Folio.** A guest folio per stay (`hotel_folios`), numbered; a master folio per group reservation. Lines (`hotel_folio_lines`) are append-only, carry the business date, tax split, source and a unique source key so nothing posts twice. | Must |
| HOT-21 | **Charges.** Room charges post nightly (HOT-30). Extras (minibar, laundry, parking, a fee) are posted by staff from core items with quantity, price, tax and business date (`hotel.folio.charge`). A line is voided by a reversing line with a reason (`hotel.folio.void`). The price is typed (the web pre-fills the item's list price); the charge and its price are audited. | Must |
| HOT-22 | **Charge to room.** A POS payment method of type `room_charge` pays a sale to a guest's room. The till asks for the room number; the server validates that the room holds a checked-in stay in the same tenant and branch whose folio is open and allows charging (`room_charge_in_house`). The sale is posted to that folio when it completes, idempotently per payment, and reversed on void and refund. A tender that cannot be posted (the guest left, the room is unknown) is kept (device wins, NFR-04) and listed for the front desk as a charge exception (`hotel_charge_exceptions`) to move to another folio or bill another way. | Must |
| HOT-23 | **Room-charge contract.** `Modules\Hotel\Contracts\RoomCharges` (`lookup`, `post`, `reverse`) is the one entry point other modules use (Restaurant and bar outlets call it later; POS reaches it through HOT-22). It answers only while the module is active, validates as HOT-22 and posts one charge per `(source, source_id)`. | Must |
| HOT-24 | **Payments, refunds and deposits.** Payments by any company payment method, cash or mobile money or card; a mobile money payment may start an STK push through the core payment intents (reference type `hotel.payment`) and posts when the provider confirms. Refunds (`hotel.folio.refund`) post a positive line and are limited by the user's refund limit (RBAC-06). | Must |
| HOT-25 | **Transfers.** Charges move between folios of one company (stay to master, master to stay, room move, a charge exception to a folio) as a pair of lines that net to zero, in one transaction and both audited (`hotel.folio.transfer`). | Must |
| HOT-26 | **Master folio.** A group reservation may have a master folio; each stay chooses `bill_to_master` (all charges) or keeps its own folio (extras). Checking out the master settles it; stays cannot be settled while they hold a balance unless it is transferred or paid. | Must |
| HOT-27 | **Settlement into fiscal invoicing and the ledger.** Settling a folio issues one invoice (`hotel_folios.invoice_number`, NUM-01) of the hotel's own charges (rooms, extras, fees, levies); POS sales charged to the room were already receipted and fiscalised at the till and appear on the folio as settled by room (no second tax document). The invoice is queued on the core fiscal queue (source `hotel`, KRA eTIMS or DRC DGI by company) and the postings below reach Accounting as `hotel.charge`, `hotel.payment`, `hotel.deposit` and `hotel.deposit_applied` events (Accounting posts them through the company's posting rules, idempotently). | Must |
| HOT-28 | **Housekeeping.** A board by floor of room statuses and tasks (`hotel_housekeeping_tasks`: `checkout_clean`, `stayover`, `inspection`, `deep_clean`, `maintenance`); tasks are assigned to housekeepers, started, finished and inspected. | Must |
| HOT-29 | **Housekeeper on a phone.** A housekeeper signs in to the POS app as themselves and sees their tasks and rooms (`hotel.housekeeping.update`), marks a room cleaning, clean or needing maintenance. Only status, never guest data, is shown. | Must |
| HOT-30 | **Night audit** (`hotel_night_audits`, `hotel:night-audit`). A scheduled per-tenant job (cross-tenant scheduling through DueTenants) that, per branch, posts the night's room charge to every in-house stay, flags and charges no-shows, creates stay-over cleaning tasks, saves an occupancy snapshot and rolls the business date. Idempotent per branch and business date: a run names the date it audits (the scheduler and the screen both do), a repeat or a concurrent run of the same date answers the audit already made and changes nothing; a manual run needs `hotel.audit.run`. | Must |
| HOT-31 | **Reports** (lists and exports, ADR 008, EXP-01): occupancy, ADR and RevPAR per day, arrivals, departures and in-house, folio balances, housekeeping, room-charge exceptions and no-shows. ADR is room revenue (net of tax) over rooms sold; RevPAR is room revenue over rooms available (out-of-order rooms excluded). | Must |
| HOT-32 | **Tax and levies.** Room and extra charges take the tax code of the item's tax category (MD-03) and, for rooms, any extra tax codes the company lists as levies in its settings. Codes and their effective-dated rates are country-pack or tenant data; a code without a confirmed rate posts the line untaxed and flagged `tax_rate_needed` (rates are never invented, CP-01). | Must |
| HOT-33 | **Roles and presets.** Permissions `hotel.resource.action`; role templates `receptionist`, `housekeeping` and `night_auditor` (RBAC-03); the onboarding preset `hotel` enables the module and offers those roles (ONB-02). | Must |
| HOT-34 | **Audit and events.** Settings, room, rate and status changes, bookings, cancellations, no-shows, check-ins and outs, every folio charge, payment, refund, transfer and void, rate overrides, fee waivers and night audits are audited (AUD-01, AUD-02). Events after commit: `StayCheckedIn`, `StayCheckedOut`, `FolioSettled`, `HotelPosting`. | Must |
| HOT-35 | **Offline till.** Sync entity `hotel_in_house` (snapshot: room number, folio id, guest display name, whether charging is allowed) for the till's branch, served only while the module is active. The room-charge tender works offline against it and is checked again by the server (HOT-22). Staff rows carry `hotel.` permissions. | Must |
| HOT-36 | **Numbering** (NUM-01): reservations `RS-{BRANCH}-{YYYY}-{00001}`, folios `FO-{BRANCH}-{YYYY}-{00001}`, invoices `HI-{BRANCH}-{YYYY}-{00001}`. | Must |
| HOT-37 | **Room moves and stay changes.** A move records the old and new room, rate effect and a housekeeping task; extending, shortening or changing dates is re-checked for availability under the lock (HOT-13). | Should |
| HOT-38 | **Personal data on the front desk.** Guest ID is shown masked by default; showing it in full needs `hotel.guest.identity` and is audited once per guest and request. | Must |

## Data model

Every table has `tenant_id` under forced RLS (TEN-01) and UUID v7 keys;
money is `bigint` minor units with a `currency`; users are referenced by
`(tenant_id, id)`.

| Table | Holds |
| --- | --- |
| `hotel_settings` | Per company, HOT-02 |
| `hotel_properties` | Per branch: the business date and when it last rolled |
| `hotel_room_types`, `hotel_rooms` | HOT-03 to HOT-05 |
| `hotel_rate_plans`, `hotel_rate_seasons` | HOT-06 to HOT-09 |
| `hotel_guests` | HOT-16 (a customer party, identity document fields) |
| `hotel_reservations` | The booking: number, kind (`direct`, `walk_in`, `group`), contact guest, group name, source, notes |
| `hotel_stays` | One room for a date range: type, plan, room, party, nightly rates (`jsonb`), status, keys, timestamps |
| `hotel_folios`, `hotel_folio_lines` | HOT-20 (append-only lines, a database trigger refuses update and delete) |
| `hotel_payments` | A payment attempt on a folio (deposit, payment, refund) and its state, intent id, applied-at |
| `hotel_charge_exceptions` | HOT-22 charges that could not post |
| `hotel_housekeeping_tasks` | HOT-28 |
| `hotel_night_audits` | HOT-30, unique per branch and business date |

Sales, payment intents and parties are named by id only; POS tables are
never referenced.

## Permissions

| Permission | What it allows |
| --- | --- |
| `hotel.settings.view`, `.edit` | A company's hotel settings |
| `hotel.room.view`, `.manage` | Room types and rooms; `.manage` also sets out of order |
| `hotel.rate.view`, `.manage`, `.override` | Rate plans; override a quoted rate |
| `hotel.guest.view`, `.manage`, `.identity` | Guest records; `.identity` shows ID numbers (personal data) |
| `hotel.reservation.view`, `.create`, `.edit`, `.cancel` | Bookings |
| `hotel.stay.checkin`, `.checkin_early`, `.checkout`, `.move` | Front desk |
| `hotel.folio.view`, `.charge`, `.payment`, `.refund`, `.void`, `.transfer`, `.settle_open` | Folios |
| `hotel.fee.waive` | Waive an early, late, cancellation or no-show fee |
| `hotel.charge.resolve` | Resolve room-charge exceptions |
| `hotel.housekeeping.view`, `.assign`, `.update` | Board, assigning tasks, updating room status |
| `hotel.audit.view`, `.run` | Night audit |
| `hotel.report.view` | Reports |

Role templates (RBAC-03): **Receptionist** works bookings, the front desk and
folios up to refunds, not rates, voids beyond their limit or the audit;
**Housekeeping** sees and updates rooms and its tasks only; **Night auditor**
runs the audit, reads folios and reports and resolves charge exceptions.
**Branch manager** holds everything but settings; **Admin** configures and
reads but does not see guest ID numbers; Accountant and Read-only auditor
view; **Cashier** and **Waiter** hold none (they charge rooms through POS).

## API (`/api/v1`, user tokens, module `hotel`)

Settings `GET|PUT companies/{company}/hotel-settings`. Setup:
`GET|POST hotel/room-types`, `PATCH hotel/room-types/{id}`, `POST .../archive`;
`GET|POST hotel/rooms`, `PATCH hotel/rooms/{id}`, `POST .../archive`,
`POST hotel/rooms/{id}/status`; `GET|POST hotel/rate-plans`,
`GET|PATCH hotel/rate-plans/{id}`, `POST .../archive`; `GET hotel/rate-quote`.
Booking: `GET hotel/availability`, `GET|POST hotel/guests`,
`GET|PATCH hotel/guests/{id}`, `GET|POST hotel/reservations`,
`GET hotel/reservations/{id}`, `POST hotel/walk-ins`,
`PATCH hotel/stays/{id}` (dates, party, plan), `POST hotel/stays/{id}/assign-room`,
`.../check-in`, `.../check-out`, `.../move-room`, `.../cancel`, `.../no-show`.
Folios: `GET hotel/folios`, `GET hotel/folios/{id}`, `POST .../charges`,
`.../payments`, `.../refunds`, `.../transfers`, `.../lines/{line}/void`,
`GET .../print`. Housekeeping: `GET hotel/housekeeping/board`,
`GET|POST hotel/housekeeping/tasks`, `POST .../tasks/{id}/assign|start|finish|inspect`,
`GET hotel/housekeeping/my-tasks`. Night audit: `GET|POST hotel/night-audits`,
`GET hotel/night-audits/{id}`. Charge to room: `GET hotel/room-charges/lookup`,
`GET hotel/charge-exceptions`, `POST hotel/charge-exceptions/{id}/resolve`.
Reports: `GET hotel/reports/occupancy|arrivals|departures|in-house|folio-balances|housekeeping|no-shows`.

## Integrations

- **POS** (existing, unchanged): the core `TenderProviders` seam for the
  new payment method type `room_charge` (a core type like Loyalty's `credit`),
  the sale `tender` object (`{room, folio_id}`), and the events. No Hotel
  code in POS.
- **Accounting**: a `HotelPosting` event handled by `PostModuleEvent`, with
  default rules for KE and CD (a guest ledger, guest deposits and hotel
  revenue accounts) and the tender type `room_charge` posting to the guest
  ledger.
- **Fiscal**: source `hotel` (invoices of folio settlement, credit notes for
  a reversed settled charge).
- **Core payments**: payment intents for M-Pesa deposits and payments.
- **Restaurant / bar** (parallel work): calls `RoomCharges`; no Restaurant
  code is touched here.

Company-wide records (settings, room types, rate plans) are changed only by someone who holds the permission for the whole company; a
branch manager's role does not reach them. Guests are the company's shared register: front-desk staff at any property can add and read them.

## To confirm (statutory or business decisions, not invented here)

1. Tourism levy and catering levy: whether they apply, their rates, their
   base and whether they are tax codes of the country pack. Hotel applies
   whatever tax codes the company lists, never a built-in rate.
2. Guest register: whether ID capture is mandatory, what is recorded and
   how long it is kept (the setting `require_guest_id` defaults to on; the
   retention is held, not erased, while stays exist).
3. Cancellation and no-show charges: the defaults (free until one day
   before arrival, then one night) are neutral business defaults.
4. Whether deposits are a liability until check-in (this module does so) or
   income on receipt, per the company's accountant.
5. The module's price in the catalogue (a placeholder equal to Inventory's).
6. Payments and deposits are taken in the folio's currency only; accepting a guest's other currency at check-out (with the rate and rounding)
   is not built.
7. KRA eTIMS and DRC DGI treatment of a folio invoice that includes charges
   already receipted at a till (this module issues the invoice for the
   hotel's own charges only).
