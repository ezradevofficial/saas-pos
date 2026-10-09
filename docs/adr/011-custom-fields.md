# ADR 011: Custom fields

Status: Accepted (Phase 5, task 2: CF-01, CF-02, CF-03, CF-06, RBAC-05)

## Context

Tenants add their own fields to records (CF-01) with settings (CF-02). These fields must work everywhere: lists, workflow conditions, templates, exports, the API and the till (CF-03). Adding a field must never need a deploy (CF-06). Field rules (RBAC-05) must govern custom fields as they govern built-in ones.

## Decision

**Definitions.** Definitions live in `custom_field_definitions`, one row per entity and key. The table has tenant RLS and is audited as `core.custom_field.*`.
- A definition is archived, never deleted. Its values stay on the records and come back with a restore.
- The entity, key and type never change, and a key is never reused.
- The label is single-language tenant text, typed once.
- Permissions: `core.custom_field.view` anywhere, and `core.custom_field.manage` at tenant scope. Owner and Admin have both.

**Entity registry.** `CustomFieldEntities` registers what carries custom fields (`item`, `party`) and what a lookup may point at (those, plus `user`). Custom forms (CF-04) register an entity at run time.
- A `CustomFieldEntity` says who sees its records and which field-rules resource applies.
- It also gives the record's display label and the records the till syncs.

**Values.** Values live in the entity table's `custom jsonb` column, in fixed shapes (`CustomFieldTypes`). Money is minor units plus currency, and numbers are decimal strings; neither is ever a float.
- Form Requests call `CustomFieldValidator`. Only the keys given change, and null clears a value.
- `CustomFieldWriter` applies defaults on create and computes formulas on every save.
- Unique values: a transaction advisory lock is taken per tenant, entity and field, then the value is checked against active records. Archived records free their values, and a restore re-checks them.
- Required: on create, every editable required field without a default needs a value. On update, a required field that is given can't be cleared, so records saved before the field existed can still be edited.
- Lookups must name a record the user may see. A value already stored stays, even if someone else picked it.
- Files use the `media` disk and are served through signed URLs bound to the user, like item images.

**Indexes (CF-06).** Each entity table has one GIN `jsonb_path_ops` index on `custom`.
- Equality filters use `custom @> {"key": value}`.
- Ranges and sorts read `custom ->> 'key'`.
- No per-field expression index is created, so a new field needs no migration. If a large tenant needs a per-field index later, add it as a partial expression index created concurrently by a job. Don't add it in a deploy.

**Formulas.** Formulas use our own tokeniser, parser and tree walker (`Formula`), never `eval`.
- Allowed: decimal arithmetic, comparisons, `and`/`or`/`not`, `if()`, `round()`, `concat()`, and references to the entity's other active, non-formula fields.
- Results are computed on save. A failure (a missing input, division by zero) gives null and never fails the save.
- Editing a formula doesn't recompute stored records; each record is recomputed on its next save.

**Field rules (RBAC-05).** A custom field is hidden or read-only for a user from two sources:
- the definition's `visible_roles` / `editable_roles`, where an empty list means everyone;
- field rules named `custom.<key>`, or `custom` for every custom field.

An Owner always sees and edits every field. Formula fields are always read-only.
- Hidden values are left out of API resources, lists, sorts, filters, exports, history, merge fields and the till.
- **A write naming a non-editable field is refused, not ignored:** 422 `field_readonly`, naming `custom.<key>`. This is the same as `GuardsFieldRules` does for built-in fields, so a client never thinks a value saved when it didn't.

**Everywhere (CF-03).**
- Lists: `cf_<key>` columns and sorts, and `?custom[key]=` or `?custom[key][min|max]=` filters.
- Exports read the same columns.
- Workflow and automation: `DocumentType::customFieldEntity()` with `customFields()` and `customValues()` (`cf_<key>` fields).
- Templates: `CustomFieldMergeFields` (`custom.<key>`).
- Till: ItemSource and CustomerSource are now version 2 with `custom`. A `custom_fields` snapshot carries the labels, and staff field rules carry the hidden keys.
- Turning `show_on_pos` on or off, or archiving or restoring such a field, re-stamps the records from a queued job.
- Imports come in phase 6. They must go through `CustomFieldValidator` and `CustomFieldWriter`.

## Consequences

- Built-in notification event types have fixed placeholders. Custom values reach notification texts through automation, whose placeholders are document type fields.
- The POS app does not show custom values yet. The server sends them; the till's WatermelonDB schema and UI come with the POS layout work (task 6).
- History may show an entry whose only change was to a hidden custom field, with that value removed.
