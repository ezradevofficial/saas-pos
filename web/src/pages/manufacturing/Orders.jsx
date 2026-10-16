import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { placeFilterFields, usePlaces } from '@/pages/inventory/inventoryData'
import { CalendarDate, InventoryOffNote, MfgStatus, Qty } from './ManufacturingParts'
import { manufacturingKey, ORDER_CREATE, ORDER_STATUSES, statusFilter } from './manufacturingData'

/**
 * MFG-10 to MFG-14, MFG-27: production orders at the places the user
 * sees, newest first: number, status, product, planned quantity, good and
 * scrap, planned dates and location; status, location and date filters,
 * sort and export; Plan order for those who may.
 */
export default function Orders() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const places = usePlaces()

  const columns = [
    { key: 'number', label: t('manufacturing.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'status', label: t('manufacturing.columns.status'), sortKey: 'status', render: (row) => <MfgStatus status={row.status} /> },
    { key: 'code', label: t('manufacturing.columns.code'), defaultHidden: true, render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('manufacturing.columns.product'), render: (row) => <span className="text-ink">{row.item?.name}</span> },
    { key: 'qty', label: t('manufacturing.columns.planned'), align: 'end', render: (row) => <Qty value={row.qty} unit={row.uom?.code} /> },
    { key: 'qty_good', label: t('manufacturing.columns.good'), align: 'end', render: (row) => <Qty value={row.qty_good} /> },
    { key: 'qty_scrap', label: t('manufacturing.columns.scrap'), align: 'end', render: (row) => <Qty value={row.qty_scrap} /> },
    { key: 'planned_start', label: t('manufacturing.columns.plannedStart'), sortKey: 'planned_start', render: (row) => <CalendarDate value={row.planned_start} /> },
    { key: 'planned_end', label: t('manufacturing.columns.plannedEnd'), render: (row) => <CalendarDate value={row.planned_end} /> },
    { key: 'location', label: t('manufacturing.columns.location'), render: (row) => row.location?.name ?? '' },
  ]

  const list = useServerList({
    id: 'manufacturing-orders',
    endpoint: 'manufacturing/orders',
    queryKey: [...manufacturingKey, 'orders'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: '-created_at',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('manufacturing.orders.title')}
        description={t('manufacturing.orders.description')}
        actions={
          can(ORDER_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/manufacturing/orders/new')}>
              {t('manufacturing.orders.new')}
            </Button>
          ) : null
        }
      />
      <InventoryOffNote />
      <ListView
        list={list}
        title={t('manufacturing.orders.title')}
        searchLabel={t('manufacturing.orders.search')}
        filterFields={[statusFilter(t, ORDER_STATUSES), ...placeFilterFields(t, places, ['location']), dateField('from', t('manufacturing.filters.from')), dateField('to', t('manufacturing.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('manufacturing.orders.emptyFiltered') : t('manufacturing.orders.empty')}
        onRowClick={(row) => navigate(`/manufacturing/orders/${row.id}`)}
      />
    </>
  )
}
