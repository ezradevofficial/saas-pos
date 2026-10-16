# E-commerce and delivery module spec (phase 7, stage 7D)

Source: concept note sections 3 (fulfilment: delivery and pickup; route
and van sales collect cash), 5 (E-commerce and delivery row: online store
synced with inventory, WhatsApp ordering, delivery orders, rider
assignment and tracking, cash on delivery; works best with POS and
Inventory) and 7.1 (M-Pesa STK push, cards through an aggregator, cash,
vouchers and points); platform core spec (TEN-01, TEN-06, AUTH-01,
AUTH-10, RBAC-01 to RBAC-08, MD-01 to MD-04, CUR-04, NUM-01, TPL-04,
BR-01, BR-04, BR-05, NOT-01, NOT-02, NOT-06, AUD-01, EXP-01, L10N-01,
L10N-02, NFR-04, NFR-06); ADR 002 (RLS), ADR 003 (money), ADR 004
(offline sync), ADR 009 (notifications); the module specs of POS,
Inventory, Loyalty, Accounting and CRM. When this file and the core spec
disagree, the core spec wins, and the conflict is flagged.

## Scope

E-commerce is a module (`api/modules/Ecommerce`, permissions
`ecommerce.*`, catalogue key `ecommerce` from phase 6, sold per online
store), switched on per tenant (RBAC-08). It works with only the core
installed: the catalogue is chosen from core items, prices come from core
price lists, tax from core tax codes, customers are core parties, payments
go through the core payment adapters and messages through the core
notification service. It reads no other module's tables. When other
modules are active it reaches them through events and small read
contracts those modules own:

- **Inventory**: stock on hand per location
  (`Inventory\Contracts\StockLevels`); `OrderFulfilled` moves stock out of
  the fulfilment location and `OrderReturned` brings it back.
- **Accounting**: `EcommercePosting` posts `ecommerce.order`,
  `ecommerce.return` and `ecommerce.cod_cashup` through the posting rules.
- **Loyalty**: promotions, coupons, points and gift cards through
  `Loyalty\Contracts\OnlineLoyalty`.
- **CRM**: the customer 360 view reads orders through
  `Ecommerce\Contracts\CustomerOrders`.
- **HR**: a rider may be linked to an employee (`HR\Contracts\EmployeeDirectory`).
- **Fiscal** (core): delivered orders and returns are fiscal documents of
  source `ecommerce` in the core queue (KRA eTIMS, DRC DGI).

Five parts:

1. **Online store**: a public storefront on the tenant's subdomain or
   verified custom domain at `/shop/{store}`, in the tenant's theme, en/fr.
2. **Cart and checkout**: guest or signed-in customer, delivery or pickup,
   zones and fees, M-Pesa, card, cash on delivery, points and gift cards,
   totals computed on the server only.
3. **Orders**: a status flow, stock reservations with expiry, fulfilment
   from a location, numbering, customer notifications and a tracking link.
4. **WhatsApp ordering**: share links, an inbound message inbox through a
   pluggable provider, conversion of a chat into an order.
5. **Delivery**: riders, assignment, a rider screen in the mobile app with
   an offline status queue, proof of delivery, last known location, cash
   on delivery and rider cash-ups.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| ECOM-01 | **Stores.** A store belongs to a company and a branch (where its orders are numbered, taxed and reported) and has a handle (lowercase letters, digits and dashes, unique in the tenant), a name, a status (`draft`, `live`, `paused`), a currency (active, CUR-01) and a core price list in that currency (tax inclusive or exclusive per the list, MD-03), a default fulfilment location of the company, pickup locations, whether delivery and pickup are offered, a minimum order, the reservation time (minutes an unpaid order holds stock, 30 by default), a safety buffer (units kept back from what is shown and sold, 0 by default), contact phone and email, a WhatsApp number, an optional tax code for delivery fees, and its payment methods. Archived, never deleted. Audited. | Must |
