// The CRM and sales back office (docs/modules/crm.md): permissions,
// statuses and their tones, and the places, pipelines, options, teams,
// assignees, customers and currencies its forms offer. It reads only core
// lists and the CRM API (the module stands alone, RBAC-08).
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '@/api/client'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'
import { useMoneyDefaults } from '@/lib/defaultCurrency'

/** RBAC-09: who may open each page (the API checks again at each branch, CRM-08). */
export const LEAD_VIEW = 'crm.lead.view'
export const LEAD_CREATE = 'crm.lead.create'
export const LEAD_ASSIGN = 'crm.lead.assign'
export const OPPORTUNITY_VIEW = 'crm.opportunity.view'
export const OPPORTUNITY_CREATE = 'crm.opportunity.create'
export const QUOTE_VIEW = 'crm.quote.view'
export const QUOTE_CREATE = 'crm.quote.create'
export const ACTIVITY_VIEW = 'crm.activity.view'
export const ACTIVITY_MANAGE = 'crm.activity.manage'
export const TARGET_VIEW = ['crm.target.view', 'crm.target.manage']
export const TARGET_MANAGE = 'crm.target.manage'
export const SETTINGS_MANAGE = 'crm.settings.manage'
/** CRM-25: the customer's Sales tab, for any CRM reader. */
export const CUSTOMER_TAB = [LEAD_VIEW, OPPORTUNITY_VIEW, QUOTE_VIEW, ACTIVITY_VIEW]
/** CRM-01: settings are read by any CRM reader and changed with crm.settings.manage. */
export const SETTINGS_VIEW = [...CUSTOMER_TAB, SETTINGS_MANAGE]

/** The values the API sends (Models' constants in api/modules/CRM). */
export const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'converted', 'disqualified']
/** CRM-13: the lead board's columns. */
export const LEAD_BOARD = ['new', 'contacted', 'qualified']
export const LEAD_CHANNELS = ['manual', 'form', 'import', 'api']
export const OPPORTUNITY_STATUSES = ['open', 'won', 'lost']
export const QUOTE_STATUSES = ['draft', 'pending', 'approved', 'rejected', 'sent', 'accepted', 'declined', 'converted', 'cancelled']
export const ACTIVITY_KINDS = ['call', 'meeting', 'email', 'task']
export const ACTIVITY_STATUSES = ['open', 'done', 'cancelled']
export const TARGET_METRICS = ['won_value', 'pos_sales', 'invoiced']
export const TARGET_SUBJECTS = ['user', 'team', 'branch']
export const OPTION_KINDS = ['source', 'won_reason', 'lost_reason']
/** CRM-06: the fields a lead form may show and require. */
export const LEAD_FORM_FIELDS = ['name', 'organisation', 'title', 'email', 'phone', 'notes']
export const SHARE_STATUSES = ['active', 'revoked', 'expired']

/** Status as a dot and a word: waiting amber, under way teal, done green, refused red, closed grey. */
export const STATUS_TONES = {
  new: 'info',
  contacted: 'info',
  qualified: 'success',
  converted: 'success',
  disqualified: 'neutral',
  open: 'info',
  won: 'success',
  lost: 'danger',
  draft: 'neutral',
  pending: 'warning',
  approved: 'info',
  rejected: 'danger',
  sent: 'info',
  accepted: 'success',
  declined: 'danger',
  cancelled: 'neutral',
  done: 'success',
  active: 'success',
  revoked: 'neutral',
  expired: 'neutral',
  archived: 'neutral',
}

/** A status in words (an unknown one shows as itself). */
export const statusLabel = (t, status) => t(`crm.statuses.${status}`, { defaultValue: status })

/** Query keys of the module, so a change refreshes its lists and details. */
export const crmKey = ['crm']

/** Options of named records ({id, name}) for a picker. */
export const namedOptions = (records) => records.map((record) => ({ value: record.id, label: record.name }))

/** Companies and branches the user sees (archived ones kept for filters), with each branch's `company_id`. */
export function usePlaces() {
  const list = (resource) => ({ queryKey: [resource, 'crm-places'], queryFn: () => api.get(`${resource}?status=all&per_page=200`), staleTime: 60_000, retry: false })
  const companies = useQuery(list('companies'))
  const branches = useQuery(list('branches'))
  const allBranches = branches.data?.data ?? []
  return {
    companies: companies.data?.data ?? [],
    branches: allBranches,
    activeBranches: allBranches.filter((branch) => !branch.archived_at),
    loading: branches.isPending,
  }
}

