import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Card, DataTable, Icon, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, Detail, FlagChips } from './PosParts'
import { RECORD_TONES, SHIFT_TONES } from './posData'
import { Variance } from './Shifts'

function Back() {
  const { t } = useTranslation()
  return (
    <Link to="/pos/shifts" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
      <Icon name="back" />
      {t('pos.shifts.back')}
    </Link>
  )
}

/**
 * POS-04: one shift: who opened and closed it, the drawer per currency
 * (opening float, expected, counted, variance), its sales count and cash
 * movements, and a note when records reached the server after the close
 * (the expected cash was recounted, H4).
 */
export default function ShiftDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { shiftId } = useParams()
  const companyOf = useCompanyOfRecord()
  const query = useQuery({ queryKey: ['pos-shifts', 'detail', shiftId], queryFn: () => api.get(`pos/shifts/${shiftId}`) })
  const shift = query.data?.data

  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) {
    return (
      <>
        <Back />
        <Alert tone="danger" title={query.error.status === 404 ? t('pos.shift.notFound') : errorMessage(query.error)} />
      </>
    )
  }

  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(shift.company?.id)) : '')
  const closed = shift.status === 'closed'

  const balanceColumns = [
    { key: 'currency', label: t('pos.shift.balances.currency'), render: (balance) => <span className="font-medium text-ink">{balance.currency}</span> },
    { key: 'opening', label: t('pos.shift.balances.opening'), align: 'end', render: (balance) => <Amount value={balance.opening} /> },
    { key: 'expected', label: t('pos.shift.balances.expected'), align: 'end', render: (balance) => <Amount value={balance.expected} /> },
    { key: 'counted', label: t('pos.shift.balances.counted'), align: 'end', render: (balance) => <Amount value={balance.counted} /> },
    { key: 'variance', label: t('pos.shift.balances.variance'), render: (balance) => (closed ? <Variance value={balance.variance} /> : <span className="text-ink-muted">{t('pos.shifts.stillOpen')}</span>) },
  ]

  const movementColumns = [
    { key: 'occurred_at', label: t('pos.shift.movements.time'), render: (movement) => <span className="tabular-nums">{when(movement.occurred_at)}</span> },
    { key: 'kind', label: t('pos.shift.movements.kind'), render: (movement) => t(`pos.shift.movements.kinds.${movement.kind}`) },
    { key: 'amount', label: t('pos.shift.movements.amount'), align: 'end', render: (movement) => <Amount value={movement.amount} /> },
    {
      key: 'reason',
      label: t('pos.shift.movements.reason'),
      wrap: true,
      render: (movement) => (
        <span className="flex flex-col gap-1">
          <span>{movement.reason}</span>
          <span className="text-caption text-ink-muted">{[movement.user?.name, movement.approver ? t('pos.shift.movements.approvedBy', { name: movement.approver.name }) : null].filter(Boolean).join(' · ')}</span>
          <FlagChips flags={movement.flags} />
        </span>
      ),
    },
    { key: 'status', label: t('pos.shift.movements.status'), render: (movement) => <StatusBadge tone={RECORD_TONES[movement.status]}>{t(`pos.records.${movement.status}`)}</StatusBadge> },
  ]

  return (
    <>
      <Back />
      <PageHeader
        eyebrow={t('pos.shift.eyebrow')}
        title={t('pos.shift.title', { device: shift.device?.name ?? '', date: when(shift.opened_at) })}
        description={<StatusBadge tone={SHIFT_TONES[shift.status]}>{t(`pos.shifts.status.${shift.status}`)}</StatusBadge>}
      />
      {shift.received_after_close > 0 ? (
        <Alert tone="info" title={t('pos.shift.receivedAfterClose', { count: shift.received_after_close, formatted: formatInteger(shift.received_after_close, locale) })}>
          {t('pos.shift.receivedAfterCloseHelp')}
        </Alert>
      ) : null}
      <div className="flex flex-col gap-5">
        <Card title={t('pos.shift.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('pos.shift.place')}>
              <span className="flex flex-col">
                <span>{shift.location?.name}</span>
                <span className="text-caption text-ink-muted">{[shift.company?.name, shift.branch?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            <Detail label={t('pos.shift.openedBy')}>
              <span className="flex flex-col">
                <span>{shift.opened_by?.name}</span>
                <span className="text-caption text-ink-muted tabular-nums">{when(shift.opened_at)}</span>
              </span>
            </Detail>
            <Detail label={t('pos.shift.closedBy')}>
              {closed ? (
                <span className="flex flex-col">
                  <span>{shift.closed_by?.name}</span>
                  <span className="text-caption text-ink-muted tabular-nums">{when(shift.closed_at)}</span>
                </span>
              ) : (
                <span className="text-ink-muted">{t('pos.shifts.stillOpen')}</span>
              )}
            </Detail>
            <Detail label={t('pos.shift.salesCount')}>
              <span className="tabular-nums">{formatInteger(shift.sales_count ?? 0, locale)}</span>
            </Detail>
            {shift.note ? <Detail label={t('pos.shift.note')}>{shift.note}</Detail> : null}
          </dl>
        </Card>
        <Card title={t('pos.shift.balances.title')} subtitle={t('pos.shift.balances.help')}>
          <DataTable caption={t('pos.shift.balances.title')} columns={balanceColumns} rows={(shift.balances ?? []).map((balance) => ({ ...balance, id: balance.currency }))} />
        </Card>
        <Card title={t('pos.shift.movements.title')}>
          <DataTable caption={t('pos.shift.movements.title')} columns={movementColumns} rows={shift.cash_movements ?? []} emptyText={t('pos.shift.movements.empty')} />
        </Card>
      </div>
    </>
  )
}
