# Loyalty, promotions and credit module spec (phase 7)

Source: concept note sections 3 (wholesale credit sales), 5 (Loyalty row),
7.1 (store credit, vouchers and points at the till); platform core spec
(MD-01, MD-04, WF-01, RBAC-06, RBAC-08, TPL-01, TPL-04, NOT-01..NOT-04,
AUD-01, EXP-01, NFR-04); ADR 003 (money), ADR 004 (offline sync); the POS
module spec (`docs/modules/pos.md`). When this file and the core spec
disagree, the core spec wins.

## Scope

Loyalty is a module (`api/modules/Loyalty`, permissions `loyalty.*`,
catalogue key `loyalty` from phase 6), sold and switched on per tenant
(RBAC-08). It works with only the core installed: programmes, promotions,
customer credit accounts, statements and gift cards are managed in the
back office. With the POS module also active, the till evaluates
promotions offline, takes points, gift cards and store credit as tenders,
and the module listens to `SaleCompleted`, `SaleVoided` and
`SaleRefunded` to post points, redemptions and credit charges.

It reads core master data (parties and their tags, items and categories,
companies and branches, payment methods, currencies and rates, the
party's credit limit) and never reads or writes another module's tables.
What it needs from a POS sale arrives in the event (a sale snapshot).

Four parts:

1. **Points**: earn, redeem as a tender, expiry, adjustments.
2. **Promotions**: percent, amount off, buy X get Y, bundles, happy hour
   (time windows in the branch time zone), coupons, per branch and per
   customer group (core party tags), evaluated on the till offline by a
   pure, deterministic engine with shared PHP and JavaScript fixtures.
3. **Customer credit**: accounts with limits (the core party credit limit
   and its change workflow), sales on account, payments, statements and
   debt ageing.
4. **Gift cards and vouchers**: issue, sell, redeem as a tender, balance
   check.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| LOY-01 | **Programme per company.** Points earned per spend (`earn_points` per `earn_spend_minor` of the programme currency, the company's base currency), the value of one point (`point_value_minor`), the least points a redemption takes (`min_redeem_points`), expiry in months (none: never), the expiry notice in days, the credit warning percentage (80 by default), the gift card low-balance threshold and the gift card item sold at the till (a `service` or `non_stock` item: a card sold is a liability, never stock). Off until switched on. Audited. | Must |
| LOY-02 | **Points accounts.** One per customer (a core party with the customer role) and company, opened on the first earn or adjustment. The balance is the sum of an append-only ledger in whole points; the account keeps it cached, updated under the account's row lock with each entry. | Must |
| LOY-03 | **Earn on completed sales.** A POS sale naming a customer earns `floor(eligible × earn_points / earn_spend_minor)` points, where `eligible` is the sale total less what points paid, converted to the programme currency with the sale's own rate snapshot (CUR-04). Posted once per sale when the sale reaches the server (device wins, idempotent by sale id). Earned points expire `expiry_months` after the sale. | Must |
| LOY-04 | **Reverse on void and refund.** A void reverses everything the sale earned and redeemed (points, gift card, credit). A refund reverses earned points in proportion to the refunded share of the sale total (floored, never more than is left), and puts back what a refund pays by points, gift card or store credit. | Must |
| LOY-05 | **Points as a tender.** A payment method of type `points` (MD-04) pays in the programme currency; the tender carries `{points}` and its amount must be `points × point_value_minor`. The till allows at least `min_redeem_points` and at most the customer's synced balance less what it redeemed since (offline rule below); the server posts the redemption and flags `points_overdrawn` when the balance would go below zero. | Must |
| LOY-06 | **Expiry.** Earned points expire first in, first out. `loyalty:expire-points` (daily) posts an `expire` entry per account for lots past their date that redemptions have not used. Managers get `loyalty.points.expiring` the day a lot comes within the notice window. | Must |
| LOY-07 | **Manual points adjustment** with a reason (`loyalty.points.adjust`), audited. | Should |
| LOY-08 | **Promotions.** Per company, optionally limited to branches and customer groups (party tags). Types: `percent` (of eligible lines), `amount_off` (off the eligible subtotal), `buy_x_get_y` (buy X units, get Y units at a percentage off, cheapest first), `bundle` (a set of items and quantities for a price). Targets: all items, items, categories. Schedule: start and end, days of the week and a daily time window in the branch's time zone (happy hour). Optional minimum spend and coupon. Priority. Drafts are not offered; active promotions run within their schedule. Every change raises the promotion's version and stores the version (append-only), so a sale can be re-evaluated with what the till had. | Must |
| LOY-09 | **Coupons.** Codes for a promotion that requires one (typed or generated, upper case), each with a usage limit (empty: unlimited). Codes sync to the till; uses are counted on the server per sale; a sale using a code past its limit is kept and flagged `coupon_overused`. | Must |
| LOY-10 | **One deterministic engine.** `PromotionEngine` (PHP) and `pos/src/loyalty/engine.js` give the same result for the same input; shared fixtures (`api/tests/Fixtures/loyalty/promotions/*.json`) prove it in PHPUnit and Jest. Rules below. | Must |
| LOY-11 | **Promotions at the till, offline.** Promotions, coupons and customer groups sync to the till. The cart shows each promotion applied and its discount; the discount goes on the line with its provenance (`adjustments`) so POS does not treat it as a manual discount (POS-07). | Must |
| LOY-12 | **Re-evaluation on upload.** The server evaluates the sale again with the promotion versions in force when it was sold (time in the branch's zone) and compares. Differences are recorded on the loyalty sale (`promotion_differs`, `promotion_unknown`, `promotion_not_applicable`, `coupon_unknown`, `coupon_overused`), never refused (device wins). | Must |
| LOY-13 | **Preview.** The back office evaluates a draft or saved promotion against a sample cart with the same engine before publishing. | Must |
| LOY-14 | **Credit accounts.** Opened per customer and company (`loyalty.credit.manage`), in the currency of the party's credit limit, else the company's base currency. The limit is the core party's credit limit (MD-01); changing it goes through the core credit-limit change flow (WF-01), which this module links to and never duplicates. A party without a limit has no bound (core rule); only customers with an open account can buy on credit. | Must |
| LOY-15 | **Sales on account.** A payment method of type `credit` pays a POS sale on account. The till offers it only for a customer with an open account, in the account's currency, to staff holding `loyalty.credit.sell`, and refuses it when the synced balance plus what the till charged since plus this sale exceeds the limit. The server posts the charge and flags `credit_limit_exceeded`, `credit_no_account`, `credit_currency_mismatch` or `credit_not_permitted` (device wins). A back-office charge over the limit is refused (`credit_limit_exceeded`) unless the user's RBAC-06 `credit_limit_override` covers the excess. | Must |
| LOY-16 | **Payments and adjustments on account.** Money received from the customer (`loyalty.credit.receive`) and adjustments with a reason (`loyalty.credit.adjust`); the credit ledger is append-only in minor units. | Must |
| LOY-17 | **Statements.** A statement for a period (`loyalty.credit.view`) lists the opening balance, each entry with its running balance, the closing balance and the ageing at the period end. It is stored (numbered `ST-{YYYY}-{00001}`), printed with the `party.statement` document template (TPL-01, this module is its live data source) and downloaded as a PDF (TPL-04). | Must |
| LOY-18 | **Debt ageing.** Open amounts per account by age of the charge at a date: 0–30, 31–60, 61–90 and 90+ days. Payments and credits settle the oldest charges first. Per company, scoped (RBAC-04), exportable (EXP-01). | Must |
| LOY-19 | **Credit notifications.** `loyalty.credit.limit_near` when a charge takes a balance to at least the warning percentage of the limit, `loyalty.credit.limit_exceeded` when above it, to the company's holders of `loyalty.credit.manage`. | Must |
| LOY-20 | **Issue gift cards and vouchers.** One or many at once, with value, currency, kind (`gift_card`, sold; `voucher`, given), optional expiry. The code (16 characters, no look-alike letters) is shown once at issue; the server keeps a SHA-256 hash and the last four characters. Cards issued unsold are `inactive` until sold. | Must |
| LOY-21 | **Sell a gift card.** In the back office (`loyalty.gift_card.sell`, payment reference recorded) or at the till: the cashier scans an inactive card and sells it as a line of the programme's gift card item; the server loads the card when the sale arrives (flagged `gift_card_already_active` when it was loaded before). | Must |
| LOY-22 | **Gift card as a tender.** A payment method of type `voucher` pays with a card in its currency; the tender carries the card's code hash. Offline rule below. The server posts the redemption under the card's row lock, so concurrent redemptions never lose an update, and flags `gift_card_overdrawn`, `gift_card_unknown`, `gift_card_inactive`, `gift_card_blocked` or `gift_card_expired`. | Must |
| LOY-23 | **Balance check.** By code in the back office and at the till (online, `POST loyalty/device/gift-cards/lookup`), else from the synced balance. | Must |
| LOY-24 | **Gift card low balance.** `loyalty.gift_card.low` to holders of `loyalty.gift_card.issue` when a redemption takes a card below the programme's threshold. | Should |
| LOY-25 | **Refund to gift card, points or credit.** A POS refund paid by one of these methods puts the amount back on the card, account or points account named by the sale's tender. | Should |
| LOY-26 | **Stand-alone and switched per tenant (RBAC-08).** Routes behind `module:loyalty`; sync entities, tenders and promotion provenance served only while the module is active; POS events are acted on only while both modules are active. | Must |
| LOY-27 | **Minimal, generic POS seams.** Core `TenderProviders` (a tender provider per payment method type), core `DiscountSources` (a module vouches for a discount's provenance) and opaque sale `extensions` keyed by module. No Loyalty code in POS. | Must |
| LOY-28 | **Audit (AUD-01).** Programme and promotion changes, coupons, account opening and closing, every manual ledger entry, statements, gift card issue, sale, block and unblock. | Must |
| LOY-29 | **Isolation (TEN-01).** Every table has forced row-level security and rows in both tenants of the isolation suite. | Must |
| LOY-30 | **Languages.** Every string in English and French (L10N-02). | Must |

## Decisions

Recorded here as the brief asked; the owner may revisit them.

- **Offline redemption rule (points and gift cards).** The till allows a
  redemption up to the last synced balance less what the server may not
  have counted yet: this till's sales not acknowledged, and its sales
  after that entity's last pull (M4; sale times and the pull time on one
  clock, the server's, `serverNow` = device time + measured skew), less
  the tenders already on the cart for the same card hash, or the same
  customer for points and credit (M3). Another till may spend the same
  balance meanwhile: the server never refuses a completed sale (device
  wins), posts both redemptions, lets the balance go below zero and flags
  the second `points_overdrawn` / `gift_card_overdrawn` for review. The
  exposure is bounded by one balance per till that is offline. Online, the
  till asks the server for the card's live balance before it accepts the
  card (less this till's sales not acknowledged).
- **Coupons offline (M5).** A code is offered while its uses left as
  last synced, less this till's own uses (sales not acknowledged or newer
  than the pull, counted at load and after each sale), stay above zero.
- **Only with the module's data (H3).** The till's points, gift card and
  credit tenders, and their payment options, apply only while the Loyalty
  catalogue extension is loaded (`catalogue.ext.loyalty`, null when the
  till holds none of the module's synced data: not subscribed, or never
  pulled).
- **Credit limit at the till** uses the same rule: synced balance plus the
  till's own charges since plus this sale, against the limit. No
  override at the till in this phase (a manager raises the limit through
  the core flow instead); the server flags what slips through.
- **Credit account per customer and company**, in one currency (the
  limit's, else the company's base). A credit tender in another currency is
  refused at the till and flagged `credit_currency_mismatch` on the
  server, posted in the account currency at the sale's own rate when the
  sale currency is the account currency, else posted at the payment's
  amount converted with the sale snapshot (never refused).
- **Customers need an open credit account** to buy on credit, even when
  the core party has no limit (core: no limit means no bound). Opening an
  account is the deliberate grant; the limit stays the core's.
- **Gift card codes are never stored in clear (M8).** The proof is
  `H(code)` = SHA-256 of the normalised code (upper case, spaces and
  dashes removed); the server stores and syncs `H(H(code))` and the last
  four characters. The till hashes twice to find a card and sends the
  proof `H(code)` in a payment or a live lookup; the server hashes it once
  more. A stolen till or export therefore holds nothing that pays: the
  stored hash is not accepted as a tender. A lost code cannot be shown
  again; block the card and issue another.
- **Promotions engine rules** (LOY-10):
  - Input: lines `{id, item_id, category_ids, qty, unit_price_minor}` in
    cart order, the context `{currency, branch_id, customer_tags, coupons,
    local: {date, weekday, time}}` and the promotions.
  - Applicable promotions are taken by priority (higher first), then id.
  - **A line gets at most one promotion.** Lines a promotion discounts are
    not offered to later ones. A coupon promotion applies only with one of
    its codes in the sale.
  - `percent`: each eligible line gets `round_half_up(gross × percent /
    100)`, gross = `round_half_up(unit price × qty)`.
  - `amount_off`: `min(amount, eligible subtotal)`, shared over the
    eligible lines by gross, largest remainder first (earlier line on a
    tie), so the parts add up exactly.
  - `buy_x_get_y`: only whole units count (`floor(qty)`). Without a get
    set, every X + Y units of the buy set give Y units; with one, every X
    units of the buy set give Y units of the get set (lines of the buy set
    excluded from it). The discounted units are the cheapest (ties: later
    line first); each gets `round_half_up(unit price × percent / 100)`.
  - `bundle`: components `{item_id, qty}`; the number of bundles is the
    least `floor(units of the item / qty)`; units are taken from lines in
    cart order. Each bundle's discount is its components' gross less the
    bundle price (none when not positive), shared over the component
    lines by the gross they gave, largest remainder first.
  - Minimum spend compares the eligible subtotal (gross) before discount.
    Promotions with an amount (`amount_off`, `bundle`, minimum spend) apply
    only in their currency.
  - Schedule: `starts_at` and `ends_at` are dates in the branch's zone,
    inclusive; `days` is a set of ISO weekdays (1 Monday … 7 Sunday);
    `time_from` ≤ local time < `time_to` (both `HH:MM`; a window past
    midnight, `22:00`–`02:00`, is allowed). In such a window the part
    after midnight belongs to the day it started: a Friday `22:00`–`02:00`
    window applies at Saturday `01:30`, not at Friday `01:30` (the shared
    fixtures cover both, PHP and JS).
  - Output: per line `{line_id, promotion_id, version, discount_minor}`,
    per promotion the total, the coupon used.
- **Points earn** (`floor`) and **refund reversal** (`floor` of earned ×
  refunded total / sale total, capped at what is left) round towards the
  shop; redemption value is exact (`points × point_value_minor`).
- **Ageing by charge date**, not due date: payment terms are core data on
  the party, but ageing buckets 0–30 … 90+ as the concept note asks read
  more simply against the sale date. Payments and credits settle the
  oldest charges first.
- **Points redemption value and earn** are both in the programme currency
  (the company's base currency at the time the programme is created). A
  sale in another currency converts with its own stored rate snapshot.
- **The till shows points earned as an estimate**; the server's posting on
  upload is the record.
- **Event payloads.** `SaleCompleted`, `SaleVoided` and `SaleRefunded`
  gain an optional snapshot of what was stored (a sale or a refund),
  built by POS. Loyalty keeps its own copy (`loyalty_sales`) before
  processing, so a failure is retried by `loyalty:reprocess` without POS.
  A Loyalty failure never fails a POS upload.
- **POS schema change (approved by the owner on 2026-10-14).** CLAUDE.md
  asks before changing another module's schema. The brief asks for a
  minimal, generic seam in POS, so three nullable `jsonb` columns are
  added by a POS migration: `pos_sales.extensions`,
  `pos_sale_lines.adjustments` and `pos_sale_payments.tender` (and
  `pos_refund_payments.tender`). They hold module data opaquely; POS reads
  none of it but the discount amount a registered source verifies. The
  migration is safe on busy tables (M1): `lock_timeout` 5 s, nullable
  columns without defaults, the type CHECK constraints added `NOT VALID`,
  then validated by a separate migration outside a transaction
  (`2026_11_05_000110`, `$withinTransaction = false`).

## Tenders and discount provenance (POS seams)

- **Core `App\Core\Payments\Tenders\TenderProviders`.** A module registers
  a `TenderProvider` for a payment method type (`points`, `voucher`,
  `credit`). POS checks only the `tender` object's shape (a flat object of
  short keys; otherwise a 422, as any malformed field) and asks the
  provider for review flags (`check()`), which POS adds to the sale's or
  refund's flags as `tender_flagged` with the provider's code. Providers of an
  inactive module are not used: the tender is stored as sent and flagged
  `tender_flagged` with code `module_inactive` (`TenderProviders::registered()`
  still names a switched-off module's provider; device wins).
- **Core `App\Core\MasterData\Prices\DiscountSources`.** A module
  registers a source key (`loyalty.promotion`) with a synchronous verifier
  (H2): given the sale (company, branch, customer, currency, time, lines,
  extensions) it answers what it gives each line. A line's `adjustments:
  [{source, reference, amount_minor}]` say which part of its discount came
  from where; POS treats as vouched only what the verifier confirms (the
  promotion engine, run on the server), and any excess goes through the
  manual discount check (`pos.discount.give` and its limit, else flagged
  `discount_unauthorised`). The `pos.sale.discount` audit records the
  vouched part and whether the rest was allowed. The source re-evaluates
  and flags differences itself.
- **Sale `extensions`.** `{loyalty: {coupons: [...], gift_cards: [{line_id,
  code_hash, last4, amount_minor}], points_estimate}}`: stored as sent,
  hashed with the sale (idempotent uploads), passed in the event. A gift
  card sold (M2) must name a line of the sale whose item is the
  programme's gift card item, a card in the sale currency (`code_hash` is
  the proof); it loads `min(amount, line total)`. Anything else is
  flagged `gift_card_sale_mismatch` (reason `malformed`, `line`,
  `currency` or `amount`), never a failure.

## Data model

All tables are tenant tables with forced row-level security (TEN-01);
amounts are bigint minor units with a currency (ADR 003); points are
bigint whole points; ledgers are append-only (no update or delete; a
database trigger refuses both).

| Table | What it holds |
| --- | --- |
| `loyalty_programmes` | One per company: the settings of LOY-01 |
| `loyalty_accounts` | Per party, company and kind (`points`, `credit`): currency (credit), cached balance, status, opened by and when, `sync_xid`/`sync_seq` (synced to tills) |
| `loyalty_points_entries` | Points ledger: kind (`earn`, `redeem`, `reverse_earn`, `reverse_redeem`, `expire`, `adjust`), points (signed), expiry of an earn lot, source (`pos_sale`, `pos_void`, `pos_refund`, `manual`, `expiry`) and its id, the sale |
| `loyalty_credit_entries` | Credit ledger: kind (`charge`, `payment`, `reverse_charge`, `refund_credit`, `adjust`), amount (signed, positive is owed), currency, date, source, reference, note, flags |
| `loyalty_promotions` | Current definition, version, status (`draft`, `active`, `archived`), company, priority |
| `loyalty_promotion_versions` | Every version's definition (append-only), with when it took effect |
| `loyalty_coupons` | Codes of a promotion: code, usage limit, uses counted, archived |
| `loyalty_sales` | Loyalty's copy of each POS sale it handled: snapshot, customer, points earned, status, flags, processed |
| `loyalty_promotion_applications` | Per sale and promotion: discount the till applied, discount the server expected, coupon |
| `loyalty_gift_cards` | Code hash and last four, kind, currency, initial value, cached balance, status (`inactive`, `active`, `blocked`), expiry, `sync_xid`/`sync_seq` |
| `loyalty_gift_card_entries` | Gift card ledger: kind (`load`, `redeem`, `reverse_redeem`, `refund_credit`, `adjust`), amount (signed), source, flags |
| `loyalty_statements` | A stored statement: number, account, period, opening and closing balances, ageing |
| `loyalty_notices` | Notices sent once (expiry lot, limit near), so a notice is never repeated |

## Events

- **Consumed** (only while POS and Loyalty are both active): `SaleCompleted`
  (earn, redeem, charge, promotion re-evaluation, gift card sale),
  `SaleVoided` (reverse all), `SaleRefunded` (reverse in proportion, put
  back refund tenders).
- **Raised**: none yet (Accounting will want `CreditPaymentReceived` and
  `GiftCardSold`; added with the Accounting integration).

## Permissions

| Permission | What it allows |
| --- | --- |
| `loyalty.programme.view`, `loyalty.programme.edit` | Read and change the company's programme |
| `loyalty.promotion.view`, `.create`, `.edit`, `.archive` | Promotions and coupons |
| `loyalty.points.view`, `loyalty.points.adjust` | Points balances and ledger; manual adjustment |
| `loyalty.credit.view` | Credit accounts, ledgers, statements and ageing |
| `loyalty.credit.manage` | Open and close credit accounts |
| `loyalty.credit.receive` | Record payments received |
| `loyalty.credit.charge` | Charge an account in the back office (within the limit, or `credit_limit_override`) |
| `loyalty.credit.adjust` | Adjust a credit balance with a reason |
| `loyalty.credit.sell` | Sell on account at the till |
| `loyalty.gift_card.view`, `.issue`, `.sell`, `.block` | Gift cards and vouchers |
| `loyalty.sale.view` | The review list of flagged sales |

Role templates: **Branch Manager** holds `loyalty.*`; **Admin** the
programme and promotions; **Accountant** credit (view, receive, adjust,
manage), points and gift card views; **Cashier** views points and gift
cards (the till's balance checks) but does not sell on credit until a
tenant grants `loyalty.credit.sell`; **Read-only Auditor** reads
(`*.view`). `loyalty.` is a till permission prefix (staff rows carry it).

## API

Prefix `/api/v1`, every route behind `module:loyalty` (403
`module_inactive`).

**Back office** (people's tokens):

| Route | Permission | Notes |
| --- | --- | --- |
| `GET`, `PUT companies/{company}/loyalty-programme` | `loyalty.programme.view` / `.edit` at the company | |
| `GET loyalty/promotions`, `POST loyalty/promotions` | `loyalty.promotion.view` / `.create` | `?status`, `?company`, `?search`, export |
| `GET`, `PATCH loyalty/promotions/{loyalty_promotion}` | `.view` / `.edit` | a change raises the version |
| `POST loyalty/promotions/{loyalty_promotion}/activate`, `/archive` | `.edit` / `.archive` | |
| `POST loyalty/promotions/preview` | `.view` | `{company_id, promotion?, promotion_ids?, lines, branch_id?, party_id?, coupons?, at?}` |
| `GET`, `POST loyalty/promotions/{loyalty_promotion}/coupons` | `.view` / `.edit` | `{codes?: [...], generate?: n, max_uses?}` |
| `POST loyalty/coupons/{loyalty_coupon}/archive` | `.edit` | |
| `GET loyalty/customers/{party}` | `loyalty.points.view` or `loyalty.credit.view` | the customer tab: accounts per company, recent entries |
| `POST loyalty/customers/{party}/points/adjust` | `loyalty.points.adjust` | `{company_id, points, reason}` |
| `GET loyalty/accounts`, `POST loyalty/accounts` | `loyalty.credit.view` / `.manage` | credit accounts; open `{party_id, company_id}` |
| `GET loyalty/accounts/{loyalty_account}` | `.view` | ledger with running balance, ageing |
| `POST loyalty/accounts/{loyalty_account}/payments`, `/charges`, `/adjustments`, `/close` | `.receive`, `.charge`, `.adjust`, `.manage` | a charge takes an idempotency key `id` (UUID): sent again, it answers the charge it made |
| `POST loyalty/accounts/{loyalty_account}/statements` | `.view` | `{from, to}` |
| `GET loyalty/statements/{loyalty_statement}`, `/pdf` | `.view` | |
| `GET loyalty/ageing` | `.view` | `?company`, `?as_of`, `?search`, export |
| `GET loyalty/gift-cards`, `POST loyalty/gift-cards` | `loyalty.gift_card.view` / `.issue` | issue answers the codes once |
| `GET loyalty/gift-cards/{loyalty_gift_card}` | `.view` | |
| `POST loyalty/gift-cards/{loyalty_gift_card}/sell`, `/block`, `/unblock` | `.sell`, `.block` | |
| `POST loyalty/gift-cards/lookup` | `.view` | `{code}`; throttled, 30 a minute per user |
| `GET loyalty/sales` | `loyalty.sale.view` | sales Loyalty flagged (`?flagged=0` for all), `?flag`, `?search` (receipt), export |

**Till** (device token): `POST loyalty/device/gift-cards/lookup` `{code_hash}`
(the proof `H(code)`; the live balance of a card of the till's company;
throttled, 30 a minute per device).

**Sync entities** (core sync API, ADR 004):

| Entity | Mode | Rows |
| --- | --- | --- |
| `loyalty_settings` | snapshot | a `module` row (always: gift cards, vouchers and credit need no programme) and the till's company programme (`programme`, when set up) |
| `loyalty_promotions` | snapshot | active promotions of the till's branch (current versions), with their coupon codes and uses left, and the customer tags they name with the customers holding them |
| `loyalty_members` | incremental (`loyalty_accounts`) | per customer of the company: points balance; credit account currency, balance, limit, status |
| `loyalty_gift_cards` | incremental | the company's cards: hash, last four, kind, currency, balance, status, expiry |

## Screens

- **Web**: Loyalty programme settings; Promotions (list, editor with
  schedule and coupons, preview); Gift cards (list, issue, detail with
  ledger, sell, block, balance check); Credit accounts (list, detail with
  ledger, payments, statements and their PDF); Debt ageing report; the
  customer page's **Loyalty** tab (points and credit per company); **Sales
  to review** (`/loyalty/review`), the flagged sales with each reason.
- **POS app**: promotions shown on cart lines and the total; coupon entry;
  the customer's points, credit and limit in the cart; tenders for
  points, gift card (scan or type the code) and credit, with the offline
  rules; selling a gift card.

## Integrations

- **POS** (both active): events consumed as above; tenders through core
  `TenderProviders`; promotion discounts through core `DiscountSources`.
- **Core**: credit limits (MD-01, WF-01), document templates
  (`party.statement`), numbering (`loyalty.statement`), notifications,
  sync, list views (LAY-04: `loyalty-promotions`, `loyalty-gift-cards`,
  `loyalty-accounts`, `loyalty-ageing`).
- **Accounting** (later): journals for gift card sales (a liability),
  redemptions, points (a provision) and customer payments.
- **CRM** (later): the customer 360 view reads the Loyalty tab's API.

## Acceptance criteria

1. The shared promotion fixtures give the same result in PHPUnit and Jest.
2. A sale with a customer earns points once, also when uploaded twice;
   its void reverses them; a refund of half the sale reverses half.
3. Points, gift card and credit tenders post to their ledgers; a balance
   overdrawn offline is kept and flagged, never refused.
4. Two sales redeeming the same gift card at the same moment post both
   redemptions; the card's balance is exact and the second is flagged.
5. A back-office charge above the credit limit is refused unless the
   user's `credit_limit_override` covers it.
6. A statement prints as a PDF through the `party.statement` template.
7. Ageing puts each open amount in 0–30, 31–60, 61–90 or 90+.
8. Every new table has row-level security and rows in the isolation suite;
   every route refuses another tenant's ids and the permission-denied case.

## Status (phase 7, branch `feat/loyalty`)

| ID | Status | Evidence |
| --- | --- | --- |
| LOY-01 | Built | BackOfficeTest (programme), web Programme page |
| LOY-02 | Built | PosIntegrationTest, customer tab |
| LOY-03 | Built | PosIntegrationTest (earn once, resend), shared points cases |
| LOY-04 | Built | PosIntegrationTest (void, proportional refunds) |
| LOY-05 | Built | PosIntegrationTest (overdraw flagged), Jest offline rule |
| LOY-06 | Built | SyncAndExpiryTest (FIFO, notice once) |
| LOY-07 | Built | BackOfficeTest (customer tab) |
| LOY-08 | Built | BackOfficeTest (versions, validation), web editor |
| LOY-09 | Built | BackOfficeTest (coupons), Jest (uses left) |
| LOY-10 | Built | PromotionEngineFixturesTest and engine.test.js on one fixture |
| LOY-11 | Built | Jest promotions on the cart, sale provenance |
| LOY-12 | Built | BackOfficeTest (re-evaluation flags `promotion_differs`) |
| LOY-13 | Built | BackOfficeTest preview, Playwright preview |
| LOY-14 | Built | BackOfficeTest (open, close, limit from the party) |
| LOY-15 | Built | PosIntegrationTest (flags), BackOfficeTest (refusal and RBAC-06 override), Jest (till limit) |
| LOY-16 | Built | BackOfficeTest |
| LOY-17 | Built | BackOfficeTest (statement and PDF) |
| LOY-18 | Built | BackOfficeTest (FIFO buckets, export) |
| LOY-19 | Built | SyncAndExpiryTest |
| LOY-20 | Built | BackOfficeTest (code shown once, hash kept) |
| LOY-21 | Partly | Back-office sale and the server's till-sale loading (`extensions.loyalty.gift_cards`) are built; the till screen to sell a card is deferred |
| LOY-22 | Built | PosIntegrationTest, GiftCardConcurrencyTest, Jest |
| LOY-23 | Built | BackOfficeTest (back office and device lookup), Jest (live lookup) |
| LOY-24 | Built | SyncAndExpiryTest |
| LOY-25 | Built | PosIntegrationTest (refund to a card) |
| LOY-26 | Built | PosIntegrationTest and BackOfficeTest (module off), SyncAndExpiryTest |
| LOY-27 | Built | POS tests unchanged and passing; seams in core and the POS app |
| LOY-28 | Built | Audited through Auditor and Audited models |
| LOY-29 | Built | Isolation suite with every loyalty table and route |
| LOY-30 | Built | TranslationParityTest, check:i18n |

### Decisions taken while building

- **Tender reference checks flag, they do not refuse.** A tender object of
  the wrong shape (not a flat object of short keys) is a 422 like any
  malformed field; a tender that does not add up (unknown card, points
  worth another amount) is stored and flagged `tender_flagged` with the
  provider's code, because the sale already happened (device wins).
- **Company-wide records.** Programmes, promotions, accounts, statements and
  gift cards belong to a company; a loyalty permission held at the company
  or at any of its branches or locations covers them (LoyaltyAccess), so a
  branch manager takes a customer's payment at their branch.
- **Promotion versions take effect when saved.** The server re-evaluates
  a sale with the versions in force at its sale time; a till that applied a
  promotion saved after the sale is flagged `promotion_unknown`.
- **A credit sale without an open account** is still recorded (device
  wins): an account is opened for it and the sale flagged
  `credit_no_account`. A credit sale by someone without
  `loyalty.credit.sell` is flagged `credit_not_permitted`.
- **Points adjustments** may be made for any party of the company (its
  roles may change); points accounts follow the party.
- **The ageing report's as-of day** ends at midnight UTC; per-account pages
  use the company's time zone.
- **Gift card code hashes in the full export** (EXP-02) are allowed: they
  are `H(H(code))`, not reversible, not accepted as a tender, and every
  till of the company holds them.
- **A void or refund before processing (H1).** A sale whose processing
  failed (retried by `loyalty:reprocess`) may be voided or refunded first.
  A void is recorded on the sale (`voided_at`); processing then posts
  nothing and flags `voided_before_processing`. Refunds wait on the sale
  (`pending_refunds`) and are applied, in order, after its postings. A
  refund applied joins `refund_ids`, so a replayed `SaleRefunded` changes
  nothing.
- **Points expiry is a true first in, first out (M6).** The ledger is
  replayed as lots: an earn is a lot that expires; any other positive
  entry (a redemption given back, a positive adjustment) is a lot that
  never expires and is spent last; a `reverse_earn` takes its own sale's
  lot first; other debits take the oldest earn lots, then the lots that
  never expire. What is left of the lots expired is what expires.
- **The credit limit override** (`credit_limit_override`, RBAC-06) for a
  back-office charge resolves at the account's company (M7): a role held
  only at a branch does not override a company-wide account's limit.
- **Lookups by code are throttled** (`loyalty-lookup`, 30 a minute per
  user or device) so codes cannot be guessed at speed.

## Deferred

- Manager override of the credit limit at the till (raise the limit
  through the core flow instead).
- Selling a gift card on the till's screen (the server already loads a
  card sold on a sale's `extensions.loyalty.gift_cards`).
- Points earned shown on the till's receipt (the server's posting is the
  record).
- Points for items or categories only (every item earns today), tiers,
  referral and birthday rewards.
- Per-customer coupon limits (total limit only).
- Accounting journals for loyalty liabilities.
- Gift card import (IMP) and dashboard widgets.
