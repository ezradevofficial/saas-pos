# SaaS POS & Business Suite: Concept Note

Oct 7, 2026 · @ezra kathurima

## 1. Purpose and vision

We are building a cloud business platform for Kenya and the DR Congo. Its core is a point of sale (POS), and around it sit HR, payroll, accounting, procurement, inventory, stores and more. Each module works on its own or together, and customers self-onboard, pick the modules they need, and pay only for those.

This note is the brief for the build team. It sets the scope, the platform rules every module must follow, and the delivery approach.

**Vision:** one platform that a corner shop in Nairobi and a multi-country distributor in Kinshasa can both run on, configured without code.

**What makes it different**

- **Modular and stand-alone:** a company can buy only payroll, or only POS, or the full suite.
- **No-code configuration:** workflows, approvals, escalations, delegations, custom fields and forms are set up by the customer.
- **Built for the region:** offline-first POS, mobile money, true multi-currency (USD/CDF/KES), KRA eTIMS and DRC DGI e-invoicing, and statutory payroll for both countries.
- **Self-service:** sign up, pick modules, configure and go live without a consultant. Field sales cover the larger accounts.

## 2. Market context: Kenya and DR Congo

The two markets need the same core product, configured differently per country. The table lists the differences the build must handle. Rates, levies and integration rules change often, so the team must confirm each one against official sources before building.

| Area | Kenya | DR Congo |
| --- | --- | --- |
| Currencies | KES (USD for some B2B) | USD and CDF used side by side every day |
| Business language | English (Swahili common) | French |
| Mobile money | M-Pesa (Safaricom), Airtel Money | Vodacom M-Pesa, Orange Money, Airtel Money, Afrimoney |
| Tax e-invoicing | KRA eTIMS (mandatory) | DGI normalized invoicing (e-MCF / electronic invoicing devices) |
| Payroll statutory | PAYE, NSSF, SHIF, Housing Levy, NITA | IPR (income tax), CNSS, INPP, ONEM |
| Connectivity | Generally good in towns | Frequent power and internet outages; offline is essential |
| Typical devices | Android phones, POS terminals, PCs | Android phones and POS terminals |

**Implication for the build:** country is a configuration ("country pack"), not a fork of the code. Each pack holds tax rules, statutory payroll, chart of accounts template, invoice formats, languages and payment providers.

## 3. Target customers and POS verticals

We serve all business sizes through tiers. Small businesses self-onboard online; mid-size and enterprise accounts are won by field sales.

| Segment | Profile | Likely modules |
| --- | --- | --- |
| Small | 1–3 outlets, under 20 staff | POS, inventory, loyalty |
| Mid-size | Several branches, 20–200 staff | POS, inventory, stores, accounting, HR, payroll, procurement |
| Enterprise / group | Many branches, several companies, possibly both countries | Full suite, advanced workflows, consolidation |
| Non-POS companies | Offices, NGOs, service firms | HR, payroll, accounting, procurement only |

**POS verticals at launch.** Each vertical is a POS "mode" built from shared building blocks, not a separate product.

| Vertical | Distinct workflow |
| --- | --- |
| Retail / boutique / hardware | Scan or search, cart, pay; variants (size/colour), serial numbers, returns |
| Supermarket / mini-mart | High-volume lanes, weighed items (scales), PLU codes, barcode labels |
| Restaurant (full service) | Table map, open order, courses, kitchen display / printer, split bill, tips |
| Quick-service / café / fast food | Order and pay upfront, order number, pickup queue, combos and modifiers |
| Bar / lounge / club | Open tabs, fast buttons, rounds, close tab later |
| Hotel / lodge | Room charges to a guest folio, settle at checkout; restaurant and bar outlets post to rooms |
| Pharmacy / agrovet | Batch and expiry tracking, prescriptions, controlled items |
| Wholesale / distribution | Price lists per customer, credit sales, quotes, sales orders, delivery notes |
| Route / van sales | Mobile ordering in the field, van stock, cash and mobile money collection, end-of-day reconciliation |
| Salon / spa / services | Appointments, staff assignment, commissions |
| Fuel station / forecourt | Pump and shift readings, attendant collections, shop sales |

**Common building blocks** each mode is configured from:

