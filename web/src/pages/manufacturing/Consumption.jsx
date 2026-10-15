import { useTranslation } from 'react-i18next'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { placeFilterFields, usePlaces } from '@/pages/inventory/inventoryData'
import { OrderLink, Qty, ValueCell } from './ManufacturingParts'
import { manufacturingKey, REPORT_STATUSES, statusFilter } from './manufacturingData'

/**
 * MFG-18: per order and material, the standard quantity for the good
 * output reported against the actual (issued − returned), the difference
 * and its share and, for valued orders and cost holders, the values;
 * status, location and date filters, sort and export.
 */
export default function Consumption() {
  const { t } = useTranslation()
  const places = usePlaces()

  const columns = [
    { key: 'order', label: t('manufacturing.columns.order'), sortKey: 'order', hideable: false, render: (row) => <OrderLink order={row.order} /> },
    { key: 'code', label: t('manufacturing.columns.code'), render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('manufacturing.columns.item'), render: (row) => <span className="text-ink">{row.item?.name}</span> },
    { key: 'unit', label: t('manufacturing.columns.unit'), render: (row) => row.uom?.code ?? '' },
    { key: 'required', label: t('manufacturing.columns.required'), align: 'end', render: (row) => <Qty value={row.required} /> },
    { key: 'standard', label: t('manufacturing.columns.standard'), align: 'end', render: (row) => <Qty value={row.standard} /> },
    { key: 'expected_loss', label: t('manufacturing.columns.expectedWastage'), align: 'end', render: (row) => <Qty value={row.expected_loss} /> },
    { key: 'actual', label: t('manufacturing.columns.actual'), align: 'end', render: (row) => <Qty value={row.actual} className="font-medium" /> },
    { key: 'variance_qty', label: t('manufacturing.columns.difference'), align: 'end', render: (row) => <Qty value={row.variance_qty} /> },
    { key: 'variance_percent', label: t('manufacturing.columns.differencePercent'), align: 'end', render: (row) => <Qty value={row.variance_percent} unit="%" /> },
    { key: 'actual_value', label: t('manufacturing.columns.actualValue'), align: 'end', render: (row) => <ValueCell row={row} value={row.actual_value} /> },
    { key: 'standard_value', label: t('manufacturing.columns.standardValue'), align: 'end', render: (row) => <ValueCell row={row} value={row.standard_value} /> },
    { key: 'value_variance', label: t('manufacturing.columns.valueDifference'), align: 'end', render: (row) => <ValueCell row={row} value={row.value_variance} /> },
  ]

  const list = useServerList({
    id: 'manufacturing-consumption',
    endpoint: 'manufacturing/consumption',
    queryKey: [...manufacturingKey, 'consumption'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: 'order',
    columns,
  })

  return (
    <>
      <PageHeader title={t('manufacturing.consumption.title')} description={t('manufacturing.consumption.description')} />
      <ListView
        list={list}
        title={t('manufacturing.consumption.title')}
        searchLabel={t('manufacturing.consumption.search')}
        filterFields={[statusFilter(t, REPORT_STATUSES), ...placeFilterFields(t, places, ['location']), dateField('from', t('manufacturing.filters.from')), dateField('to', t('manufacturing.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('manufacturing.consumption.emptyFiltered') : t('manufacturing.consumption.empty')}
      />
    </>
  )
}
