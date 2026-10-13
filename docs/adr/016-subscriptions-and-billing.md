# ADR 016: Subscriptions and the platform's own billing

Status: Accepted (Phase 6, task 2: SUB-02..SUB-07, LIM-05, TEN-01, AUD-01)

## Context

Customers pay for the modules they use, monthly or yearly, by mobile money, card or (Enterprise) bank transfer. The invoices must be fiscal (eTIMS in Kenya, DGI in the DRC). An unpaid account must lose write access without losing sales. Prices are data (ADR 013). Tenants never read each other's rows, and the scheduler never uses the owner connection (ADR 002).

## Decision

**The operator is a tenant.** `platform:operator --country=KE --country=CD` creates one internal tenant (`tenants.is_operator`, at most one) with a selling company per country. Each company gets its country pack's tax codes and default payment methods. Platform invoices (`operator_invoices`) are documents of that tenant. They are numbered (`core.billing_invoice`, NUM-01) and handed to the existing fiscal queue as source `billing`. They are collected through its payment methods (M-Pesa on its Daraja method). Credentials are entered later; fakes are used until then (`--fake` in local and testing). The operator id comes from the security-definer function `app_operator_tenant_id()`. The flag is set when the operator tenant is inserted. A trigger refuses any later change to it except by the schema owner, so no tenant can make itself the seller.

**Customer side.** These tables are under RLS:
- `subscriptions`: one per tenant. It holds the plan (modules, quantities, cycle, bundle), the catalogue price rows of the current period (`price_ids`, locked for the period), the status, the period, `pending_change`, the dunning dates and the grace override.
- `billing_invoices`: the read model.
- `billing_payment_methods`: phone or card token only, encrypted.
- `billing_payments`: the tenant's view of each payment.

The billing service writes the copy in the tenant's own context, in the same transaction as the operator invoice. A tenant never reads operator rows. `operator_accounts` mirrors each customer for the console.

**Changes (SUB-02).** Each change is a quote, then a confirm with the amount shown. A stale amount answers 409 `quote_changed`, with the fresh quote.
- During the trial, a change applies at once and costs nothing.
- A change that costs more applies at once, and the difference for the days left is invoiced. `PriceCalculator::prorate` takes the old and new period totals, the period's days and the days left. It uses integer maths and rounds half up to `billing.rounding_minor` (KES: whole shillings, so M-Pesa can collect it; USD: cents). A change that adds and removes items is judged on its total.
- A change that costs less or the same, or any cycle change, waits for the period end.
- A coupon added during a paid period applies from the next period, together with any change made with it.
- Plan changes and "Subscribe now" need the Owner. Viewing needs `core.subscription.view`; paying and payment methods need `core.subscription.manage`.
- What is charged is whole steps of the currency (`Rounding`, `billing.rounding_minor`): KES in whole shillings, which M-Pesa requires. Coupon discounts, quote totals and overage lines are rounded half up, and an invoice whose total still is not a whole step gets a rounding line.
- The module registry follows the plan, for modules the catalogue sells. A module switched off records `switched_off_at` and keeps its data. `billing:purge-report` flags it after 12 months for staff and deletes nothing.

**Renewal and dunning (SUB-03, SUB-05, SUB-06).** `billing:cycle` runs hourly. It gets tenant ids from `app_tenants_with_billing_due(at)` and runs a job per tenant in its context. The job (`RunBillingCycle`) goes on the `payments` queue, so it runs on Horizon's payments supervisor next to settlement, not on the default workers.
- Reminders go 7 and 3 days before the due date and on the due date, each once.
- On the due date the next period starts. The pending change applies, prices are the day's (then locked), overage is added, and the invoice is issued. A default saved method requests payment automatically.
- An invoice still open a day after its due date makes the subscription `past_due`. After the grace period (platform setting, default 7 days, per-tenant override) it becomes `read_only`, and `pos_stops_at` is the read-only time plus the POS window (default 3 days).
- A read-only subscription is not renewed.
- Paying the last overdue invoice makes it `active` at once.
- **Decision (the owner can reverse it): reactivation restarts the period.** When the overdue invoice is paid after the subscription's `current_period_end`, the period restarts from the payment date: one new period invoice, dated and due that day. The months spent read-only are never back-charged. (The alternative, a catch-up invoice from the old period end, charged for time the customer could not use.) `BillingCycle::restartAfterReactivation`; pinned by `DunningTest`.
- A period starts once. `startPeriod` re-checks under the subscription's lock that it has not started (the first: no period yet; a renewal: the period still ends on the due date) and issues nothing otherwise. A racing "Subscribe now" answers 409 `already_subscribed`.
- An invoice issued late (a catch-up after a reactivation) falls due when it is issued, never in the past. Its grace period runs from then, so a reactivated subscription does not slip back into read-only.
- A trial is invoiced when it ends, or earlier with "Subscribe now" (period from today, due at the trial end).