- **When payment happens:** upfront, at the end, or on credit
- **What an order attaches to:** nothing, a table, a tab, a room, an appointment, a route
- **Fulfilment:** instant, kitchen, delivery, pickup
- **Pricing:** fixed, weighed, time-based, customer price list

## 4. Product principles

Every module must follow these rules. They are what make "buy any module on its own" work.

1. **Stand-alone first.** Each module works with only the shared platform core. Integration with other modules switches on automatically when both are subscribed. Example: POS posts journals to accounting only if accounting is active; otherwise it keeps its own sales reports.
2. **Shared master data.** Customers, suppliers, items, employees, branches, currencies and taxes live in the core once, and every module uses them.
3. **Configuration over code.** Workflows, approvals, numbering, custom fields, forms, print templates and notifications are set up in the UI. No per-customer code branches.
4. **Country packs.** Tax, payroll statutory, e-invoicing, chart-of-accounts templates and languages are packaged per country.
5. **Offline-first POS.** The till never stops selling because the internet or power is down.
6. **Everything is audited.** Every create, change, approval, void, discount and price override records who, when, where (branch/device) and what changed.
7. **Bilingual from day one.** English and French throughout the UI, receipts, invoices, payslips and notifications. These are the only planned languages; no Swahili or Lingala. All text still goes through translation files, never hard-coded.
8. **Mobile-friendly.** Back office works in a phone browser; POS runs on Android phones, tablets and POS terminals.

## 5. Module catalogue

The platform core is always included. Every other module is sold separately and works stand-alone.

| Module | Key features | Works best with |
| --- | --- | --- |
| **Platform core** (always on) | Organisation setup, users, roles and permissions, master data, workflow engine, custom fields and forms, notifications, audit log, multi-currency, document templates, data import/export, subscription and billing | Everything |
| **POS** | All vertical modes (section 3), offline selling, split and multi-currency tender, change in another currency, returns and voids with approval, shifts and cash-up, receipt printing, barcode scanning, scales, kitchen display, customer display | Inventory, Loyalty, Accounting |
| **Loyalty, promotions and credit** | Points earn and redeem (points as tender, expiry rules), promotions engine (% or fixed, buy X get Y, bundles, happy hour, coupons, per-branch and per-customer-group), customer credit accounts with limits, statements and debt ageing, gift cards and vouchers | POS, CRM |
| **Inventory** | Items, variants, units of measure and conversions, batches and expiry, serial numbers, multi-warehouse, transfers, stock counts, adjustments with approval, reorder levels, costing (FIFO, weighted average), barcode labels | POS, Procurement |
| **Stores** | Internal store requisitions, issues to departments, returns to store, store ledgers, consumption reports | Inventory, Procurement |
| **Procurement** | Purchase requisitions, RFQs, tenders and bidding (open or invited tenders, supplier portal for bid submission, sealed bids with timed bid opening, scored technical and financial evaluation, award and regret letters), supplier quotes and comparison, purchase orders, goods received notes, supplier invoices, multi-currency purchasing, landed costs, supplier performance | Inventory, Accounting |
| **Accounting** | Chart of accounts per country template, general ledger, AR, AP, bank and mobile money reconciliation, multi-currency with FX gains/losses, budgets, cost centres, period close, financial statements, group consolidation | Everything |
| **HR** | Employee records, contracts, departments and positions, leave, shifts and rosters (shift patterns, rotating rosters per branch or department, shift swaps with approval, overtime, night and public-holiday rules), attendance (clock-in by app, PIN or biometric device, late and absence tracking), documents, onboarding/offboarding, disciplinary, appraisals, employee self-service | Payroll |
| **Payroll** | Kenya and DRC statutory deductions, allowances and deductions, overtime and shift allowances pulled from HR attendance, loans and advances, multi-currency pay (e.g. USD salary, CDF tax), payslips, bank and mobile money payment files, statutory returns | HR, Accounting |
| **CRM and sales** | Leads, opportunities, quotes, customer 360 view, follow-ups, sales targets | POS, Loyalty |
| **E-commerce and delivery** | Online store synced with inventory, WhatsApp ordering, delivery orders, rider assignment and tracking, cash on delivery | POS, Inventory |
| **Fixed assets** | Asset register, categories, depreciation methods, transfers, disposals, maintenance schedules | Accounting, Procurement |
| **Manufacturing / production** | Bills of materials and recipes, production orders, raw material consumption, wastage, yield, costing | Inventory, POS (recipes for restaurants) |

