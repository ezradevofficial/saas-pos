import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { ListView, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, FlagChips, TendersText } from './PosParts'
import { dateField } from './posFields'
import { FLAG_CODES, flagLabel, placeFilterFields, SALE_STATUSES, SALE_TONES, usePlaces } from './posData'

/**
 * POS-12: sales from every till the user reaches (RBAC-04), newest first,
 * with search by receipt number, filters (place, status, flags, review,
 * dates), sort, columns and export (EXP-01). Times are in each sale's
 * company zone (L10N-03). A row opens the sale.
 */
export default function Sales() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const companyOf = useCompanyOfRecord()
  const places = usePlaces()

  const columns = [
    { key: 'receipt_number', label: t('pos.sales.columns.receipt'), sortKey: 'receipt_number', hideable: false, render: (sale) => <span className="font-medium text-ink tabular-nums">{sale.receipt_number}</span> },
    { key: 'sold_at', label: t('pos.sales.columns.soldAt'), sortKey: 'sold_at', render: (sale) => <span className="tabular-nums">{formatCompanyTime(sale.sold_at, locale, companyOf(sale.company?.id))}</span> },
    {
      key: 'location',
      label: t('pos.sales.columns.place'),
      render: (sale) => (
        <span className="flex flex-col">
          <span>{sale.location?.name}</span>
          <span className="text-caption text-ink-muted">{sale.device?.name}</span>
        </span>
      ),
    },
    { key: 'cashier', label: t('pos.sales.columns.cashier'), render: (sale) => sale.cashier?.name ?? '' },
    { key: 'customer', label: t('pos.sales.columns.customer'), defaultHidden: true, render: (sale) => sale.customer?.name ?? '' },
    { key: 'total', label: t('pos.sales.columns.total'), sortKey: 'total', align: 'end', render: (sale) => <Amount value={sale.total} /> },
    { key: 'tenders', label: t('pos.sales.columns.payments'), exportKey: null, render: (sale) => <TendersText tenders={sale.tenders} /> },
    { key: 'status', label: t('pos.sales.columns.status'), sortKey: 'status', render: (sale) => <StatusBadge tone={SALE_TONES[sale.status]}>{t(`pos.sales.status.${sale.status}`)}</StatusBadge> },
    {
      key: 'flags',
      label: t('pos.sales.columns.flags'),
      wrap: true,
      render: (sale) =>
        sale.flags?.length ? (
          <span className="flex flex-col gap-1">
            <FlagChips flags={sale.flags} />
            {sale.reviewed_at ? <span className="text-caption text-ink-muted">{t('pos.sales.reviewed')}</span> : null}
          </span>
        ) : null,
    },
  ]

  const list = useServerList({
    id: 'pos-sales',
    endpoint: 'pos/sales',
    queryKey: ['pos-sales'],
    filters: { company: '', branch: '', location: '', status: '', flagged: '', flag: '', reviewed: '', from: '', to: '' },
    defaultSort: '-sold_at',
    columns,
  })

  const yesNo = (name, yes, no, all) => ({
    name,
    label: t(`pos.sales.filters.${name}`),
    options: [
      { value: '', label: t(all) },
      { value: '1', label: t(yes) },
      { value: '0', label: t(no) },
    ],
  })

  return (
    <>
      <PageHeader title={t('pos.sales.title')} description={t('pos.sales.description')} />
      <ListView
        list={list}
        title={t('pos.sales.title')}
        searchLabel={t('pos.sales.search')}
        filterFields={[
          ...placeFilterFields(t, places),
          {
            name: 'status',
            label: t('pos.sales.filters.status'),
            options: [{ value: '', label: t('pos.sales.filters.allStatuses') }, ...SALE_STATUSES.map((status) => ({ value: status, label: t(`pos.sales.status.${status}`) }))],
          },
          yesNo('flagged', 'pos.sales.filters.flaggedYes', 'pos.sales.filters.flaggedNo', 'pos.sales.filters.flaggedAll'),
          {
            name: 'flag',
            label: t('pos.sales.filters.flag'),
            options: [{ value: '', label: t('pos.sales.filters.allFlags') }, ...FLAG_CODES.map((code) => ({ value: code, label: flagLabel(t, code) }))],
          },
          yesNo('reviewed', 'pos.sales.filters.reviewedYes', 'pos.sales.filters.reviewedNo', 'pos.sales.filters.reviewedAll'),
          dateField('from', t('pos.sales.filters.from')),
          dateField('to', t('pos.sales.filters.to')),
        ]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('pos.sales.emptyFiltered') : t('pos.sales.empty')}
        onRowClick={(sale) => navigate(`/pos/sales/${sale.id}`)}
      />
    </>
  )
}
