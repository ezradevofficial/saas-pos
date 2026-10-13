# ADR 018: Usage metering and soft limits

Status: Accepted (Phase 6, task 3: LIM-01..LIM-05, TEN-01, AUD-01)

## Context

Concept note 8.3 decides soft limits with a 7-day window. The platform counts outlets, devices, users and employees against the plan. When a customer goes over, the Owner is warned in the app and by email, with the extra cost and a one-click upgrade, and reminded daily for 7 days. After day 7 without an upgrade, only adding more of that item is refused. Sales, payroll runs and approvals are never blocked (LIM-04), and the extra usage is charged at the add-on rate on the next invoice either way (LIM-05).

Subscriptions (SUB-02) were built in parallel, so limits and billing meet only through two interfaces.

## Decision

**Units.** These are the four things counted:

- outlets: locations of type `outlet` that are not archived;
- devices: devices with status `active`;
- users: users with status `active`;
- employees: whatever `EmployeeCount` returns. It returns 0 (`NoEmployees`) until HR binds its own count.

**Interfaces with billing** (`App\Core\Limits`):

- `PlanAllowances::for(string $tenantId): Allowances` gives the allowed count of each unit, or null for unlimited. It also carries an optional `PlanSelection` (country, modules, quantities, bundle), used to price extra units through the catalogue.
  - The default, `SelectionAllowances`, reads the onboarding or trial selection (`onboarding_states.selection`) under row-level security.
  - From the catalogue it derives: outlets when a module sold per outlet is taken; devices as at least what the modules include (POS includes 2 per outlet); employees when HR or Payroll is taken.
  - Users stay unlimited, because the catalogue sells no user unit.
  - With no selection, or before onboarding is installed, everything is unlimited.
  - Billing binds its own implementation. `singletonIf` keeps whichever is bound first.
- `UsageOverage::overageFor(string $tenantId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): list<UsageOverageLine>` gives the overage of the UTC days in `[start, end)`.
  - Each line has `unit`, `quantity` (units over), `days` (days at that quantity) and `periodDays`.
  - Billing prices each line at the add-on rate through the catalogue, pro-rated by days.
  - `MeteredUsageOverage` reads `usage_meters` in the tenant's own context, so the answer is the same every time it is asked. Billing charges each period once by asking for it once, and no "billed" state is kept here.

**Metering (LIM-01).**

- `usage:meter` runs daily at 03:00 UTC. It reads tenant ids from `app_active_tenant_ids()` through `DueTenants::active()`, which is a security-definer function (ADR 002). No new function was needed, and the owner connection is never used.
- It dispatches one `MeterTenantUsage` job per tenant (`TenantAware`, unique per tenant).
- The job writes one `usage_meters` row per unit and UTC day, with the count and that day's allowance. A second run on the same day updates the day's row.
- The runtime role has no DELETE or TRUNCATE on `usage_meters` or `usage_overages`, so the history is kept.

**Overages (LIM-02, LIM-03).**

- When a count goes above its allowance, the job opens a `usage_overages` row with `started_at`. A partial unique index keeps it to one open row per unit.
- The Owners receive `core.usage_limit.exceeded` in the app and by email.
  - It is a system event type (ADR 009): mandatory and never digested.
  - It names the item, the count, the allowance and the extra monthly cost, and links to `/settings/subscription`.
  - The extra cost is the catalogue quote of the plan with the exceeded units at their counts, minus the quote at the allowances.
- On days 1 to 6 they receive `core.usage_limit.reminder`, once a day, whatever time the job runs: the day number is computed from `started_at`.
- From day 7 they receive `core.usage_limit.blocked` once.
- A row is closed (`within_allowance`, or `unlimited` when the plan stops limiting the unit), never deleted. Opening, updates and closing are audited as `core.usage_overage.*` (AUD-01).

**The only guard (LIM-03, LIM-04).** `UsageLimits::ensureCanAdd($unit)` is the only code that consults the limits.

- It is called by these actions:
  - outlets: create an outlet location, change a location to an outlet, restore an archived outlet;
  - devices: add a device, issue a pairing code, pair, resume a suspended device that was paired;
  - users: invite, reactivate.

  Each is called after the Form Request and policy, so a user without permission gets 403, not 402.
- It refuses with `402 usage_limit_reached` only when both are true: the unit's overage is past its window, and one more would still be above the allowance. The 402 carries a translated message (en and fr), `unit`, `allowed`, `count` and `upgrade_url`.
- If the count already fits (items archived or unpaired, or the plan upgraded), it closes the overage at once and lets the action through.
- `UsageLimitGuardsTest` finds every call in `app/` and `modules/`, and every route that reaches one, and fails on any other.
- Approvals, workflows, POS sales, shifts, number ranges and sync are proven to work while every unit is blocked.

**Web.**

- `GET usage` is for Owners only (`OwnerGuard`). No new permission was added.
- `me/permissions` gains an `owner` flag, which gates the banner, the Settings → Usage card and its menu entry.

## Consequences

- An invitation sent before the block can still be accepted, because it already exists. Devices given a code before the block are refused when they pair.
- Overage starts a new window each time it reopens. Going back within the plan for a day and then over again gives 7 more days of adding. The extra usage is still billed day by day from the meter records.
- Days with no meter record (a missed run) are billed as within the plan.
- When billing binds `PlanAllowances`, the allowances come from the subscription's quantities. The meter rows record the allowance in force each day, so billing never re-derives past allowances.
