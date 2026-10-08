// One-line summaries of flow steps for the canvas cards ("Manager of the
// requester's branch · escalates after 8 business hours") and plain
// descriptions of conditions ("Total is more than KES 250,000.00").
import { formatAmount } from '@/lib/money'
import { roleRefsOf, userIdsOf } from './notifyRecipients'

const EMPTY_OPS = new Set(['empty', 'not_empty'])

/** "8 business hours" from {amount, unit}. */
export function describeDuration(t, duration) {
  if (!duration || !Number.isInteger(duration.amount)) return null
  return t(`workflows.units.${duration.unit}`, { count: duration.amount, defaultValue: `${duration.amount}` })
}

function describeValue(t, locale, field, value) {
  if (value === null || value === undefined || value === '') return '…'
  if (Array.isArray(value)) return value.map((one) => describeValue(t, locale, field, one)).join(', ')
  if (field?.type === 'money' && typeof value === 'object') {
    return `${value.currency ?? ''} ${formatAmount(value.amount_minor ?? '0', value.currency, locale)}`.trim()
  }
  if (field?.type === 'boolean') return value ? t('workflows.condition.yes') : t('workflows.condition.no')
  return String(value)
}

/** A condition (comparison or group) in words; null when there is none. */
export function describeCondition(t, locale, condition, fields = []) {
  if (!condition || typeof condition !== 'object') return null
  const group = Array.isArray(condition.all) ? 'all' : Array.isArray(condition.any) ? 'any' : null
  if (group) {
    const parts = condition[group].map((child) => describeCondition(t, locale, child, fields)).filter(Boolean)
    if (parts.length === 0) return null
    return parts.join(group === 'all' ? t('workflows.condition.andJoin') : t('workflows.condition.orJoin'))
  }
  const field = fields.find((one) => one.name === condition.field)
  const label = field?.label ?? condition.field ?? '…'
  if (EMPTY_OPS.has(condition.op)) return t(`workflows.operatorPhrases.${condition.op}`, { field: label })
  const value =
    condition.other !== undefined
      ? (fields.find((one) => one.name === condition.other)?.label ?? condition.other)
      : describeValue(t, locale, field, condition.value)
  return t(`workflows.operatorPhrases.${condition.op}`, { field: label, value, defaultValue: `${label} ${condition.op} ${value}` })
}

function roleName(roles, ref) {
  if (typeof ref !== 'string') return ''
  if (ref.startsWith('template:')) {
    const key = ref.slice('template:'.length)
    return roles.find((role) => role.template_key === key)?.name ?? key
  }
  return roles.find((role) => role.id === ref)?.name ?? ref
}

/** Who approves, in words. */
export function describeApprover(t, approver, { roles = [], users = [], approverTypes = [] } = {}) {
  if (!approver?.type) return t('workflows.approver.none')
  switch (approver.type) {
    case 'role':
      return t('workflows.approver.roleNamed', { role: roleName(roles, approver.role) || '…' })
    case 'user':
      return users.find((user) => user.id === approver.user_id)?.name ?? t('workflows.approver.namedPerson')
    case 'manager_levels_up':
      return t('workflows.approver.levelsUp', { count: Number(approver.levels) || 1 })
    default: {
      const listed = approverTypes.find((one) => one.key === approver.type)
      return listed?.label ?? t(`workflows.approverTypes.${approver.type}`, { defaultValue: approver.type })
    }
  }
}

/**
 * The card's one-line summary for a node. `context`: { fields,
 * nextDocuments, types, roles, users, approverTypes, locale }.
 */
