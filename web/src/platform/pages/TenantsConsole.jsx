import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, Dialog, Money, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { STATUS_TONES, INVOICE_TONES } from '@/pages/settings/subscription/subscriptionData'
import { usePlatformAuth } from '../usePlatformAuth'
import { platformApi } from '../platformClient'

const TENANTS_KEY = ['platform', 'tenants']

/** Platform console: customer tenants with plan, status, MRR and trial end (ADR 016). */
export function TenantsList() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const tenants = useQuery({ queryKey: TENANTS_KEY, queryFn: () => platformApi.get('platform/tenants') })
  const columns = [
    { key: 'name', label: t('platform.tenants.columns.name'), render: (row) => <span className="text-ink">{row.name}</span> },
    { key: 'country', label: t('platform.tenants.columns.country'), render: (row) => row.country },
    { key: 'plan', label: t('platform.tenants.columns.plan'), render: (row) => <span className="font-mono text-caption">{row.plan}</span> },
    { key: 'status', label: t('platform.tenants.columns.status'), render: (row) => <StatusBadge tone={STATUS_TONES[row.status] ?? 'neutral'}>{t(`subscription.status.${row.status}`)}</StatusBadge> },
    { key: 'mrr', label: t('platform.tenants.columns.mrr'), render: (row) => <Money amount={row.mrr.amount_minor} currency={row.mrr.currency} /> },
    { key: 'trial', label: t('platform.tenants.columns.trialEnds'), render: (row) => (row.status === 'trial' && row.trial_ends_at ? formatCalendarDate(row.trial_ends_at, locale) : '') },
  ]

  return (
    <>
      <PageHeader title={t('platform.tenants.title')} description={t('platform.tenants.description')} />
      {tenants.error ? <Alert tone="danger" title={errorMessage(tenants.error)} /> : null}
      <DataTable
        caption={t('platform.tenants.title')}
        columns={columns}
        rows={tenants.data?.data ?? []}
        loading={tenants.isPending}
        emptyText={t('platform.tenants.empty')}
        onRowClick={(row) => navigate(`/platform/tenants/${row.tenant_id}`)}
      />
    </>
  )
}

function DateDialog({ open, onClose, tenantId }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [date, setDate] = useState('')
  const extend = useMutation({
    mutationFn: () => platformApi.post(`platform/tenants/${tenantId}/trial`, { trial_ends_at: `${date}T23:59:59Z` }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['platform', 'tenants'] })
      onClose()
    },
  })
  const errors = formErrors(extend.error)
  return (
    <Dialog
      open={open}
      title={t('platform.tenants.extendTrial')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('subscription.pay.cancel')}</Button>
          <Button variant="primary" loading={extend.isPending} disabled={!date} onClick={() => extend.mutate()}>{t('platform.tenants.saveTrial')}</Button>
        </>
      }
    >
      <TextField type="date" label={t('platform.tenants.trialEndsOn')} value={date} error={errors.trial_ends_at} onChange={(event) => setDate(event.target.value)} />
      {extend.error && !errors.trial_ends_at ? <Alert tone="danger" title={errorMessage(extend.error)} /> : null}
    </Dialog>
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
      title={t('platform.tenants.grace')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('subscription.pay.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{t('platform.tenants.saveGrace')}</Button>
        </>
      }
    >
      <TextField type="number" min={0} max={90} label={t('platform.tenants.graceDays')} help={t('platform.tenants.graceHelp')} value={days} error={errors.grace_days} onChange={(event) => setDays(event.target.value)} />
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
      title={t('platform.tenants.markPaidTitle', { number: invoice.number })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('subscription.pay.cancel')}</Button>
          <Button variant="pay" loading={mark.isPending} disabled={reference.trim().length < 3} onClick={() => mark.mutate()}>{t('platform.tenants.markPaid')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Money amount={invoice.total.amount_minor} currency={invoice.total.currency} size="lg" />
        <TextField label={t('platform.tenants.bankReference')} value={reference} error={errors.reference} onChange={(event) => setReference(event.target.value)} />
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
  const manage = can('billing.manage')
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
          <Button variant="ghost" onClick={() => setPaying(row)} aria-label={t('platform.tenants.markPaidFor', { number: row.number })}>
            {t('platform.tenants.markPaid')}
          </Button>
        ) : row.paid_via ? (
          <span className="text-caption text-ink-muted">{t(`platform.tenants.paidVia.${row.paid_via}`)}</span>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        eyebrow={<Link to="/platform/tenants" className="text-caption text-ink-muted hover:text-ink">{t('platform.tenants.title')}</Link>}
        title={data?.account.name ?? t('platform.tenants.title')}
      />
      {detail.error ? <Alert tone="danger" title={errorMessage(detail.error)} /> : null}
      {data ? (
        <>
          <Card
            title={t('platform.tenants.subscription')}
            actions={
              manage ? (
                <div className="flex flex-wrap gap-2">
                  {data.subscription?.status === 'trial' && !data.subscription.current_period_start ? (
                    <Button onClick={() => setDialog('trial')}>{t('platform.tenants.extendTrial')}</Button>
                  ) : null}
                  <Button onClick={() => setDialog('grace')}>{t('platform.tenants.grace')}</Button>
                </div>
              ) : null
            }
          >
            <dl className="grid gap-4 px-5 py-4 sm:grid-cols-3">
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.tenants.columns.status')}</dt>
                <dd><StatusBadge tone={STATUS_TONES[data.account.status] ?? 'neutral'}>{t(`subscription.status.${data.account.status}`)}</StatusBadge></dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.tenants.columns.plan')}</dt>
                <dd className="font-mono text-caption">{data.account.plan} · {t(`subscription.cycles.${data.account.cycle}`)}</dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.tenants.columns.mrr')}</dt>
                <dd><Money amount={data.account.mrr.amount_minor} currency={data.account.mrr.currency} /></dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.tenants.columns.trialEnds')}</dt>
                <dd>{day(data.account.trial_ends_at)}</dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('subscription.overview.period')}</dt>
                <dd>{data.subscription?.current_period_start ? t('subscription.overview.periodRange', { from: day(data.subscription.current_period_start), to: day(data.subscription.current_period_end) }) : ''}</dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('platform.tenants.graceDays')}</dt>
                <dd>{data.grace_days == null ? t('platform.tenants.gracePlatform') : t('platform.tenants.graceValue', { count: data.grace_days })}</dd>
              </div>
            </dl>
            {data.account.purge_due?.length ? (
              <div className="px-5 pb-4">
                <Alert tone="warning" title={t('platform.tenants.purgeDue', { modules: data.account.purge_due.map((entry) => entry.module).join(', ') })} />
              </div>
            ) : null}
          </Card>
          <section className="flex flex-col gap-3">
            <h2 className="text-h2 text-ink">{t('subscription.invoices.title')}</h2>
            <DataTable caption={t('subscription.invoices.title')} columns={columns} rows={data.invoices} emptyText={t('subscription.invoices.empty')} />
          </section>
          {dialog === 'trial' ? <DateDialog open onClose={() => setDialog(null)} tenantId={tenantId} /> : null}
          {dialog === 'grace' ? <GraceDialog open onClose={() => setDialog(null)} tenantId={tenantId} current={data.grace_days} /> : null}
          {paying ? <MarkPaidDialog invoice={paying} onClose={() => setPaying(null)} /> : null}
        </>
      ) : null}
    </>
  )
}
