# ADR 022: Country pack publishing

Status: Accepted (Phase 8, stage 8A: ADM-04, CP-01, CP-02, CP-03)

## Context

Country packs are data (CP-01, ADR 007). They ship as JSON files under `api/country-packs/{KE,CD}`:

- `pack.json`: tax codes and rates;
- `payroll.json`: the statutory components;
- `hr.json`: identifier types and statutory leave days;
- `holidays.json`;
- `fixed_assets.json`: tax wear-and-tear classes;
- `accounts.json`: the chart template.

Most figures are null ("rate needed"): nobody invents a rate. The owner will confirm the real values. Shipping them as a code change would mean a release of the app for every legal change, made by engineers, with no second pair of eyes. ADM-04 asks for a console path instead: staff load, diff, validate and publish updates with effective dates and versioning, and publishing takes two people.

Two of the six kinds are already written into database tables by owner-only deploy commands:

- tax: `country-packs:publish` writes versions into `country_packs` and `country_pack_tax_codes`, then propagates rates to tenants (ADR 007);
- holidays: `country-packs:holidays` replaces `public_holidays`.

The other four are read straight from the files at runtime (`PayrollPack`, `HrPack`, `TaxClasses`, `ChartTemplates`).

## Decision

**Releases are database rows that override the files.** We considered two options:

- Generating files on the server: they would be lost on the next deploy, they cannot be shared across web hosts, and they cannot be audited.
- Writing every kind into its own normalised tables: a schema per kind, for data whose shape still changes as the packs are confirmed.

We chose neither. `country_pack_releases` holds one row per release:

- country, kind and version;
- the whole JSON document and its sha256;
- an effective date;
- the validation and diff computed when it was loaded;
- who loaded, approved, rejected and published it, and when.

`PackDocuments` answers "what is the pack of KE payroll today?". The answer is the latest **published** release whose `effective_from` has come (UTC date), else the shipped file. Every reader asks it first:

- `PayrollPack`: source `release` and its version, stamped on each run;
- `HrPack`, `TaxClasses` and `ChartTemplates`;
- `FiscalRules`: the tax pack's `documents.fiscal`;
- the deploy commands `country-packs:publish` and `country-packs:holidays`.

So the files are the bootstrap and the fallback, and a published release wins from its date. Readers cache parsed content by release id, so a long-running worker picks up a new release without a restart. Rolling back means publishing a new release with the earlier content.

**Global, platform-written.** The table has no `tenant_id` (ADR 013): packs are the same for every tenant. Grants:

- the platform role `app_platform` has SELECT, INSERT and UPDATE;
- the runtime role `app` has SELECT;
- nobody has DELETE.

A trigger refuses every change to a release's content, hash, country, kind, version, effective date, loader or validation. It allows status only forward (`draft` → `approved` → `published`, or `rejected`). Check constraints refuse an approver who loaded the release and a publisher who approved it. `RlsCoverageTest` lists the table with the platform tables. The isolation suite treats it as global reference data.

**Lifecycle.**

1. **Load** (`platform.packs.manage`: owner, compliance, engineering). The document is validated and diffed at once and stored as the next version of its country and kind, as a draft, errors included, so staff see what to fix.
2. **Approve** (`platform.packs.approve`: owner, compliance, engineering). Not by the loader. Refused while the release has errors.
3. **Publish** (`platform.packs.publish`: owner, compliance). Not by the approver.

Every step is audited in the platform chain (`platform.pack.load|approve|reject|publish|apply`).

**Validation (`PackValidator`, `PackSchemas`).**

- **Errors:**
  - the document's `code` is not the release's country;
  - the kind's schema refuses it. The core registers tax (`PackFile`) and holidays (`HolidayFile`). Each module registers its own kind: Payroll, HR, Fixed assets, and Accounting, whose chart check is `ChartTemplates::check`. The core never reads a module's code.
  - two periods of one key overlap;
  - a row marked confirmed (`needs_confirmation: false`) has a null figure. Fields where null means "none" are exempt: `effective_to`, `pattern` and similar, and an exempt tax code's rate.
- **Warnings:** rows still marked as needing confirmation. Their nulls keep blocking what uses them at runtime, exactly as the shipped files do.
- **Diff:** against the pack in force (the release, else the file). Entries are keyed per kind: tax codes by code and start date, payroll components by code, leave types by key and start date, chart accounts by code. The diff lists entries added, removed and changed, and top-level settings changed.

**Effective dates.**

- Payroll, HR, fixed asset classes and charts are read where they are used, so a release simply takes over on its date.
- Tax and holidays are materialised:
  - tax: a new `country_packs` version, then propagation to tenants (ADR 007), whose rules are unchanged: a code with a tenant-entered rate is skipped;
  - holidays: the country's `public_holidays` are replaced.
- This happens when the release is published, if its date has come. Otherwise `country-packs:apply-releases` does it on the date (daily, 00:10 UTC). `applied_at` makes it happen once.
- The deploy commands read the release in force, so a deploy never reverts a published tax or holiday release to the file.

**No owner credentials on web or worker hosts (ADR 002).** Applying a tax or holidays release writes the pack tables as the platform role. The migration grants it SELECT and INSERT on `country_packs` and `country_pack_tax_codes`, and SELECT, INSERT and DELETE on `public_holidays`. Propagation lists tenants through `app_active_tenant_ids()` and enters each one under row-level security (`PropagateCountryPack::runFor`), never through the owner connection. The owner path (`country-packs:publish` at deploy) is unchanged.

**The owner's confirmed values take this path in production.** A compliance or engineering staff member:

1. starts from the shipped file;
2. fills in the confirmed figures, with `needs_confirmation: false`;
3. adds the legal reference to `sources`;
4. loads the document with the date the law takes effect.

A second person checks the diff and approves, and a compliance lead or the owner publishes.

## Consequences

- The shipped files stay the reference for new environments and tests. Production values live in releases until someone copies them back into the files.
- A release is a whole document, not a patch. A mistake is corrected by loading the next version; nothing is edited in place.
- Pack labels stay translation files (ADR 007). A release that adds a tax code or leave type needs its label in `lang/{en,fr}` to show a name rather than the code.
- Publishing a tax release propagates to every active tenant within the request (or the daily command). This is fine at today's scale. A queued fan-out per tenant is the follow-up when tenant counts grow.
