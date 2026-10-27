import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { lotColumns } from './lotColumns'
import { locationFilter, pharmacyKey, usePlaces } from './pharmacyData'

const WINDOWS = ['', '30', '60', '90', '180']
/**
 * PHA-10, PHA-24: the expiry report: lots with stock that expired or expire
 * within a window (each company's own near-expiry window by default),
 * earliest first; sort and export as any list.
 */
export default function ExpiryReport() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const places = usePlaces()
  const columns = lotColumns(t)
  const list = useServerList({
    id: 'pharmacy-expiry',
    endpoint: 'pharmacy/reports/expiry',
    queryKey: [...pharmacyKey, 'report', 'expiry'],
    filters: { days: '', location: '' },
    defaultSort: 'expires_on',
    columns,
  })
  return (
    <>
      <PageHeader title={t('pharmacy.reports.expiry.title')} description={t('pharmacy.reports.expiry.description')} />
      <ListView
        list={list}
        title={t('pharmacy.reports.expiry.title')}
        searchLabel={t('pharmacy.lots.search')}
        filterFields={[
          { name: 'days', label: t('pharmacy.reports.expiry.window'), options: WINDOWS.map((days) => ({ value: days, label: days ? t('pharmacy.reports.expiry.within', { count: Number(days) }) : t('pharmacy.reports.expiry.companyWindow') })) },
          ...locationFilter(t, places),
        ]}
        emptyText={t('pharmacy.reports.expiry.empty')}
        onRowClick={(row) => navigate(`/pharmacy/lots/${row.lot_id}`)}
      />
    </>
  )
}
