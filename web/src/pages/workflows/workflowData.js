// What the workflow builder offers (WF-01..WF-11, APR-01..APR-09, spec 6.4)
// and where it reads it from. The API stays the judge: every graph is
// validated again before it is saved or published.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'


/** Time units of stage limits, reminders and escalation (GraphValidator::DUE_UNITS). */
export const DUE_UNITS = ['business_hours', 'business_days', 'hours', 'days']

/** Join modes (WF-06). */
export const JOIN_MODES = ['all', 'any']

/** What happens to a created document when the flow is cancelled (WF-11). */
export const ON_CANCEL = ['keep', 'cancel']

/** End outcomes offered; the API accepts any lowercase word. */
export const OUTCOMES = ['approved', 'rejected', 'completed']

/** Group-approval modes (APR-01): any one, all, or most of the approvers. */
export const APPROVAL_MODES = ['any', 'all', 'majority']

/** At the final timeout (APR-05): nothing, approve or reject automatically. */
export const FINAL_ACTIONS = ['none', 'approve', 'reject']

/** Notification channels (NOT-01); the API sends only those configured. */
export const CHANNELS = ['in_app', 'email', 'sms', 'whatsapp']

/**
 * Approver types (APR-02) offered until the API lists its own resolvers
 * (`meta.approver_types` of workflow/document-types). Each type names the
 * settings it needs: `user` (a named person), `role`, `levels` (how many
 * levels up). The approval node stores `{ type, ...settings }` as given.
 */
export const DEFAULT_APPROVER_TYPES = [
  { key: 'branch_manager', params: [] },
  { key: 'department_head', params: [] },
  { key: 'cost_centre_owner', params: [] },
  { key: 'manager_levels_up', params: ['levels'] },
  { key: 'role', params: ['role'] },
  { key: 'user', params: ['user'] },
]

/** Where escalation goes (APR-05): the next level up, a role or a named person. */
export const ESCALATE_TO = [
  { key: 'next_level', params: [] },
  { key: 'role', params: ['role'] },
  { key: 'user', params: ['user'] },
]

/** Palette kinds in the design's order (Wait is not an engine step yet). */
export const PALETTE = ['stage', 'approval', 'condition', 'parallel', 'notify', 'create_document', 'end']

/**
 * The document types of the tenant's active modules with their fields,
 * operators, actions and next documents (WF-01), plus the action handlers
 * and, once the approvals service lists them, the approver types.
 */
export function useDocumentTypes() {
  const query = useQuery({ queryKey: ['workflow', 'document-types'], queryFn: () => api.get('workflow/document-types'), staleTime: 300_000 })
  const types = query.data?.data ?? []
  const meta = query.data?.meta ?? {}
  return {
    ...query,
    types,
    actionHandlers: meta.action_handlers ?? ['create_document', 'notify'],
    approverTypes: normalizeApproverTypes(meta.approver_types),
  }
}

function normalizeApproverTypes(list) {
  if (!Array.isArray(list) || list.length === 0) return DEFAULT_APPROVER_TYPES
  return list
    .map((entry) => (typeof entry === 'string' ? { key: entry } : entry))
    .filter((entry) => entry && typeof entry.key === 'string')
    .map((entry) => ({ key: entry.key, label: entry.label, params: Array.isArray(entry.params) ? entry.params : (DEFAULT_APPROVER_TYPES.find((one) => one.key === entry.key)?.params ?? []) }))
}

/** Active roles (role pickers) and users (named approvers), loaded once per visit. */
export function useRoleOptions() {
  const query = useQuery({ queryKey: ['roles', 'options'], queryFn: () => api.get('roles?per_page=200') })
  return query.data?.data ?? []
}

export function useUserOptions(enabled = true) {
  const query = useQuery({ queryKey: ['users', 'options'], queryFn: () => api.get('users?per_page=200'), enabled })
  return query.data?.data ?? []
}

/**
 * What the user may do with a flow (RBAC-04): edit and publish at the
 * flow's company, or tenant-wide for the flow of every company. The API
 * checks again.
 */
export function useWorkflowRights(workflow) {
  const { can, tenantWide } = usePermissions()
  const companyId = workflow?.company_id ?? null
  const at = (name) => (companyId ? can(name, { type: 'company', id: companyId }) : tenantWide(name))
  return { canEdit: at('core.workflow.edit'), canPublish: at('core.workflow.publish') }
}
