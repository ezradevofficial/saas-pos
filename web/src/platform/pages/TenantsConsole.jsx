import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, Dialog, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { decimalToMinor } from '@/lib/money'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { STATUS_TONES, INVOICE_TONES } from '@/pages/settings/subscription/subscriptionData'
import { useCatalogueKeyNames } from '../useCatalogueName'
import { usePlatformAuth } from '../usePlatformAuth'
import { platformApi } from '../platformClient'
import { Detail, ReasonDialog } from './adminParts'

const TENANTS_KEY = ['platform', 'tenants']
const EMPTY_FILTERS = { search: '', owner_email: '', country: '', plan: '', status: '', module: '' }
const STATUSES = ['trial', 'active', 'past_due', 'read_only', 'suspended', 'cancelled']

/** Platform console: customer tenants with plan, status, MRR and trial end (ADR 016). */
export function TenantsList() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePlatformAuth()
  const names = useCatalogueKeyNames()
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [applied, setApplied] = useState({})
  const query = new URLSearchParams(Object.entries(applied).filter(([, value]) => value)).toString()
  const tenants = useQuery({ queryKey: [...TENANTS_KEY, 'search', query], queryFn: () => platformApi.get(query ? `platform/tenants?${query}` : 'platform/tenants') })
  const set = (field) => (event) => setFilters((current) => ({ ...current, [field]: event.target.value }))
  const columns = [
    { key: 'name', label: t('platform.customers.columns.name'), render: (row) => <span className="text-ink">{row.name}</span> },
    { key: 'country', label: t('platform.customers.columns.country'), render: (row) => row.country },
    { key: 'plan', label: t('platform.customers.columns.plan'), render: (row) => names?.plan(row.plan) ?? '' },
    {
      key: 'status',
      label: t('platform.customers.columns.status'),
      render: (row) => (
        <div className="flex flex-wrap items-center gap-3">
          <StatusBadge tone={STATUS_TONES[row.status] ?? 'neutral'}>{t(`subscription.status.${row.status}`)}</StatusBadge>
          {row.alerts?.length ? <StatusBadge tone="danger">{t('platform.customers.overpayment')}</StatusBadge> : null}
        </div>
      ),
    },
    { key: 'mrr', label: t('platform.customers.columns.mrr'), render: (row) => <Money amount={row.mrr.amount_minor} currency={row.mrr.currency} /> },
    { key: 'trial', label: t('platform.customers.columns.trialEnds'), render: (row) => (row.status === 'trial' && row.trial_ends_at ? formatCalendarDate(row.trial_ends_at, locale) : '') },
  ]

  return (
    <>
      <PageHeader title={t('platform.customers.title')} description={t('platform.customers.description')} />
      {/* ADM-01a: search by name or slug, owner email, country, plan, status and module. */}
      <form
        role="search"
        aria-label={t('admin.tenants.search')}
        className="grid gap-3 rounded-lg border border-border bg-surface-200 p-4 sm:grid-cols-3"
        onSubmit={(event) => {
          event.preventDefault()
          setApplied({ ...filters, owner_email: filters.owner_email.trim(), search: filters.search.trim() })
        }}
      >
        <TextField label={t('admin.tenants.nameOrSlug')} value={filters.search} onChange={set('search')} />
        <TextField type="email" label={t('admin.tenants.ownerEmail')} value={filters.owner_email} onChange={set('owner_email')} />
        <Select
          label={t('platform.customers.columns.country')}
          value={filters.country}
          onChange={set('country')}
          options={[{ value: '', label: t('admin.any') }, { value: 'KE', label: t('admin.countries.KE') }, { value: 'CD', label: t('admin.countries.CD') }]}
        />
        <TextField label={t('admin.tenants.planKey')} help={t('admin.tenants.planKeyHelp')} value={filters.plan} onChange={set('plan')} />
        <Select
          label={t('platform.customers.columns.status')}
          value={filters.status}
          onChange={set('status')}
          options={[{ value: '', label: t('admin.any') }, ...STATUSES.map((status) => ({ value: status, label: t(`subscription.status.${status}`) }))]}
        />
        <TextField label={t('admin.tenants.moduleKey')} help={t('admin.tenants.moduleKeyHelp')} value={filters.module} onChange={set('module')} />
        <div className="flex justify-end gap-2 sm:col-span-3">
          <Button
            variant="ghost"
            type="button"
            onClick={() => {
              setFilters(EMPTY_FILTERS)
              setApplied({})
            }}
          >
            {t('admin.tenants.clear')}
          </Button>
          <Button variant="primary" type="submit">{t('admin.tenants.searchButton')}</Button>
        </div>
      </form>
      {tenants.error ? <Alert tone="danger" title={errorMessage(tenants.error)} /> : null}
      <DataTable
        caption={t('platform.customers.title')}
        columns={columns}
        rows={tenants.data?.data ?? []}
        loading={tenants.isPending}
        emptyText={t('platform.customers.empty')}
        onRowClick={(row) => navigate(`/platform/tenants/${row.tenant_id}`)}
      />
      {can('platform.tenants.create') ? <AwaitingHandover /> : null}
    </>
  )
}

