import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DataTable, Dialog, Icon, KpiTile, Money, MoneyInput, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { saveFile } from '@/lib/files'
import { formatAmount } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { BalanceOfLimit } from './CreditAccounts'
import { ACCOUNT_TONES, AGEING_BUCKETS, fieldErrors } from './loyaltyData'

const ACTIONS = { payments: 'loyalty.credit.receive', charges: 'loyalty.credit.charge', adjustments: 'loyalty.credit.adjust' }

/**
 * LOY-14..LOY-18: one credit account: balance against the core limit,
 * ageing today, the ledger with its running balance, payments, charges,
 * adjustments, and statements printed as PDF (`party.statement`).
 */
export default function CreditAccountDetail() {
  const { accountId } = useParams()
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const key = ['loyalty', 'account', accountId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`loyalty/accounts/${accountId}`) })
  const [action, setAction] = useState(null)
  const [statementOpen, setStatementOpen] = useState(false)
  const close = useMutation({ mutationFn: () => api.post(`loyalty/accounts/${accountId}/close`), onSuccess: () => queryClient.invalidateQueries({ queryKey: key }) })
  const account = query.data?.data

  if (query.isError) return <Alert tone="danger" title={query.error.status === 404 ? t('loyalty.credit.notFound') : errorMessage(query.error)} />
  if (!account) return <p className="text-ink-muted">{t('common.loading')}</p>
  const currency = account.currency
  const zone = companyOf(account.company?.id)
  const open = account.status === 'open'

  return (
    <>
      <Link to="/loyalty/credit" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
        <Icon name="back" />
        {t('loyalty.credit.back')}
      </Link>
      <PageHeader
        title={account.party?.name ?? ''}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <StatusBadge tone={ACCOUNT_TONES[account.status]}>{t(`loyalty.credit.statuses.${account.status}`)}</StatusBadge>
            <span>{account.company?.name}</span>
          </span>
        }
        actions={
          <span className="flex flex-wrap gap-2">
            {open && can(ACTIONS.payments) ? <Button variant="primary" onClick={() => setAction('payments')}>{t('loyalty.credit.recordPayment')}</Button> : null}
            {open && can(ACTIONS.charges) ? <Button onClick={() => setAction('charges')}>{t('loyalty.credit.charge')}</Button> : null}
            {can(ACTIONS.adjustments) ? <Button onClick={() => setAction('adjustments')}>{t('loyalty.credit.adjust')}</Button> : null}
            <Button icon="print" onClick={() => setStatementOpen(true)}>{t('loyalty.statements.create')}</Button>
            {open && can('loyalty.credit.manage') ? <Button variant="danger" loading={close.isPending} onClick={() => close.mutate()}>{t('loyalty.credit.close')}</Button> : null}
          </span>
        }
      />
      {close.isError ? <Alert tone="danger" title={errorMessage(close.error)} /> : null}
      <div className="grid gap-4 md:grid-cols-3">
        <Card title={t('loyalty.credit.columns.balance')}>
          <BalanceOfLimit account={account} />
        </Card>
        <Card title={t('loyalty.credit.columns.available')}>
          {account.available ? <Money amount={account.available.amount_minor} currency={currency} /> : <span className="text-ink-muted">{t('loyalty.credit.noLimit')}</span>}
        </Card>
        <Card title={t('loyalty.credit.limitTitle')}>
          <p className="text-body text-ink-muted">{t('loyalty.credit.limitHelp')}</p>
          <Link to={`/contacts/customers/${account.party?.id}?tab=credit`} className="text-label text-primary hover:text-primary-hover">{t('loyalty.credit.changeLimit')}</Link>
        </Card>
      </div>
      <Card title={t('loyalty.ageing.title')} subtitle={t('loyalty.ageing.today')}>
        <div className="grid gap-4 md:grid-cols-4">
          {AGEING_BUCKETS.map((bucket) => (
            <KpiTile key={bucket} label={t(`loyalty.ageing.buckets.${bucket}`)} value={`${currency} ${formatAmount(account.ageing.buckets[bucket] ?? '0', currency, locale)}`} />
          ))}
        </div>
      </Card>
      <Card title={t('loyalty.credit.ledger')}>
        <DataTable
          caption={t('loyalty.credit.ledger')}
          rows={account.entries}
          emptyText={t('loyalty.credit.noEntries')}
          columns={[
            { key: 'date', label: t('loyalty.credit.entry.date'), render: (row) => <span className="tabular-nums">{formatCompanyTime(row.occurred_at, locale, zone)}</span> },
            { key: 'kind', label: t('loyalty.credit.entry.kind'), render: (row) => t(`loyalty.credit.kinds.${row.kind}`) },
            { key: 'reference', label: t('loyalty.credit.entry.reference'), render: (row) => [row.reference, row.note].filter(Boolean).join(' · ') },
            { key: 'amount', label: t('loyalty.credit.entry.amount'), render: (row) => <Money amount={row.amount.amount_minor} currency={row.amount.currency} /> },
            { key: 'balance', label: t('loyalty.credit.entry.balance'), render: (row) => (row.balance ? <Money amount={row.balance.amount_minor} currency={row.balance.currency} /> : null) },
            { key: 'flags', label: t('loyalty.credit.entry.flags'), render: (row) => (row.flags ?? []).map((flag) => <StatusBadge key={flag.code} tone="warning">{t(`loyalty.flags.${flag.code}`, { defaultValue: flag.code })}</StatusBadge>) },
          ]}
        />
      </Card>
      <Card title={t('loyalty.statements.title')}>
        <DataTable
          caption={t('loyalty.statements.title')}
          rows={account.statements}
          emptyText={t('loyalty.statements.empty')}
          columns={[
            { key: 'number', label: t('loyalty.statements.number'), render: (row) => <span className="font-medium">{row.number}</span> },
            { key: 'period', label: t('loyalty.statements.period'), render: (row) => `${row.period_from} – ${row.period_to}` },
            { key: 'closing', label: t('loyalty.statements.closing'), render: (row) => <Money amount={row.closing.amount_minor} currency={row.closing.currency} /> },
            { key: 'pdf', label: '', render: (row) => <StatementPdf statement={row} /> },
          ]}
        />
      </Card>
      {action ? <EntryDialog action={action} account={account} onClose={() => setAction(null)} onDone={() => { setAction(null); queryClient.invalidateQueries({ queryKey: key }) }} /> : null}
      {statementOpen ? <StatementDialog account={account} onClose={() => setStatementOpen(false)} onDone={() => { setStatementOpen(false); queryClient.invalidateQueries({ queryKey: key }) }} /> : null}
    </>
  )
}

