import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable, Dialog, Money, MoneyInput, Select, StatusBadge, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { platformApi } from '../platformClient'
import { usePlatformAuth } from '../usePlatformAuth'
import { Detail, ReasonDialog, TextAreaField } from './adminParts'

const KEY = ['platform', 'marketplace']
const VERSION_TONES = { draft: 'neutral', submitted: 'warning', approved: 'success', rejected: 'danger' }
const APP_TONES = { active: 'success', suspended: 'danger', archived: 'neutral' }

function Diff({ label, diff }) {
  const { t } = useTranslation()
  if (!diff || (diff.added.length === 0 && diff.removed.length === 0)) return null
  return (
    <div className="flex flex-col gap-1">
      <span className="text-label text-ink">{label}</span>
      {diff.added.map((entry) => (
        <span key={`+${entry}`} className="font-mono text-caption text-ink">
          {t('admin.marketplace.added', { entry })}
        </span>
      ))}
      {diff.removed.map((entry) => (
        <span key={`-${entry}`} className="font-mono text-caption text-ink-muted">
          {t('admin.marketplace.removed', { entry })}
        </span>
      ))}
    </div>
  )
}

function ApproveDialog({ version, onDone, onClose }) {
  const { t } = useTranslation()
  const [note, setNote] = useState('')
  const approve = useMutation({ mutationFn: () => platformApi.post(`platform/marketplace/versions/${version.id}/approve`, { note: note.trim() || null }), onSuccess: onDone })
  return (
    <Dialog
      open
      title={t('admin.marketplace.approveTitle', { version: version.version })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="pay" loading={approve.isPending} onClick={() => approve.mutate()}>{t('admin.marketplace.approve')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('admin.marketplace.approveText')}</p>
        <TextAreaField label={t('admin.marketplace.note')} value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} />
        {approve.error ? <Alert tone="danger" title={errorMessage(approve.error)} /> : null}
      </div>
    </Dialog>
  )
}