| ECOM-02 | **Catalogue from core items.** A store lists products chosen from the company's or shared core items (active, not kits): published or not, position, a URL slug (unique per store), a description (plain text, up to 5,000 characters), whether stock is tracked, a manual `in_stock` flag, featured. Names, categories, units, barcodes and images are the core item's (MD-02); the storefront shows the item's images. A product of an archived item is hidden. | Must |
| ECOM-03 | **Online prices from core.** The price is the store's price list price for the item's base unit on the day (PriceResolver, quantity breaks included), never typed in the module. A product without a list price, whose item has no tax code for its category, or whose code has no confirmed rate ("Rate needed") is shown unavailable with the reason and cannot be ordered (`price_missing`, `tax_code_missing`, `tax_rate_missing`). Rates are never invented. | Must |
| ECOM-04 | **Availability.** With Inventory active and stock tracked: available = stock on hand at the store's fulfilment location (Inventory's read contract) − active reservations − the safety buffer, never below zero, shown as "In stock", "Only N left" (at most 5) or "Out of stock". Without Inventory, or for an untracked product or a non-stock item: the manual `in_stock` flag. An order never takes more than is available (`out_of_stock`, naming the product). | Must |
| ECOM-05 | **Storefront.** A separate public route group of the web app (`/shop/{store}`: home with featured products and categories, category, product, cart, checkout, order tracking, account), served on the tenant's subdomain or verified custom domain (BR-04, BR-05), in the tenant theme (BR-01; logo and colours from the published theme), in English and French (L10N-01). A store that is not `live`, of a tenant without the module or not active, answers not found. | Must |
| ECOM-06 | **Storefront tenant resolution.** The public API names the store by host and handle (`X-Shop-Host` header, else the request's `Origin` host). A security-definer function returns only the tenant id of the active tenant whose slug or verified domain the host names and that has a live, not archived store with that handle (ADR 002); every query then runs under that tenant's row-level security. A host of tenant A with a handle of tenant B answers not found. | Must |
| ECOM-07 | **Storefront security.** Strict Content-Security-Policy and no Referer on storefront pages (as the supplier portal); API answers carry `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` and `Cache-Control: no-store` on customer data. The storefront never reads or sends a back-office token: its own client keeps only the customer session (sessionStorage); the shop API refuses any bearer token that is not a customer session (`customer_session_invalid`). Every route is rate-limited per IP (reads, checkout, sign-in codes per contact). No cookies are used, so checkout is not exposed to CSRF; the idempotency key and the server's recomputation make replays harmless. | Must |
| ECOM-08 | **Customer accounts.** An online customer (separate from tenant users) per company: name, phone, email, a link to a core party with the customer role (created on the first signed-in order, or matched by phone or email, MD-01), marketing consent, last sign-in. A customer exists only for a contact proven by a sign-in code and is found by that contact alone (decision 11). Sign-in by a 6-digit code by SMS or email, with core's OTP rules (valid 30 minutes, single use, 5 attempts, 5 sends an hour, 60 seconds between sends, codes kept as an HMAC), a per-store cap of codes an hour, a limiter of its own for code checks, and after a few codes from one address a proof-of-work challenge answered by the browser (decision 13); a session token (`ecs_` + 48 characters, stored as sha256, 30 days sliding, revocable). The account shows the customer's orders and saved addresses. Guests check out with a name and phone; a guest order names no customer and no party. | Must |
| ECOM-09 | **Cart quote on the server.** `POST shop/{store}/quote` prices a cart (products and quantities, fulfilment, zone, coupon, points, gift card): every price, discount, tax, fee and total is computed by the server; nothing the client sends about money is used. | Must |
| ECOM-10 | **Delivery zones and fees.** Per store: zones (name, areas as plain names, fee in minor units of the store currency, free above an order value, minimum order, estimated time text, active, position). Delivery needs a zone; pickup needs one of the store's pickup locations and has no fee. The fee is taxed with the store's delivery tax code when set (core calculator, the price list's inclusive flag), else carries no tax. | Must |
| ECOM-11 | **Line and total maths (ADR 003).** gross = round_half_up(qty × unit price); promotion discounts from Loyalty per line; amount = gross − discount; tax per line from the item's tax category → the company's tax code, rate at the order time in the company's time zone, inclusive or exclusive; delivery fee with its tax; totals: subtotal = Σ gross, discount = Σ discount, tax = Σ line tax + fee tax, delivery = fee (net of tax when exclusive), total = Σ line totals + fee total; paid by points and gift cards first, the rest by the chosen method. Minor units of the store currency, never floats; base-currency amounts and the rate used are stored at placement (CUR-04). Quantities are whole numbers for `count` units, else up to 3 decimals. | Must |
| ECOM-12 | **Tampered totals are refused.** An order carries the total the customer saw (`expected_total_minor`); when the server's total differs (a price, stock, promotion or rate changed, or the client was tampered with), placement is refused with `total_changed` and the new quote, and nothing is stored. | Must |
| ECOM-13 | **Idempotent placement.** `POST shop/{store}/orders` requires an `Idempotency-Key` header (16 to 100 characters). The same key with the same body answers the same order (200); the same key with another body is refused (`idempotency_mismatch`, 409); keys are kept per store. | Must |
| ECOM-14 | **Stock reservations with expiry.** Placing an order reserves each tracked product's base quantity at the fulfilment location, under an advisory lock per location and item taken in a fixed order, so two orders can never take the last unit. An order waiting for an online payment holds its reservation for the store's reservation time; past it, `ecommerce:expire` cancels the order (`payment_timeout`) and releases the stock. A paid or cash on delivery order holds its reservation until it is fulfilled (consumed) or cancelled (released). | Must |
| ECOM-15 | **Payments.** A store offers any of: `mpesa` (STK push through the core Daraja adapter, KES, whole units), `card` (the core hosted checkout adapter: the customer is sent to the provider's page and back), `cod` (cash on delivery, or at pickup), plus Loyalty's `points` and `gift_card` when Loyalty is active. Each maps to a core payment method of the company of the right type (MD-04). Online payments are core payment intents of reference type `ecommerce.order`; the result arrives through the core callback and `PaymentIntentSettled`, applied to the order once under its row lock: a repeated or late callback changes nothing; an amount that differs is not taken as paid (`amount_mismatch`). A failed or cancelled payment can be retried from the tracking page until the reservation expires. | Must |
| ECOM-16 | **Order statuses.** `placed` → `accepted` → `picking` → `ready` (pickup) or `out_for_delivery` (delivery) → `delivered`; `cancelled` from any status before dispatch; `returned` from `out_for_delivery` (a failed delivery) or `delivered` (a return). The payment status is separate: `pending`, `paid`, `cod`, `cod_collected`, `failed`, `refund_due`, `refunded`. Accepting needs `paid` or `cod`. Each change records who, when and a note in the order's history and is audited (AUD-01). | Must |
| ECOM-17 | **Order numbering (NUM-01).** Numbered at placement with `ecommerce.order` (`WEB-{BRANCH}-{YYYY}-{00001}`, yearly), in the placing transaction. | Must |
| ECOM-18 | **Fulfilment.** An order is fulfilled from its location: handing it to the rider (`out_for_delivery`) or to the customer at pickup (`delivered`) consumes its reservations and raises `OrderFulfilled` (lines with base quantities), on which Inventory moves the stock out (`ecommerce.order`, kind `sale`); a return raises `OrderReturned` and Inventory brings back what went out, at its cost. Each once. | Must |
| ECOM-19 | **Cancel and refund.** Cancelling (`ecommerce.order.cancel`, a reason) releases reservations and reverses Loyalty redemptions; a paid online payment becomes `refund_due`, refunded through the core payout (`ecommerce.order.refund`, within `max_refund_amount`, RBAC-06), or marked refunded by hand with a reference. | Must |
| ECOM-20 | **Customer notifications (NOT-01, NOT-02).** Order placed, paid, accepted, ready for pickup, out for delivery (with the delivery code), delivered and cancelled are sent to the customer's phone (SMS) and email through the core notifier (system event types to a contact address, ADR 009), with the tracking link. Delivery is tracked per message (NOT-06). | Must |
| ECOM-21 | **Tracking link.** Each order has a tracking token (40 characters, stored as sha256); `/shop/{store}/orders/{number}?t=…` shows the status, its history, the rider's first name and the time of the last location report, never another order. | Must |
| ECOM-22 | **Back-office orders.** List (search, filters: status, payment status, channel, fulfilment, store, from, to; sort; export EXP-01), detail (lines, totals, payments, history, delivery), actions (accept, pick, ready, cancel, refund, mark returned), and a phone or WhatsApp order created by staff (`ecommerce.order.create`, channel `phone` or `whatsapp`) through the same pricing, reservation and numbering. Scoped by the fulfilment location (RBAC-04). | Must |
| ECOM-23 | **Share links.** A store and each product have a share URL and a WhatsApp share link (`https://wa.me/?text=…`, and `https://wa.me/{number}?text=…` to the store's number). | Must |
| ECOM-24 | **WhatsApp inbound (pluggable provider).** A store's inbound webhook (`POST shop/whatsapp/{token}`, a 40-character token stored as sha256, its tenant found by a security-definer function) hands the request to the configured `WhatsAppProvider` driver, which verifies it and returns messages; each message is stored once (by the provider's message id) in a conversation per store and phone. Only a `fake` driver ships; real credentials are owner input. | Must |
| ECOM-25 | **WhatsApp inbox.** Conversations (open, converted, closed) with their messages; staff reply through the provider (`ecommerce.whatsapp.reply`) and convert a chat into an order (ECOM-22), linking the conversation to it. | Must |
| ECOM-26 | **Riders.** A rider is an active user of the tenant (to sign in to the mobile app) at a company, optionally linked to an HR employee when HR is active (read contract); phone, vehicle note, active, archived. | Must |
| ECOM-27 | **Deliveries and assignment.** A delivery order is created when a delivery order is accepted; it is assigned (and reassigned until picked up) to an active rider of the company (`ecommerce.delivery.assign`). Delivery statuses: `unassigned`, `assigned`, `picked_up`, `delivered`, `failed`. A board shows deliveries by status. | Must |
| ECOM-28 | **Rider screen (mobile app).** The rider signs in with their user account and sees their assigned deliveries (customer first name, phone, address, amount to collect), marks picked up, delivered or failed, reports their location, and takes proof of delivery: the customer's 6-digit delivery code (sent with the out-for-delivery message, checked by the server) or a photo. Status updates are queued on the phone and uploaded when online, idempotently by their UUID v7 (made on the phone); a code refused on upload leaves the delivery out for delivery and shows the reason. 48px targets; en/fr. | Must |
| ECOM-29 | **Live tracking.** Status plus the last reported location (latitude, longitude, time) on the delivery; no maps provider is called (a `TrackingMap` hook, none by default). | Must |
| ECOM-30 | **Cash on delivery and rider cash-up.** A rider marks a COD order delivered with the amount collected (it must equal the amount due, else the delivery is flagged `cod_short` or `cod_over`). The cash is the person's who confirmed the delivery (`collected_by`): the rider's, or the staff member's who confirmed it in the back office, which never goes into the rider's cash-up (decision 12). A cash-up (`ecommerce.cashup.manage`) takes a rider's collected, not cashed-up deliveries in one currency, the counted cash, and computes expected, counted and variance; once closed it is final. `CodCashedUp` posts it when Accounting is active. | Must |
| ECOM-31 | **Inventory integration.** ECOM-04 and ECOM-18 only while Inventory is active; otherwise availability is the manual flag and nothing moves. | Must |
| ECOM-32 | **Accounting integration.** While Accounting is active: a delivered order posts `ecommerce.order` (fields `tender.{payment method}`, `cod`, `revenue.{item category}`, `promotion`, `delivery`, `tax.{tax code}`; Σ debits = Σ credits exactly); a return posts `ecommerce.return` (the order rule with its sides swapped unless it has its own); a cash-up posts `ecommerce.cod_cashup` (`cash` debit, `cod` credit, `shortage` debit, `overage` credit). Default rules come from the country charts (KE, CD), with a COD clearing account; the stock moved posts cost of sales through Inventory (`ecommerce.order`, `ecommerce.return` as `inventory.cost_of_sales`). | Must |
| ECOM-33 | **Loyalty integration.** While Loyalty is active: promotions and coupons are evaluated by Loyalty's engine on every quote and placement (versions in force at the order time); points and gift cards pay part or all of an order, redeemed when it is placed and given back when it is cancelled or returned; a delivered order earns points for a customer with a party; a return reverses them. | Must |
| ECOM-34 | **CRM integration.** `Ecommerce\Contracts\CustomerOrders` answers a customer's orders (count, totals per currency, recent orders) for a reader holding `ecommerce.order.view`; the CRM customer 360 view shows them while E-commerce is active. | Must |
| ECOM-35 | **Fiscalisation.** A delivered order is queued as a `sale` and a returned delivered order as a `refund` of source `ecommerce` in the core fiscal queue (KRA eTIMS, DRC DGI) when the company has fiscal submission on. | Must |
| ECOM-36 | **Reports.** Per store and period: orders, revenue and average order per currency, by channel, payment method and status, top products, deliveries on time and failed, COD outstanding per rider. | Must |
| ECOM-37 | **Events.** `OrderPlaced`, `OrderPaid`, `OrderStatusChanged`, `OrderFulfilled`, `OrderReturned`, `OrderCompleted` (delivered), `CodCashedUp`, `EcommercePosting`, dispatched after commit, ids and amounts only. | Must |
| ECOM-38 | **Isolation and audit.** Every table has `tenant_id` and forced row-level security and rows in both tenants of the isolation suite; every create, change, status change, cancellation, refund, assignment and cash-up is audited (AUD-01). | Must |
| ECOM-39 | **Languages.** Every string in English and French (L10N-02), in the API, the web app and the mobile app. | Must |

## Decisions

1. **The storefront is a route group of the web app**, not a second
   build: `/shop/{store}/…` under its own layout (no sidebar, the host's
   brand, strict CSP and no Referer, as the supplier portal), with its own
   API client that never touches the back-office token. A separate entry
   can be split out later if its bundle needs to shrink.
2. **Host, then handle.** One tenant may run several stores (one per
   company or brand), so the URL names the store; the host names the
   tenant. The host is not a credential (anyone may send any host), so it
   only chooses which tenant's public catalogue is read.
3. **Customer sign-in codes reuse core's OTP rules, not its table.**
   `verification_challenges` belongs to users (a not-null user, a fixed
   set of purposes); customers are not users. `ecom_customer_codes` keeps
   the same code length, HMAC, lifetime, attempt and send limits under the
   tenant's RLS (the tenant is known from the host). Flagged: the core
   challenge service could take non-user subjects later.
4. **WhatsApp is a conversation channel, notifications are SMS and
   email.** Core contact addresses (ADR 009) take email and SMS only, so
   order notifications go through the notifier by SMS and email; replies
   in a WhatsApp chat go through the module's provider interface.
   Flagged for the core: a WhatsApp contact address.
5. **Revenue is recognised on delivery.** `ecommerce.order` posts when the
   order is delivered (collected at pickup or by the rider), with every
   tender of the order; cash on delivery is debited to a COD clearing
   account until the rider's cash-up moves it to cash. A paid order
   cancelled before delivery posts nothing (its refund goes back through
   the provider).
6. **Stock leaves at hand-over**, not at placement: a reservation holds it
   until then, so an order cancelled before dispatch never touches the
   ledger.
7. **Riders are users.** The rider screen signs in with the rider's own
   user account (a user token), so every update names a person; the
   rider template (`ecommerce.delivery.deliver`, own deliveries only) is a
   new system template (RBAC-03 does not name it: flagged for the spec).
8. **Server-side money only.** The client sends products, quantities and
   choices; prices, promotions, tax, fees and totals are computed again at
   placement inside the transaction, and a different total is refused
   (ECOM-12).
9. **Delivery codes are checked on the server.** The phone never holds a
   code or its hash, so a lost phone cannot replay them; an offline rider
   queues the code and learns the result on upload.
10. **One return per order.** A return takes the whole order back (lines,
    tax, promotion; the delivery fee only when the user says so); partial
    returns are deferred.
11. **A contact joins a customer only through its code.** A guest order
    (and a phone or WhatsApp order placed by staff) names no customer and
    no party, and never adds a phone or an email to a customer: otherwise
    a guest could type their phone beside someone else's email and sign
    in to that account by SMS. Party-bound loyalty (points, member
    promotions, store credit) needs the customer's session and uses its
    party; a gift card stays usable by guests (it is a bearer code, its
    failed lookups limited per address and per store, and only the amount
    applied is shown).
12. **Staff-confirmed cash on delivery is the staff member's.** When staff
    confirm a COD delivery in the back office, the delivery records them
    as `collected_by`, and the rider's cash-up leaves it out; a rider's
    own confirmation is theirs to hand in.
13. **Paid once, never oversold.** A payment retry waits while the
    previous push is pending or unknown, and no payment starts after the
    reservation's time; money beyond the order (a second settlement, a
    wrong amount) is held on its payment as `refund_due`, flagged on the
    order (`overpaid`, `amount_mismatch`), never posted, and paid back by
    the refund action (status `refunded_excess`). A payment landing after
    the reservation lapsed takes the stock locks and re-checks what is
    left: short, the order becomes `refund_due` with `stock_short`.
    Coupons are counted atomically (`uses < max_uses`) and given back on
    cancel or expiry. Refunds keep to the user's `max_refund_amount`
    (RBAC-06). Sign-in codes cost money, so after
    `ecommerce.shop.challenge_after` codes from one address in an hour the
    next one first needs a proof of work (sha256 of `nonce:solution`
    starting with N zeros), checked on the server with no third party.

## Data model

Every table has `tenant_id` (RLS), UUID v7 keys, `created_at` and
`updated_at`. Money is `bigint` minor units with a currency; rates
`numeric(18,8)` in an FX snapshot; quantities `numeric(20,6)`.

| Table | Columns |
| --- | --- |
| `ecom_stores` | company, branch, location, handle (unique per tenant), name, status, currency, price list, delivery and pickup enabled, pickup location ids, minimum order minor, reservation minutes, safety buffer, contact phone and email, WhatsApp number, WhatsApp token hash (unique), delivery tax code, payment methods (`[{kind, payment_method_id}]`), archived |
| `ecom_products` | store, item, slug, description, published, featured, position, track stock, in stock, archived |
| `ecom_zones` | store, name, areas, fee minor, free over minor, minimum order minor, eta, active, position, archived |
| `ecom_customers` | company, party, name, phone, email, consent, last signed in at, archived |
| `ecom_customer_codes` | customer (nullable until first order), contact, channel, code hash, attempts, expires at, consumed at |
| `ecom_customer_sessions` | customer, token hash (unique), last used at, expires at, revoked at |
| `ecom_addresses` | customer, label, zone, area, address line, notes, archived |
| `ecom_orders` | store, company, branch, location, number, channel (`web`, `phone`, `whatsapp`), customer, party, contact name, phone and email, fulfilment (`delivery`, `pickup`), zone, address, status, payment status, payment kind, currency, subtotal, discount, tax, delivery fee, delivery tax, total, paid by loyalty, due minor, base currency, `fx`, base total, coupon, promotions, idempotency key and body hash (unique per store), tracking token hash, delivery code hash, reserved until, placed, paid, accepted, picking, ready, dispatched, delivered, cancelled (at, by, reason), returned (at, by, reason), refund reference, conversation, created by, notes, flags |
| `ecom_order_lines` | order, line no, product, item, uom, name, qty, qty base, unit price, gross, discount, net, tax, total minor, tax code, tax rate, tax inclusive, promotion |
| `ecom_reservations` | order, item, location, qty base, expires at, released at, consumed at |
| `ecom_payments` | order, kind, payment method, payment intent, currency, amount, status, provider reference, collected by, cash-up |
| `ecom_order_events` | order, from, to, payment status, note, by user, at |
| `ecom_riders` | company, user (unique per company), employee, name, phone, vehicle, active, archived |
| `ecom_deliveries` | order (unique), rider, status, assigned at, picked up at, delivered at, failed at, reason, proof kind, proof photo path, last latitude, longitude, located at, COD due, COD collected, collected at, cash-up, flags |
| `ecom_delivery_updates` | id from the phone (unique), delivery, rider, kind (`status`, `location`), status, latitude, longitude, occurred at, received at, outcome, error |
| `ecom_cashups` | company, rider, currency, expected, counted, variance minor, deliveries count, note, closed by, closed at |
| `ecom_conversations` | store, phone, name, status, order, last message at |
| `ecom_messages` | conversation, direction, body, provider message id (unique per store), sent by, at, status |

## Events

| Event | When | Payload |
| --- | --- | --- |
| `OrderPlaced` | after commit | tenant, order, store, company, number, channel, currency, total |
| `OrderPaid` | after commit | tenant, order, payment, amount |
| `OrderStatusChanged` | after commit | tenant, order, from, to |
| `OrderFulfilled` | after commit | tenant, order, number, company, branch, location, at, user, lines `[{line_id, item_id, uom_id, qty, qty_base}]` |
| `OrderReturned` | after commit | tenant, order, number, company, location, at, user, delivered (bool) |
| `OrderCompleted` | after commit | tenant, order, company (Loyalty earn, fiscal) |
| `CodCashedUp` | after commit | tenant, cash-up, company, rider, currency, expected, counted |
| `EcommercePosting` | after commit | tenant, company, source type, posting id, date, currency, amounts, fx, dimensions, reference, link |

Consumed: `PaymentIntentSettled` (reference type `ecommerce.order`).

## Read contracts in other modules

| Contract | Module | Answers |
| --- | --- | --- |
| `Modules\Inventory\Contracts\StockLevels::onHand(locationId, itemIds)` | Inventory | base quantity on hand per item at a location (decimal strings), only while Inventory is active (else null) |
| `Modules\Loyalty\Contracts\OnlineLoyalty` | Loyalty | `promotions(...)` (the engine's discounts per line), `redeemPoints`, `redeemGiftCard`, `giveBack`, `earn`, `reverseEarn`, idempotent by order id; null or refused when Loyalty is off |
| `Modules\HR\Contracts\EmployeeDirectory` | HR | the rider's employee (existing) |
| `Modules\Ecommerce\Contracts\CustomerOrders::forCustomer(User, partyId, limit)` | E-commerce | for CRM: orders the reader sees with `ecommerce.order.view` |

## Permissions

| Permission | What it allows |
| --- | --- |
| `ecommerce.store.view`, `.manage` | Read stores, products, zones; set them up |
| `ecommerce.order.view`, `.create`, `.manage`, `.cancel`, `.refund` | Read orders; create phone and WhatsApp orders; accept, pick, ready, hand over, mark returned; cancel; refund within `max_refund_amount` |
| `ecommerce.delivery.view`, `.assign`, `.deliver` | The delivery board; assign riders; act as a rider (own deliveries only) |
| `ecommerce.rider.manage` | Riders |
| `ecommerce.cashup.view`, `.manage` | Rider cash-ups |
| `ecommerce.whatsapp.view`, `.reply` | The WhatsApp inbox; reply |
| `ecommerce.customer.view` | Online customers |
| `ecommerce.report.view` | Reports |

Role templates (RBAC-03): **Owner** everything; **Admin**
`ecommerce.*.view`, `ecommerce.store.manage`, `ecommerce.rider.manage`;
**Branch Manager** `ecommerce.*` except `ecommerce.delivery.deliver`;
**Cashier** `ecommerce.order.view`, `ecommerce.order.manage`,
`ecommerce.order.create`, `ecommerce.whatsapp.*` (packing and answering at
the outlet); **Accountant** `ecommerce.order.view`, `ecommerce.cashup.*`,
`ecommerce.report.view`; **Rider** (new) `ecommerce.delivery.deliver`;
**Read-only Auditor** `ecommerce.*.view`.

## API

Prefix `/api/v1`. Back-office routes: people's tokens, behind
`module:ecommerce` (403 `module_inactive`). Amounts are digit strings in
minor units.

| Route | Permission | Requirement |
| --- | --- | --- |
| `GET/POST ecommerce/stores`, `GET/PATCH …/{id}`, `POST …/{id}/archive\|restore\|whatsapp-token` | `store.*` | ECOM-01, ECOM-24 |
| `GET/POST ecommerce/stores/{id}/products`, `PATCH ecommerce/products/{id}`, `POST …/{id}/archive` | `store.*` | ECOM-02 |
| `GET/POST ecommerce/stores/{id}/zones`, `PATCH ecommerce/zones/{id}`, `POST …/{id}/archive` | `store.*` | ECOM-10 |
| `POST ecommerce/stores/{id}/quote` | `order.create` | ECOM-09 |
| `GET/POST ecommerce/orders`, `GET …/{id}`, `POST …/{id}/accept\|pick\|ready\|dispatch\|deliver\|cancel\|return\|refund` | `order.*` | ECOM-16 to ECOM-22 |
| `GET ecommerce/deliveries`, `POST ecommerce/deliveries/{id}/assign` | `delivery.view`, `delivery.assign` | ECOM-27 |
| `GET/POST ecommerce/riders`, `PATCH …/{id}`, `POST …/{id}/archive` | `rider.manage` (list: `delivery.view`) | ECOM-26 |
| `GET/POST ecommerce/cashups`, `GET ecommerce/cashups/pending` | `cashup.*` | ECOM-30 |
| `GET ecommerce/conversations`, `GET …/{id}`, `POST …/{id}/reply\|close\|order` | `whatsapp.*`, `order.create` | ECOM-25 |
| `GET ecommerce/customers` | `customer.view` | ECOM-08 |
| `GET ecommerce/reports/summary` | `report.view` | ECOM-36 |
| `GET ecommerce/rider/deliveries`, `POST ecommerce/rider/updates`, `POST ecommerce/rider/deliveries/{id}/photo` | `delivery.deliver` | ECOM-28 |

Public storefront routes (no tenant token; `X-Shop-Host`; rate-limited):
`GET shop/{store}` (store, theme, categories, zones, payment kinds),
`GET shop/{store}/products`, `GET shop/{store}/products/{slug}`,
`GET shop/{store}/images/{product}/{n}`, `POST shop/{store}/quote`,
`POST shop/{store}/orders` (Idempotency-Key), `GET shop/{store}/orders/{number}?t=`,
`POST shop/{store}/orders/{number}/pay`, `POST shop/{store}/auth/code`,
`POST shop/{store}/auth/verify`, `POST shop/{store}/auth/sign-out`,
`GET shop/{store}/account`, and the WhatsApp webhook `POST shop/whatsapp/{token}`.

## Screens

**Web, back office** (navigation group **Online store**, only while
E-commerce is active): Orders (list, detail with actions), Deliveries
(board by status, assign rider), WhatsApp inbox (conversations, reply,
convert to order), Cash-ups (pending collections per rider, close a
cash-up), Riders, Reports, and Store settings (stores, catalogue, zones and
fees, payment methods, share links). Tokens only; en and fr.

**Web, storefront** (`/shop/{store}`): home, category, product, cart,
checkout, order tracking, account (sign in by code, orders). Tenant theme;
en and fr; the currency code first on every amount.

**Mobile app**: a rider mode (sign in with the user account; assigned
deliveries; picked up, delivered with code or photo, failed; location
report; offline queue). NativeWind tokens; en and fr; 48px targets.

## Integrations

- **Inventory**: `StockLevels` (new read contract); listener on
  `OrderFulfilled` and `OrderReturned` (`ecommerce.order`, `ecommerce.return`
  sources, kinds `sale` and `sale_return`).
- **Accounting**: `EcommercePosting` through `Integrations`; event types
  `ecommerce.order`, `ecommerce.return`, `ecommerce.cod_cashup` in the
  country charts (KE: COD clearing 1270; CD: 4712); `InventorySources`
  maps the two stock sources to cost of sales.
- **Loyalty**: `OnlineLoyalty` (new read and write contract, ledger
  source type `ecommerce_order`).
- **CRM**: the 360 view's `ecommerce` part through `CustomerOrders`.
- **Core**: payments (`PaymentIntents::request`, payouts,
  `PaymentIntentSettled`), fiscal queue source `ecommerce`, numbering
  (`ecommerce.order`), notifications (system event types to contact
  addresses), branding (host to tenant, the theme), audit.

## Acceptance criteria

1. A storefront on tenant A's host shows only A's live store; tenant B's
   handle on A's host is not found.
2. A placed order's totals equal the server's quote exactly (KES with 2
   decimals, CDF with none, inclusive and exclusive); a tampered total is
   refused and nothing is stored.
3. Two orders for the last unit: one succeeds, the other is refused
   `out_of_stock`; an unpaid order past its reservation time is cancelled
   and the unit is available again.
4. Placing twice with one idempotency key stores one order; a payment
   callback delivered twice marks the order paid once.
5. A COD order delivered by a rider and cashed up posts balanced journals
   (`ecommerce.order`, `ecommerce.cod_cashup`) with Accounting on, and
   nothing with it off.
6. Inventory moves stock on hand-over and back on return only while it is
   active.
7. The rider's status updates queued offline upload once each when the
   phone comes back online.
8. Every E-commerce table has row-level security and isolation tests.

## Open questions (owner input)

1. The WhatsApp Business provider (Meta Cloud API directly or a BSP) and
   its credentials; only the fake driver ships.
2. The card aggregator per country (the core hosted checkout adapter is
   used; its credentials are configuration).
3. Whether order notifications should also go by WhatsApp once the core
   notifier supports WhatsApp contact addresses (decision 4).
4. Partial returns and exchanges (decision 10).
5. A maps provider for live tracking (ECOM-29 leaves a hook).
6. The rider screen in the mobile app proves delivery by the customer's
   code only. Photo proof and location reports need a camera and a
   location library in the app (new dependencies, to be approved). The API
   already takes both: `POST ecommerce/rider/deliveries/{id}/photo` and
   `kind: location` updates.
