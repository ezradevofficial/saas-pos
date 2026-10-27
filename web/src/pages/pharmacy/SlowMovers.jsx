import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { lotColumns } from './lotColumns'
import { locationFilter, pharmacyKey, usePlaces } from './pharmacyData'

const IDLE = ['30', '60', '90', '180', '365']

/** PHA-24: slow movers per batch: lots with stock that did not sell at their place for a number of days. */
export default function SlowMovers() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const places = usePlaces()
  const columns = lotColumns(t, 'slow')
  const list = useServerList({
    id: 'pharmacy-slow-movers',
    endpoint: 'pharmacy/reports/slow-movers',
    queryKey: [...pharmacyKey, 'report', 'slow'],
    filters: { days: '90', location: '' },
    defaultSort: 'last_sold_at',
    columns,
  })
  return (
    <>
      <PageHeader title={t('pharmacy.reports.slow.title')} description={t('pharmacy.reports.slow.description')} />
      <ListView
        list={list}
        title={t('pharmacy.reports.slow.title')}
        searchLabel={t('pharmacy.lots.search')}
        filterFields={[
          { name: 'days', label: t('pharmacy.reports.slow.idle'), options: IDLE.map((days) => ({ value: days, label: t('pharmacy.reports.slow.noSale', { count: Number(days) }) })) },
          ...locationFilter(t, places),
        ]}
        emptyText={t('pharmacy.reports.slow.empty')}
        onRowClick={(row) => navigate(`/pharmacy/lots/${row.lot_id}`)}
      />
    </>
  )
}