/** ADM-05: one app: its developer, client, versions, and what a submitted version changes. */
function AppReview({ appId, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { can } = usePlatformAuth()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...KEY, 'app', appId], queryFn: () => platformApi.get(`platform/marketplace/apps/${appId}`) })
  const app = query.data?.data
  const done = async () => {
    setDialog(null)
    await queryClient.invalidateQueries({ queryKey: KEY })
  }
  const submitted = app?.versions.find((version) => version.status === 'submitted')
  const review = can('platform.marketplace.review')

  return (
    <Dialog
      open
      size="lg"
      title={app?.name ?? t('common.loading')}
      onClose={onClose}
      footer={
        app && review ? (
          <>
            {app.status === 'suspended' ? (
              <Button onClick={() => setDialog('reinstate')}>{t('admin.marketplace.reinstate')}</Button>
            ) : (
              <Button variant="danger" onClick={() => setDialog('suspend')}>{t('admin.marketplace.suspend')}</Button>
            )}
            {submitted ? <Button variant="danger" onClick={() => setDialog('reject')}>{t('admin.marketplace.reject')}</Button> : null}
            {submitted ? <Button variant="pay" onClick={() => setDialog('approve')}>{t('admin.marketplace.approve')}</Button> : null}
          </>
        ) : null
      }
    >
      {query.error ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {app ? (
        <div className="flex flex-col gap-5">
          <div className="grid gap-4 sm:grid-cols-2">
            <Detail label={t('admin.marketplace.developer')}>{`${app.developer?.name ?? ''} · ${app.developer?.email ?? ''}`}</Detail>
            <Detail label={t('admin.marketplace.status')}>
              <StatusBadge tone={APP_TONES[app.status]}>{t(`admin.marketplace.appStatus.${app.status}`)}</StatusBadge>
            </Detail>
            <Detail label={t('admin.marketplace.client')}>
              <code className="font-mono text-caption break-all">{app.client.id}</code>
            </Detail>
            <Detail label={t('admin.marketplace.redirects')}>{app.client.redirect_uris.join(', ') || t('admin.marketplace.none')}</Detail>
          </div>
          {app.suspended_reason ? <Alert tone="danger" title={app.suspended_reason} /> : null}
          {submitted && app.changes ? (
            <Card title={t('admin.marketplace.changesTitle', { version: submitted.version })}>
              <div className="flex flex-col gap-3">
                {app.changes.needs_consent ? <Alert tone="warning" title={t('admin.marketplace.needsConsent')} /> : null}
                <Diff label={t('admin.marketplace.scopes')} diff={app.changes.scopes} />
                <Diff label={t('admin.marketplace.events')} diff={app.changes.webhook_events} />
                <Diff label={t('admin.marketplace.redirects')} diff={app.changes.redirect_uris} />
                {app.changes.pricing_changed ? <span className="text-caption text-ink">{t('admin.marketplace.pricingChanged')}</span> : null}
                <Detail label={t('admin.marketplace.webhookUrl')}>{submitted.webhook_url ?? t('admin.marketplace.none')}</Detail>
                <p className="whitespace-pre-line text-body text-ink">{submitted.description}</p>
              </div>
            </Card>
          ) : null}
          <DataTable
            caption={t('admin.marketplace.versions')}
            rows={app.versions}
            emptyText=""
            columns={[
              { key: 'version', label: t('admin.marketplace.version'), render: (row) => <span className="tabular-nums">{row.version}</span> },
              { key: 'status', label: t('admin.marketplace.status'), render: (row) => <StatusBadge tone={VERSION_TONES[row.status]}>{t(`admin.marketplace.versionStatus.${row.status}`)}</StatusBadge> },
              { key: 'scopes', label: t('admin.marketplace.scopes'), wrap: true, render: (row) => <span className="font-mono text-caption">{row.scopes.join(', ')}</span> },
              { key: 'submitted', label: t('admin.marketplace.submitted'), render: (row) => (row.submitted_at ? formatWhen(row.submitted_at, locale) : '') },
            ]}
          />
        </div>
      ) : null}
      {dialog === 'approve' ? <ApproveDialog version={submitted} onDone={done} onClose={() => setDialog(null)} /> : null}
      {dialog === 'reject' ? (
        <ReasonDialog title={t('admin.marketplace.rejectTitle')} confirmLabel={t('admin.marketplace.reject')} confirmVariant="danger" send={(reason) => platformApi.post(`platform/marketplace/versions/${submitted.id}/reject`, { reason })} onDone={done} onClose={() => setDialog(null)} />
      ) : null}
      {dialog === 'suspend' ? (
        <ReasonDialog title={t('admin.marketplace.suspendTitle')} description={t('admin.marketplace.suspendText')} confirmLabel={t('admin.marketplace.suspend')} confirmVariant="danger" send={(reason) => platformApi.post(`platform/marketplace/apps/${appId}/suspend`, { reason })} onDone={done} onClose={() => setDialog(null)} />
      ) : null}
      {dialog === 'reinstate' ? (
        <ReasonDialog title={t('admin.marketplace.reinstateTitle')} confirmLabel={t('admin.marketplace.reinstate')} send={(reason) => platformApi.post(`platform/marketplace/apps/${appId}/reinstate`, { reason })} onDone={done} onClose={() => setDialog(null)} />
      ) : null}
    </Dialog>
  )
}

/** ADM-05: the marketplace review queue (platform.marketplace.view; deciding needs .review). */
export default function MarketplaceConsole() {
  const { t } = useTranslation()
  const [status, setStatus] = useState('submitted')
  const [open, setOpen] = useState(null)
  const query = useQuery({ queryKey: [...KEY, 'apps', status], queryFn: () => platformApi.get(`platform/marketplace/apps?status=${status}`) })

  return (
    <>
      <PageHeader title={t('admin.marketplace.title')} description={t('admin.marketplace.description')} />
      <Tabs
        className="mb-5"
        value={status}
        onChange={setStatus}
        items={['submitted', 'listed', 'suspended', 'all'].map((value) => ({ value, label: t(`admin.marketplace.tabs.${value}`) }))}
      />
      {query.error ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('admin.marketplace.title')}
        rows={query.data?.data ?? []}
        loading={query.isPending}
        emptyText={t('admin.marketplace.empty')}
        onRowClick={(row) => setOpen(row.id)}
        columns={[
          { key: 'name', label: t('admin.marketplace.app'), render: (row) => <div className="flex flex-col"><span className="font-medium text-ink">{row.name}</span><code className="font-mono text-caption text-ink-muted">{row.slug}</code></div> },
          { key: 'developer', label: t('admin.marketplace.developer'), render: (row) => row.developer_name },
          { key: 'status', label: t('admin.marketplace.status'), render: (row) => <StatusBadge tone={APP_TONES[row.status]}>{t(`admin.marketplace.appStatus.${row.status}`)}</StatusBadge> },
          { key: 'latest', label: t('admin.marketplace.latest'), render: (row) => (row.latest_status ? <StatusBadge tone={VERSION_TONES[row.latest_status]}>{t('admin.marketplace.versionLabel', { version: row.latest_version, status: t(`admin.marketplace.versionStatus.${row.latest_status}`) })}</StatusBadge> : null) },
        ]}
      />
      {open ? <AppReview appId={open} onClose={() => setOpen(null)} /> : null}
    </>
  )
}