function StatementPdf({ statement }) {
  const { t } = useTranslation()
  const [busy, setBusy] = useState(false)
  return (
    <Button variant="ghost" icon="download" loading={busy} onClick={async () => {
      setBusy(true)
      try {
        const { blob, filename } = await api.download(`loyalty/statements/${statement.id}/pdf`)
        saveFile(blob, filename ?? `statement-${statement.number}.pdf`)
      } finally {
        setBusy(false)
      }
    }}>
      {t('loyalty.statements.pdf')}
    </Button>
  )
}

/** A payment received, a charge or an adjustment (signed). */
function EntryDialog({ action, account, onClose, onDone }) {
  const { t } = useTranslation()
  const [amount, setAmount] = useState('')
  const [negative, setNegative] = useState(false)
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const save = useMutation({
    mutationFn: () => api.post(`loyalty/accounts/${account.id}/${action}`, action === 'adjustments'
      ? { amount_minor: `${negative ? '-' : ''}${amount}`, reason: note }
      : { amount_minor: amount, reference: reference || null, note: note || null }),
    onSuccess: onDone,
  })
  const errors = fieldErrors(save.error)
  return (
    <Dialog
      open
      title={t(`loyalty.credit.dialog.${action}`)}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('loyalty.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!amount} onClick={() => save.mutate()}>{t(`loyalty.credit.dialog.${action}Confirm`)}</Button>
        </>
      }
    >
      <MoneyInput label={t('loyalty.credit.dialog.amount')} currency={account.currency} value={amount} onChange={(value) => setAmount(value ?? '')} error={errors.amount_minor} />
      {action === 'adjustments' ? (
        <Checkbox label={t('loyalty.credit.dialog.reduce')} checked={negative} onChange={(event) => setNegative(event.target.checked)} />
      ) : (
        <TextField label={t('loyalty.credit.dialog.reference')} value={reference} onChange={(event) => setReference(event.target.value)} error={errors.reference} />
      )}
      <TextField label={action === 'adjustments' ? t('loyalty.credit.dialog.reason') : t('loyalty.credit.dialog.note')} value={note} onChange={(event) => setNote(event.target.value)} error={errors.reason ?? errors.note} />
      {save.isError && !errors.amount_minor && !errors.reason ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
    </Dialog>
  )
}

/** LOY-17: a statement for a period, then its PDF. */
function StatementDialog({ account, onClose, onDone }) {
  const { t } = useTranslation()
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10))
  const [from, setFrom] = useState(() => `${new Date().toISOString().slice(0, 8)}01`)
  const create = useMutation({ mutationFn: () => api.post(`loyalty/accounts/${account.id}/statements`, { from, to }), onSuccess: onDone })
  const errors = fieldErrors(create.error)
  return (
    <Dialog
      open
      title={t('loyalty.statements.create')}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('loyalty.cancel')}</Button>
          <Button variant="primary" loading={create.isPending} onClick={() => create.mutate()}>{t('loyalty.statements.createConfirm')}</Button>
        </>
      }
    >
      <TextField type="date" label={t('loyalty.statements.from')} value={from} onChange={(event) => setFrom(event.target.value)} error={errors.from} />
      <TextField type="date" label={t('loyalty.statements.to')} value={to} onChange={(event) => setTo(event.target.value)} error={errors.to} />
      {create.isError && !errors.from && !errors.to ? <Alert tone="danger" title={errorMessage(create.error)} /> : null}
    </Dialog>
  )
}
