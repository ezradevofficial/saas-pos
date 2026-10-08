// What the automation rule editor offers (AUTO-01..AUTO-07) and where it
// reads it from. The API stays the judge: every rule is validated again
// when it is saved, enabled or tested.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'

export const TRIGGER_TYPES = ['record_created', 'record_updated', 'record_archived', 'field_changed', 'stage_entered', 'stage_left', 'date', 'threshold', 'schedule']
export const ACTION_TYPES = ['update_field', 'change_stage', 'assign_user', 'notify', 'create_document', 'set_credit_hold', 'webhook']
export const SCHEDULE_EVERY = ['day', 'week', 'month']
export const WEEK_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
export const DATE_WHEN = ['before', 'after', 'on']
export const STAGE_HOW = ['completed', 'returned', 'cancelled', 'joined']
export const RUN_OUTCOMES = ['succeeded', 'skipped', 'failed', 'throttled', 'loop_blocked', 'retrying', 'queued', 'running']
export const MAX_ACTIONS = 20

/** Run outcomes as a dot's tone (status is a dot and a word). */
export const OUTCOME_TONES = {
  succeeded: 'success',
  skipped: 'neutral',
  failed: 'danger',
  throttled: 'warning',
  loop_blocked: 'danger',
  retrying: 'warning',
  queued: 'neutral',
  running: 'info',
}

/** A rule's status as a dot's tone. */
export const STATUS_TONES = { enabled: 'success', disabled: 'neutral', archived: 'neutral' }

/** Per-action results in the run log. */
export const ACTION_RESULT_TONES = { done: 'success', rolled_back: 'warning', failed: 'danger', not_run: 'neutral' }

/** Triggers that come from a change, whose test needs the values before it. */
export const CHANGE_TRIGGERS = new Set(['record_updated', 'field_changed', 'threshold'])

/** A trigger without a document (a schedule): its actions cannot read or change one. */
export const hasDocument = (trigger) => trigger?.type !== 'schedule'

/**
 * The catalogue (AUTO-01..AUTO-03): per document type its fields, the
 * fields automation may write or assign, user and date fields,
 * capabilities and usable triggers and actions; plus the action list,
 * placeholders and limits.
 */
export function useAutomationCatalogue() {
  const query = useQuery({ queryKey: ['automation', 'catalogue'], queryFn: () => api.get('automation/catalogue'), staleTime: 300_000 })
  const types = query.data?.data ?? []
  const meta = query.data?.meta ?? {}
  return {
    ...query,
    types,
    meta,
    actionsMeta: meta.actions ?? [],
    placeholders: meta.placeholders ?? ['document_type', 'rule_name'],
    limits: { subject: 150, message: 1000, actions: MAX_ACTIONS, max_days: 3650, ...(meta.limits ?? {}) },
  }
}

/** Whether an action needs the document (catalogue meta); unknown actions are assumed to. */
export function needsDocument(actionsMeta, key) {
  const entry = actionsMeta.find((one) => one.key === key)
  return entry ? Boolean(entry.needs_document) : !['notify', 'create_document', 'webhook'].includes(key)
}

/**
 * The stages of a document type's flows (WF-02), for stage triggers and
 * "change stage": stage and approval nodes of each flow's live version
 * (else its draft), by node id. Empty when the type has no flow yet.
 */
export function useFlowStages(documentType) {
  const query = useQuery({
    queryKey: ['workflows', 'stages', documentType],
    queryFn: () => api.get(`workflows?type=${encodeURIComponent(documentType)}&per_page=100`),
    enabled: Boolean(documentType),
    staleTime: 60_000,
  })
  const stages = []
  for (const flow of query.data?.data ?? []) {
    const graph = flow.published?.graph ?? flow.draft?.graph
    for (const node of graph?.nodes ?? []) {
      if ((node.type === 'stage' || node.type === 'approval') && !stages.some((one) => one.id === node.id)) {
        stages.push({ id: node.id, name: node.name || node.id })
      }
    }
  }
  return { stages, isLoading: query.isPending && Boolean(documentType) }
}

/** What the user may do with a rule (RBAC-04): edit at its company, or tenant-wide for a rule of every company. */
export function useAutomationRights(companyId) {
  const { can, tenantWide } = usePermissions()
  const canEdit = companyId ? can('core.automation.edit', { type: 'company', id: companyId }) : tenantWide('core.automation.edit')
  return { canEdit, canEditSomewhere: can('core.automation.edit'), canEditAll: tenantWide('core.automation.edit') }
}

/** A trigger of `type` with its settings filled from the document type; money starts in `currency`. */
export function newTrigger(type, info, currency) {
  const fields = info?.fields ?? []
  switch (type) {
    case 'field_changed':
      return { type, field: fields[0]?.name ?? '' }
    case 'stage_entered':
    case 'stage_left':
      return { type }
    case 'date':
      return { type, field: info?.date_fields?.[0] ?? '', days: 7, when: 'before' }
    case 'threshold': {
      const field = fields.find((one) => one.type === 'number' || one.type === 'money')
      return { type, field: field?.name ?? '', value: field?.type === 'money' ? { amount_minor: '', currency } : '', direction: 'down' }
    }
    case 'schedule':
      return { type, every: 'day', time: '08:00' }
    default:
      return { type }
  }
}

/** An action of `type` with its settings filled from the document type. */
export function newAction(type, info) {
  switch (type) {
    case 'update_field':
      return { type, field: info?.writable_fields?.[0] ?? '', value: '' }
    case 'change_stage':
      return { type, mode: 'move' }
    case 'assign_user':
      return { type, field: info?.assignable_fields?.[0] ?? '', user: '' }
    case 'notify':
      return { type, to: [], subject: '', message: '' }
    case 'create_document':
      return { type, target: '', mapping: {}, values: {} }
    case 'set_credit_hold':
      return { type, hold: true, reason: '' }
    case 'webhook':
      return { type, url: '' }
    default:
      return { type }
  }
}

/** Whether a value typed in the editor holds nothing yet (money with no amount, an empty string). */
export function isBlank(value) {
  if (value === undefined || value === null || value === '') return true
  if (typeof value === 'object' && !Array.isArray(value) && 'amount_minor' in value) return value.amount_minor === '' || value.amount_minor == null
  if (Array.isArray(value)) return value.length === 0
  return false
}

/** Sample values for a test: only the fields given a value. */
export function filledValues(values) {
  return Object.fromEntries(Object.entries(values ?? {}).filter(([, value]) => !isBlank(value)))
}

/** Keys the API adds to an action for display only (a webhook's `url_display` and `has_url`); never sent back. */
const DISPLAY_ONLY = ['url_display', 'has_url']

/**
 * An action as the API takes it, display-only keys dropped. A webhook whose
 * address was not retyped goes without `url` but with its `id`, so the
 * server keeps the stored address.
 */
export function actionBody(action) {
  const body = { ...action }
  for (const key of DISPLAY_ONLY) delete body[key]
  return body
}

/** The rule body the API takes (POST, PATCH and the unsaved test). */
export function ruleBody(draft) {
  return {
    name: draft.name,
    document_type: draft.document_type,
    company_id: draft.company_id ?? null,
    trigger: draft.trigger,
    conditions: draft.conditions ?? null,
    actions: (draft.actions ?? []).map(actionBody),
  }
}
