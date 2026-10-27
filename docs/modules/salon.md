# Salon and services module spec

Source: concept note section 3 (salon / spa / services: appointments, staff
assignment, commissions); platform core spec (TEN-01, TEN-06, RBAC-01 to
RBAC-08, RBAC-05, RBAC-06, NOT-01, NOT-02, NUM-01, NUM-02, AUD-01, AUD-02,
NFR-04, NFR-08); docs/modules/pos.md (the sale path, POS-09),
docs/modules/hr.md, docs/modules/payroll.md, docs/modules/inventory.md,
docs/modules/loyalty.md, docs/modules/hotel.md and docs/modules/van-sales.md
(the structure this module follows); docs/privacy.md; ADR 004 (offline sync).
When this file and the core spec disagree, the core spec wins.

## Scope

Salon is a module (`api/modules/Salon`, key `salon`, permissions `salon.*`,
tables `salon_*`), sold and switched on per tenant like every other module
(catalogue key `salon`, per outlet, requires POS, RBAC-08). Routes, menus,
permissions, sync sources, listeners and jobs exist only while the tenant's
subscription has it. The `salon` business type suggests it by default.
**Price to set:** the catalogue row is a placeholder equal to Inventory's
(KES 1,500.00 and USD 12.00 per outlet per month) until the owner sets it; a
price change is a new catalogue price row.

- **A visit is a normal POS sale.** The appointment is the plan; the till
  sells it (services, add-ons, products, a tip) through `POST pos/sales`, so
  payments, M-Pesa, fiscal submission (eTIMS, DGI), Loyalty, Accounting and
  stock use the existing path. Salon never reads or writes POS, Inventory,
  CRM, HR or Payroll tables. It links to a sale through the sale's
  `extensions.salon` and the `SaleCompleted`, `SaleVoided` and `SaleRefunded`
  events; to stock through Inventory's `StockWrittenOff` event; to customers
  through core parties; to people through HR's read contracts and Payroll's
  `ExternalEarnings` contract.
- **A service is a core item** of type `service` (its price comes from the
  price list, MD-03). Salon adds duration, who may do it, the resource it
  needs, add-ons and the products it uses.
- **A client is a core customer party** plus the salon's own profile
  (preferences, allergies, formula notes). Both are personal data (NFR-08).
- **Prepaid value** (a deposit, a package session) is paid with the payment
  method type `salon_credit`, provided by Salon through the core tender seam
  (as Hotel provides `room_charge`). The sale shows the real services; the
  credit is how part of it was paid. The device never refuses a sale; the
  server checks the credit and keeps what it cannot honour as an exception.
- **Money** is `bigint` minor units with a currency (ADR 003). Ids are UUID
  v7; the device makes the id of what it creates offline.
- **Offline.** Staff, services, resources, today's and the coming days'
  appointments, the queue and the clients' packages are pulled; check-in,
  start, finish, walk-ins and the queue are pushed idempotently by
  `POST salon/device/sync`; the sale is the till's own upload.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| SAL-01 | **Module gate.** Routes, permissions, sync sources, listeners and jobs exist only while the tenant has the module (RBAC-08); it works with core and POS only. Inventory, HR, Payroll, Loyalty, CRM and E-commerce switch on integrations when both are subscribed. | Must |