**Integration rule:** modules talk through internal events (for example "sale completed", "goods received", "payroll approved"). Each module subscribes only to events it understands, so adding or removing a module never breaks another.

**Developer platform (at launch):**

- **Public API:** documented REST API covering every module, with API keys and OAuth per tenant, permissions tied to RBAC, rate limits, a sandbox environment and versioning.
- **Webhooks:** customers subscribe to events (sale completed, stock low, invoice approved, payroll approved) and receive them in their own systems.
- **Integrations marketplace:** third-party developers register, build and submit add-ons; we review them for security before listing. Customers install with one click and grant only the permissions an add-on needs. Paid add-ons are billed through our subscription system with a revenue share (rate to be set).
- **First-party connectors** in the marketplace at launch: couriers, SMS/WhatsApp, Power BI/Excel export, and biometric attendance devices.

## 6. Platform core

### 6.1 Organisation structure (multi-tenancy)

One customer account (tenant) holds **Group → Companies → Branches → Warehouses/Outlets → Tills/Devices**. A group can hold a Kenyan and a DRC company, each with its own country pack, base currency and tax registration, and still see consolidated reports.

- Data is isolated per tenant (tenant ID on every row, enforced in the data layer).
- Users can belong to several companies and branches, with different roles in each.

### 6.2 Role-based access control (RBAC)

- **Permissions** are fine-grained per module, screen and action (view, create, edit, delete, approve, export, print, void, give discount, override price).
- **Roles** are bundles of permissions. The system ships templates (Owner, Admin, Branch Manager, Cashier, Waiter, Storekeeper, Accountant, HR Officer, Payroll Officer, Procurement Officer, Employee self-service). Customers copy and edit them.
- **Scope:** each role assignment is limited to a company, branch or warehouse. Example: a manager of Branch A cannot see Branch B.
- **Field and data rules:** hide cost price from cashiers, hide salaries from non-payroll staff, limit discount to a set %.
- **POS controls:** PIN or card login at the till, manager override with PIN for voids, refunds, price changes and discounts above a limit.
- **Security:** two-factor login for back office, session and device management, full audit trail.

### 6.3 No-code process and workflow engine

One engine serves every module and does three jobs: (A) process flows that set the order of steps, (B) approvals, and (C) automation rules. It covers purchase requisitions, leave, expenses, payroll approval, stock adjustments, credit limit changes, discounts, journal entries, new customers, and any custom form.

**A. Process flows (order of steps)**

Each customer defines which documents and stages a process goes through, and in what order. The system ships a default flow per process and country pack; customers copy and change it.

| Process | Example: full flow | Example: simple flow |
| --- | --- | --- |
| Procurement | Requisition → RFQ → Tender → Purchase order → Goods received → Supplier invoice → Payment | Purchase order → Goods received → Payment |
| Sales (wholesale) | Quote → Sales order → Delivery note → Invoice → Payment | Invoice → Payment |
| Restaurant order | Order → Kitchen → Ready → Served → Bill → Payment | Order → Payment → Pickup |
| Stock transfer | Request → Approve → Dispatch → In transit → Receive | Transfer → Receive |
| Employee onboarding | Offer → Contract → Documents → Equipment → Payroll setup | Contract → Payroll setup |

- **Configure stages:** add, remove, rename and reorder stages; mark them mandatory or optional; add custom stages (e.g. "Quality check" after goods received).
- **Entry and exit rules:** conditions before a document moves on, e.g. no supplier payment until goods are received and the invoice matches the PO (three-way match), or no delivery for a customer over their credit limit.
- **Branching by condition:** different paths by amount, branch, department, item category, customer group or any custom field.
- **Parallel stages:** steps that run at the same time, e.g. IT setup and payroll setup during onboarding.
- **Auto-create the next document:** e.g. an approved requisition creates a draft PO; a received PO creates a draft supplier invoice.
- **Stage permissions:** which roles can move a document into or out of each stage.
- **Time limits per stage:** with the same reminders and escalation as approvals.
- **Status tracking:** every document shows where it is in its flow, who holds it and for how long; dashboards show bottlenecks.

**B. Approvals, escalation and delegation**

Any stage of a process flow can require an approval:

