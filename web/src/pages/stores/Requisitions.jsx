import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { DocumentStatus } from './StoresParts'
import { REQUISITION_CREATE, REQUISITION_STATUSES, statusFilter, storeFilter, storesKey, useStorePlaces } from './storesData'

/**
 * STR-02 to STR-04, STR-18: requisitions at the stores the user sees,
 * newest first, with search (number or note), status, store and date
 * filters, sort and export; New requisition for those who may ask.
 */
export default function Requisitions() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const places = useStorePlaces()

  const columns = [
    { key: 'number', label: t('stores.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'created_at', label: t('stores.columns.createdAt'), sortKey: 'created_at', render: (row) => <span className="tabular-nums">{formatCompanyTime(row.created_at, locale, companyOf(row.company?.id))}</span> },
    { key: 'status', label: t('stores.columns.status'), sortKey: 'status', render: (row) => <DocumentStatus status={row.status} /> },
    { key: 'location', label: t('stores.columns.store'), render: (row) => row.location?.name ?? '' },
    { key: 'department', label: t('stores.columns.department'), render: (row) => row.department?.name ?? '' },
    { key: 'cost_centre', label: t('stores.columns.costCentre'), defaultHidden: true, render: (row) => row.cost_centre?.name ?? '' },
    { key: 'needed_by', label: t('stores.columns.neededBy'), sortKey: 'needed_by', render: (row) => <span className="tabular-nums">{row.needed_by ?? ''}</span> },
    { key: 'requested_by', label: t('stores.columns.requestedBy'), render: (row) => row.requested_by?.name ?? '' },
    { key: 'lines_count', label: t('stores.columns.lines'), align: 'end', render: (row) => <span className="tabular-nums">{row.lines_count}</span> },
  ]

  const list = useServerList({
    id: 'stores-requisitions',
    endpoint: 'stores/requisitions',
    queryKey: [...storesKey, 'requisitions'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: '-created_at',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('stores.requisitions.title')}
        description={t('stores.requisitions.description')}
        actions={
          can(REQUISITION_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/stores/requisitions/new')}>
              {t('stores.requisitions.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('stores.requisitions.title')}
        searchLabel={t('stores.requisitions.search')}
        filterFields={[statusFilter(t, REQUISITION_STATUSES), ...storeFilter(t, places.stores), dateField('from', t('stores.filters.from')), dateField('to', t('stores.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('stores.requisitions.emptyFiltered') : t('stores.requisitions.empty')}
        onRowClick={(row) => navigate(`/stores/requisitions/${row.id}`)}
      />
    </>
  )
}
