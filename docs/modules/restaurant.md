# Restaurant and bar module spec

Source: concept note sections 3, 5 (POS row: kitchen display), 6.4 (restaurant
order flow); platform core spec (TEN-01, TEN-06, RBAC-01 to RBAC-08, NUM-02,
AUD-01, NFR-04, NFR-07); docs/modules/pos.md (the sale path, POS-01, POS-05,
POS-07, POS-09, POS-13); ADR 004 (offline sync). When this file and the core
spec disagree, the core spec wins.

## Scope

Restaurant is a module (`api/modules/Restaurant`, permissions `restaurant.*`,
tables `rest_*`), switched on per tenant (RBAC-08). It is the table, tab,
course and kitchen layer of the POS `restaurant` and `bar` modes. It is sold
with POS (registry `soldAs('restaurant', ['pos'])`, no separate price; owner to
confirm) and shown only to tenants whose `pos_mode` is `restaurant`, `bar` or
`hotel`.

- **An order is not a sale.** An order (table or tab) is open for hours or
  days, grows in rounds and may be paid in parts. When it is paid it becomes
  one or more **normal POS sales**, uploaded through `POST pos/sales`, so
  payments, M-Pesa, fiscal (eTIMS, DGI), Loyalty, Accounting and stock use
  the existing path. Restaurant never reads or writes POS tables; it links
  to a sale through the sale's `extensions.restaurant` and the
  `SaleCompleted` and `SaleVoided` events.
- **Recipe stock.** Selling a menu item backflushes its recipe when
  Manufacturing and Inventory are active (MFG-07): nothing here.
- **Money** is `bigint` minor units with a currency (ADR 003). Quantities are
  decimal strings. Ids are UUID v7; the device makes the id of an order, a
  line and a resend.