- **Visual builder:** drag-and-drop steps on a canvas: start, approval, parallel approval, condition, notification, update field, wait, end.
- **Conditions:** route by amount, currency, branch, department, item category, requester, custom field. Example: requisitions over 5,000 USD need the CFO.
- **Approvers:** a named user, a role, the requester's line manager, the department head, or a group (any one or all must approve).
- **Escalation:** if not acted on within a set time, remind, then escalate to the next level, or auto-approve/reject as configured.
- **Delegation:** users delegate approval rights for a date range (leave, travel). Admins can reassign stuck items. Every delegated action is logged with both names.
- **Actions:** approve, reject, return for changes, comment, attach documents.
- **Channels:** in-app inbox and email at launch; mobile push. SMS/WhatsApp notifications can be added later.
- **Versioning:** workflows are versioned; items in progress finish on the version they started on.
- **Custom fields and forms:** add fields to any record (text, number, date, dropdown, file, lookup), build new forms, and set document numbering and print templates, all without code.

&#91;embedded content: sample approval flow · purchase requisition\]

The customer draws this in the builder: the amount decides whether the CFO is needed, and escalation and delegation apply to every approval step.

**C. Automation rules (if this, then that)**

Customers build rules from a trigger, optional conditions and one or more actions, without code.

- **Triggers:** a record is created or changed, a stage is reached, a date arrives (e.g. 30 days before contract end), a threshold is crossed (stock below reorder level), or a schedule (every Monday 8:00).
- **Conditions:** any field, including custom fields, with AND/OR logic.
- **Actions:** create a document, update a field, change a stage, assign a user, send an in-app, email or SMS notification, put a customer on credit hold, call a webhook.

Examples:

- When stock falls below reorder level, create a purchase requisition for the preferred supplier.
- When a customer invoice is 30 days overdue, put the customer on credit hold and notify the account manager.
- When an employee contract ends in 30 days, notify HR and the line manager.
- When a sale over 1,000 USD has a discount above 10%, require manager approval before payment.

Every rule run is logged, can be tested in a sandbox before going live, and has safeguards against loops (a rule cannot trigger itself endlessly).

### 6.4 Multi-currency

- Each company has a **base currency** and one or more **reporting currencies** (e.g. DRC: base CDF or USD, report in USD).
- **Exchange rates:** daily rates entered manually or fetched from a feed, with full history. Each transaction stores the rate used.
- **At the till:** prices can be shown in two currencies, customers can pay in a mix (e.g. part USD cash, part CDF, part mobile money), and change is given in the currency chosen, with rounding rules per currency.
- **Purchasing and accounting:** supplier invoices in any currency, landed costs, realised and unrealised FX gains and losses, revaluation at period end.
- **Payroll:** salary defined in one currency and paid or taxed in another where the law requires.

### 6.5 Branding, layout and appearance (make it theirs)

Each customer can make the platform look like their own system rather than a generic SaaS product, without code. Customisation is stored per tenant (and optionally per company or branch) and applies to web, POS app and documents.

**Brand and theme**

- Logo, favicon, brand colours, fonts, light/dark mode, button and corner styles, background images.
- Theme presets plus a live preview; a contrast check stops unreadable colour choices.
- Branded login page with their logo, background and welcome message.

**Their own address and identity (white-label)**

- Custom domain, e.g. `erp.theircompany.co.ke`, with automatic SSL certificates.
- Emails, SMS and WhatsApp messages sent under their name and domain.
- Option to hide our branding ("Powered by") on higher plans.
- Enterprise option: a separately branded mobile/POS app published under their name in the app stores.

**Layout designers (drag and drop)**

- **Dashboards:** choose and arrange widgets (KPIs, charts, lists, shortcuts) per role or per user.
- **Navigation:** reorder, rename, hide and group menu items per role; set each role's home page.
- **Forms and screens:** arrange fields into sections, tabs and columns; set labels, help text, defaults and required fields; hide what a role does not need.
- **List views:** choose columns, filters, sorting and saved views.
- **POS screen:** product grid size and order, category tiles with colours and images, quick-action buttons, keypad position, and a customer-facing display with their logo and promotions.
- **Documents:** a template designer for receipts, invoices, quotes, POs, delivery notes, payslips and reports: logo, layout, fields, terms, QR codes and bilingual (English/French) text.

**Rules for the build team**

