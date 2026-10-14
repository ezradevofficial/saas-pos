import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Select, StatusBadge, Switch, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { cn } from '@/lib/utils'
import { CalendarDate, NoCompany } from './AccountingParts'
import { accountOptions, companyPath, moduleLink, useAccountingCompany, useAccounts } from './accountingData'

const TYPES = ['asset', 'liability', 'equity', 'income', 'expense']
const SUBTYPES = ['header', 'cash', 'bank', 'mobile_money', 'receivable', 'inventory', 'current_asset', 'fixed_asset', 'accumulated_depreciation', 'intercompany', 'suspense',
  'payable', 'tax', 'current_liability', 'long_term_liability', 'equity', 'retained_earnings', 'revenue', 'other_income', 'cost_of_sales', 'expense', 'depreciation', 'fx']
const CASH_FLOWS = ['cash', 'operating', 'non_cash', 'investing', 'financing']
const INDENT = ['', 'pl-4', 'pl-6', 'pl-10', 'pl-12']
const ROLES = ['receivable', 'payable', 'sales_tax', 'purchase_tax', 'fx_gain', 'fx_loss', 'rounding', 'retained_earnings', 'suspense']

/** Depth of an account in the chart (headings above it), for indentation. */
function depths(accounts) {
  const byId = new Map(accounts.map((account) => [account.id, account]))
  const depth = (account, seen = 0) => (account.parent_id && byId.has(account.parent_id) && seen < 10 ? 1 + depth(byId.get(account.parent_id), seen + 1) : 0)
  return new Map(accounts.map((account) => [account.id, depth(account)]))
}