/** The company id of a branch, or null. */
export const companyOfBranch = (places, branchId) => places.branches.find((branch) => branch.id === branchId)?.company_id ?? null

/** A company record ({base_currency, timezone}) by id, or null. */
export const companyById = (places, companyId) => places.companies.find((company) => company.id === companyId) ?? null

/**
 * The company the CRM pages work in: the one picked on the page, else the
 * switcher's, else the first the user sees. `choose(id)` picks one.
 */
export function useCrmCompany() {
  const { company: switcher } = useCompanySelection()
  const { companies, ready } = useCompanies()
  const active = companies.filter((entry) => !entry.archived_at)
  const [chosen, setChosen] = useState('')
  const company = active.find((entry) => entry.id === chosen) ?? switcher ?? active[0] ?? null
  return { company, companies: active, choose: setChosen, ready }
}

const lookup = (key, path, enabled) => ({ queryKey: [...crmKey, ...key], queryFn: () => api.get(path), enabled, staleTime: 30_000, retry: false })

/** CRM-02: a company's pipelines with their stages (`status`: active by default, or all). */
export function usePipelines(companyId, status = '') {
  const query = useQuery(lookup(['pipelines', companyId, status], `crm/pipelines?company=${companyId}${status ? `&status=${status}` : ''}`, Boolean(companyId)))
  const all = query.data?.data ?? []
  return { ...query, all, active: all.filter((pipeline) => !pipeline.archived_at) }
}

/** The open stages of a pipeline, in order. */
export const stagesOf = (pipeline) => [...(pipeline?.stages ?? [])].filter((stage) => !stage.archived_at).sort((a, b) => a.position - b.position)

/** CRM-03: a company's sources, won reasons or lost reasons. */
export function useCrmOptions(companyId, kind, status = '') {
  const query = useQuery(lookup(['options', companyId, kind, status], `crm/options?company=${companyId}&kind=${kind}${status ? `&status=${status}` : ''}`, Boolean(companyId)))
  const all = query.data?.data ?? []
  return { ...query, all, active: all.filter((option) => !option.archived_at) }
}

/** CRM-04: a company's teams. */
export function useTeams(companyId, status = '') {
  const query = useQuery(lookup(['teams', companyId, status], `crm/teams?company=${companyId}${status ? `&status=${status}` : ''}`, Boolean(companyId)))
  const all = query.data?.data ?? []
  return { ...query, all, active: all.filter((team) => !team.archived_at) }
}

/** CRM-10: who may own leads at a branch (active users who may view them there). */
export function useAssignees(branchId, enabled = true) {
  const query = useQuery(lookup(['assignees', branchId ?? 'any'], `crm/assignees${branchId ? `?branch=${branchId}` : ''}`, enabled))
  return query.data?.data ?? []
}

/** CRM-01: a company's CRM settings. */
export function useCrmSettings(companyId) {
  return useQuery(lookup(['settings', companyId], `crm/companies/${companyId}/settings`, Boolean(companyId)))
}

/** CUR-01: the currencies a record may be in (the tenant's active ones) and the company's base as the default. */
export function useCurrencies(companyId) {
  const { currency, options } = useMoneyDefaults(companyId ?? undefined)
  return { base: currency, options: options.map((code) => ({ value: code, label: code })) }
}

/** A {amount_minor, currency} value, or null when no amount was typed. */
export const moneyOf = (minor, currency) => (minor === '' || minor === null || minor === undefined ? null : { amount_minor: minor, currency })

/** The message of a field from a 422, else undefined. */
export const fieldError = (error, field) => {
  const messages = error?.status === 422 ? error.errors?.[field] : null
  return messages ? (Array.isArray(messages) ? messages[0] : String(messages)) : undefined
}

/** A calendar date ("2026-10-14") moved by `days`, without the browser's time zone. */
export function addDays(date, days) {
  const [year, month, day] = String(date).split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10)
}

/** The Monday of the week of a calendar date. */
export function weekStart(date) {
  const [year, month, day] = String(date).split('-').map(Number)
  const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay()
  return addDays(date, -((weekday + 6) % 7))
}

/** The first and last day of the month of a calendar date. */
export function monthRange(date) {
  const [year, month] = String(date).split('-').map(Number)
  const first = `${year}-${String(month).padStart(2, '0')}-01`
  const last = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10)
  return { first, last }
}

/** The `count` calendar dates from `from`. */
export const daysFrom = (from, count) => Array.from({ length: count }, (_, index) => addDays(from, index))