- All UI draws colours, fonts and spacing from theme tokens; nothing is hard-coded, so a theme change applies everywhere.
- Layouts are stored as versioned configuration (JSON) and can be previewed, published, rolled back and copied between companies or branches.
- Required fiscal elements (KRA eTIMS and DRC DGI data, tax lines, QR codes) are locked on documents and cannot be removed by a template.
- Upgrades never break a customer's layout: new fields arrive hidden or in a default position for them to place.

**Packaging:** basic branding (logo, colours, document templates) is included in every plan. Custom domain, hiding our branding and advanced layout designers come with Business and Enterprise. Starter customers can buy the Brand Pack add-on (custom domain, emails and SMS under their name, our branding removed) for KES 1,500 / USD 12 a month; the layout designers stay with Business and Enterprise. A separately branded app store app is Enterprise-only and quoted.

## 7. Payments, tax and compliance

### 7.1 Payments at the till

| Method | Kenya | DR Congo | Notes |
| --- | --- | --- | --- |
| Mobile money | M-Pesa (STK push, Till/Paybill confirmation), Airtel Money | Vodacom M-Pesa, Orange Money, Airtel Money, Afrimoney | Auto-match payment to the sale; manual confirmation fallback when offline |
| Cards | Visa/Mastercard via bank or PSP terminals, tap-to-pay on Android | Same, via local acquirers | Integrate through a payment aggregator where possible to reduce one-by-one integrations |
| Cash | KES (USD where accepted) | USD and CDF | Multi-currency cash drawer, change in either currency |
| Store credit, vouchers, points | Yes | Yes | From the Loyalty module |
| Split payment | Yes | Yes | Any mix of the above on one receipt |

**Build note:** use a payment-provider adapter layer, so each provider is a plug-in with the same interface (initiate, confirm, refund, reconcile).

**Decision – hybrid integration:**

- **Kenya M-Pesa:** direct to Safaricom Daraja (STK Push, C2B confirmation, B2C for refunds and salary payouts). This keeps merchants on Safaricom's standard tariff with no middleman fee.
- **Kenya cards and Airtel Money:** through one licensed aggregator.
- **DRC (Vodacom M-Pesa, Orange Money, Airtel Money, Afrimoney, cards):** through one aggregator active in the DRC, so one API covers all wallets.
- The specific aggregators are chosen after sandbox tests: fees, settlement time, USD and CDF support, uptime and support quality.

### 7.2 Tax and e-invoicing

- **Kenya – KRA eTIMS:** every sale and credit note is transmitted and the receipt carries the eTIMS details and QR code. Support the OSCU/VSCU integration options.
- **DRC – DGI normalized invoicing:** integrate with the approved electronic invoicing device/system (e-MCF) so receipts carry the required fiscal data.
- **Offline handling:** sales made offline are queued and transmitted when back online, within the deadlines the authorities allow.
- **Tax engine:** VAT rates and exemptions, tax-inclusive and exclusive prices, withholding tax, excise where applicable, configured per country pack.

### 7.3 Payroll compliance

- **Kenya:** PAYE with reliefs, NSSF, SHIF, Affordable Housing Levy, NITA; P9 and statutory return files.
- **DRC:** IPR, CNSS, INPP, ONEM; required declarations.
- Rates and bands are data tables with effective dates, so legal changes are a configuration update, not a code release.

### 7.4 Data protection

Comply with Kenya's Data Protection Act (register with the ODPC) and applicable DRC rules. Encrypt data in transit and at rest, keep backups, and give customers data export.

## 8. Self-onboarding, pricing and billing

### 8.1 Self-onboarding flow

1. Sign up with email or phone; verify by OTP.
2. Choose country, language (English/French) and business type. The business type pre-selects a POS mode, role templates and suggested modules.
3. Pick modules and the number of branches, users and employees; see the monthly price live.
4. Start a **14–30 day free trial** (no payment needed to start).
5. Guided setup wizard: company details, tax registration (KRA PIN / DRC NIF), currencies, branches, first items (import from Excel), payment methods, users and roles.
6. Download the POS app and pair devices with a code.
7. Pay to convert from trial: mobile money or card; monthly or annual.

In-app checklists, sample data and short videos help users get to their first sale fast.

### 8.2 Pricing model (recommended: hybrid)

Each module is priced by the unit it naturally grows with, so revenue rises as customers grow.

