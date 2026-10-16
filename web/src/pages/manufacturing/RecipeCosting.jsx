import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { placeFilterFields, usePlaces } from '@/pages/inventory/inventoryData'
import { Amount, InventoryOffNote, Qty } from './ManufacturingParts'
import { BOM_KINDS, kindFilter, kindLabel, manufacturingKey } from './manufacturingData'

/**
 * MFG-06, MFG-27 (owner decision B, 2026-10-16): what each BOM in force
 * costs now: ingredients at Inventory's current unit costs, plus labour and
 * overhead where the company sets them, less the by-products' credit, per
 * output unit and per batch; the menu item's price, its price before tax
 * and the cost's share of that; the items without a cost. Kind and company
 * filters, sort and export.
 */
export default function RecipeCosting() {
  const { t } = useTranslation()
  const places = usePlaces()

  const columns = [
    { key: 'code', label: t('manufacturing.columns.code'), hideable: false, render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    {
      key: 'item',
      label: t('manufacturing.columns.item'),
      sortKey: 'item',
      render: (row) => (
        <Link to={`/manufacturing/boms/${row.id}`} className="text-primary hover:text-primary-hover" onClick={(event) => event.stopPropagation()}>
          {row.item?.name}
        </Link>
      ),
    },
    { key: 'kind', label: t('manufacturing.columns.kind'), sortKey: 'kind', render: (row) => kindLabel(t, row.kind) },
    {
      key: 'output',
      label: t('manufacturing.columns.output'),
      align: 'end',
      render: (row) => (
        <span className="flex flex-col items-end">
          {row.output_qty ? <Qty value={row.output_qty} unit={row.output_uom?.code} /> : null}
          {row.error ? <span className="text-caption text-ink-muted">{t(`manufacturing.errors.${row.error}`, { defaultValue: t('manufacturing.errors.other') })}</span> : null}
        </span>
      ),
    },
    { key: 'unit_cost', label: t('manufacturing.columns.unitCost'), align: 'end', render: (row) => <Amount value={row.unit_cost} className="font-medium" /> },
    { key: 'batch_cost', label: t('manufacturing.columns.batchCost'), align: 'end', render: (row) => <Amount value={row.batch_cost} /> },
    { key: 'materials_cost', label: t('manufacturing.columns.materialsCost'), align: 'end', defaultHidden: true, render: (row) => <Amount value={row.materials_cost} /> },
    { key: 'labour_cost', label: t('manufacturing.columns.labourCost'), align: 'end', defaultHidden: true, render: (row) => <Amount value={row.labour_cost} /> },
    { key: 'overhead_cost', label: t('manufacturing.columns.overheadCost'), align: 'end', defaultHidden: true, render: (row) => <Amount value={row.overhead_cost} /> },
    { key: 'by_product_credit', label: t('manufacturing.columns.byProductCredit'), align: 'end', defaultHidden: true, render: (row) => <Amount value={row.by_product_credit} /> },
    {
      key: 'price',
      label: t('manufacturing.columns.price'),
      align: 'end',
      render: (row) =>
        row.price ? (
          <span className="flex flex-col items-end">
            <Amount value={row.price} />
            {row.price.tax_inclusive ? <span className="text-caption text-ink-muted">{t('manufacturing.recipeCosting.inclTax')}</span> : null}
          </span>
        ) : (
          <span className="text-ink-muted">—</span>
        ),
    },
    {
      key: 'net_price',
      label: t('manufacturing.columns.netPrice'),
      align: 'end',
      render: (row) =>
        row.net_price ? (
          <Amount value={row.net_price} />
        ) : row.price ? (
          <span className="text-caption text-ink-muted">{t('manufacturing.recipeCosting.taxUnknown')}</span>
        ) : (
          <span className="text-ink-muted">—</span>
        ),
    },
    { key: 'cost_share_percent', label: t('manufacturing.columns.costOfPrice'), align: 'end', render: (row) => <Qty value={row.cost_share_percent} unit="%" /> },
    {
      key: 'uncosted',
      label: t('manufacturing.columns.uncosted'),
      wrap: true,
      render: (row) => (row.uncosted?.length ? <span className="text-caption text-ink-muted">{row.uncosted.map((item) => item?.name).filter(Boolean).join(', ')}</span> : ''),
    },
  ]

  const list = useServerList({
    id: 'manufacturing-recipe-costing',
    endpoint: 'manufacturing/recipe-costing',
    queryKey: [...manufacturingKey, 'recipe-costing'],
    filters: { kind: '', company: '' },
    defaultSort: 'item',
    columns,
  })

  return (
    <>
      <PageHeader title={t('manufacturing.recipeCosting.title')} description={t('manufacturing.recipeCosting.description')} />
      <InventoryOffNote />
      <ListView
        list={list}
        title={t('manufacturing.recipeCosting.title')}
        searchLabel={t('manufacturing.recipeCosting.search')}
        filterFields={[kindFilter(t, BOM_KINDS), ...placeFilterFields(t, places, ['company'])]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('manufacturing.recipeCosting.emptyFiltered') : t('manufacturing.recipeCosting.empty')}
      />
    </>
  )
}