**Read-only (SUB-05).** `EnforceSubscription` is in the `tenant` middleware group. Every non-GET request answers 402 `subscription_overdue`, except:
- billing (`subscription/*`);
- approvals: decisions, comments, attachments and reassignment, the bulk approve, and the POS's held voids, refunds and cash movements;
- the user's own account: sign-out (here or everywhere), session revoke, two-factor and their own POS PIN (there is no own-password route; password reset is public);
- `tenant-exports` (EXP-02), and the export's download link (`tenant-exports/{id}/link`);
- the till's PIN, override and secret routes;
- POS uploads, until `pos_stops_at`; after that they answer 402 `subscription_pos_stopped`.

The till keeps the records and shows why. `DunningTest` enumerates every write route and asserts the allow-list. LIM-04 is absolute: approvals are never blocked, by usage limits or by dunning. Payroll runs get the same treatment when Payroll lands.

**Payments (SUB-04).** Payment intents of the operator tenant carry reference `billing.invoice`. They now have a `checkout` mode for hosted cards. `billing.drivers` picks the adapter per provider: M-Pesa through Daraja; Airtel Money, Orange Money, Afrimoney and the card checkout through adapters that report themselves unconfigured. All of these are `fake` in local and testing. An intent keeps the adapter it started with. A settled intent (`SettleBillingPayment`, also run when the payer's screen polls) marks the operator invoice, then the copy and the subscription. Staff mark bank transfers paid.
- **Exactly once.** Settling locks the operator invoice. The copy is paid by a conditional update, and only the update that changed it notifies and audits.
- **No double charge.** A new payment is refused (409 `payment_in_progress`) while any intent of the invoice is pending or unknown, whatever its age.
- **Overpayments.** A succeeded intent on an invoice already paid is recorded on the invoice (`overpayments`), alerted on the operator's account (shown in the console list) and in the platform audit, for staff to refund.

**Overage (LIM-05).** Limits' `UsageOverage::overageFor` gives the units over the plan, with days. `OveragePricing` prices them from the ended period's locked snapshot:
- devices at `pos_device`;
- outlets at the bundle's extra-outlet price, else the per-outlet modules' sum;
- employees at the per-employee modules' sum;
- users at nothing.

Each line is pro-rated by days over period days, half up. `subscriptions.overage_billed_through` makes each period charged once. Billing binds `PlanAllowances` to `SubscriptionAllowances`, which falls back to the onboarding selection. A plan change re-meters usage, so an overage the new plan covers closes at once.

**Trial (ONB-04).** Onboarding's picker (at sign-up) and assisted creation start the trial through `TrialStarter`. Billing binds it, `TrialStatusProvider` and `TrialExpiry` to `BillingTrial`:
- The first selection opens the trial subscription. A later one changes its plan at once, while the trial runs and no period is paid.
- There is one trial end, `subscriptions.trial_ends_at`. It is copied to `onboarding_states.trial_ends_at`, also when staff extend a trial.
- There is one trial length: `onboarding.trial_days`, which the staff platform setting can override.
- When the trial ends, the billing cycle issues the first invoice and the subscribed modules stay on. Onboarding's expiry does nothing.

**Tax.** Prices are tax-inclusive. The operator company's tax code (`billing.tax_codes`, a country pack code) supplies the rate on the issue date. With no confirmed rate, the tax is left out, and the fiscal queue holds the invoice for attention. No rate is ever written in code.

## Consequences

- Platform invoices fiscalise like customers' sales, and need the operator's eTIMS and DGI setup (owner input).
- Unpaid invoices are never voided automatically. A credit note for an abandoned invoice is a follow-up.
- A coupon applies to one period: the first after the trial, or the next one when it is added during a paid period. Redemptions count on the platform connection and are given back if the change rolls back.
- The PDF uses the default A4 invoice template from the tenant's copy. The authority's receipt details are not on it yet.
- Owner questions:
  - Are prices tax-inclusive?
  - Should coupons last longer than one period?
  - A reactivated read-only account restarts its period from the payment date (see Renewal and dunning). Keep it, or back-charge from the old period end?