/** ONB-08: tenants created for customers and not handed over after 30 days (never deleted). */
function AwaitingHandover() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const awaiting = useQuery({ queryKey: [...TENANTS_KEY, 'awaiting-handover'], queryFn: () => platformApi.get('platform/tenants/awaiting-handover') })
  const columns = [
    { key: 'business', label: t('platform.customers.awaitingColumns.business'), render: (row) => <span className="text-ink">{row.business_name}</span> },
    { key: 'staff', label: t('platform.customers.awaitingColumns.staff'), render: (row) => row.staff_name ?? '' },
    { key: 'contact', label: t('platform.customers.awaitingColumns.contact'), render: (row) => row.contact },
    { key: 'created', label: t('platform.customers.awaitingColumns.created'), render: (row) => (row.created_at ? formatCalendarDate(row.created_at, locale) : '') },
  ]
  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <h2 className="text-h2 text-ink">{t('platform.customers.awaitingTitle')}</h2>
        <p className="text-body text-ink-muted">{t('platform.customers.awaitingDescription')}</p>
      </div>
      {awaiting.error ? <Alert tone="danger" title={errorMessage(awaiting.error)} /> : null}
      <DataTable
        caption={t('platform.customers.awaitingTitle')}
        columns={columns}
        rows={awaiting.data?.data ?? []}
        loading={awaiting.isPending}
        emptyText={t('platform.customers.awaitingEmpty')}
        onRowClick={(row) => navigate(`/platform/tenants/${row.tenant_id}`)}
      />
    </section>
  )
}

/** ONB-08: a new Owner invitation while the tenant has not been handed over. */
function Reinvite({ tenantId, contact }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const reinvite = useMutation({
    mutationFn: () => platformApi.post(`platform/tenants/${tenantId}/reinvite`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TENANTS_KEY }),
  })
  return (
    <div className="flex flex-col gap-3 px-5 pb-4">
      <Alert
        tone={reinvite.isSuccess ? 'success' : 'info'}
        title={reinvite.isSuccess ? t('platform.customers.reinvited', { destination: reinvite.data.data.destination_masked }) : t('platform.customers.handover', { contact })}
        action={
          <Button loading={reinvite.isPending} disabled={reinvite.isPending} onClick={() => reinvite.mutate()}>
            {t('platform.customers.reinvite')}
          </Button>
        }
      />
      {reinvite.error ? <Alert tone="danger" title={errorMessage(reinvite.error)} /> : null}
    </div>
  )
}

function DateDialog({ onClose, tenantId }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [date, setDate] = useState('')
  return (
    <ReasonDialog
      title={t('platform.customers.extendTrial')}
      confirmLabel={t('platform.customers.saveTrial')}
      ready={Boolean(date)}
      extra={<TextField type="date" label={t('platform.customers.trialEndsOn')} value={date} onChange={(event) => setDate(event.target.value)} />}
      send={(reason) => platformApi.post(`platform/tenants/${tenantId}/trial`, { trial_ends_at: `${date}T23:59:59Z`, reason })}
      onDone={async () => {
        await queryClient.invalidateQueries({ queryKey: TENANTS_KEY })
        onClose()
      }}
      onClose={onClose}
    />
  )
}

