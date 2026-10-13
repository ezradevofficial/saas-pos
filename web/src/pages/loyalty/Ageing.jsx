import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { ListView, Money } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { dateField } from '@/pages/pos/posFields'
import { AGEING_BUCKETS } from './loyaltyData'

/**
 * LOY-18: what customers owe by age of the sale on account (0–30, 31–60,
 * 61–90, 90+ days) at a date; payments settle the oldest charges first.
 * Exportable (EXP-01).
 */
export default function Ageing() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const amount = (row, minor) => <Money amount={minor ?? '0'} currency={row.currency} />

  const columns = [
    { key: 'customer', label: t('loyalty.credit.columns.customer'), hideable: false, render: (row) => <span className="font-medium text-ink">{row.party?.name}</span> },
    ...AGEING_BUCKETS.map((bucket) => ({ key: `ageing_${bucket}`, label: t(`loyalty.ageing.buckets.${bucket}`), render: (row) => amount(row, row.ageing?.buckets?.[bucket]) })),
    { key: 'open', label: t('loyalty.ageing.open'), render: (row) => amount(row, row.ageing?.open_minor) },
    { key: 'limit', label: t('loyalty.credit.columns.limit'), defaultHidden: true, render: (row) => (row.credit_limit ? <Money amount={row.credit_limit.amount_minor} currency={row.credit_limit.currency} /> : '') },
  ]
  const list = useServerList({ id: 'loyalty-ageing', endpoint: 'loyalty/ageing', queryKey: ['loyalty-ageing'], filters: { as_of: '' }, defaultSort: '-balance', columns })

  return (
    <>
      <PageHeader title={t('loyalty.ageing.title')} description={t('loyalty.ageing.description')} />
      <ListView
        list={list}
        title={t('loyalty.ageing.title')}
        searchLabel={t('loyalty.credit.search')}
        filterFields={[dateField('as_of', t('loyalty.ageing.asOf'))]}
        emptyText={t('loyalty.ageing.empty')}
        onRowClick={(row) => navigate(`/loyalty/credit/${row.id}`)}
      />
    </>
  )
}