| SAL-02 | **Service menu.** A service is a core `service` item with a duration (5 to 600 minutes), a clean-up buffer after it, a category, the staff qualified to do it, optional resources that may host it (a chair, a room), add-ons (other services marked `addon`, each with its own duration and item) and optional products used (item, quantity). `online_bookable` offers it on the public page. Archived, never deleted (TEN-06). | Must |
| SAL-03 | **Staff.** A staff member belongs to a company (and a home branch), optionally is a user (own calendar, sign-in at the till) and optionally an HR employee. They have a colour, a title, `accepts_online` and may be archived. A user is staff once. | Must |
| SAL-04 | **Working hours, breaks and time off.** Per staff, branch and ISO weekday: one or more work spans and break spans in the branch's local time; time off (leave, sick, training, other) with a start and an end. With HR active and the staff member linked to an employee, the planned roster shifts and approved leave of HR replace the weekly hours and the time off (HR is the source; breaks of the shift still apply). Without HR the staff calendar is the module's own. | Must |
| SAL-05 | **Resources.** A branch has resources (chairs, basins, rooms, beds) of a kind; a service with resources needs one free at its time. | Must |
| SAL-06 | **Availability.** For a branch, a date, services in order and optionally a staff member, the open start times on the company's slot grid (5 to 60 minutes): the staff member qualified, on duty, not on a break, not on time off, with no other booking (buffer included), and a free resource when the service needs one. "Any staff" picks the qualified member with the fewest bookings that day. | Must |
| SAL-07 | **Booking.** An appointment has a client (a customer party, or a guest name and phone for walk-ins and online), one or more lines (service, staff, resource, start, end, price snapshot) in sequence, a number (`SAL-` per branch, NUM-01), a source (`staff`, `walk_in`, `online`, `device`) and a status: `booked`, `checked_in`, `in_service`, `completed`, `paid`, `cancelled`, `no_show`. Booking outside the staff member's hours or on time off needs `salon.appointment.override` (audited, with a reason); a double booking is never allowed. | Must |
| SAL-08 | **No double booking under concurrency.** Booking, moving and seating a walk-in lock the staff and resource rows involved (in id order) in the transaction and re-check overlaps, so two requests for the same time give one booking and one `slot_taken` (409). The same check guards every path (staff, device, online, queue). | Must |
| SAL-09 | **Rebooking.** An appointment is moved to another time, staff or resource while `booked` or `checked_in`, by the same checks; the old time is freed in the same transaction; the move is audited and the client told. | Must |
| SAL-10 | **Cancellation and no-show fees.** A company sets `cancel_window_hours`, `late_cancel_fee_percent` and `no_show_fee_percent` (0 to 100) of the booked total. A cancellation inside the window and a no-show create a fee (`fee_minor`, status `due`); a cancellation outside it is free. A fee is waived (`salon.fee.waive`, with a reason, audited) or paid at the till (SAL-16). | Must |
| SAL-11 | **Deposits.** A company sets `deposit_percent` (0 to 100, default 0) and whether online bookings need it. A deposit is taken with the company's payment methods: cash, card, bank transfer, a typed mobile-money receipt, or an M-Pesa push through the core payment intents (reference type `salon.payment`), settled exactly once when the provider answers. An online booking that needs a deposit is held (`booked`, `deposit_pending`) for `hold_minutes` and released if unpaid. The deposit is applied at the till (`salon_credit`, SAL-16), kept against a fee, or refunded (`salon.deposit.refund`, within the refunder's `max_refund_amount`, RBAC-06). | Must |
| SAL-12 | **Walk-ins and the queue.** The front desk or a till adds a walk-in to the branch queue (name, optional phone, services wanted, preferred staff); entries are ordered by arrival with an estimated wait from the staff's next free time; seating one makes an appointment `checked_in` starting now with the chosen free staff member (SAL-08). An entry leaves the queue when seated or abandoned. | Must |
| SAL-13 | **Day flow.** Check-in (client arrived), start (service begins, `in_service`) and finish (`completed`, ready to pay) are recorded by the staff member, the receptionist or a manager, with the time and the person; from a till the person is proven (`actor_proof`, AUTH-07). | Must |
| SAL-14 | **Reminders.** A scheduled job sends each booked appointment a reminder `reminder_hours` before (default 24) and a confirmation when booked or moved, through the core notifier as transactional system messages to the client's contacts (email always; SMS and WhatsApp where the platform can send them, NOT-01). Once per appointment and kind, never marketing: they ignore marketing consent and carry no offers. A phone gets at most 20 salon messages a day. | Must |
| SAL-15 | **Online booking (optional).** A branch may turn on a public page (`salon-booking/{code}`, no account): services, open times and a booking form with name, a phone or an email, and the chosen slot. Rate-limited per address and per code; the tenant is found by the code through a security-definer function that returns only the id of an active tenant with the page on (ADR 002). With E-commerce active and a signed-in storefront customer's session the booking is tied to their customer account. | Should |
| SAL-16 | **Checkout through POS.** The till builds the sale from the appointment (the server's lines, not retyped): service and add-on lines at the price list price, product lines, a tip line (the settings' tip item), or the fee line (the settings' fee item) for a fee due. The sale carries `extensions.salon = {appointment_id, tips?: [{staff_id, amount_minor}], sellers?: [{line_id, staff_id}], fee?: true}`. On `SaleCompleted` the server links the sale to the appointment (`paid`), applies credit payments (SAL-17, SAL-18), makes commissions (SAL-21) and takes the tip (SAL-22). A void takes it all back. | Must |
| SAL-17 | **Deposit credit.** A `salon_credit` payment with `tender.source = deposit` is checked against the appointment's deposit balance (settled deposits less refunds and credit already applied) and the sale currency. On `SaleCompleted` it is applied once per payment under a row lock; a void or refund returns it to the balance. | Must |
| SAL-18 | **Packages and memberships.** A package type (a core service item that sells the package, N sessions, a price, optional validity in days, the services it covers) is sold as a normal POS sale of that item to a customer; on `SaleCompleted` a package of N sessions is made for the customer, once per sale line unit. Its prepaid value is the price paid; a session is worth the remaining value divided by the remaining sessions (the last takes the rest). A membership is a package type with a validity period. A session is redeemed at checkout by a `salon_credit` payment with `tender.source = package`; the server redeems under a row lock, once per sale payment, and a package out of sessions, expired, for another customer or not covering the service is kept as an exception and the sale stands (device wins). A void or full refund of the payment returns the session. | Must |
| SAL-19 | **Service products.** With Inventory active, when a service is finished (SAL-13) the products it uses leave stock at the branch's salon location (`StockWrittenOff`, source `salon.consumption`), once per appointment. Without Inventory nothing happens. | Should |
| SAL-20 | **Commission rules.** Effective-dated rows: for a staff member or all, for a service or all services, or for product sales; a percentage (0 to 100, four decimals) or a fixed amount per line. The most specific live rule on the sale date wins (staff and service, staff, service, default). A staff member without a rule earns nothing. | Must |
| SAL-21 | **Commission calculation.** When a salon sale is completed (so paid) each service line earns its staff member (from the appointment, never from the device) and each product line earns the seller named in `sellers` (else the appointment's first staff member), on the line's net amount after discounts and without tax. Entries are append-only. A void reverses every entry of the sale; a refund reverses the refunded share of each line; a reversal after export is carried as a negative entry into the next batch. | Must |
| SAL-22 | **Tips.** The tip line of a sale is split among the staff named in `tips` (their total at most the tip line); an unnamed tip goes to the appointment's first staff member. Tips are not commissionable and are reported per staff; a void reverses them. | Should |
| SAL-23 | **Commission review and approval.** A person with `salon.commission.build` builds a batch for a period from the entries not yet batched (a staff member whose net is zero or negative stays unbatched and is carried forward), submits it and a different person with `salon.commission.approve` approves or rejects it. **Never self-approved:** the builder, the submitter and anyone whose own commission is in the batch cannot approve it (`self_approval`); the check is on the server whatever the screen shows. Approval is audited. | Must |
| SAL-24 | **Export to payroll.** An approved batch is exported once (`salon.commission.export`): with Payroll active and the staff member linked to an HR employee, each payable amount becomes a one-off earning of the company's commission item in Payroll for the pay period date, through Payroll's `ExternalEarnings` contract (idempotent by batch and staff; the item's tax treatment is Payroll's setup, nothing is assumed); otherwise, or for staff without an employee, the batch is the report (JSON and CSV). Export never changes an approved amount. | Must |
| SAL-25 | **Client records.** A client profile keeps preferences, allergies and formula notes (private), a preferred staff member and the visit history (appointments, services, staff, sale, formula used). Allergies and formulas show only to `salon.client.notes` and are hidden by field rules (RBAC-05, resource `salon_client`: `allergies`, `formulas`, `contact`); changes are audited by field, never by value. Notes never go to the tills in the synced data; a flag says that notes exist. | Must |
| SAL-26 | **Privacy.** Clients, appointments and queue entries are registered with the privacy module (NFR-08): an access report lists them, a deletion clears names, contacts and notes and keeps the visit counts and sales. A guest who booked without an account (no party) has their name, phone and email cleared `guest_retention_days` (default 365) after the appointment. The public page asks only for a name and a phone or an email, shows no one else's data and stores nothing else about the visitor. | Must |
| SAL-27 | **Reports.** Per company, branch, staff and period: utilisation per staff (booked or served minutes over available minutes), revenue per service (net of paid lines), no-show rate, cancellations, commissions and tips per staff, and package liability (sessions and value outstanding per currency, expiring soon, deposits held). | Must |
| SAL-28 | **Permissions and roles.** `salon.resource.action`; role templates **Stylist** (own calendar, check-in, start, finish, clients with notes, sells at the till), **Receptionist** (the existing template: bookings, queue, deposits, clients without notes) and **Salon manager** (everything but settings and rules; approves commissions); the Branch manager holds `salon.*` except settings and commission rules; the Admin sets up. | Must |
| SAL-29 | **Offline.** The schedule is pulled for the device's branch (today and the next 7 days), with staff, services, resources, queue, clients' names and packages; check-in, start, finish, walk-ins and queue changes are pushed by `POST salon/device/sync` (idempotent, per-record results, `slot_taken` for a clash); the day can be viewed and worked with no connection for 7 days (NFR-04). | Must |
| SAL-30 | **Audit.** Create, update and archive of services, staff, hours, resources, rules and settings; bookings, moves, cancellations, no-shows, fee waivers; deposits and refunds; credit applied; batches, approvals, rejections and exports; client changes (fields only); guest data purges are audited (AUD-01, AUD-02). | Must |

## Permissions

| Permission | Allows |
| --- | --- |
| `salon.settings.view`, `.edit` | A company's slot grid, fees, deposits, reminders, tip and fee items |
| `salon.service.view`, `.manage` | See / set up the service menu and package types |
| `salon.staff.view`, `.manage` | See / set up staff, hours and time off |
| `salon.resource.view`, `.manage` | See / set up resources |
| `salon.online.view`, `.manage` | See / set up a branch's public booking page |
| `salon.appointment.view`, `.view_own` | Read every appointment at the place / only one's own as staff |
| `salon.appointment.create`, `.edit`, `.cancel` | Book, move, cancel and mark no-show |
| `salon.appointment.serve` | Check in, start and finish |
| `salon.appointment.override` | Book outside hours or on time off (with a reason) |
| `salon.queue.view`, `.manage` | See / run the walk-in queue |
| `salon.client.view`, `.manage`, `.notes` | See / edit client profiles; read and write allergies and formulas |
| `salon.package.view`, `.adjust` | See packages; correct sessions with a reason (a package is sold at the till, so there is no sell permission) |
| `salon.deposit.take`, `.refund` | Take a deposit; refund one |
| `salon.fee.waive` | Waive a cancellation or no-show fee |
| `salon.exception.view`, `.resolve` | See and close credit exceptions |
| `salon.commission.view`, `.view_own` | Read all commissions and tips / one's own |
| `salon.commission.rule` | Set commission rules |
| `salon.commission.build`, `.approve`, `.export` | Build and submit a batch; approve or reject; export |
| `salon.report.view` | Reports |

Selling and refunding at the till use core's `pos.sale.create`, `pos.sale.refund` and
the shift's `pos.shift.*`.

## API

Prefix `/api/v1`, every route behind `module:salon`. Amounts are digit strings
(minor units), times ISO 8601 UTC, dates `YYYY-MM-DD` in the branch's time
zone, hours `HH:MM` local. Lists are `{data: [...]}`, whole, unless noted
(appointments, clients and commission entries are paginated with `links` and
`meta`). Errors are `{message, code, errors?}`.

**Back office (people's tokens, checked at the place a record is at, RBAC-04).**

| Route | Notes |
| --- | --- |
| `GET/PUT salon/companies/{company}/settings` | `{slot_minutes, cancel_window_hours, late_cancel_fee_percent, no_show_fee_percent, deposit_percent, deposit_online_required, hold_minutes, reminder_hours, guest_retention_days, tip_item_id, fee_item_id, commission_item_code, payroll_period_day}` |
| `GET/POST salon/services`, `PUT salon/services/{id}`, `POST .../archive` | `{company_id, item_id, name?, category?, duration_minutes, buffer_minutes, kind: service\|addon, online_bookable, staff_ids, resource_ids, addon_ids, products: [{item_id, qty}]}` |
| `GET/POST salon/package-types`, `PUT salon/package-types/{id}`, `POST .../archive` | `{company_id, item_id, name, kind: package\|membership, sessions, validity_days?, service_ids}` |
| `GET/POST salon/staff`, `GET/PUT salon/staff/{id}`, `POST .../archive` | `{company_id, branch_id?, name, title?, color, user_id?, employee_id?, accepts_online}` |
| `GET/PUT salon/staff/{id}/hours` | `{hours: [{branch_id, weekday, kind: work\|break, start, end}]}` replaces the list |
| `GET/POST salon/staff/{id}/time-off`, `POST salon/time-off/{id}/cancel` | `{starts_at, ends_at, reason: leave\|sick\|training\|other, note?}` |
| `GET/POST salon/resources`, `PUT salon/resources/{id}`, `POST .../archive` | `{branch_id, name, kind}` |
| `GET/PUT salon/branches/{branch}/online` | `{enabled, public_name, lead_minutes, max_days_ahead, location_id}`, plus `public_code`, `url`; `POST .../regenerate-code` |
| `GET salon/availability` | `?branch_id&date&service_ids[]&staff_id?` returns `{data: {slots: [{starts_at, ends_at, lines: [{service_id, staff_id, resource_id, starts_at, ends_at}]}]}}` |
| `GET salon/calendar?branch_id&date&days?` | Every staff member's and resource's day: appointments, hours, breaks, time off |
| `GET/POST salon/appointments`, `GET salon/appointments/{id}` | Filters `branch_id`, `staff_id`, `client_id`, `status`, `from`, `to`, `source`; create `{branch_id, client_id?, guest_name?, guest_phone?, guest_email?, notes?, lines: [{service_id, staff_id, resource_id?, starts_at}], override_reason?}` (later lines follow the first) |
| `POST salon/appointments/{id}/move` | `{starts_at, lines?: [{id, staff_id?, resource_id?}], override_reason?}` (the lines keep their order and lengths after the first) |
| `POST salon/appointments/{id}/check-in\|start\|finish` | Day flow |
| `POST salon/appointments/{id}/cancel`, `.../no-show`, `.../waive-fee` | `{reason}` (waive needs a reason) |
| `PUT salon/appointments/{id}/notes` | `{service_notes}` (formula used, `salon.client.notes`) |
| `GET salon/appointments/{id}/checkout` | The lines the till rings: `{lines: [{item_id, name, qty, unit_price_minor, currency, staff_id, kind}], fee, deposit_balance_minor, packages}` |
| `POST salon/appointments/{id}/deposit`, `.../deposit/refund` | `{payment_method_id, amount_minor, mode: direct\|stk, phone?, reference?}`; payments listed in the appointment; `GET salon/payments/{id}/check` polls a push |
| `GET/POST salon/queue`, `POST salon/queue/{id}/seat\|leave` | `{branch_id, guest_name, guest_phone?, client_id?, service_ids, staff_id?}`; seat `{staff_id, resource_id?}` |
| `GET/POST salon/clients`, `GET/PUT salon/clients/{id}`, `GET salon/clients/{id}/visits` | `company_id` is optional on reads (the client's own, else the first company the reader may view); profile `{party_id, name, phone, email, preferred_staff_id, preferences, allergies, formulas, notes}` |
| `GET salon/packages`, `GET salon/packages/{id}`, `POST salon/packages/{id}/adjust` | `{customer_id, status}`; adjust `{sessions, reason}` |
| `GET salon/exceptions`, `POST salon/exceptions/{id}/resolve` | `{note}` |
| `GET/POST salon/commission-rules`, `PUT .../{id}`, `POST .../archive` | `{company_id, staff_id?, service_id?, applies_to: service\|product, kind: percent\|fixed, rate?, amount_minor?, currency?, effective_from, effective_to?}` |
| `GET salon/commissions` | Entries; filters `staff_id`, `from`, `to`, `batch_id`, `unbatched` |
| `GET/POST salon/commission-batches`, `GET .../{id}` | `{company_id, branch_id?, period_start, period_end, currency}` (with a branch, only that branch's entries are batched); the batch has `payouts` per staff and the entries |
| `POST salon/commission-batches/{id}/submit\|approve\|reject\|export` | reject needs `{reason}`; `GET .../{id}/csv` |
| `GET salon/tips` | Tips per staff |
| `GET salon/reports/utilisation\|services\|no-shows\|commissions\|liability` | `?company_id\|branch_id&from&to` |

**Public (no token, rate-limited).**

| Route | Notes |
| --- | --- |
| `GET salon-booking/{code}` | The branch's public name, services and staff who accept online bookings |
| `GET salon-booking/{code}/slots` | `?service_ids[]&date&staff_id?` |
| `POST salon-booking/{code}/appointments` | `{guest_name, guest_phone?, guest_email?, service_ids, staff_id?, starts_at, locale?}` (at least one contact); `Idempotency-Key` header |

**Device (device token; the place is the device's).**

| Route | Notes |
| --- | --- |
| `POST salon/device/sync` | `{appointments: [], events: [], queue: []}`, at most 100 in all; per-record results |
| Pull (core sync API) | `salon_settings`, `salon_staff`, `salon_services`, `salon_schedule`, `salon_queue`, `salon_packages` |

Device records: appointment (a walk-in or booking made at the till) `{id, user_id, actor_proof, client_id?, guest_name?, guest_phone?, notes?, lines: [{id, service_id, staff_id, resource_id?, starts_at}], source, created_at}`; event `{id, appointment_id, type: check_in\|start\|finish\|no_show\|cancel, user_id, actor_proof, at, reason?}`; queue `{id, user_id, actor_proof, action: join\|seat\|leave, guest_name?, guest_phone?, service_ids?, staff_id?, entry_id?, appointment_id?, at}`. A result is `{id, status: stored, ...}` or `{id, status: rejected, code, field?}`; the same record again answers the same.

## Decisions to confirm

1. The catalogue price is a placeholder (price to set).
2. Approval of commission batches is Salon's own step (builder, submitter and anyone paid in the batch are refused) and is not routed through the workflow engine.
3. Tips are reported per staff but not exported to payroll; paying them out is outside this module.
4. Accounting has no salon-specific postings: a deposit or package is a payment of the sale like any other, and deferring its revenue is not done. Package liability is reported by Salon.
5. Memberships are packages with a validity period; there is no recurring billing.
6. Commission items in Payroll (taxable or not, contributory or not) are set up by the company in Payroll; Salon only names the item code.
