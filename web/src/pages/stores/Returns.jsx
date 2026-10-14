import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { Amount } from './StoresParts'
import { storeFilter, storesKey, useStorePlaces } from './storesData'

/** STR-06, STR-18: returns to the stores the user sees, newest first, with their reason and value (value holders only). */
export default function Returns() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const companyOf = useCompanyOfRecord()
  const places = useStorePlaces()

  const columns = [
    { key: 'number', label: t('stores.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'returned_at', label: t('stores.columns.returnedAt'), sortKey: 'returned_at', render: (row) => <span className="tabular-nums">{formatCompanyTime(row.returned_at, locale, companyOf(row.company?.id))}</span> },
    { key: 'issue', label: t('stores.columns.issue'), render: (row) => <span className="tabular-nums">{row.issue?.number ?? ''}</span> },
    { key: 'reason', label: t('stores.columns.reason'), render: (row) => t(`stores.returnReasons.${row.reason}`, { defaultValue: row.reason }) },
    { key: 'location', label: t('stores.columns.store'), render: (row) => row.location?.name ?? '' },
    { key: 'department', label: t('stores.columns.department'), render: (row) => row.department?.name ?? '' },
    { key: 'returned_by', label: t('stores.columns.returnedBy'), render: (row) => row.returned_by?.name ?? '' },
    { key: 'value', label: t('stores.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
  ]

  const list = useServerList({
    id: 'stores-returns',
    endpoint: 'stores/returns',
    queryKey: [...storesKey, 'returns'],
    filters: { location: '', from: '', to: '' },
    defaultSort: '-returned_at',
    columns,
  })

  return (
    <>
      <PageHeader title={t('stores.returns.title')} description={t('stores.returns.description')} />
      <ListView
        list={list}
        title={t('stores.returns.title')}
        searchLabel={t('stores.returns.search')}
        filterFields={[...storeFilter(t, places.stores), dateField('from', t('stores.filters.from')), dateField('to', t('stores.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('stores.returns.emptyFiltered') : t('stores.returns.empty')}
        onRowClick={(row) => navigate(`/stores/returns/${row.id}`)}
      />
    </>
  )
}