export function summarize(t, node, context = {}) {
  const { fields = [], nextDocuments = [], types = [], locale = 'en' } = context
  const parts = []
  switch (node.type) {
    case 'stage': {
      const entry = describeCondition(t, locale, node.entry, fields)
      if (entry) parts.push(t('workflows.summary.entry', { rule: entry }))
      const exit = describeCondition(t, locale, node.exit, fields)
      if (exit) parts.push(t('workflows.summary.exit', { rule: exit }))
      if (node.mandatory === false) parts.push(t('workflows.summary.optional'))
      const due = describeDuration(t, node.due)
      if (due) parts.push(t('workflows.summary.due', { duration: due }))
      if (parts.length === 0) parts.push(t('workflows.summary.stage'))
      break
    }
    case 'approval': {
      const chain = Array.isArray(node.approval?.chain) && node.approval.chain.length > 0 ? node.approval.chain : null
      // APR-01: a chain reads "Branch manager, then CFO".
      parts.push(
        chain
          ? chain.map((approver) => describeApprover(t, approver, context)).reduce((first, next) => t('workflows.summary.then', { first, next }))
          : describeApprover(t, node.approval?.approver, context),
      )
      const after = describeDuration(t, node.escalation?.after)
      if (after) parts.push(t('workflows.summary.escalates', { duration: after }))
      else {
        const due = describeDuration(t, node.due)
        if (due) parts.push(t('workflows.summary.due', { duration: due }))
      }
      if (node.escalation?.final === 'approve' || node.escalation?.final === 'reject') {
        parts.push(t(`workflows.summary.final.${node.escalation.final}`))
      }
      break
    }
    case 'condition': {
      if (Array.isArray(node.branches)) {
        parts.push(t('workflows.summary.branches', { count: node.branches.length + 1 }))
      } else {
        parts.push(describeCondition(t, locale, node.condition, fields) ?? t('workflows.summary.noRule'))
      }
      break
    }
    case 'parallel':
      parts.push(t('workflows.summary.parallel'))
      break
    case 'join':
      parts.push(t(`workflows.summary.join.${node.mode === 'any' ? 'any' : 'all'}`))
      break
    case 'action': {
      if (node.action === 'create_document') {
        const next = nextDocuments.find((one) => one.key === node.config?.mapping)
        const target = next ? (types.find((type) => type.key === next.target)?.label ?? next.label) : null
        parts.push(target ? t('workflows.summary.createDocument', { document: target }) : t('workflows.summary.chooseDocument'))
      } else if (node.action === 'notify') {
        const { roles = [], users = [] } = context
        const names = [
          ...roleRefsOf(node.config?.to).map((ref) => roleName(roles, ref)),
          ...userIdsOf(node.config?.to).map((id) => users.find((user) => user.id === id)?.name ?? t('workflows.approver.namedPerson')),
        ]
        parts.push(names.length ? t('workflows.summary.notifies', { recipients: names.join(', ') }) : t('workflows.summary.notifyNobody'))
      } else {
        parts.push(node.action ?? t('workflows.summary.chooseAction'))
      }
      break
    }
    default:
      break
  }
  return parts.filter(Boolean).join(' · ')
}

/** The caption above a card's name: its kind, plus "entry rule" when a stage has one. */
export function kindLabel(t, node) {
  if (node.type === 'action') return t(`workflows.kinds.action_${node.action === 'create_document' ? 'create' : node.action === 'notify' ? 'notify' : 'other'}`)
  if (node.type === 'stage' && node.entry) return t('workflows.kinds.stageEntry')
  return t(`workflows.kinds.${node.type}`)
}

/** The name shown for a node: its own, else its kind's default ("Start", "End: approved"). */
export function displayName(t, node) {
  if (typeof node.name === 'string' && node.name.trim() !== '') return node.name
  if (node.type === 'start') return t('workflows.defaults.start')
  if (node.type === 'end') return t('workflows.defaults.endOutcome', { outcome: t(`workflows.outcomes.${node.outcome ?? 'completed'}`, { defaultValue: node.outcome ?? '' }) })
  if (node.type === 'join') return t('workflows.defaults.join')
  if (node.type === 'parallel') return t('workflows.defaults.parallel')
  return t('workflows.defaults.unnamed')
}

/** The label of an edge's branch on the canvas ("Yes", "Rejected", a branch's name). */
export function branchLabel(t, node, branch) {
  if (!branch) return null
  if (node?.type === 'condition' && Array.isArray(node.branches)) {
    if (branch === 'else') return t('workflows.branches.else')
    const named = node.branches.find((one) => one.key === branch)
    return named?.name || branch
  }
  return t(`workflows.branches.${branch}`, { defaultValue: branch })
}