/** ADM-01c: suspend or reactivate, with a reason; the tenant's Owners are told. */
function StatusDialog({ action, tenantId, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  return (
    <ReasonDialog
      title={t(`admin.tenants.${action}Title`)}
      description={t(`admin.tenants.${action}Text`)}
      confirmLabel={t(`admin.tenants.${action}`)}
      send={(reason) => platformApi.post(`platform/tenants/${tenantId}/${action}`, { reason })}
      onDone={async () => {
        await queryClient.invalidateQueries({ queryKey: TENANTS_KEY })
        onClose()
      }}
      onClose={onClose}
    />
  )
}

function GraceDialog({ open, onClose, tenantId, current }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [days, setDays] = useState(current == null ? '' : String(current))
  const save = useMutation({
    mutationFn: () => platformApi.post(`platform/tenants/${tenantId}/grace`, { grace_days: days === '' ? null : Number(days) }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['platform', 'tenants'] })
      onClose()
    },
  })
  const errors = formErrors(save.error)
  return (
    <Dialog
      open={open}
      title={t('platform.customers.grace')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('subscription.pay.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{t('platform.customers.saveGrace')}</Button>
        </>
      }
    >
      <TextField type="number" min={0} max={90} label={t('platform.customers.graceDays')} help={t('platform.customers.graceHelp')} value={days} error={errors.grace_days} onChange={(event) => setDays(event.target.value)} />
    </Dialog>
  )
}

function MarkPaidDialog({ invoice, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [reference, setReference] = useState('')
  const mark = useMutation({
    mutationFn: () => platformApi.post(`platform/invoices/${invoice.id}/mark-paid`, { reference }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['platform', 'tenants'] })
      onClose()
    },
  })
  const errors = formErrors(mark.error)
  return (
    <Dialog
      open
      title={t('platform.customers.markPaidTitle', { number: invoice.number })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('subscription.pay.cancel')}</Button>
          <Button variant="pay" loading={mark.isPending} disabled={reference.trim().length < 3} onClick={() => mark.mutate()}>{t('platform.customers.markPaid')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Money amount={invoice.total.amount_minor} currency={invoice.total.currency} size="lg" />
        <TextField label={t('platform.customers.bankReference')} value={reference} error={errors.reference} onChange={(event) => setReference(event.target.value)} />
        {mark.error && !errors.reference ? <Alert tone="danger" title={errorMessage(mark.error)} /> : null}
      </div>
    </Dialog>
  )
}

