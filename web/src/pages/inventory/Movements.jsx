import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, FlagChips, Qty } from './InventoryParts'
import { dateField } from './inventoryHelpers'
import { inventoryKey, kindLabel, MOVEMENT_KINDS, placeFilterFields, usePlaces } from './inventoryData'

/** The page of a movement's source document, when the web app has one. */
const SOURCE_PATHS = {
  'inventory.transfer': (id) => `/inventory/transfers/${id}`,
  'inventory.adjustment': (id) => `/inventory/adjustments/${id}`,
  'inventory.count': (id) => `/inventory/counts/${id}`,
  'pos.sale': (id) => `/pos/sales/${id}`,
}

/**
 * INV-04, INV-19: a stock ledger as a server list: time (the company's
 * zone), kind, item, place, batch, quantity, balance after, value
 * (valuation holders only) and flags, with kind and date filters and
 * export. `endpoint` is the whole ledger or one item's; `withItem` shows
 * the item columns.
 */
export function MovementList({ endpoint, withItem = true, withPlaces = true }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const companyOf = useCompanyOfRecord()
  const places = usePlaces()

  const columns = [
    {
      key: 'occurred_at',
      label: t('inventory.columns.occurredAt'),
      sortKey: 'occurred_at',
      hideable: false,
      render: (row) => <span className="tabular-nums">{formatCompanyTime(row.occurred_at, locale, companyOf(row.company_id))}</span>,
    },
    {
      key: 'kind',
      label: t('inventory.columns.kind'),
      sortKey: 'kind',
      render: (row) => {
        const path = SOURCE_PATHS[row.source_type]?.(row.source_id)
        return path ? (
          <Link to={path} className="text-primary hover:text-primary-hover">
            {kindLabel(t, row.kind)}
          </Link>
        ) : (
          kindLabel(t, row.kind)
        )
      },
    },
    ...(withItem
      ? [
          { key: 'code', label: t('inventory.columns.code'), render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
          { key: 'item', label: t('inventory.columns.item'), render: (row) => row.item?.name ?? '' },
        ]
      : []),
    { key: 'location', label: t('inventory.columns.location'), render: (row) => row.location?.name ?? <span className="text-ink-muted">{t('inventory.inTransit')}</span> },
    { key: 'batch', label: t('inventory.columns.batch'), render: (row) => row.batch?.batch_number ?? '' },
    { key: 'qty', label: t('inventory.columns.qty'), sortKey: 'qty', align: 'end', render: (row) => <Qty value={row.qty} /> },
    { key: 'balance_after', label: t('inventory.columns.balanceAfter'), align: 'end', render: (row) => <Qty value={row.balance_after} /> },
    { key: 'value', label: t('inventory.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
    { key: 'flags', label: t('inventory.columns.flags'), wrap: true, render: (row) => <FlagChips flags={row.flags} /> },
  ]

  const list = useServerList({
    id: 'inventory-movements',
    endpoint,
    queryKey: [...inventoryKey, 'movements', endpoint],
    filters: { location: '', kind: '', from: '', to: '' },
    defaultSort: '-occurred_at',
    columns,
  })

  return (
    <ListView
      list={list}
      title={t('inventory.movements.title')}
      searchLabel={t('inventory.movements.search')}
      filterFields={[
        ...(withPlaces ? placeFilterFields(t, places, ['location']) : []),
        {
          name: 'kind',
          label: t('inventory.filters.kind'),
          options: [{ value: '', label: t('inventory.filters.all.kind') }, ...MOVEMENT_KINDS.map((kind) => ({ value: kind, label: kindLabel(t, kind) }))],
        },
        dateField('from', t('inventory.filters.from')),
        dateField('to', t('inventory.filters.to')),
      ]}
      emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('inventory.movements.emptyFiltered') : t('inventory.movements.empty')}
    />
  )
}

/** INV-04, INV-19: the stock ledger of every place the user sees. */
export default function Movements() {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('inventory.movements.title')} description={t('inventory.movements.description')} />
      <MovementList endpoint="inventory/movements" />
    </>
  )
}