- **Offline.** The till keeps open orders on the device and sells for 7 days
  without a connection (NFR-04).

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| REST-01 | **Module gate.** Routes, permissions, sync sources and listeners exist only while the tenant has the module (RBAC-08); it works with only the core and POS. | Must |
| REST-02 | **Floor plan.** A location has areas (Terrace, Main hall) and tables with a name, seats, a position and shape on the plan, and a status. Stored status is manual (`free`, `reserved`, `cleaning`, `blocked`); the status shown is derived: `occupied` while an order is open on the table, `bill` once the bill was asked for. Areas and tables are archived, never deleted (TEN-06). | Must |
| REST-03 | **Table orders.** An order is opened on a table with covers (guests), a waiter, and lines. A table may carry more than one open order (two tills opened it offline); the second is accepted and flagged `table_shared`, never refused. | Must |
| REST-04 | **Bar tabs.** A tab is an order with a name or a customer and no table. It stays open across rounds and days until paid, and is listed on the tab view of every till at the location. | Must |
| REST-05 | **Waiters.** An order has one waiter. Moving a table or tab to another waiter (`restaurant.order.transfer`) is audited. A transfer by someone without the permission is kept (the device wins) and flagged `transfer_unauthorised`. | Must |
| REST-06 | **Lines, courses and seats.** A line has an item, quantity, unit price and tax as the till priced it, a course (1 to 9), an optional seat, a note and chosen modifiers. Lines are append-only by id; a line can be edited until it is sent. Courses are held until the waiter fires them (sends them). | Must |
| REST-07 | **Modifiers and notes.** A modifier group (Cooking, Extras) has min and max choices and modifiers with a price effect in minor units (0 or more) in the group's currency. Groups attach to items or categories. A line stores the modifiers as chosen (name and price at that moment); its price is (unit price + effects) × quantity. A note ("no onions") has no price. | Must |
| REST-08 | **Stations and routing.** A location has stations (Kitchen, Bar, Cold section) of kind `kitchen`, `bar` or `other`. An item category is routed to one station per location; items of unrouted categories go to the location's default station. | Must |
| REST-09 | **Tickets.** Sending lines creates one ticket per station and round with the table or tab, waiter, course, quantities, modifiers and notes. A ticket is `new`, `preparing`, `ready` or `served`. Kitchen staff set the first three (`restaurant.ticket.update`); waiters mark `served`. Ticket numbers restart each day per location. | Must |
| REST-10 | **Re-send.** A re-send of sent lines (lost print, kitchen asked) makes a new ticket marked `resend`; the original stays. A re-send is idempotent by its id. | Must |
| REST-11 | **Void after send.** Removing a sent line needs `pos.sale.void` held by the person within their `max_refund_amount` limit (RBAC-06) for the line's value, or a manager's override (AUTH-08), plus a reason. The line stays on the order marked void; a `void` ticket tells the station. Unsent lines are removed freely. Unproven approvals are applied and flagged (`actor_unverified`, `override_unverified`) for review; every void is audited (AUD-01). | Must |
| REST-12 | **Kitchen display.** A web screen per station lists active tickets (oldest first, age, course, modifiers), advances them (new, preparing, ready) and recalls them, refreshing every 5 seconds. Printing sends the same ticket to the station's printer by the till. | Must |
| REST-13 | **Service charge and tips.** A company sets a service charge percentage (0 to 30) and the core items that carry the service charge and the tip on a sale. The charge is computed on the net amount of the live lines (tax stripped from tax-inclusive prices, void lines out) and rounded half up to the minor unit; the sale line of the service item then takes that item's own tax, so the charge is taxable or not by the item's tax category. A tip is not revenue of the lines: it is a line of the tip item, never taxed, and is shared across split parts in proportion to their totals. | Must |
| REST-14 | **Split bill.** An order is paid whole, or split `item` (named groups of lines), `seat` (one part per seat, unseated lines shared equally) or `equal` (n parts). Parts add up exactly to the order: shares by largest remainder, equal parts take 1/n of every line's quantity with the last part taking the remainder. Each part is its own sale. | Must |
| REST-15 | **Merge tables.** Moving every live line of one open order to another (same location) and marking the first `merged`. Tickets follow. Done online by `restaurant.order.merge` or recorded by the till and applied on sync; idempotent. | Must |
| REST-16 | **Close into a sale.** The till rings each part as a normal sale with `extensions.restaurant = {order_id, mode, part, parts, final, line_ids, covers}`. On `SaleCompleted` the module records a settlement (one per sale, idempotent) and closes the order when the part is `final`. A voided sale reopens the order when no live settlement remains. | Must |
| REST-17 | **Offline.** Open orders, tabs and the floor plan are kept on the device and synced by `POST restaurant/orders/sync`: lines are unioned by id (never lost), header changes (table, waiter, covers, tab name) are last-writer-wins by the record's `updated_at`, sent marks and voids only move forward. A line for an order already closed is rejected `order_closed` (the till rings it as a new order). Sales stay append-only and idempotent (POS-09). | Must |
| REST-18 | **Reports.** Per company, branch or location and period: covers, orders, sales and tips per currency, average spend per cover and per order, average table turn time (open to close), sales per waiter, and voids after send (count and value, by person and reason). | Must |
| REST-19 | **Permissions and roles.** `restaurant.resource.action`; role templates **Waiter** (orders, floor view, tickets view, sells), **Kitchen** (tickets, stations view) and **Bartender** (orders, tabs, tickets); the Branch Manager holds `restaurant.*`; the Admin sets up the floor, stations, modifiers and service charge. | Must |
| REST-20 | **Audit.** Create, update and archive of areas, tables, stations, modifier groups and settings, and every transfer, merge, void after send, re-send and closing is audited (AUD-01, AUD-02). | Must |

## Permissions

| Permission | Allows |
| --- | --- |
| `restaurant.floor.view`, `restaurant.floor.manage` | See / set up areas and tables, set a table's manual status |
| `restaurant.station.view`, `restaurant.station.manage` | See / set up stations and routing |
| `restaurant.modifier.view`, `restaurant.modifier.manage` | See / set up modifier groups |
| `restaurant.order.view`, `.create`, `.edit` | Read orders; open and add to orders; change covers, table, tab name |
| `restaurant.order.send` | Send, fire courses and re-send |
| `restaurant.order.transfer`, `restaurant.order.merge` | Move to another waiter; merge tables |
| `restaurant.order.close` | Bill, split and close |
| `restaurant.ticket.view`, `restaurant.ticket.update` | See tickets on the display; change their status |
| `restaurant.settings.edit` | A company's service charge and tip items |
| `restaurant.report.view` | Reports |

Voiding a sent line uses core's `pos.sale.void` (REST-11).

## API

Prefix `/api/v1`, every route behind `module:restaurant`. Amounts are digit
strings, times ISO 8601 UTC.

