# Phase 6: self-onboarding, subscriptions, billing and limits

Roadmap exit criteria: onboarding matching `OnboardBusiness`, `OnboardModules` and `OnboardSetup`; plan catalogue and live pricing from concept note 8.4; dunning; soft limits; import wizard; exports.

Requirements: ONB-01..08, SUB-01..07, LIM-01..05, IMP-01..03, EXP-02. EXP-01 shipped in phase 3. Concept note 8.1, 8.3 and 8.4.

## Ground rules

- **CLAUDE.md in full.**
  - Every tenant table has an isolation test.
  - Money is bigint minor units plus currency, with no floats; KES has 2 decimals and USD 2.
  - Every create, update, payment, plan change and status change is audited.
  - Form Requests and policies on every endpoint.
  - en/fr for every string.
  - Styling uses tokens only (spacing 0, px, 1–6, 10, 12 plus the token utilities).
  - Tenant-written text is single-language.
- **Prices are data, not code.** The 8.4 price list, bundles, units and the annual discount (2 months free) are seeded as catalogue rows that platform staff edit. Nothing about price is a constant in code.
- **Platform staff** are not tenant users. They get:
  - a separate `platform_users` table and auth guard;
  - 2FA required for every account;
  - every action audited.

  Cross-tenant work never bypasses RLS. It runs inside `TenantContext::run($tenantId, …)`, or through narrow SECURITY DEFINER functions as in ADR 002. The full super-admin console (ADM-) is phase 8. This phase builds only what SUB-01, ONB-04 (trial extension), ONB-08 (assisted onboarding) and dunning need.
- **The platform's own billing** (SUB-03) is done by the platform operator as a seller:
  - Invoices are issued per country by the operator's legal entity, using the existing fiscal queue and payment adapters.
  - The operator is set up as an internal "operator tenant" with its own companies (KE, CD), company fiscal settings and payment methods.
  - Platform invoices are documents of that tenant, so eTIMS/DGI and M-Pesa work exactly as for customers.
  - Credentials stay owner input. Fakes are used until then.
- **Payment providers** (SUB-04): M-Pesa (Daraja, existing), plus Airtel Money, Orange Money, Afrimoney and card behind the payment adapter interface. All have fake drivers; real drivers need credentials. Saved methods allow auto-renewal where the provider supports it.
- **LIM-04 is absolute.** Sales, payroll runs and approvals are never blocked by usage limits or by dunning, except the POS stop after the dunning POS window (SUB-05), which is an explicit owner rule.
- **Process.**
  - Write tests as you go; run only the touched tests, plus the shared fragile suites before every merge to main: RoleTemplates counts, Isolation, TranslationParity, SyncPerformance, Notifications preferences.
  - Run `pint --test` over the whole API before every push to main.
  - Merge locale JSON by parsing, never as text.
  - Check CI on main after each merge, waiting in the foreground.
- **Reviews.** Risky tasks get a reviewer: platform auth and catalogue, billing and payments, dunning, limits, and imports (data integrity, undo). Screens get Playwright checks with the individual tools, re-navigating before each step because the browser is shared. One whole-phase review at the end.

## Tasks

| # | Task | Requirements | Risk | After |
|---|------|--------------|------|-------|
| 1 | Platform staff foundation, plan catalogue and pricing engine | SUB-01, ONB-03 (price) | risky | – |
| 2 | Subscriptions, invoices, payments, dunning, reactivation, module switch-off | SUB-02..07, LIM-05 | risky | 1 |
| 3 | Metering and soft limits | LIM-01..04 | risky | 1 |
| 4 | Self-onboarding: sign-up, business types, module picker, trial, setup wizard, sample data, checklist, assisted onboarding | ONB-01..08 | normal (sign-up touches auth → reviewer) | 1 |
| 5 | Import wizard | IMP-01..03 | risky (data) | – |
| 6 | Full tenant data export | EXP-02 | risky (data leak) | – |
| 7 | Whole-phase review, Playwright walkthrough, report, roadmap tick | all | – | all |

