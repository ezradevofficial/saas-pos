import { useTranslation } from 'react-i18next'
import { KpiTile, ListView, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatAmount } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { Amount, Qty } from './StoresParts'
import { GROUPS, storeFilter, storesKey, useStorePlaces } from './storesData'

/**
 * STR-12: what departments consumed (issued − returned) over a period,
 * grouped by department, cost centre, item or month, one row per group
 * and item, with quantities in the item's base unit and values when
 * Inventory valued them (value holders only), totals per currency, store
 * and date filters and export.
 */
export default function Consumption() {
  const { t } = useTranslation()
  const locale = useLocale()
  const places = useStorePlaces()

  const columns = [
    { key: 'group', label: t('stores.columns.group'), sortKey: 'group', hideable: false, render: (row) => <span className="text-ink">{row.group?.name ?? t('stores.consumption.none')}</span> },
    { key: 'code', label: t('stores.columns.code'), render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('stores.columns.item'), sortKey: 'item', render: (row) => <span className="font-medium text-ink">{row.item?.name}</span> },
    { key: 'issued_qty', label: t('stores.columns.issued'), align: 'end', render: (row) => <Qty value={row.issued_qty} unit={row.unit?.code} /> },
    { key: 'returned_qty', label: t('stores.columns.returned'), align: 'end', render: (row) => <Qty value={row.returned_qty} unit={row.unit?.code} /> },
    { key: 'net_qty', label: t('stores.columns.consumed'), sortKey: 'net_qty', align: 'end', render: (row) => <Qty value={row.net_qty} unit={row.unit?.code} className="font-medium" /> },
    { key: 'net_value', label: t('stores.columns.consumedValue'), align: 'end', render: (row) => <Amount value={row.net_value} /> },
  ]

  const list = useServerList({
    id: 'stores-consumption',
    endpoint: 'stores/consumption',
    queryKey: [...storesKey, 'consumption'],
    filters: { group: 'department', location: '', from: '', to: '' },
    defaultSort: 'group',
    columns,
  })
  const group = list.filters.group || 'department'
  const totals = list.meta?.totals ?? []

  return (
    <>
      <PageHeader title={t('stores.consumption.title')} description={t('stores.consumption.description')} />
      <nav aria-label={t('stores.consumption.groupBy')}>
        <Tabs value={group} onChange={(value) => list.setFilter('group', value)} items={GROUPS.map((value) => ({ value, label: t(`stores.groups.${value}`) }))} />
      </nav>
      {totals.length ? (
        <section aria-label={t('stores.consumption.totals')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {totals.map((total) => (
            <KpiTile key={total.currency} label={t('stores.consumption.totalIn', { currency: total.currency })} value={`${total.currency} ${formatAmount(total.amount_minor, total.currency, locale)}`} />
          ))}
        </section>
      ) : null}
      <ListView
        list={list}
        title={t(`stores.consumption.by.${group}`)}
        searchLabel={t('stores.consumption.search')}
        filterFields={[...storeFilter(t, places.stores), dateField('from', t('stores.filters.from')), dateField('to', t('stores.filters.to'))]}
        emptyText={list.term || list.filters.location || list.filters.from || list.filters.to ? t('stores.consumption.emptyFiltered') : t('stores.consumption.empty')}
      />
    </>
  )
}