/** One tenant: subscription, invoices, trial extension, grace override, bank transfers (ADR 016). */
export function TenantDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { tenantId } = useParams()
  const { can } = usePlatformAuth()
  const manage = can('platform.billing.manage')
  const tenantsManage = can('platform.tenants.manage')
  const names = useCatalogueKeyNames()
  const [dialog, setDialog] = useState(null)
  const [paying, setPaying] = useState(null)
  const detail = useQuery({ queryKey: [...TENANTS_KEY, tenantId], queryFn: () => platformApi.get(`platform/tenants/${tenantId}`) })
  const data = detail.data?.data
  const day = (value) => (value ? formatCalendarDate(value, locale) : '')

  const columns = [
    { key: 'number', label: t('subscription.invoices.columns.number'), render: (row) => row.number },
    { key: 'kind', label: t('subscription.invoices.columns.kind'), render: (row) => t(`subscription.invoices.kinds.${row.kind}`) },
    { key: 'period', label: t('subscription.invoices.columns.period'), render: (row) => t('subscription.overview.periodRange', { from: day(row.period_start), to: day(row.period_end) }) },
    { key: 'total', label: t('subscription.invoices.columns.total'), render: (row) => <Money amount={row.total.amount_minor} currency={row.total.currency} /> },
    { key: 'due', label: t('subscription.invoices.columns.due'), render: (row) => day(row.due_at) },
    { key: 'status', label: t('subscription.invoices.columns.status'), render: (row) => <StatusBadge tone={INVOICE_TONES[row.status]}>{t(`subscription.invoices.statuses.${row.status}`)}</StatusBadge> },
    {
      key: 'actions',
      label: t('subscription.invoices.columns.actions'),
      render: (row) =>
        manage && row.status === 'open' ? (
          <Button variant="ghost" onClick={() => setPaying(row)} aria-label={t('platform.customers.markPaidFor', { number: row.number })}>
            {t('platform.customers.markPaid')}
          </Button>
        ) : row.paid_via ? (
          <span className="text-caption text-ink-muted">{t(`platform.customers.paidVia.${row.paid_via}`)}</span>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        eyebrow={<Link to="/platform/tenants" className="text-caption text-ink-muted hover:text-ink">{t('platform.customers.title')}</Link>}
        title={data?.account.name ?? t('platform.customers.title')}
      />
      {detail.error ? <Alert tone="danger" title={errorMessage(detail.error)} /> : null}
      {data ? (
        <>
          <Card
            title={t('platform.customers.subscription')}
            actions={
              manage || tenantsManage ? (
                <div className="flex flex-wrap gap-2">
                  {tenantsManage && data.subscription?.status === 'trial' && !data.subscription.current_period_start ? (
                    <Button onClick={() => setDialog('trial')}>{t('platform.customers.extendTrial')}</Button>
                  ) : null}
                  {manage ? <Button onClick={() => setDialog('grace')}>{t('platform.customers.grace')}</Button> : null}
                  {tenantsManage && data.status === 'suspended' ? <Button onClick={() => setDialog('reactivate')}>{t('admin.tenants.reactivate')}</Button> : null}
                  {tenantsManage && data.status && data.status !== 'suspended' ? <Button variant="ghost" onClick={() => setDialog('suspend')}>{t('admin.tenants.suspend')}</Button> : null}
                </div>
              ) : null
            }
          >
            <dl className="grid gap-4 px-5 py-4 sm:grid-cols-3">
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.customers.columns.status')}</dt>
                <dd><StatusBadge tone={STATUS_TONES[data.account.status] ?? 'neutral'}>{t(`subscription.status.${data.account.status}`)}</StatusBadge></dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.customers.columns.plan')}</dt>
                <dd>{names ? `${names.plan(data.account.plan)} · ${t(`subscription.cycles.${data.account.cycle}`)}` : ''}</dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.customers.columns.mrr')}</dt>
                <dd><Money amount={data.account.mrr.amount_minor} currency={data.account.mrr.currency} /></dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.customers.columns.trialEnds')}</dt>
                <dd>{day(data.account.trial_ends_at)}</dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('subscription.overview.period')}</dt>
                <dd>{data.subscription?.current_period_start ? t('subscription.overview.periodRange', { from: day(data.subscription.current_period_start), to: day(data.subscription.current_period_end) }) : ''}</dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.customers.graceDays')}</dt>
                <dd>{data.grace_days == null ? t('platform.customers.gracePlatform') : t('platform.customers.graceValue', { count: data.grace_days })}</dd>
              </div>
            </dl>
            {data.assisted && !data.assisted.handed_over_at && can('platform.tenants.create') ? <Reinvite tenantId={tenantId} contact={data.assisted.contact} /> : null}
            {data.account.alerts?.length ? (
              <div className="px-5 pb-4">
                <Alert tone="danger" title={t('platform.customers.overpaymentText', { invoices: data.account.alerts.map((alert) => alert.invoice).join(', ') })} />
              </div>
            ) : null}
            {data.account.purge_due?.length ? (
              <div className="px-5 pb-4">
                <Alert tone="warning" title={t('platform.customers.purgeDue', { modules: names ? names.list(data.account.purge_due.map((entry) => entry.module)) : '' })} />
              </div>
            ) : null}
          </Card>
          <Overview data={data} names={names} />
          {can('platform.billing.view') ? <Deals tenantId={tenantId} currency={data.account.mrr.currency} /> : null}
          {can('platform.support.request') ? <SupportRequest tenantId={tenantId} /> : null}
          <section className="flex flex-col gap-3">
            <h2 className="text-h2 text-ink">{t('subscription.invoices.title')}</h2>
            <DataTable caption={t('subscription.invoices.title')} columns={columns} rows={data.invoices} emptyText={t('subscription.invoices.empty')} />
          </section>
          {dialog === 'trial' ? <DateDialog onClose={() => setDialog(null)} tenantId={tenantId} /> : null}
          {dialog === 'suspend' || dialog === 'reactivate' ? <StatusDialog action={dialog} tenantId={tenantId} onClose={() => setDialog(null)} /> : null}
          {dialog === 'grace' ? <GraceDialog open onClose={() => setDialog(null)} tenantId={tenantId} current={data.grace_days} /> : null}
          {paying ? <MarkPaidDialog invoice={paying} onClose={() => setPaying(null)} /> : null}
        </>
      ) : null}
    </>
  )
}

