import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { categoryTree, ITEM_TYPES, useItemCategories, useUoms } from './catalogueData'

const STATUSES = ['active', 'archived']

/**
 * MD-02: the catalogue. Search (debounced) reads a code prefix, a name in
 * either language or an exact barcode; filters by category (with those
 * beneath it) and type; active or archived; sort, server pages, columns and
 * export (EXP-01, LAY-04).
 */
export default function Items() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const categories = useItemCategories()
  const uoms = useUoms()

  const categoryName = (id) => categories.all.find((entry) => entry.id === id)?.name ?? ''
  const uomCode = (id) => uoms.all.find((uom) => uom.id === id)?.code ?? ''

  const columns = [
    {
      key: 'code',
      label: t('items.columns.code'),
      sortKey: 'code',
      render: (item) => <span className="font-mono text-caption text-ink-muted">{item.code}</span>,
    },
    {
      key: 'name',
      label: t('items.columns.name'),
      sortKey: 'name',
      hideable: false,
      render: (item) => <span className="font-medium text-ink">{item?.name ?? ''}</span>,
    },
    { key: 'category', label: t('items.columns.category'), sortKey: 'category', render: (item) => categoryName(item.category_id) },
    { key: 'type', label: t('items.columns.type'), sortKey: 'type', render: (item) => (item.type ? t(`items.types.${item.type}`) : '') },
    { key: 'unit', label: t('items.columns.unit'), exportKey: 'base_unit', render: (item) => uomCode(item.base_uom_id) },
    {
      key: 'barcode',
      label: t('items.columns.barcode'),
      exportKey: 'barcodes',
      render: (item) =>
        item.barcodes?.length ? (
          <span className="tabular-nums">
            {item.barcodes[0].barcode}
            {item.barcodes.length > 1 ? <span className="text-ink-muted"> {t('items.moreBarcodes', { count: item.barcodes.length - 1 })}</span> : null}
          </span>
        ) : (
          ''
        ),
    },
  ]

  const list = useServerList({
    id: 'items',
    endpoint: 'items',
    queryKey: ['items'],
    filters: { status: 'active', category: '', type: '' },
    columns,
  })
  const { status, category, type } = list.filters
  const filtered = Boolean(list.term || category || type)

  return (
    <>
      <PageHeader
        title={t('catalogue.items.title')}
        description={t('catalogue.items.description')}
        actions={
          can('core.item.create') ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/catalogue/items/new')}>
              {t('items.add')}
            </Button>
          ) : null
        }
      />
      <Tabs
        items={STATUSES.map((value) => ({ value, label: t(`items.status.${value}`) }))}
        value={status}
        onChange={(next) => list.setFilter('status', next)}
      />
      <ListView
        list={list}
        title={t('catalogue.items.title')}
        searchLabel={t('items.search')}
        searchPlaceholder={t('items.searchPlaceholder')}
        filterFields={[
          {
            name: 'category',
            label: t('items.filters.category'),
            options: [
              { value: '', label: t('items.filters.allCategories') },
              ...categoryTree(categories.all.filter((entry) => !entry.archived_at)).map(({ row, depth }) => ({
                value: row.id,
                label: `${'— '.repeat(depth)}${row?.name ?? ''}`,
              })),
            ],
            // The chip names the category without its depth dashes.
            valueLabel: (id) => categoryName(id) || id,
          },
          {
            name: 'type',
            label: t('items.filters.type'),
            options: [{ value: '', label: t('items.filters.allTypes') }, ...ITEM_TYPES.map((value) => ({ value, label: t(`items.types.${value}`) }))],
          },
        ]}
        onRowClick={(item) => navigate(`/catalogue/items/${item.id}`)}
        emptyText={filtered ? t('items.emptyFiltered') : t(`items.empty.${status}`)}
      />
    </>
  )
}