function PayoutDialog({ row, onDone, onClose }) {
  const { t } = useTranslation()
  const [amount, setAmount] = useState(String(row.balance_minor))
  const [reference, setReference] = useState('')
  const pay = useMutation({
    mutationFn: () => platformApi.post(`platform/marketplace/developers/${row.developer_id}/payouts`, { currency: row.currency, amount_minor: Number(amount), reference: reference.trim() }),
    onSuccess: onDone,
  })
  return (
    <Dialog
      open
      title={t('admin.payouts.recordTitle', { name: row.developer?.name ?? '' })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="pay" loading={pay.isPending} disabled={!amount || reference.trim().length < 3} onClick={() => pay.mutate()}>{t('admin.payouts.markPaid')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('admin.payouts.recordText')}</p>
        <MoneyInput label={t('admin.payouts.amount')} currency={row.currency} value={amount} onChange={(value) => setAmount(value ?? '')} />
        <TextField label={t('admin.payouts.reference')} help={t('admin.payouts.referenceHelp')} value={reference} maxLength={100} onChange={(event) => setReference(event.target.value)} />
        {pay.error ? <Alert tone="danger" title={errorMessage(pay.error)} /> : null}
      </div>
    </Dialog>
  )
}

/** MKT-03: what each developer is owed (their 80 % of paid app lines), and manual payouts (platform.marketplace.payouts). */
export function PayoutsConsole() {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const [paying, setPaying] = useState(null)
  const [currency, setCurrency] = useState('')
  const query = useQuery({ queryKey: [...KEY, 'payouts'], queryFn: () => platformApi.get('platform/marketplace/payouts') })
  const rows = (query.data?.data ?? []).filter((row) => !currency || row.currency === currency).map((row) => ({ ...row, id: `${row.developer_id}-${row.currency}` }))

  return (
    <>
      <PageHeader title={t('admin.payouts.title')} description={t('admin.payouts.description')} />
      <Select
        label={t('admin.payouts.currency')}
        className="mb-5 max-w-field"
        options={[{ value: '', label: t('admin.payouts.allCurrencies') }, ...['KES', 'USD', 'CDF'].map((code) => ({ value: code, label: code }))]}
        value={currency}
        onChange={(event) => setCurrency(event.target.value)}
      />
      {query.error ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('admin.payouts.title')}
        rows={rows}
        loading={query.isPending}
        emptyText={t('admin.payouts.empty')}
        columns={[
          { key: 'developer', label: t('admin.payouts.developer'), render: (row) => <div className="flex flex-col"><span className="text-ink">{row.developer?.name}</span><span className="text-caption text-ink-muted">{row.developer?.email}</span></div> },
          { key: 'earned', label: t('admin.payouts.earned'), numeric: true, render: (row) => <Money amount={row.earned_minor} currency={row.currency} locale={locale} /> },
          { key: 'paid', label: t('admin.payouts.paid'), numeric: true, render: (row) => <Money amount={row.paid_minor} currency={row.currency} locale={locale} /> },
          { key: 'balance', label: t('admin.payouts.balance'), numeric: true, render: (row) => <Money amount={row.balance_minor} currency={row.currency} locale={locale} /> },
          { key: 'last', label: t('admin.payouts.lastPayout'), render: (row) => (row.last_payout_at ? formatWhen(row.last_payout_at, locale) : t('admin.payouts.never')) },
          { key: 'actions', label: t('admin.payouts.actions'), align: 'end', render: (row) => (row.balance_minor > 0 ? <Button onClick={() => setPaying(row)}>{t('admin.payouts.record')}</Button> : null) },
        ]}
      />
      {paying ? (
        <PayoutDialog
          row={paying}
          onClose={() => setPaying(null)}
          onDone={async () => {
            setPaying(null)
            await queryClient.invalidateQueries({ queryKey: KEY })
          }}
        />
      ) : null}
    </>
  )
}