/** ADM-01b: modules, usage and health, read in the tenant's context: counts and times only. */
function Overview({ data, names }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const health = data.health ?? {}
  const when = (value) => (value ? formatCalendarDate(value, locale) : t('admin.tenants.never'))
  const lag = health.sync_lag_seconds == null ? t('admin.tenants.noDevices') : t('admin.tenants.lagHours', { count: Math.floor(health.sync_lag_seconds / 3600) })
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Card title={t('admin.tenants.modulesAndUsage')}>
        <dl className="grid gap-4 px-5 py-4 sm:grid-cols-2">
          <Detail label={t('admin.tenants.status')}>
            <StatusBadge tone={data.status === 'suspended' ? 'danger' : 'success'}>{t(`admin.tenants.statuses.${data.status ?? 'active'}`)}</StatusBadge>
          </Detail>
          <Detail label={t('admin.tenants.modules')}>{names ? names.list(data.modules ?? []) : ''}</Detail>
          {(data.usage?.units ?? []).map((unit) => (
            <Detail key={unit.unit} label={t(`admin.units.${unit.unit}`, { defaultValue: unit.unit })}>
              <span className="tabular-nums">{unit.allowed == null ? t('admin.tenants.usageUnlimited', { count: unit.count }) : t('admin.tenants.usageOf', { count: unit.count, allowed: unit.allowed })}</span>
            </Detail>
          ))}
        </dl>
      </Card>
      <Card title={t('admin.tenants.health')} subtitle={t('admin.tenants.healthHelp')}>
        <dl className="grid gap-4 px-5 py-4 sm:grid-cols-2">
          <Detail label={t('admin.tenants.lastActivity')}>{when(health.last_activity_at)}</Detail>
          <Detail label={t('admin.tenants.devices')}>
            <span className="tabular-nums">{health.devices ?? 0}</span>
          </Detail>
          <Detail label={t('admin.tenants.syncLag')}>{lag}</Detail>
          <Detail label={t('admin.tenants.failedJobs')}>
            <span className="tabular-nums">{health.failed_jobs ?? 0}</span>
          </Detail>
          {health.posting_exceptions != null ? (
            <Detail label={t('admin.tenants.postingExceptions')}>
              <span className="tabular-nums">{health.posting_exceptions}</span>
            </Detail>
          ) : null}
        </dl>
      </Card>
    </div>
  )
}

/** "2500.50" to minor units, or null when it is not a positive decimal of the currency. */
function toMinorOrNull(text, decimals) {
  const value = String(text ?? '').trim()
  const pattern = decimals === 0 ? /^\d+$/ : new RegExp(`^\\d+(\\.\\d{1,${decimals}})?$`)
  return pattern.test(value) ? Number(decimalToMinor(value, decimals)) : null
}

const DEAL_TONES ={ pending: 'warning', approved: 'success', rejected: 'neutral', ended: 'neutral' }

/** ADM-02: the tenant's custom Enterprise deals: drafted by sales or finance, approved by finance. */
function Deals({ tenantId, currency }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { can, user } = usePlatformAuth()
  const [drafting, setDrafting] = useState(false)
  const deals = useQuery({ queryKey: [...TENANTS_KEY, tenantId, 'deals'], queryFn: () => platformApi.get(`platform/tenants/${tenantId}/deals`) })
  const decide = useMutation({
    mutationFn: ({ id, action }) => platformApi.post(`platform/tenants/${tenantId}/deals/${id}/${action}`, {}),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TENANTS_KEY }),
  })
  const day = (value) => (value ? formatCalendarDate(value, locale) : t('admin.deals.openEnded'))
  const approver = can('platform.deals.approve')
  const columns = [
    { key: 'lines', label: t('admin.deals.lines'), render: (row) => row.lines.map((line) => line.key).join(', ') },
    { key: 'price', label: t('admin.deals.price'), render: (row) => <Money amount={row.lines[0]?.amount ?? 0} currency={row.currency} /> },
    { key: 'period', label: t('admin.deals.period'), render: (row) => t('subscription.overview.periodRange', { from: day(row.effective_from), to: day(row.effective_to) }) },
    { key: 'status', label: t('admin.deals.status'), render: (row) => <StatusBadge tone={DEAL_TONES[row.status]}>{t(`admin.deals.statuses.${row.status}`)}</StatusBadge> },
    { key: 'by', label: t('admin.deals.draftedBy'), render: (row) => row.created_by?.name ?? '' },
    {
      key: 'actions',
      label: t('subscription.invoices.columns.actions'),
      render: (row) =>
        approver && row.status === 'pending' && row.created_by?.id !== user?.id ? (
          <div className="flex gap-2">
            <Button variant="ghost" onClick={() => decide.mutate({ id: row.id, action: 'approve' })}>{t('admin.deals.approve')}</Button>
            <Button variant="ghost" onClick={() => decide.mutate({ id: row.id, action: 'reject' })}>{t('admin.deals.reject')}</Button>
          </div>
        ) : approver && row.status === 'approved' ? (
          <Button variant="ghost" onClick={() => decide.mutate({ id: row.id, action: 'end' })}>{t('admin.deals.end')}</Button>
        ) : null,
    },
  ]
  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h2 className="text-h2 text-ink">{t('admin.deals.title')}</h2>
          <p className="text-body text-ink-muted">{t('admin.deals.description')}</p>
        </div>
        {can('platform.deals.manage') ? <Button onClick={() => setDrafting(true)}>{t('admin.deals.draft')}</Button> : null}
      </div>
      {deals.error ? <Alert tone="danger" title={errorMessage(deals.error)} /> : null}
      {decide.error ? <Alert tone="danger" title={errorMessage(decide.error)} /> : null}
      <DataTable caption={t('admin.deals.title')} columns={columns} rows={deals.data?.data ?? []} loading={deals.isPending} emptyText={t('admin.deals.empty')} />
      {drafting ? <DealDialog tenantId={tenantId} currency={currency} onClose={() => setDrafting(false)} /> : null}
    </section>
  )
}

