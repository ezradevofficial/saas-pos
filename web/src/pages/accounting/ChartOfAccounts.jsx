import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Select, StatusBadge, Switch, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { cn } from '@/lib/utils'
import { NoCompany } from './AccountingParts'
import { accountOptions, companyPath, useAccountingCompany, useAccounts } from './accountingData'

const TYPES = ['asset', 'liability', 'equity', 'income', 'expense']
const SUBTYPES = ['header', 'cash', 'bank', 'mobile_money', 'receivable', 'inventory', 'current_asset', 'fixed_asset', 'accumulated_depreciation', 'intercompany', 'suspense',
  'payable', 'tax', 'current_liability', 'long_term_liability', 'equity', 'retained_earnings', 'revenue', 'other_income', 'cost_of_sales', 'expense', 'depreciation', 'fx']
const CASH_FLOWS = ['cash', 'operating', 'non_cash', 'investing', 'financing']
const INDENT = ['', 'pl-4', 'pl-8', 'pl-12']
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

  return (
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
  )
}

function RuleDialog({ company, eventTypes, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const accounts = useAccounts(company)
  const [eventType, setEventType] = useState(eventTypes[0] ?? '')
  const [lines, setLines] = useState([{ field: 'total', side: 'debit', account_id: '', party: false }, { field: 'total', side: 'credit', account_id: '', party: false }])
  const save = useMutation({
    mutationFn: () => api.post(companyPath(company, 'posting-rules'), { event_type: eventType, lines }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-posting-rules', company.id] })
      onClose()
    },
  })
  const update = (index, patch) => setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  const options = accountOptions(accounts.data?.data, { includeControl: true })

  return (
    <Dialog
      open
      size="lg"
      title={t('accounting.rules.addTitle')}
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
        <TextField label={t('accounting.rules.eventType')} help={t('accounting.rules.eventTypeHelp', { examples: eventTypes.slice(0, 3).join(', ') })} value={eventType} onChange={(event) => setEventType(event.target.value)} maxLength={60} />
        {lines.map((line, index) => (
          <div key={index} className="grid items-end gap-3 sm:grid-cols-[1fr_8rem_2fr]">
            <TextField label={t('accounting.rules.field')} value={line.field} onChange={(event) => update(index, { field: event.target.value })} maxLength={60} />
            <Select label={t('accounting.rules.side')} options={['debit', 'credit'].map((value) => ({ value, label: t(`accounting.columns.${value}`) }))} value={line.side} onChange={(event) => update(index, { side: event.target.value })} />
            <Select label={t('accounting.columns.account')} placeholder={t('accounting.journalForm.chooseAccount')} options={options} value={line.account_id} onChange={(event) => update(index, { account_id: event.target.value })} />
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
  const [adding, setAdding] = useState(false)
  const rules = useQuery({ queryKey: ['accounting-posting-rules', company.id], queryFn: () => api.get(companyPath(company, 'posting-rules')) })
  const exceptions = useQuery({ queryKey: ['accounting-posting-exceptions', company.id], queryFn: () => api.get(companyPath(company, 'posting-exceptions')) })
  const retry = useMutation({
    mutationFn: (id) => api.post(`accounting/posting-exceptions/${id}/retry`),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['accounting-posting-exceptions', company.id] }),
  })
  const manage = can('accounting.posting_rule.manage')

  return (
    <>
      {manage ? <div className="flex justify-end"><Button icon="plus" onClick={() => setAdding(true)}>{t('accounting.rules.add')}</Button></div> : null}
      <DataTable
        caption={t('accounting.rules.title')}
        emptyText={t('accounting.rules.empty')}
        rows={rules.data?.data ?? []}
        columns={[
          { key: 'event_type', label: t('accounting.rules.eventType'), render: (row) => <span className="font-mono text-caption">{row.event_type}</span> },
          { key: 'lines', label: t('accounting.journalForm.lines'), render: (row) => row.lines.map((line) => `${line.field} → ${t(`accounting.columns.${line.side}`)}`).join(', ') },
        ]}
      />
      <Card title={t('accounting.rules.exceptionsTitle')} subtitle={t('accounting.rules.exceptionsText')}>
        {retry.isError ? <Alert tone="danger" title={errorMessage(retry.error)} /> : null}
        <DataTable
          caption={t('accounting.rules.exceptionsTitle')}
          emptyText={t('accounting.rules.noExceptions')}
          rows={exceptions.data?.data ?? []}
          columns={[
            { key: 'source', label: t('accounting.rules.source'), render: (row) => <span className="font-mono text-caption">{row.source_type}</span> },
            { key: 'error', label: t('accounting.rules.reason'), render: (row) => row.error_message },
            { key: 'attempts', label: t('accounting.rules.attempts'), align: 'end', render: (row) => <span className="tabular-nums">{row.attempts}</span> },
            { key: 'status', label: t('accounting.columns.status'), render: (row) => <StatusBadge tone={row.status === 'open' ? 'warning' : 'success'}>{t(`accounting.exceptionStatuses.${row.status}`)}</StatusBadge> },
            { key: 'retry', label: '', align: 'end', render: (row) => (manage && row.status === 'open' ? <Button size="sm" loading={retry.isPending} onClick={() => retry.mutate(row.id)}>{t('accounting.rules.retry')}</Button> : null) },
          ]}
        />
      </Card>
      {adding ? <RuleDialog company={company} eventTypes={rules.data?.meta?.event_types ?? []} onClose={() => setAdding(false)} /> : null}
    </>
  )
}

/** ACC-01, ACC-02, ACC-07: the chart of accounts, the accounts each role posts to, and posting rules. */
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
