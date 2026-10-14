import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, DocumentStatus } from './InventoryParts'
import { inventoryKey, placeFilterFields, TRANSFER_CREATE, TRANSFER_STATUSES, usePlaces } from './inventoryData'
import { dateField, statusField } from './inventoryHelpers'

/**
 * INV-08, INV-19: transfers from or to the places the user sees, newest
 * first, with search by number, status, place and date filters, sort and
 * export. A row opens the transfer; "New transfer" for
 * inventory.transfer.create.
 */
export default function Transfers() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const places = usePlaces()

  const columns = [
    { key: 'number', label: t('inventory.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'created_at', label: t('inventory.columns.createdAt'), sortKey: 'created_at', render: (row) => <span className="tabular-nums">{formatCompanyTime(row.created_at, locale, companyOf(row.company?.id))}</span> },
    { key: 'status', label: t('inventory.columns.status'), sortKey: 'status', render: (row) => <DocumentStatus status={row.status} /> },
    { key: 'from_location', label: t('inventory.columns.from'), render: (row) => row.from_location?.name ?? '' },
    { key: 'to_location', label: t('inventory.columns.to'), render: (row) => row.to_location?.name ?? '' },
    { key: 'lines_count', label: t('inventory.columns.lines'), align: 'end', numeric: true, render: (row) => row.lines_count },
    { key: 'requested_by', label: t('inventory.columns.requestedBy'), render: (row) => row.requested_by?.name ?? '' },
    { key: 'value', label: t('inventory.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
  ]

  const list = useServerList({
    id: 'inventory-transfers',
    endpoint: 'inventory/transfers',
    queryKey: [...inventoryKey, 'transfers'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: '-created_at',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('inventory.transfers.title')}
        description={t('inventory.transfers.description')}
        actions={
          can(TRANSFER_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/inventory/transfers/new')}>
              {t('inventory.transfers.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('inventory.transfers.title')}
        searchLabel={t('inventory.transfers.search')}
        filterFields={[statusField(t, TRANSFER_STATUSES), ...placeFilterFields(t, places, ['location']), dateField('from', t('inventory.filters.from')), dateField('to', t('inventory.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('inventory.transfers.emptyFiltered') : t('inventory.transfers.empty')}
        onRowClick={(row) => navigate(`/inventory/transfers/${row.id}`)}
      />
    </>
  )
}
