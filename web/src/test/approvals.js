// Fixtures for the approvals pages (APR-01..APR-08), shaped like the API's answers.

const CAN_ALL = { approve: true, reject: true, return: true, request_info: true, bulk_approve: true, comment: true, attach: true, reassign: false }

/** One inbox item; `overrides` replace top-level keys. */
export function approvalItem(id, overrides = {}) {
  return {
    id,
    status: 'pending',
    outcome: null,
    auto_decided: false,
    blocked_reason: null,
    blocked_label: null,
    document: {
      type: 'procurement.requisition',
      type_label: 'Purchase requisition',
      id: `doc-${id}`,
      number: 'PR-NBO-00231',
      title: 'Cooking oil restock, 40 cartons',
      amount: { amount_minor: '11845000', currency: 'KES' },
      amount_label: 'Total before VAT',
    },
    step: { node_id: 'manager', name: 'Branch manager approves', index: 2, count: 3 },
    mode: 'any',
    company: { id: 'c-1', name: 'Amani Retail Ltd' },
    requester: { id: 'u-9', name: 'Grace Wanjiru' },
    received_at: '2026-10-07T06:14:00Z',
    waiting_since: '2026-10-07T06:15:00Z',
    due_at: '2026-10-08T14:00:00Z',
    overdue: false,
    escalation: { at: '2026-10-08T14:00:00Z', to: 'the area manager' },
    final: null,
    decided_at: null,
    my_assignment: { id: `as-${id}`, status: 'pending', delegated_from: null, decided_at: null, on_behalf_of: null },
    can: { ...CAN_ALL },
    require_reason: false,
    ...overrides,
  }
}

/** The detail of an item: route, approvers, history, attachments and return targets. */
export function approvalDetail(item, overrides = {}) {
  return {
    ...item,
    version: { id: 'v-3', number: 3 },
    settings: { mode: 'any', chain: [], require_reason: item.require_reason, allow_bulk: true, allow_delegation: true, allow_email: true },
    document_link: null,
    route: [
      {
        node_id: 'big',
        node_name: 'Total over KES 250,000?',
        kind: 'condition',
        branch: 'no',
        checks: [{ branch: 'yes', field: 'total', label: 'Total', op: 'gt', passed: false }],
        explanations: ['Under KES 250,000, so the CFO step is skipped.'],
      },
    ],
    approvers: [
      {
        id: 'as-1',
        user: { id: 'u-1', name: 'Amina Otieno' },
        step: 2,
        source: 'resolved',
        status: 'pending',
        decided_by: null,
        on_behalf_of: null,
        decided_at: null,
        comment: null,
        reassigned_from: null,
        reassigned_by: null,
      },
    ],
    history: [
      { id: 'h-1', type: 'requested', label: 'Sent for approval', user: { id: 'u-9', name: 'Grace Wanjiru' }, on_behalf_of: null, comment: null, data: {}, occurred_at: '2026-10-07T06:14:00Z' },
      {
        id: 'h-2',
        type: 'approved',
        label: 'Approved',
        user: { id: 'u-5', name: 'Brian Kiprop' },
        on_behalf_of: { id: 'u-6', name: 'Jean Kabila' },
        comment: 'Stock is low',
        data: {},
        occurred_at: '2026-10-07T07:00:00Z',
      },
    ],
    attachments: [
      { id: 'f-1', name: 'quote.pdf', mime: 'application/pdf', size: 2048, uploaded_by: { id: 'u-9', name: 'Grace Wanjiru' }, created_at: '2026-10-07T06:14:00Z', url: 'https://files.example/quote.pdf?signature=x' },
    ],
    return_targets: [{ node_id: 'start', name: 'Requisition submitted' }],
    ...overrides,
  }
}

export const page = (data, total = data.length) => ({ data, meta: { total, last_page: 1, from: data.length ? 1 : null, to: data.length } })