function AccountDialog({ company, account, accounts, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(() => ({
    code: account?.code ?? '', name: account?.name ?? '', type: account?.type ?? 'expense', subtype: account?.subtype ?? 'expense',
    parent_id: account?.parent_id ?? '', postable: account?.postable ?? true, cash_flow: account?.cash_flow ?? '',
  }))
  const save = useMutation({
    mutationFn: () => {
      const body = { ...form, parent_id: form.parent_id || null, cash_flow: form.cash_flow || null }
      return account ? api.patch(`accounting/accounts/${account.id}`, body) : api.post(companyPath(company, 'accounts'), body)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-accounts-all', company.id] })
      onClose()
    },
  })
  const headings = accounts.filter((a) => !a.postable && a.id !== account?.id).map((a) => ({ value: a.id, label: `${a.code} · ${a.name}` }))
  const set = (patch) => setForm((current) => ({ ...current, ...patch }))

  return (
    <Dialog
      open
      title={account ? t('accounting.chart.editTitle', { code: account.code }) : t('accounting.chart.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('accounting.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!form.code || !form.name} onClick={() => save.mutate()}>{t('accounting.chart.save')}</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {save.isError ? <div className="sm:col-span-2"><Alert tone="danger" title={errorMessage(save.error)} /></div> : null}
        <TextField label={t('accounting.columns.code')} value={form.code} onChange={(event) => set({ code: event.target.value })} maxLength={20} required />
        <TextField label={t('accounting.columns.name')} value={form.name} onChange={(event) => set({ name: event.target.value })} maxLength={150} required />
        <Select label={t('accounting.columns.type')} options={TYPES.map((value) => ({ value, label: t(`accounting.accountTypes.${value}`) }))} value={form.type} onChange={(event) => set({ type: event.target.value })} />
        <Select label={t('accounting.columns.subtype')} options={SUBTYPES.map((value) => ({ value, label: t(`accounting.subtypes.${value}`) }))} value={form.subtype} onChange={(event) => set({ subtype: event.target.value })} />
        <Select label={t('accounting.chart.parent')} placeholder={t('accounting.chart.noParent')} options={[{ value: '', label: t('accounting.chart.noParent') }, ...headings]} value={form.parent_id} onChange={(event) => set({ parent_id: event.target.value })} />
        <Select label={t('accounting.chart.cashFlow')} options={[{ value: '', label: t('accounting.chart.noCashFlow') }, ...CASH_FLOWS.map((value) => ({ value, label: t(`accounting.cashFlows.${value}`) }))]} value={form.cash_flow} onChange={(event) => set({ cash_flow: event.target.value })} />
        <Switch label={t('accounting.chart.postable')} checked={form.postable} onChange={(postable) => set({ postable })} />
      </div>
    </Dialog>
  )
}

function ChartTab({ company }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const accounts = useAccounts(company)
  const templates = useQuery({ queryKey: ['accounting-templates'], queryFn: () => api.get('accounting/chart-templates') })
  const [editing, setEditing] = useState(null)
  const [template, setTemplate] = useState('')
  const create = useMutation({
    mutationFn: () => api.post(companyPath(company, 'chart'), template ? { template } : {}),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['accounting-accounts-all', company.id] }),
  })
  const archive = useMutation({
    mutationFn: (account) => api.post(`accounting/accounts/${account.id}/${account.archived_at ? 'restore' : 'archive'}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['accounting-accounts-all', company.id] }),
  })
  const rows = accounts.data?.data ?? []
  const depth = depths(rows)
  const manage = can('accounting.account.manage')

  if (accounts.isSuccess && rows.length === 0) {
    return (
      <Card title={t('accounting.chart.emptyTitle')} subtitle={t('accounting.chart.emptyText')}>
        {create.isError ? <Alert tone="danger" title={errorMessage(create.error)} /> : null}
        {manage ? (
          <div className="flex flex-wrap items-end gap-2">
            <Select label={t('accounting.chart.template')} options={[{ value: '', label: t('accounting.chart.countryTemplate') }, ...(templates.data?.data ?? []).map((tpl) => ({ value: tpl.code, label: tpl.name }))]} value={template} onChange={(event) => setTemplate(event.target.value)} />
            <Button variant="primary" loading={create.isPending} onClick={() => create.mutate()}>{t('accounting.chart.create')}</Button>
          </div>
        ) : null}
      </Card>
    )
  }

  return (
    <>
      {manage ? <div className="flex justify-end"><Button icon="plus" onClick={() => setEditing({})}>{t('accounting.chart.add')}</Button></div> : null}
      {archive.isError ? <Alert tone="danger" title={errorMessage(archive.error)} /> : null}
      <DataTable
        caption={t('accounting.chart.title')}
        loading={accounts.isLoading}
        rows={rows}
        columns={[
          { key: 'code', label: t('accounting.columns.code'), render: (row) => <span className={cn('tabular-nums', INDENT[Math.min(depth.get(row.id) ?? 0, INDENT.length - 1)])}>{row.code}</span> },
          { key: 'name', label: t('accounting.columns.name'), render: (row) => <span className={row.postable ? '' : 'font-medium'}>{row.name}</span> },
          { key: 'type', label: t('accounting.columns.type'), render: (row) => t(`accounting.accountTypes.${row.type}`) },
          { key: 'subtype', label: t('accounting.columns.subtype'), render: (row) => t(`accounting.subtypes.${row.subtype}`) },
          { key: 'flags', label: t('accounting.chart.flags'), render: (row) => [row.is_control ? t('accounting.chart.control') : null, row.currency, row.archived_at ? t('accounting.chart.archived') : null].filter(Boolean).join(' · ') },
          {
            key: 'actions', label: '', align: 'end',
            render: (row) => (manage ? (
              <span className="inline-flex gap-1">
                <Button variant="ghost" size="sm" onClick={() => setEditing(row)}>{t('accounting.chart.edit')}</Button>
                <Button variant="ghost" size="sm" onClick={() => archive.mutate(row)}>{row.archived_at ? t('accounting.chart.restore') : t('accounting.chart.archive')}</Button>
              </span>
            ) : null),
          },
        ]}
      />
      {editing ? <AccountDialog company={company} account={editing.id ? editing : null} accounts={rows} onClose={() => setEditing(null)} /> : null}
    </>
  )
}

function SettingsTab({ company }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const accounts = useAccounts(company)
  const settings = useQuery({ queryKey: ['accounting-settings', company.id], queryFn: () => api.get(companyPath(company, 'settings')) })
  const [roles, setRoles] = useState(null)
  const current = roles ?? Object.fromEntries((settings.data?.data.roles ?? []).map((role) => [role.role, role.account_id ?? '']))
  const save = useMutation({
    mutationFn: () => api.put(companyPath(company, 'settings'), { roles: ROLES.map((role) => ({ role, account_id: current[role] || null })) }),
    onSuccess: async (result) => {
      queryClient.setQueryData(['accounting-settings', company.id], result)
      setRoles(null)
    },
  })
  const options = accountOptions(accounts.data?.data, { includeControl: true })
  const salesPosting = useMutation({
    mutationFn: (value) => api.put(companyPath(company, 'settings'), { sales_posting: value }),
    onSuccess: (result) => queryClient.setQueryData(['accounting-settings', company.id], result),
  })

  return (
    <>
    <Card title={t('accounting.settings.salesTitle')} subtitle={t('accounting.settings.salesDescription')}>
      <div className="flex flex-col gap-4">
        {salesPosting.isError ? <Alert tone="danger" title={errorMessage(salesPosting.error)} /> : null}
        <div className="max-w-sm">
          <Select label={t('accounting.settings.salesPosting')} disabled={!can('accounting.account.manage') || salesPosting.isPending}
            options={['per_sale', 'daily_summary'].map((value) => ({ value, label: t(`accounting.settings.salesPostings.${value}`) }))}
            value={settings.data?.data.sales_posting ?? 'per_sale'} onChange={(event) => salesPosting.mutate(event.target.value)} />
        </div>
      </div>
    </Card>
    <Card title={t('accounting.settings.title')} subtitle={t('accounting.settings.description')}>
      <div className="flex flex-col gap-4">
        {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        {save.isSuccess ? <Alert tone="success" title={t('accounting.settings.saved')} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          {ROLES.map((role) => (
            <Select key={role} label={t(`accounting.roles.${role}`)} placeholder={t('accounting.journalForm.chooseAccount')} options={options} value={current[role] ?? ''} disabled={!can('accounting.account.manage')}
              onChange={(event) => setRoles({ ...current, [role]: event.target.value })} />
          ))}
        </div>
        {can('accounting.account.manage') ? <div><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{t('accounting.settings.save')}</Button></div> : null}
      </div>
    </Card>
    </>
  )
}

/** A source type's name in the UI language (`pos.sale` → "POS sale"), else its code. */
function sourceName(t, type) {
  return t(`accounting.sources.${type.replaceAll('.', '_')}`, { defaultValue: type })
}

/** A rule line as text: "tender.* → Debit · 1110 Cash on hand (3 mapped)". */
function lineText(t, line, byId) {
  const account = line.account_id ? byId.get(line.account_id) : null
  const mapped = Object.keys(line.accounts ?? {}).length
  return [
    `${line.field} → ${t(`accounting.columns.${line.side}`)}`,
    account ? ` · ${account.code} ${account.name}` : '',
    mapped ? ` ${t('accounting.rules.mapped', { count: mapped })}` : '',
  ].join('')
}

function RuleDialog({ company, eventTypes, rule, type, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const accounts = useAccounts(company)
  const [eventType, setEventType] = useState(rule?.event_type ?? type ?? eventTypes[0] ?? '')
  const [lines, setLines] = useState(() => rule?.lines?.map((line) => ({ ...line, account_id: line.account_id ?? '' }))
    ?? [{ field: 'total', side: 'debit', account_id: '', party: false }, { field: 'total', side: 'credit', account_id: '', party: false }])
  const save = useMutation({
    // A line's map (`accounts`) and resolver (`by`) are kept as they are; a line on `prefix.*` may have no default account.
    mutationFn: () => api.post(companyPath(company, 'posting-rules'), { event_type: eventType, lines: lines.map((line) => ({ ...line, account_id: line.account_id || null })) }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-posting-rules', company.id] })
      onClose()
    },
  })
  const update = (index, patch) => setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  const options = accountOptions(accounts.data?.data, { includeControl: true })
  const types = [...new Set([...eventTypes, eventType].filter(Boolean))]

  return (
    <Dialog
      open
      size="lg"
      title={rule ? t('accounting.rules.editTitle', { source: sourceName(t, rule.event_type) }) : t('accounting.rules.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('accounting.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{t('accounting.rules.save')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        {rule ? null : (
          <Select label={t('accounting.rules.eventType')} options={types.map((value) => ({ value, label: `${sourceName(t, value)} · ${value}` }))} value={eventType} onChange={(event) => setEventType(event.target.value)} />
        )}
        <p className="text-caption text-ink-muted">{t('accounting.rules.fieldHelp')}</p>
        {lines.map((line, index) => (
          <div key={index} className="grid items-end gap-3 sm:grid-cols-4">
            <TextField label={t('accounting.rules.field')} value={line.field} onChange={(event) => update(index, { field: event.target.value })} maxLength={60} />
            <Select label={t('accounting.rules.side')} options={['debit', 'credit'].map((value) => ({ value, label: t(`accounting.columns.${value}`) }))} value={line.side} onChange={(event) => update(index, { side: event.target.value })} />
            <Select className="sm:col-span-2" label={Object.keys(line.accounts ?? {}).length ? t('accounting.rules.defaultAccount', { count: Object.keys(line.accounts).length }) : t('accounting.columns.account')}
              placeholder={t('accounting.journalForm.chooseAccount')} options={[{ value: '', label: t('accounting.rules.noDefault') }, ...options]} value={line.account_id} onChange={(event) => update(index, { account_id: event.target.value })} />
          </div>
        ))}
        <div><Button icon="plus" onClick={() => setLines((current) => [...current, { field: '', side: 'credit', account_id: '', party: false }])}>{t('accounting.journalForm.addLine')}</Button></div>
      </div>
    </Dialog>
  )
}

function RulesTab({ company }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const accounts = useAccounts(company)
  const [editing, setEditing] = useState(null)
  const [status, setStatus] = useState('open')
  const rules = useQuery({ queryKey: ['accounting-posting-rules', company.id], queryFn: () => api.get(companyPath(company, 'posting-rules')) })
  const exceptions = useQuery({ queryKey: ['accounting-posting-exceptions', company.id, status], queryFn: () => api.get(companyPath(company, `posting-exceptions?status=${status}`)) })
  const retry = useMutation({
    mutationFn: (id) => api.post(`accounting/posting-exceptions/${id}/retry`),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['accounting-posting-exceptions', company.id] }),
  })
  const defaults = useMutation({
    mutationFn: () => api.post(companyPath(company, 'posting-rules/defaults')),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-posting-rules', company.id] })
      await queryClient.invalidateQueries({ queryKey: ['accounting-accounts-all', company.id] })
    },
  })
  const manage = can('accounting.posting_rule.manage')
  const byId = new Map((accounts.data?.data ?? []).map((account) => [account.id, account]))
  const active = rules.data?.data ?? []
  const meta = rules.data?.meta ?? {}
  const eventTypes = meta.event_types ?? []
  const fallbacks = meta.fallbacks ?? {}
  // ACC-24: every source the screen knows, with its own rule, the rule it falls back to, or none.
  const rows = [...new Set([...eventTypes, ...active.map((rule) => rule.event_type)])].map((type) => ({
    id: type, type, rule: active.find((rule) => rule.event_type === type) ?? null, fallback: fallbacks[type] ?? null,
  }))
  const ruleStatus = (row) => {
    if (row.rule) return <StatusBadge tone="success">{t('accounting.rules.own')}</StatusBadge>
    if (row.fallback && active.some((rule) => rule.event_type === row.fallback.event_type)) {
      return <StatusBadge tone="info">{t(row.fallback.reversed ? 'accounting.rules.fallbackReversed' : 'accounting.rules.fallback', { source: sourceName(t, row.fallback.event_type) })}</StatusBadge>
    }
    return <StatusBadge tone="warning">{t('accounting.rules.none')}</StatusBadge>
  }

  return (
    <>
      {manage ? (
        <div className="flex flex-wrap justify-end gap-2">
          <Button loading={defaults.isPending} onClick={() => defaults.mutate()}>{t('accounting.rules.defaults')}</Button>
          <Button icon="plus" onClick={() => setEditing({})}>{t('accounting.rules.add')}</Button>
        </div>
      ) : null}
      {defaults.isError ? <Alert tone="danger" title={errorMessage(defaults.error)} /> : null}
      {defaults.isSuccess ? <Alert tone="success" title={t('accounting.rules.defaultsAdded', { rules: defaults.data.data.rules, accounts: defaults.data.data.accounts })} /> : null}
      <DataTable
        caption={t('accounting.rules.title')}
        emptyText={t('accounting.rules.empty')}
        loading={rules.isLoading}
        rows={rows}
        columns={[
          { key: 'source', label: t('accounting.rules.source'), render: (row) => (
            <span className="flex flex-col">
              <span>{sourceName(t, row.type)}</span>
              <span className="font-mono text-caption text-ink-muted">{row.type}</span>
            </span>
          ) },
          { key: 'status', label: t('accounting.columns.status'), render: ruleStatus },
          { key: 'lines', label: t('accounting.journalForm.lines'), wrap: true, render: (row) => (row.rule ? (
            <span className="flex flex-col gap-1 text-caption">{row.rule.lines.map((line, index) => <span key={index}>{lineText(t, line, byId)}</span>)}</span>
          ) : null) },
          { key: 'edit', label: '', align: 'end', render: (row) => (manage ? (
            <Button size="sm" variant="ghost" onClick={() => setEditing(row.rule ?? { event_type: row.type, lines: null, isNew: true })}>{row.rule ? t('accounting.rules.edit') : t('accounting.rules.add')}</Button>
          ) : null) },
        ]}
      />
      <Card title={t('accounting.rules.exceptionsTitle')} subtitle={t('accounting.rules.exceptionsText')}>
        <div className="flex flex-col gap-4">
          {retry.isError ? <Alert tone="danger" title={errorMessage(retry.error)} /> : null}
          {retry.isSuccess && retry.data?.data?.status === 'posted' ? <Alert tone="success" title={t('accounting.rules.retried')} /> : null}
          <div className="max-w-xs">
            <Select label={t('accounting.columns.status')} options={['open', 'resolved', 'all'].map((value) => ({ value, label: t(`accounting.exceptionStatuses.${value}`) }))} value={status} onChange={(event) => setStatus(event.target.value)} />
          </div>
          <DataTable
            caption={t('accounting.rules.exceptionsTitle')}
            emptyText={t('accounting.rules.noExceptions')}
            loading={exceptions.isLoading}
            rows={exceptions.data?.data ?? []}
            columns={[
              { key: 'source', label: t('accounting.rules.source'), render: (row) => {
                const to = moduleLink(row.link)
                return (
                  <span className="flex flex-col">
                    {to ? <Link to={to} className="text-primary hover:text-primary-hover">{sourceName(t, row.source_type)}</Link> : <span>{sourceName(t, row.source_type)}</span>}
                    <span className="text-caption text-ink-muted">{row.reference ?? ''}</span>
                  </span>
                )
              } },
              { key: 'date', label: t('accounting.columns.date'), render: (row) => (row.date ? <CalendarDate value={row.date.slice(0, 10)} /> : null) },
              { key: 'error', label: t('accounting.rules.reason'), wrap: true, render: (row) => row.error_message },
              { key: 'attempts', label: t('accounting.rules.attempts'), align: 'end', render: (row) => <span className="tabular-nums">{row.attempts}</span> },
              { key: 'status', label: t('accounting.columns.status'), render: (row) => (
                row.journal_id ? <Link to={`/accounting/journals/${row.journal_id}`} className="text-primary hover:text-primary-hover">{t(`accounting.exceptionStatuses.${row.status}`)}</Link>
                  : <StatusBadge tone={row.status === 'open' ? 'warning' : 'success'}>{t(`accounting.exceptionStatuses.${row.status}`)}</StatusBadge>
              ) },
              { key: 'retry', label: '', align: 'end', render: (row) => (manage && row.status === 'open' ? <Button size="sm" loading={retry.isPending && retry.variables === row.id} onClick={() => retry.mutate(row.id)}>{t('accounting.rules.retry')}</Button> : null) },
            ]}
          />
        </div>
      </Card>
      {editing ? <RuleDialog company={company} eventTypes={eventTypes} rule={editing.lines ? editing : null} type={editing.event_type} key={editing.event_type ?? 'new'} onClose={() => setEditing(null)} /> : null}
    </>
  )
}

/** ACC-01, ACC-02, ACC-07, ACC-24: the chart of accounts, the accounts each role posts to, how POS sales post, and posting rules with what waits to post. */
export default function ChartOfAccounts() {
  const { t } = useTranslation()
  const company = useAccountingCompany()
  const [params, setParams] = useSearchParams()
  const tab = ['chart', 'settings', 'rules'].includes(params.get('tab')) ? params.get('tab') : 'chart'

  return (
    <>
      <PageHeader title={t('accounting.chart.title')} description={t('accounting.chart.description')} />
      <Tabs value={tab} onChange={(next) => setParams({ tab: next })} items={[
        { value: 'chart', label: t('accounting.chart.tab') },
        { value: 'settings', label: t('accounting.settings.tab') },
        { value: 'rules', label: t('accounting.rules.tab') },
      ]} />
      {!company ? <NoCompany /> : tab === 'settings' ? <SettingsTab company={company} /> : tab === 'rules' ? <RulesTab company={company} /> : <ChartTab company={company} />}
    </>
  )
}