### Task details

1. **Platform foundation, catalogue and pricing.**
   - `platform_users` table with 2FA required, a `platform` guard, and a minimal console at `/platform` in the web app, with its own sign-in and no tenant data.
   - Catalogue: modules (keys match the module registry), units (outlet, device, employee, company, online store, tenant), prices per country and currency (KES, USD), minimums (HR and Payroll), included quantities (POS includes 2 devices), bundles with included modules and per-extra-outlet prices, add-ons (Brand Pack), coupons (percent or fixed, with expiry and limits), and the annual discount.
   - Seed 8.4 exactly.
   - A pure `PriceCalculator`: a selection of modules and quantities, a country, a cycle and a coupon gives line items, the cheapest bundle suggestion and totals in minor units. Property tests check that a bundle is suggested only when cheaper. Shared with the web through an API endpoint used by the picker.
   - Versioned so prices can change without touching existing subscriptions.
2. **Subscriptions and billing.**
   - Per-tenant subscription: plan selection, quantities, cycle, status (trial, active, past_due, read_only, suspended, cancelled), period dates.
   - Self-service changes, pro-rated; downgrades take effect at period end.
   - Invoices generated automatically each cycle and on upgrades. They are documents of the operator tenant, fiscalised (eTIMS/DGI) through the fiscal queue.
   - Payment through the adapters, with saved methods and auto-renewal.
   - Dunning: reminders 7 and 3 days before and on the due date; grace period (platform setting, default 7 days); then read-only; the POS keeps selling for a further window (default 3 days), then stops.
   - Reactivation is immediate on payment.
   - Switching a module off hides it and keeps its data for 12 months; re-subscribing restores it.
   - Overage from Task 3 goes on the next invoice at the add-on rate.
   - Owner screens: Subscription (plan, usage, change, invoices, payment methods).
3. **Metering and limits.**
   - A daily count of active outlets, devices, users and employees per tenant (DueTenants, never the owner connection).
   - Over-limit warning to the Owner, in the app and by email, with the extra cost and one-click upgrade.
   - Daily reminders for 7 days; after day 7, block adding more of that item only.
   - Never block sales, payroll or approvals.
   - Overage reported to billing.
4. **Self-onboarding.**
   - Sign-up asks for country and language first. The tenant and subdomain are created in under a minute.
   - Business type presets: retail, supermarket, restaurant, bar, hotel, pharmacy, wholesale, van sales, salon, fuel station, non-POS company. Each pre-selects the POS mode, role templates, default flows and suggested modules, as data.
   - Module picker with live price (Task 1 engine) and bundle suggestions.
   - 14-day trial with no payment details. Trial status and days left are always visible; platform staff can extend it.
   - Setup wizard, every step skippable and resumable: company and tax ID, currencies and rates, branches and locations, taxes, payment methods, first items (manual or import, Task 5), users and roles, devices.
   - Sample data loadable and removable in one click.
   - Getting-started checklist until the first sale.
   - Assisted onboarding: platform staff create a tenant and hand over ownership.
   - Screens follow the onboarding screens in the design references.
5. **Import wizard.**
   - Items, customers, suppliers and prices now. Employees, opening stock and opening balances wait until HR, Inventory and Accounting exist; the importer framework is ready for them.
   - Templates to download in en and fr.
   - Steps: upload (xlsx, csv), column mapping, validation preview with row errors, dry run, import.
   - Background job with progress and a result file; at least 50,000 rows.
   - Undo the whole batch within 24 hours if nothing depends on it.
   - Custom fields go through the same validator (CF-03). Every row is audited as a batch.
6. **Full data export.**
   - The Owner requests a complete machine-readable export (JSON lines per table in a zip, plus files) of their tenant. Built in the background under RLS, with a download link that expires and is single-user. Audited.
   - Never includes secrets: hashes, device secrets, provider credentials.
