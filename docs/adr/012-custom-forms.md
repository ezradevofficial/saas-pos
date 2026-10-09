# ADR 012: Custom forms

Status: Accepted (Phase 5, task 7: CF-04, CF-05, NUM-01, WF-01, RBAC-04)

## Context

Tenants build their own forms, such as a petty cash request or a vehicle request (CF-04). A form has fields, attachments, an optional table of lines with totals (CF-05), numbering and a process flow. It gets a list, an inbox and a detail page like a built-in document. Nothing about a form may need a deploy (rule 4).

## Decision

**Tables.** `custom_form_types`, `custom_form_records`, `custom_form_lines` and `custom_form_attachments`, all with `tenant_id` and forced row-level security.

- A type has a key (set once, never reused), a name typed once, and switches for the workflow, the line table and attachments.
- A record belongs to a company and, optionally, a branch and a location. It is archived, never deleted.
- A draft's lines are replaced as a whole when it is saved. The previous lines stay in the audit entry `core.custom_form.lines`.
- Attachments use the media disk like custom field files: uploaded first, tied to the record on save, read through a temporary signed URL.

**One form type, four registrations.** A type is tenant data, so nothing is registered at boot. Each registry takes a source, a closure that answers the current tenant's types when asked (`CustomFormTypes`, read once per request):

| Registry | What a type becomes |
| --- | --- |
| `CustomFieldEntities` | `custom_form:<key>` for header fields and `custom_form_line:<key>` for line fields (ADR 011) |
| `DocumentNumberTypes` | `core.custom_form_<key>`, default format `<KEY>-{YYYY}-{00001}`, reset yearly, editable in Numbering (NUM-01) |
| `DocumentTypeRegistry` | `core.custom_form_<key>`, when the type's workflow is on (WF-01) |
| `FormCatalogue` | the form layout `custom_form.<key>` (ADR 010) |

So fields are ordinary custom fields with every type and rule. Flows, approvals, conditions and automation read a record through `CustomFormDocumentType`: its number, place, amount, each header field (`cf_<key>`) and each line total (`total_<key>`).

**Totals (CF-05).** Every number and money line field is summed when the record is saved. Money is summed in minor units and must use one currency per field. The first money total, else the first money header value, is the record's amount for lists and the approvals inbox.

**Status.** Draft, then pending while its flow runs, then approved or rejected. Without a workflow, a sent draft is submitted. A draft or a pending record can be cancelled; cancelling a pending record cancels its flow. Only a draft changes.

**Default flow.** One approval by an Admin at the record's place. Tenants replace it in the workflow builder. The requester never approves their own record (APR-07).

**Permissions.** The permission catalogue is global and form types are tenant rows, so a permission per type is not possible. We use one generic set:

- `core.custom_form.view`, `create`, `edit` and `approve`, held at the record's company, branch or location with the usual inheritance (RBAC-04);
- a role list per type (`role_ids`). When it is not empty, the user must also hold one of those roles at a scope covering the record. Owners pass the list;
- `core.custom_form_type.manage` at tenant scope to build and change types.

A draft is changed, sent or cancelled by its creator (with `create` there) or by a holder of `edit`. Archiving needs `edit`.

Owner and Admin hold all of these. Branch Manager gets view, create, edit and approve; Accountant gets view, create and approve; Approver gets approve through `*.approve`.

## Consequences

- A type's workflow can't be turned off while records wait in a flow.
- Line fields can't be unique, and lines are not lookup targets.
- Custom forms have no printable template yet. A type can register one with the document templates' data sources later.
- The till does not show custom forms.