/** ADM-02b: one negotiated monthly price for a module or bundle (Enterprise included), effective-dated. */
function DealDialog({ tenantId, currency, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState(() => ({ type: 'bundle', key: 'enterprise', amount: '', extra: '', from: new Date().toISOString().slice(0, 10), to: '' }))
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const decimals = currency === 'CDF' ? 0 : 2
  const amount = toMinorOrNull(values.amount, decimals)
  return (
    <ReasonDialog
      title={t('admin.deals.draftTitle')}
      description={t('admin.deals.draftText')}
      confirmLabel={t('admin.deals.submit')}
      ready={amount != null && values.key.trim() !== ''}
      extra={
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('admin.deals.itemType')}
            value={values.type}
            onChange={set('type')}
            options={[
              { value: 'bundle', label: t('admin.deals.types.bundle') },
              { value: 'module', label: t('admin.deals.types.module') },
            ]}
          />
          <TextField label={t('admin.deals.itemKey')} help={t('admin.deals.itemKeyHelp')} value={values.key} onChange={set('key')} />
          <TextField label={t('admin.deals.monthlyPrice')} prefix={currency} inputMode="decimal" value={values.amount} onChange={set('amount')} />
          <TextField label={t('admin.deals.extraOutlet')} prefix={currency} inputMode="decimal" value={values.extra} onChange={set('extra')} />
          <TextField type="date" label={t('admin.deals.from')} value={values.from} onChange={set('from')} />
          <TextField type="date" label={t('admin.deals.to')} help={t('admin.deals.toHelp')} value={values.to} onChange={set('to')} />
        </div>
      }
      send={(reason) => {
        const extra = toMinorOrNull(values.extra, decimals)
        return platformApi.post(`platform/tenants/${tenantId}/deals`, {
          bundle: values.type === 'bundle' ? values.key.trim() : null,
          lines: [{ type: values.type, key: values.key.trim(), amount, ...(extra == null ? {} : { extra_outlet_amount: extra }) }],
          effective_from: values.from,
          effective_to: values.to || null,
          reason,
        })
      }}
      onDone={async () => {
        await queryClient.invalidateQueries({ queryKey: TENANTS_KEY })
        onClose()
      }}
      onClose={onClose}
    />
  )
}

/** RBAC-13a: ask the tenant for time-limited access; nothing opens until it approves. */
function SupportRequest({ tenantId }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [mode, setMode] = useState('read')
  const [hours, setHours] = useState('4')
  return (
    <Card title={t('admin.support.askTitle')} subtitle={t('admin.support.askText')} actions={<Button onClick={() => setOpen(true)}>{t('admin.support.ask')}</Button>}>
      {open ? (
        <ReasonDialog
          title={t('admin.support.askTitle')}
          description={t('admin.support.askDialogText')}
          confirmLabel={t('admin.support.send')}
          ready={Number(hours) >= 1 && Number(hours) <= 72}
          extra={
            <div className="grid gap-4 sm:grid-cols-2">
              <Select
                label={t('admin.support.mode')}
                value={mode}
                onChange={(event) => setMode(event.target.value)}
                options={[
                  { value: 'read', label: t('admin.support.modes.read') },
                  { value: 'write', label: t('admin.support.modes.write') },
                ]}
              />
              <TextField type="number" min={1} max={72} label={t('admin.support.hours')} help={t('admin.support.hoursHelp')} value={hours} onChange={(event) => setHours(event.target.value)} />
            </div>
          }
          send={(reason) => platformApi.post(`platform/tenants/${tenantId}/support-requests`, { reason, mode, hours: Number(hours) })}
          onDone={() => navigate('/platform/support')}
          onClose={() => setOpen(false)}
        />
      ) : null}
    </Card>
  )
}
