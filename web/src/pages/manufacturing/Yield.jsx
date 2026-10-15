import { useTranslation } from 'react-i18next'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { placeFilterFields, usePlaces } from '@/pages/inventory/inventoryData'
import { MfgStatus, OrderLink, Qty, ValueCell } from './ManufacturingParts'
import { manufacturingKey, REPORT_STATUSES, statusFilter } from './manufacturingData'

/**
 * MFG-19: per order, planned, good and scrap quantities, yield and scrap
 * share and, for valued orders and cost holders, material, labour,
 * overhead, received and variance amounts; filters, sort and export.
 */
export default function Yield() {
  const { t } = useTranslation()
  const places = usePlaces()

  const columns = [
    { key: 'number', label: t('manufacturing.columns.number'), sortKey: 'number', hideable: false, render: (row) => <OrderLink order={row} /> },
    { key: 'status', label: t('manufacturing.columns.status'), render: (row) => <MfgStatus status={row.status} /> },
    { key: 'code', label: t('manufacturing.columns.code'), defaultHidden: true, render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('manufacturing.columns.product'), render: (row) => <span className="text-ink">{row.item?.name}</span> },
    { key: 'unit', label: t('manufacturing.columns.unit'), render: (row) => row.uom?.code ?? '' },
    { key: 'planned', label: t('manufacturing.columns.planned'), align: 'end', render: (row) => <Qty value={row.planned} /> },
    { key: 'good', label: t('manufacturing.columns.good'), align: 'end', render: (row) => <Qty value={row.good} /> },
    { key: 'scrap', label: t('manufacturing.columns.scrap'), align: 'end', render: (row) => <Qty value={row.scrap} /> },
    { key: 'yield_percent', label: t('manufacturing.columns.yieldPercent'), align: 'end', render: (row) => <Qty value={row.yield_percent} unit="%" className="font-medium" /> },
    { key: 'scrap_percent', label: t('manufacturing.columns.scrapPercent'), align: 'end', render: (row) => <Qty value={row.scrap_percent} unit="%" /> },
    { key: 'material_cost', label: t('manufacturing.costs.materials'), align: 'end', render: (row) => <ValueCell row={row} value={row.material_cost} /> },
    { key: 'labour_cost', label: t('manufacturing.costs.labour'), align: 'end', render: (row) => <ValueCell row={row} value={row.labour_cost} /> },
    { key: 'overhead_cost', label: t('manufacturing.costs.overhead'), align: 'end', render: (row) => <ValueCell row={row} value={row.overhead_cost} /> },
    { key: 'relieved', label: t('manufacturing.costs.received'), align: 'end', render: (row) => <ValueCell row={row} value={row.relieved} /> },
    { key: 'variance', label: t('manufacturing.costs.variance'), align: 'end', render: (row) => <ValueCell row={row} value={row.variance} /> },
  ]

  const list = useServerList({
    id: 'manufacturing-yield',
    endpoint: 'manufacturing/yield',
    queryKey: [...manufacturingKey, 'yield'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: '-released_at',
    columns,
  })

  return (
    <>
      <PageHeader title={t('manufacturing.yield.title')} description={t('manufacturing.yield.description')} />
      <ListView
        list={list}
        title={t('manufacturing.yield.title')}
        searchLabel={t('manufacturing.yield.search')}
        filterFields={[statusFilter(t, REPORT_STATUSES), ...placeFilterFields(t, places, ['location']), dateField('from', t('manufacturing.filters.from')), dateField('to', t('manufacturing.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('manufacturing.yield.emptyFiltered') : t('manufacturing.yield.empty')}
      />
    </>
  )
}
