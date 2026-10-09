import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { ListView, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { toMinor } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { useAmountText } from './useAmountText'
import { placeFilterFields, SHIFT_STATUSES, SHIFT_TONES, usePlaces } from './posData'
import { dateField } from './posFields'

/** POS-04: a counted drawer's variance as a dot and a word: balanced, over or short (never colour alone). */
export function Variance({ value }) {
  const { t } = useTranslation()
  const amount = useAmountText()
  if (!value) return <span className="text-ink-muted">{t('pos.shifts.notCounted')}</span>
  const minor = toMinor(value.amount_minor)
  if (minor === 0n) return <StatusBadge tone="success">{t('pos.shifts.balanced', { currency: value.currency })}</StatusBadge>
  const absolute = { ...value, amount_minor: String(minor < 0n ? -minor : minor) }
  return minor > 0n ? (
    <StatusBadge tone="warning">{t('pos.shifts.over', { amount: amount(absolute) })}</StatusBadge>
  ) : (
    <StatusBadge tone="danger">{t('pos.shifts.short', { amount: amount(absolute) })}</StatusBadge>
  )
}

/** Each currency's amount of `key` (opening, expected, counted) in one line. */
function BalancesText({ balances, field }) {
  const amount = useAmountText()
  const values = (balances ?? []).map((balance) => balance[field]).filter(Boolean)
  if (!values.length) return <span className="text-ink-muted">—</span>
  return <span className="tabular-nums">{values.map(amount).join(' · ')}</span>
}

/**
 * POS-04, POS-12: shifts from every till the user reaches (RBAC-04),
 * newest first, with the drawer per currency: opening float, expected,
 * counted and variance. Search finds a till or the cashier who opened it.
 */
export default function Shifts() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const companyOf = useCompanyOfRecord()
  const places = usePlaces()
  const when = (shift, value) => (value ? formatCompanyTime(value, locale, companyOf(shift.company?.id)) : '')

  const columns = [
    { key: 'opened_at', label: t('pos.shifts.columns.opened'), sortKey: 'opened_at', hideable: false, render: (shift) => <span className="tabular-nums">{when(shift, shift.opened_at)}</span> },
    { key: 'closed_at', label: t('pos.shifts.columns.closed'), sortKey: 'closed_at', render: (shift) => <span className="tabular-nums">{when(shift, shift.closed_at)}</span> },
    { key: 'status', label: t('pos.shifts.columns.status'), sortKey: 'status', render: (shift) => <StatusBadge tone={SHIFT_TONES[shift.status]}>{t(`pos.shifts.status.${shift.status}`)}</StatusBadge> },
    {
      key: 'location',
      label: t('pos.shifts.columns.place'),
      render: (shift) => (
        <span className="flex flex-col">
          <span>{shift.location?.name}</span>
          <span className="text-caption text-ink-muted">{shift.device?.name}</span>
        </span>
      ),
    },
    { key: 'opened_by', label: t('pos.shifts.columns.openedBy'), render: (shift) => shift.opened_by?.name ?? '' },
    { key: 'opening', label: t('pos.shifts.columns.opening'), render: (shift) => <BalancesText balances={shift.balances} field="opening" /> },
    { key: 'expected', label: t('pos.shifts.columns.expected'), defaultHidden: true, render: (shift) => <BalancesText balances={shift.balances} field="expected" /> },
    { key: 'counted', label: t('pos.shifts.columns.counted'), defaultHidden: true, render: (shift) => <BalancesText balances={shift.balances} field="counted" /> },
    {
      key: 'variance',
      label: t('pos.shifts.columns.variance'),
      render: (shift) =>
        shift.status === 'closed' ? (
          <span className="flex flex-col gap-1">
            {(shift.balances ?? []).map((balance) => (
              <Variance key={balance.currency} value={balance.variance} />
            ))}
          </span>
        ) : (
          <span className="text-ink-muted">{t('pos.shifts.stillOpen')}</span>
        ),
    },
  ]

  const list = useServerList({
    id: 'pos-shifts',
    endpoint: 'pos/shifts',
    queryKey: ['pos-shifts'],
    filters: { company: '', branch: '', location: '', status: '', from: '', to: '' },
    defaultSort: '-opened_at',
    columns,
  })

  return (
    <>
      <PageHeader title={t('pos.shifts.title')} description={t('pos.shifts.description')} />
      <ListView
        list={list}
        title={t('pos.shifts.title')}
        searchLabel={t('pos.shifts.search')}
        filterFields={[
          ...placeFilterFields(t, places),
          {
            name: 'status',
            label: t('pos.shifts.filters.status'),
            options: [{ value: '', label: t('pos.shifts.filters.allStatuses') }, ...SHIFT_STATUSES.map((status) => ({ value: status, label: t(`pos.shifts.status.${status}`) }))],
          },
          dateField('from', t('pos.shifts.filters.from')),
          dateField('to', t('pos.shifts.filters.to')),
        ]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('pos.shifts.emptyFiltered') : t('pos.shifts.empty')}
        onRowClick={(shift) => navigate(`/pos/shifts/${shift.id}`)}
      />
    </>
  )
}