**Back office (people's tokens, checked at the location, RBAC-04).**

| Route | Notes |
| --- | --- |
| `GET/POST restaurant/areas`, `PUT restaurant/areas/{id}`, `POST restaurant/areas/{id}/archive` | `{location_id, name, sort}` |
| `GET restaurant/tables?location_id`, `POST restaurant/tables`, `PUT restaurant/tables/{id}`, `POST .../archive`, `POST .../status` | `{area_id, name, seats, x, y, width, height, shape: square\|round\|rect}`; `GET` returns the derived `status` and the open order's summary |
| `GET/POST restaurant/stations`, `PUT restaurant/stations/{id}`, `POST .../archive` | `{location_id, name, kind, printer?, is_default, sort, category_ids: []}` (the list replaces the routes) |
| `GET/POST restaurant/modifier-groups`, `PUT .../{id}`, `POST .../archive` | `{company_id, name, min_select, max_select, currency, item_ids, category_ids, modifiers: [{id?, name, price_minor, sort}]}` |
| `GET/PUT restaurant/companies/{company}/settings` | `{service_charge_percent, service_charge_item_id, tip_item_id, tip_presets: [5, 10]}` |
| `GET restaurant/orders`, `GET restaurant/orders/{id}` | Filters `location_id`, `status`, `kind`, `waiter_id`, `table_id`, `search`; detail has lines, tickets, settlements, bill |
| `POST restaurant/orders/{id}/transfer` | `{waiter_id}` |
| `POST restaurant/orders/{id}/merge` | `{into_order_id}` |
| `POST restaurant/orders/{id}/lines/{line}/void` | `{reason, override?}` |
| `POST restaurant/orders/{id}/cancel` | `{reason}`; only an order with nothing sent or paid |
| `POST restaurant/orders/{id}/split` | Preview only: `{mode: full\|item\|seat\|equal, parts?, groups?: [[line_id]], tip_minor?, tip_percent?}` returns the parts |
| `GET restaurant/tickets?location_id&station_id&status=active\|all&since` | Kitchen display feed |
| `POST restaurant/tickets/{id}/status` | `{status: new\|preparing\|ready\|served}` |
| `GET restaurant/reports/summary?from&to&company_id\|branch_id\|location_id` | REST-18 |

**Till (device token; place from the device).**

| Route | Notes |
| --- | --- |
| `POST restaurant/orders/sync` | `{orders: [order record]}`, at most 25; one result per order |
| `POST restaurant/device/tickets/{id}/status` | `{status, user_id}` (a waiter marks served) |
| Pull (core sync API) | `restaurant_tables`, `restaurant_stations`, `restaurant_modifiers`, `restaurant_settings` and `restaurant_orders` (open orders of the location with lines and ticket states) |

An order record: `{id, kind: table|tab, table_id?, tab_name?, customer_id?, waiter_id, covers, currency, opened_at, updated_at, updated_by, note?, bill_requested_at?, status?: open|cancelled, merged_into_id?, lines: [{id, item_id, name, qty, unit_price_minor, tax_code_id?, tax_rate?, tax_inclusive, course, seat?, note?, modifiers: [{id, name, price_minor}], created_at, round?, sent_at?, sent_by?, removed?: {at, by, reason, override?, actor_proof?}}], resends: [{id, line_ids, at, by}], actor_proof?}`. A result is `{id, status: stored, rev, flags: [], lines: [{id, status: stored|rejected, error?}]}` or `{id, status: rejected, error}`; a resend of the same content answers the same.

Errors: `table_unknown`, `order_closed`, `waiter_unknown`, `line_changed` (a sent line's item, quantity or price differs), `void_not_permitted`, `void_limit_exceeded`, `override_required`, `station_unknown`, `ticket_status_invalid`, `split_invalid`.

## Closing into a sale (device)

The till builds each part from the order's lines (unit price plus modifier effects; service charge and tip as lines of the settings' items) and uploads it as a normal sale with `extensions.restaurant`. The same rules are in `api/modules/Restaurant/Billing` (PHP) and `pos/src/restaurant/bill.js` (JS) with shared fixtures, so the till and server agree to the unit.

## Deferred

Reservations and waiting lists, tablet self-ordering by QR, happy-hour rules (Loyalty promotions apply on the sale), room charges (hotel mode), item availability (86 list), tip pooling and payout, and course timing alerts.
