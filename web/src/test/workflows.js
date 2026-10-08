// Fixtures for the workflow pages (WF-01..WF-11, spec 6.4), shaped like the API's answers.
import { mockRoutes, tenantWide } from './renderApp'

export const DOCUMENT_TYPE = {
  key: 'procurement.requisition',
  label: 'Purchase requisition',
  fields: [
    { name: 'total', type: 'money', label: 'Total', values: [], reference: null, operators: ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty'] },
    { name: 'category', type: 'enum', label: 'Category', values: ['goods', 'services'], reference: null, operators: ['eq', 'ne', 'in', 'not_in', 'empty', 'not_empty'] },
  ],
  actions: ['submit', 'approve', 'reject'],
  next_documents: [{ key: 'order', target: 'procurement.order', label: 'Purchase order', fields: { amount: 'total' } }],
}

export const GRAPH = {
  nodes: [
    { id: 'start', type: 'start', name: 'Requisition submitted', position: { x: 0, y: 0 } },
    { id: 'budget', type: 'stage', name: 'Check budget', position: { x: 0, y: 150 }, entry: { all: [{ field: 'total', op: 'lte', value: { amount_minor: '100000000', currency: 'KES' } }] } },
    {
      id: 'manager',
      type: 'approval',
      name: 'Branch manager approves',
      position: { x: 0, y: 300 },
      approval: { approver: { type: 'branch_manager' }, mode: 'any' },
      escalation: { after: { amount: 8, unit: 'business_hours' } },
    },
    { id: 'big', type: 'condition', name: 'Total over KES 250,000?', position: { x: 0, y: 450 }, condition: { all: [{ field: 'total', op: 'gt', value: { amount_minor: '25000000', currency: 'KES' } }] } },
    { id: 'cfo', type: 'approval', name: 'CFO approves', position: { x: -200, y: 600 }, approval: { approver: { type: 'role', role: '0192a1b2-0000-7000-8000-0000000000c1' }, mode: 'any' } },
    { id: 'po', type: 'action', name: 'Create draft purchase order', position: { x: 0, y: 750 }, action: 'create_document', config: { mapping: 'order', on_cancel: 'keep' } },
    { id: 'done', type: 'end', outcome: 'approved', position: { x: 0, y: 900 } },
    { id: 'refused', type: 'end', outcome: 'rejected', position: { x: 300, y: 600 } },
  ],
  edges: [
    { from: 'start', to: 'budget' },
    { from: 'budget', to: 'manager' },
    { from: 'manager', to: 'big', branch: 'approved' },
    { from: 'manager', to: 'refused', branch: 'rejected' },
    { from: 'big', to: 'cfo', branch: 'yes' },
    { from: 'big', to: 'po', branch: 'no' },
    { from: 'cfo', to: 'po', branch: 'approved' },
    { from: 'cfo', to: 'refused', branch: 'rejected' },
    { from: 'po', to: 'done' },
  ],
}

export const WORKFLOW = {
  id: 'w-1',
  document_type: 'procurement.requisition',
  document_type_label: 'Purchase requisition',
  company_id: 'c-1',
  company_name: 'Amani Retail Ltd',
  published: { id: 'v-3', workflow_id: 'w-1', version: 3, status: 'published', source: 'draft', published_at: '2026-10-01T08:00:00Z', graph: GRAPH },
  draft: { id: 'v-4', workflow_id: 'w-1', version: 4, status: 'draft', source: 'draft', published_at: null, graph: GRAPH },
  created_at: '2026-09-01T08:00:00Z',
  updated_at: '2026-10-07T08:00:00Z',
}

export const VERSIONS = [
  { id: 'v-4', workflow_id: 'w-1', version: 4, status: 'draft', source: 'draft', published_at: null, in_progress: 0 },
  { id: 'v-3', workflow_id: 'w-1', version: 3, status: 'published', source: 'draft', published_at: '2026-10-01T08:00:00Z', in_progress: 6 },
  { id: 'v-2', workflow_id: 'w-1', version: 2, status: 'archived', source: 'default', published_at: '2026-09-01T08:00:00Z', in_progress: 0 },
]

export const COMPANIES = [
  { id: 'c-1', name: 'Amani Retail Ltd', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null },
  { id: 'c-2', name: 'Kin Market', country: 'CD', base_currency: 'CDF', timezone: 'Africa/Kinshasa', archived_at: null },
]

export const WORKFLOW_PERMISSIONS = tenantWide(['core.workflow.view', 'core.workflow.edit', 'core.workflow.publish', 'core.company.view'])

/** The GET answers the workflow pages ask for; `extra` routes win. */
export function mockWorkflows(api, { workflow = WORKFLOW, permissions = WORKFLOW_PERMISSIONS, extra = [] } = {}) {
  mockRoutes(
    api,
    [
      ...extra,
      ['workflow/document-types', { data: [DOCUMENT_TYPE], meta: { action_handlers: ['create_document', 'notify'] } }],
      [`workflows/${workflow.id}`, { data: workflow }],
      [`workflows/${workflow.id}/versions`, { data: VERSIONS }],
      ['roles?per_page=200', { data: [{ id: '0192a1b2-0000-7000-8000-0000000000c1', name: 'CFO', is_system: false, template_key: null }, { id: '0192a1b2-0000-7000-8000-0000000000a1', name: 'Administrator', is_system: true, template_key: 'admin' }] }],
      ['users?status=active&per_page=200', { data: [{ id: '0192a1b2-0000-7000-8000-0000000000b2', name: 'Baraka Mwangi' }] }],
    ],
    { permissions, companies: COMPANIES },
  )
}
