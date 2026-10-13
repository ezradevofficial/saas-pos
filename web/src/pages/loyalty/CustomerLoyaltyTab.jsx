import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { BalanceOfLimit, OpenAccountDialog } from './CreditAccounts'
import { ACCOUNT_TONES, fieldErrors, useLoyaltyCompany } from './loyaltyData'

/**
 * LOY-02, LOY-07, LOY-14 on the customer page: points and credit per
 * company, the latest entries, a manual points adjustment and opening a
 * credit account (the limit stays the core party's, changed on the Credit
 * limit tab through its flow).
 */
export function CustomerLoyaltyTab({ party }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const companyOf = useCompanyOfRecord()
  const { can } = usePermissions()
  const company = useLoyaltyCompany()
  const key = ['loyalty', 'customer', party.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`loyalty/customers/${party.id}`) })
  const [opening, setOpening] = useState(false)
  const [adjusting, setAdjusting] = useState(false)
  const data = query.data?.data

  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!data) return <p className="text-ink-muted">{t('common.loading')}</p>
  const points = data.accounts.filter((account) => account.kind === 'points')
  const credit = data.accounts.filter((account) => account.kind === 'credit')
  const entryColumns = (zone) => [
    { key: 'date', label: t('loyalty.credit.entry.date'), render: (row) => <span className="tabular-nums">{formatCompanyTime(row.occurred_at, locale, zone)}</span> },
    { key: 'kind', label: t('loyalty.credit.entry.kind'), render: (row) => t(row.points != null ? `loyalty.points.kinds.${row.kind}` : `loyalty.credit.kinds.${row.kind}`) },
    { key: 'amount', label: t('loyalty.credit.entry.amount'), render: (row) => (row.points != null ? <span className="tabular-nums">{row.points}</span> : <Money amount={row.amount.amount_minor} currency={row.amount.currency} />) },
  ]

  return (
    <div className="flex flex-col gap-4">
      {data.can.points ? (
        <Card
          title={t('loyalty.customer.points')}
          actions={can('loyalty.points.adjust') ? <Button onClick={() => setAdjusting(true)}>{t('loyalty.customer.adjust')}</Button> : null}
        >
          {points.length ? points.map((account) => (
            <div key={account.id} className="flex flex-col gap-3">
              <p className="flex flex-wrap items-baseline gap-3">
                <span className="text-h2 tabular-nums text-ink">{t('loyalty.customer.pointsValue', { points: account.points })}</span>
                <span className="text-caption text-ink-muted">{account.company?.name}</span>
              </p>
              <DataTable caption={t('loyalty.customer.points')} rows={account.entries} columns={entryColumns(companyOf(account.company?.id))} emptyText={t('loyalty.customer.noEntries')} />
            </div>
          )) : <p className="text-body text-ink-muted">{t('loyalty.customer.noPoints')}</p>}
        </Card>
      ) : null}
      {data.can.credit ? (
        <Card
          title={t('loyalty.customer.credit')}
          actions={can('loyalty.credit.manage') && !credit.some((account) => account.status === 'open') ? <Button onClick={() => setOpening(true)}>{t('loyalty.credit.open')}</Button> : null}
        >
          {credit.length ? credit.map((account) => (
            <div key={account.id} className="flex flex-col gap-3">
              <p className="flex flex-wrap items-center gap-3">
                <BalanceOfLimit account={account} />
                <StatusBadge tone={ACCOUNT_TONES[account.status]}>{t(`loyalty.credit.statuses.${account.status}`)}</StatusBadge>
                <Link to={`/loyalty/credit/${account.id}`} className="text-label text-primary hover:text-primary-hover">{t('loyalty.customer.openAccount')}</Link>
              </p>
              <DataTable caption={t('loyalty.customer.credit')} rows={account.entries} columns={entryColumns(companyOf(account.company?.id))} emptyText={t('loyalty.customer.noEntries')} />
            </div>
          )) : <p className="text-body text-ink-muted">{t('loyalty.customer.noCredit')}</p>}
        </Card>
      ) : null}
      {opening ? <OpenAccountDialog partyId={party.id} onClose={() => setOpening(false)} onOpened={() => { setOpening(false); queryClient.invalidateQueries({ queryKey: key }) }} /> : null}
      {adjusting ? <AdjustPoints party={party} companyId={company?.id} onClose={() => setAdjusting(false)} onDone={() => { setAdjusting(false); queryClient.invalidateQueries({ queryKey: key }) }} /> : null}
    </div>
  )
}

function AdjustPoints({ party, companyId, onClose, onDone }) {
  const { t } = useTranslation()
  const [points, setPoints] = useState('')
  const [direction, setDirection] = useState('add')
  const [reason, setReason] = useState('')
  const save = useMutation({ mutationFn: () => api.post(`loyalty/customers/${party.id}/points/adjust`, { company_id: companyId, points: `${direction === 'remove' ? '-' : ''}${points}`, reason }), onSuccess: onDone })
  const errors = fieldErrors(save.error)
  return (
    <Dialog
      open
      title={t('loyalty.customer.adjust')}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('loyalty.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!/^\d+$/.test(points) || !reason.trim()} onClick={() => save.mutate()}>{t('loyalty.customer.adjustConfirm')}</Button>
        </>
      }
    >
      <Select label={t('loyalty.customer.direction')} value={direction} onChange={setDirection} options={[{ value: 'add', label: t('loyalty.customer.add') }, { value: 'remove', label: t('loyalty.customer.remove') }]} />
      <TextField label={t('loyalty.customer.pointsField')} inputMode="numeric" value={points} onChange={(event) => setPoints(event.target.value)} error={errors.points} />
      <TextField label={t('loyalty.credit.dialog.reason')} value={reason} onChange={(event) => setReason(event.target.value)} error={errors.reason} />
      {save.isError && !errors.points && !errors.reason ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
    </Dialog>
  )
}