| Module group | Price unit |
| --- | --- |
| POS, Inventory, Stores, E-commerce | Per branch/outlet per month (includes a set number of devices) |
| HR, Payroll | Per employee per month (minimum fee) |
| Accounting, Procurement, CRM, Fixed assets, Manufacturing | Per company per month, tiered by transaction volume or users |
| Loyalty, promotions and credit | Add-on per branch per month |
| Extra devices, extra users, SMS | Add-on charges |

**Bundles** (Starter, Business, Enterprise) combine popular modules at about 15–25% below the à la carte total. Annual payment earns about 2 months free. Prices are set per country in local currency (KES; USD in DRC). Proposed price points are in 8.4.

### 8.3 Subscription management

- Customers add or remove modules, branches, users and employees any time; charges are pro-rated.
- Usage limits are enforced per plan (devices, users, transactions).
- Billing by mobile money and card, with automatic invoices and eTIMS/DGI-compliant receipts.
- Dunning: reminders before and after due date, then read-only mode after a grace period (POS keeps selling for a short window so the customer never loses sales).
- A super-admin console for our team: tenants, plans, coupons, trials, impersonation (with consent and audit), revenue reports.

**Usage limits – soft limits with a 7-day window (decided):** the system counts outlets, devices, users and employees against the plan. When a customer goes over, it warns the owner in-app and by email, shows the extra cost, and offers a one-click upgrade. Reminders follow for 7 days. If the customer has not upgraded by day 7, the system blocks adding more outlets, devices, users or employees until they do; everything already set up keeps working. It never blocks a sale, a payroll run or an approval. Extra usage is charged at the add-on rate on the next invoice either way. Soft limits cover going over the plan's quantities only; they never cover non-payment. An unpaid invoice follows the dunning rules above: reminders, grace period, then read-only.

### 8.4 Price list (agreed baseline, refine after pilots)

The proposal prices a one-outlet shop on POS + Inventory + Loyalty at KES 3,900 a month, inside the KES 1,500–5,000 per till that most Kenyan SME POS systems charge, while bundling more features. DRC prices are in USD, since software there is bought in dollars.

**Benchmarks found (indicative, September 2026):**

