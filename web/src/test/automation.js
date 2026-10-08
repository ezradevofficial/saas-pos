// Fixtures for the automation pages (AUTO-01..AUTO-07), shaped like the API's answers.
import { mockRoutes, tenantWide } from './renderApp'
import { COMPANIES } from './workflows'

const MONEY_OPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty']

export const REQUISITION = {
  key: 'procurement.requisition',
  label: 'Purchase requisition',
  fields: [
    { name: 'total', type: 'money', label: 'Total', values: [], reference: null, operators: MONEY_OPS },
    { name: 'category', type: 'enum', label: 'Category', values: ['goods', 'services'], reference: null, operators: ['eq', 'ne', 'in', 'not_in', 'empty', 'not_empty'] },
    { name: 'needed_by', type: 'date', label: 'Needed by', values: [], reference: null, operators: MONEY_OPS },
    { name: 'quantity', type: 'number', label: 'Quantity', values: [], reference: null, operators: MONEY_OPS },
    { name: 'owner', type: 'reference', label: 'Owner', values: [], reference: 'core.user', operators: ['eq', 'ne', 'in', 'not_in', 'empty', 'not_empty'] },
    { name: 'note', type: 'string', label: 'Note', values: [], reference: null, operators: ['eq', 'ne', 'in', 'not_in', 'contains', 'empty', 'not_empty'] },
  ],
  writable_fields: ['category', 'note', 'total'],
  assignable_fields: ['owner'],
  user_fields: ['owner'],
  date_fields: ['needed_by'],
  capabilities: ['update_fields', 'assign_users', 'dates', 'create_drafts', 'credit_hold'],
  triggers: ['record_created', 'record_updated', 'record_archived', 'field_changed', 'stage_entered', 'stage_left', 'date', 'threshold', 'schedule'],
  actions: ['update_field', 'change_stage', 'assign_user', 'notify', 'create_document', 'set_credit_hold', 'webhook'],
}

export const ORDER = {
  key: 'procurement.order',
  label: 'Purchase order',
  fields: [
    { name: 'amount', type: 'money', label: 'Amount', values: [], reference: null, operators: MONEY_OPS },
    { name: 'memo', type: 'string', label: 'Memo', values: [], reference: null, operators: ['eq', 'ne', 'contains', 'empty', 'not_empty'] },
  ],
  writable_fields: [],
  assignable_fields: [],
  user_fields: [],
  date_fields: [],
  capabilities: ['create_drafts'],
  triggers: ['record_created', 'record_updated', 'record_archived', 'field_changed', 'stage_entered', 'stage_left', 'threshold', 'schedule'],
  actions: ['change_stage', 'notify', 'create_document', 'webhook'],
}

export const CATALOGUE = {
  data: [REQUISITION, ORDER],
  meta: {
    triggers: [],
    actions: [
      { key: 'update_field', label: 'Update a field', needs_document: true },
      { key: 'change_stage', label: 'Change stage', needs_document: true },
      { key: 'assign_user', label: 'Assign a person', needs_document: true },
      { key: 'notify', label: 'Send a notification', needs_document: false },
      { key: 'create_document', label: 'Create a document', needs_document: false },
      { key: 'set_credit_hold', label: 'Set credit hold', needs_document: true },
      { key: 'webhook', label: 'Call a webhook', needs_document: false },
    ],
    schedule: { every: ['day', 'week', 'month'], days: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] },
    recipient_prefixes: ['role:', 'user:', 'field:'],
    placeholders: ['document_type', 'rule_name'],
    limits: { subject: 150, message: 1000, actions: 20, max_days: 3650 },
  },
}

export const ROLE_ID = '0192a1b2-0000-7000-8000-0000000000c1'
export const USER_ID = '0192a1b2-0000-7000-8000-0000000000b2'

export const RULE = {
  id: 'r-1',
  name: 'Tell finance about big requisitions',
  document_type: 'procurement.requisition',
  document_type_label: 'Purchase requisition',
  company_id: 'c-1',
  company_name: 'Amani Retail Ltd',
  trigger: { type: 'record_created' },
  trigger_description: 'When a Purchase requisition is created',
  conditions: { all: [{ field: 'total', op: 'gt', value: { amount_minor: '25000000', currency: 'KES' } }] },
  actions: [{ type: 'notify', to: [`role:${ROLE_ID}`], subject: 'Big requisition', message: 'Total {total}' }],
  enabled: false,
  status: 'disabled',
  version: 2,
  has_webhook_secret: false,
  next_run_at: null,
  created_at: '2026-10-01T08:00:00Z',
  updated_at: '2026-10-07T08:00:00Z',
  archived_at: null,
}

export const RUN = {
  id: 'run-1',
  rule_id: 'r-1',
  rule_name: RULE.name,
  rule_version: 2,
  trigger_type: 'record_created',
  trigger: {},
  document_type: 'procurement.requisition',
  document_id: '0192a1b2-0000-7000-8000-00000000d0c1',
  outcome: 'failed',
  conditions: { passed: true, checks: [], failures: [] },
  actions: [
    { type: 'update_field', status: 'rolled_back' },
    { type: 'webhook', status: 'failed', error: 'The webhook answered 500.' },
  ],
  error: 'The webhook answered 500.',
  deliveries: [{ id: 'd-1', action_index: 1, url_display: 'hooks.example.com/in', status: 'failed', attempts: 3, response_status: 500, response_body: null, error: null, delivered_at: null }],
  error_code: 'action_failed',
  attempts: 3,
  chain_id: 'ch-1',
  depth: 1,
  caused_by_rule: false,
  started_at: '2026-10-07T08:00:00Z',
  finished_at: '2026-10-07T08:00:02Z',
  next_attempt_at: null,
  created_at: '2026-10-07T08:00:00Z',
}

export const AUTOMATION_PERMISSIONS = tenantWide(['core.automation.view', 'core.automation.edit', 'core.company.view'])

/** The GET answers the automation pages ask for; `extra` routes win. */
export function mockAutomation(api, { rule = RULE, permissions = AUTOMATION_PERMISSIONS, extra = [] } = {}) {
  mockRoutes(
    api,
    [
      ...extra,
      ['automation/catalogue', CATALOGUE],
      [`automation-rules/${rule.id}`, { data: rule }],
      // The rule a new rule's first save creates (the editor reads it again after saving).
      ['automation-rules/r-2', { data: { ...rule, id: 'r-2' } }],
      [/^workflows\?type=/, { data: [] }],
      ['roles?per_page=200', { data: [{ id: ROLE_ID, name: 'Finance', is_system: false, template_key: null }] }],
      ['users?status=active&per_page=200', { data: [{ id: USER_ID, name: 'Baraka Mwangi' }] }],
    ],
    { permissions, companies: COMPANIES },
  )
}
