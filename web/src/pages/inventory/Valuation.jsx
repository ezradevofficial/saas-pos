import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { KpiTile, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatAmount } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useItemCategories } from '@/pages/catalogue/catalogueData'
import { Amount, Qty, UnitCost } from './InventoryParts'
import { inventoryKey, placeFilterFields, usePlaces } from './inventoryData'
import { minorToMajor } from './inventoryHelpers'

/**
 * INV-20: what each company holds of each item, its unit cost and value
 * in the company's base currency, with totals per currency (meta.totals),
 * company and category filters, search, sort and export (EXP-01). For
 * holders of inventory.valuation.view at the company.
 */
export default function Valuation() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const places = usePlaces()
  const categories = useItemCategories()

  const columns = [
    { key: 'company', label: t('inventory.columns.company'), render: (row) => row.company?.name ?? '' },
    { key: 'code', label: t('inventory.columns.code'), sortKey: 'code', render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('inventory.columns.item'), sortKey: 'item', hideable: false, render: (row) => <span className="font-medium text-ink">{row.item?.name}</span> },
    { key: 'qty', label: t('inventory.columns.qty'), sortKey: 'qty', align: 'end', render: (row) => <Qty value={row.qty} /> },
    { key: 'unit', label: t('inventory.columns.unit'), render: (row) => row.base_uom?.code ?? '' },
    { key: 'unit_cost', label: t('inventory.columns.unitCost'), align: 'end', render: (row) => <UnitCost value={minorToMajor(row.unit_cost_minor, row.currency)} currency={row.currency} /> },
    { key: 'value', label: t('inventory.columns.value'), sortKey: 'value', align: 'end', render: (row) => <Amount value={row.value} /> },
  ]

  const list = useServerList({
    id: 'inventory-valuation',
    endpoint: 'inventory/valuation',
    queryKey: [...inventoryKey, 'valuation'],
    filters: { company: '', category: '' },
    defaultSort: '-value',
    columns,
  })
  const totals = list.meta?.totals ?? []

  return (
    <>
      <PageHeader title={t('inventory.valuation.title')} description={t('inventory.valuation.description')} />
      {totals.length ? (
        <section aria-label={t('inventory.valuation.totals')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {totals.map((total) => (
            <KpiTile key={total.currency} label={t('inventory.valuation.totalIn', { currency: total.currency })} value={`${total.currency} ${formatAmount(total.amount_minor, total.currency, locale)}`} />
          ))}
        </section>
      ) : null}
      <ListView
        list={list}
        title={t('inventory.valuation.title')}
        searchLabel={t('inventory.valuation.search')}
        filterFields={[
          ...placeFilterFields(t, places, ['company']),
          ...(categories.all.length
            ? [{ name: 'category', label: t('inventory.filters.category'), options: [{ value: '', label: t('inventory.filters.all.category') }, ...categories.all.map((category) => ({ value: category.id, label: category.name }))] }]
            : []),
        ]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('inventory.valuation.emptyFiltered') : t('inventory.valuation.empty')}
        onRowClick={(row) => navigate(`/inventory/items/${row.item?.id}`)}
      />
    </>
  )
}