- Kenyan SME POS: mostly KES 1,500–5,000 per till per month; multi-branch and ERP-bundled options KES 8,000–15,000+ ([paybillke comparison](https://paybillke.com/guides/mpesa-pos-integration-kenya-2026)). One local POS lists plans of KES 1,500, 3,500 and 7,500 a month ([SokoniPOS](https://shocpcms.vps.webdock.cloud/)).
- Kinshasa phone-based POS subscriptions: roughly the equivalent of 8,000–20,000 FCFA a month, about USD 14–35 ([Kolonell](https://kolonell.com/fr/blog/application-caisse-pos-mobile-money-boutique-kinshasa-2026)).
- Payroll: Sage launched Kenyan cloud payroll at KES 100 per employee per month ([Business Daily](https://www.businessdailyafrica.com/bd/corporate/companies/accounting-software-firm-sage-launches-new-product-2146616)); Workpay charges per employee per month.
- Accounting: QuickBooks Online lists at USD 38–275 a month, Xero USD 20–78, Zoho Books from USD 20 ([NerdWallet](https://www.nerdwallet.com/article/small-business/quickbooks-pricing), [Beancount](https://beancount.io/blog/2026/07/26/quickbooks-online-price-increase-2026-cost-breakdown-guide)).

**À la carte prices (monthly)**

| Module | Unit | Kenya (KES) | DRC (USD) |
| --- | --- | --- | --- |
| POS (all verticals, 2 devices included) | per outlet | 2,500 | 20 |
| Extra POS device | per device | 500 | 4 |
| Inventory | per outlet/warehouse | 1,500 | 12 |
| Stores | per outlet/warehouse | 1,000 | 8 |
| Loyalty, promotions and credit | per outlet | 1,000 | 8 |
| E-commerce and delivery | per online store | 2,500 | 20 |
| Accounting Essentials | per company | 3,500 | 28 |
| Accounting Pro (multi-currency, budgets, consolidation) | per company | 7,000 | 55 |
| Procurement (incl. tenders and bidding) | per company | 3,000 | 25 |
| CRM and sales | per company | 2,000 | 15 |
| Fixed assets | per company | 1,500 | 12 |
| Manufacturing / production | per company | 4,000 | 30 |
| HR (incl. shifts and attendance) | per employee (min. 1,500 / 12) | 100 | 1.00 |
| Payroll | per employee (min. 2,000 / 15) | 150 | 1.50 |
| Brand Pack (Starter only: custom domain, emails/SMS under their name, no "Powered by") | per tenant | 1,500 | 12 |

**Bundles (monthly)**

| Bundle | Includes | Kenya (KES) | DRC (USD) |
| --- | --- | --- | --- |
| Starter | 1 outlet: POS, Inventory, Loyalty | 3,900 | 30 |
| Business | 1 outlet: POS, Inventory, Stores, Loyalty, Accounting Essentials, Procurement; extra outlets at the outlet rate | 9,900 + 4,000 per extra outlet | 79 + 32 per extra outlet |
| Enterprise | Everything, unlimited workflows, consolidation, priority support | From 40,000 (quoted) | From 320 (quoted) |

HR and Payroll stay per employee in every bundle. Annual payment gives 2 months free. One-time paid onboarding (data migration, training) is offered to mid-size and enterprise accounts.

### 8.5 Support model (tiered by plan)

Support is in English for Kenya and French for the DRC. Hours follow local time in each country (Kenya UTC+3, Kinshasa UTC+1). Response targets are proposals to confirm once the support team is sized.

| Plan | Channels | Hours | Target first response |
| --- | --- | --- | --- |
| Starter | Help centre, in-app chat, WhatsApp, email | Business hours, Mon–Sat | Within 8 business hours |
| Business | Starter + phone, remote screen-share | Extended hours, 7 days | Within 2 hours |
| Enterprise | Business + dedicated account manager, on-site visits (paid) | 24/7 for critical issues (POS down, payments failing) | Within 30 minutes for critical, 2 hours for others |

- **All plans:** a help centre with articles and short videos in English and French, in-app guided tours, and a status page.
- **Severity levels:** Critical (cannot sell or pay staff), High (major feature broken), Normal (question or minor bug).
- **Tooling:** one helpdesk that brings WhatsApp, email, chat and phone tickets together, linked to the tenant in the super-admin console.

## 9. Technical architecture

The platform is a **modular monolith** on the team's chosen stack: one Laravel codebase split into independent modules, with a React web app and a React Native POS app. This is faster to build and run than microservices, and modules can be split out later if one needs to scale on its own.

| Layer | Choice | Notes |
| --- | --- | --- |
| Web app (back office, admin, workflow builder) | React (JavaScript) | Component library + i18n (English/French); React Flow (or similar) for the drag-and-drop workflow canvas |
| POS and mobile apps | React Native (JavaScript) | Android phones, tablets, POS terminals (e.g. Sunmi SDK for built-in printers/scanners) |
| Backend API | Laravel (PHP) | One module per business module; REST API (versioned); queues for background jobs |
| Database | PostgreSQL | Tenant ID on every table plus row-level security; JSONB for custom fields and workflow definitions |
| Offline store on device | SQLite (via a sync-capable library such as WatermelonDB) | Holds items, prices, customers, promotions and unsent sales |
| Cache and queues | Redis + Laravel Horizon | Notifications, e-invoice transmission, payroll runs, reports |
| Files | Object storage (S3-compatible) | Receipts, attachments, payslips, exports |
| Search and reporting | PostgreSQL read replica at first | Add a reporting store later if volumes need it |

### Key design decisions

- **Module boundaries:** each module owns its tables, API routes and UI; modules communicate through domain events and a small set of shared core services, never by reading each other's tables directly.
- **Feature flags per tenant:** a module's routes, menus and event listeners load only if the tenant subscribes to it.
- **Offline sync:** every record created on a device gets a UUID; sales are append-only and synced idempotently (safe to resend). Master data syncs down in increments. Conflicts are resolved by clear rules (server wins for prices, device wins for completed sales). Receipt numbers are pre-allocated per device.
- **Workflow engine:** workflow definitions stored as JSON (nodes + edges + conditions), executed by a state machine with timers for escalations; every module raises "approval needed" through one shared service.
- **RBAC:** permissions checked in API middleware and policies, scoped by company and branch; the same permission list drives what the UI shows.
- **Integrations:** adapters for payment providers, KRA eTIMS, DRC DGI, SMS/email and bank files, each behind a common interface.
- **Printing and hardware:** ESC/POS receipt and kitchen printers (Bluetooth, USB, network), barcode scanners, scales, cash drawers. We do not sell hardware: we publish a certified device list (Android phones and tablets, POS terminals, printers, scanners, scales, drawers) that the team tests and keeps current, with setup guides for each, and customers buy from their own suppliers.
- **Hosting:** Linode (Akamai Cloud). Use the Johannesburg region if it is generally available for the services we need; otherwise Frankfurt or London, and test latency from Nairobi and Kinshasa before choosing. Run separate servers for web/API, queue workers and the database (or Akamai Managed PostgreSQL), behind a NodeBalancer, with Object Storage for files. Daily backups, point-in-time recovery, monitoring and alerts. Confirm with counsel that hosting outside Kenya and the DRC meets data-protection rules for cross-border transfers.
- **Security:** HTTPS everywhere, encryption at rest, two-factor auth, rate limiting, audit logs, regular penetration tests.

&#91;embedded content: system architecture · 3 apps, 1 API, 12 modules on a shared core\]

All three apps call one Laravel API; modules sit on the shared core and talk through domain events, and the core reaches outside services only through adapters.

## 10. Delivery plan

The decision is to launch with all modules. That is a large build, so the team should still **build in a fixed order internally** and test each layer before the next depends on it, then release everything together.

**Rough estimate (to be refined by the team):** about 12–18 months with a team of roughly 14–20 people. A smaller team means a longer timeline, not a smaller scope.

| Role | Approx. count |
| --- | --- |
| Product manager / business analysts (incl. Kenya and DRC tax/payroll expertise) | 2–3 |
| UI/UX designer | 1–2 |
| Laravel backend developers | 5–7 |
| React web developers | 3–4 |
| React Native developers | 2–3 |
| QA / test engineers | 2–3 |
| DevOps / security | 1 |

**Internal build order**

1. Platform core: tenancy, users, RBAC, master data, multi-currency, audit, i18n, subscription and billing.
2. Workflow engine, custom fields and forms (everything else uses them).
3. POS (offline sync first), Inventory, Loyalty/promotions/credit, payments, eTIMS and DGI.
4. Accounting, Procurement, Stores.
5. HR, Payroll (both countries).
6. CRM, E-commerce and delivery, Fixed assets, Manufacturing.
7. Self-onboarding polish, pilot with 5–10 real businesses in each country, then public launch.

## 11. Key risks and mitigations

| Risk | Mitigation |
| --- | --- |
| All-at-once launch delays revenue and feedback | Run private pilots per module as each finishes; keep the release date flexible |
| Offline sync bugs cause lost or duplicated sales | Design sync first, idempotent UUIDs, heavy automated testing, field tests in DRC |
| Tax and payroll rules change | Rules as dated configuration tables; local compliance advisers in both countries |
| Payment and tax integrations take longer than expected | Start approvals and sandbox access in month 1; use aggregators where possible |
| Too complex for small shops | Business-type presets, simple default setup, hide unused modules |
| Low trust in cloud software | Local support (phone/WhatsApp), on-site training via field sales, data export |

## 12. Open questions

- [x] Actual price points per module and bundle, in KES and USD
- [x] Which payment aggregator(s) to use in each country
- [x] Hosting provider and region, and any data-residency requirement
- [x] Support model: hours, channels, languages
- [x] Hardware: resell POS devices and printers, or certify a list only?
- [x] Will Swahili or Lingala be added later for cashier screens?
- [x] Public API and marketplace for third-party integrations: at launch or later?
- [x] Usage limits per plan: which limits to enforce (devices, users, transactions), how strictly, and what happens when a customer hits one

## 13. Name ideas

**No working name**. Designs and code show "Product name" and read the real name from one setting, so it can be chosen at any time. The ideas below remain candidates; each needs a trademark, company-name and domain check in both countries.

| Name | Meaning / idea |
| --- | --- |
| Mosala | Lingala for "work"; short, works in French and English |
| Umoja Suite | Swahili for "unity": one platform for every part of the business |
| Kitovu | Swahili for "hub" or "centre" |
| DukaOne | "Duka" (shop) plus one platform; strongly retail-flavoured |
| Biashara360 | Swahili "business", 360° view |
| Nexa Business | Neutral, bilingual, easy to brand across markets |
