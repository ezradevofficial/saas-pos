import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { Amount, Qty } from './StoresParts'
import { kindLabel, MOVEMENT_KINDS, storeFilter, storesKey, useStorePlaces, useStoresStatus } from './storesData'

/** The page of a movement's Stores document. */
const SOURCE_PATHS = {
  'stores.issue': (id) => `/stores/issues/${id}`,
  'stores.return': (id) => `/stores/returns/${id}`,
}

/**
 * STR-10: the store ledger as a server list: time (the company's zone),
 * movement, document, store, item, signed quantity in the item's base
 * unit, the balance after it (own ledger), department and value (value
 * holders, once valued), with store, kind and date filters and export.
 * With Inventory on, the full stock ledger is Inventory's (linked).
 */
export default function StoreLedger() {
  const { t } = useTranslation()
  const locale = useLocale()
  const companyOf = useCompanyOfRecord()
  const places = useStorePlaces()
  const status = useStoresStatus()
  const { can, hasModule } = usePermissions()

  const columns = [
    { key: 'occurred_at', label: t('stores.columns.date'), sortKey: 'occurred_at', hideable: false, render: (row) => <span className="tabular-nums">{formatCompanyTime(row.occurred_at, locale, companyOf(row.company?.id))}</span> },
    { key: 'kind', label: t('stores.columns.movement'), sortKey: 'kind', render: (row) => kindLabel(t, row.kind) },
    {
      key: 'reference',
      label: t('stores.columns.document'),
      render: (row) => {
        const path = SOURCE_PATHS[row.source?.type]?.(row.source?.id)
        return path ? (
          <Link to={path} className="text-primary tabular-nums hover:text-primary-hover" onClick={(event) => event.stopPropagation()}>
            {row.source?.reference}
          </Link>
        ) : (
          <span className="tabular-nums">{row.source?.reference ?? ''}</span>
        )
      },
    },
    { key: 'location', label: t('stores.columns.store'), render: (row) => row.location?.name ?? '' },
    { key: 'code', label: t('stores.columns.code'), render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('stores.columns.item'), render: (row) => <span className="text-ink">{row.item?.name}</span> },
    { key: 'qty', label: t('stores.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty} unit={row.unit?.code} /> },
    { key: 'balance_after', label: t('stores.columns.balanceAfter'), align: 'end', render: (row) => <Qty value={row.balance_after} /> },
    { key: 'department', label: t('stores.columns.department'), render: (row) => row.department?.name ?? '' },
    { key: 'value', label: t('stores.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
  ]

  const list = useServerList({
    id: 'stores-ledger',
    endpoint: 'stores/ledger',
    queryKey: [...storesKey, 'ledger'],
    filters: { location: '', kind: '', from: '', to: '' },
    defaultSort: '-occurred_at',
    columns,
  })

  return (
    <>
      <PageHeader title={t('stores.ledger.title')} description={t('stores.ledger.description')} />
      {status?.inventory_active ? (
        <Alert tone="info" title={t('stores.ledger.inventoryTitle')}>
          {hasModule('inventory') && can('inventory.stock.view') ? (
            <Link to="/inventory/movements" className="text-primary hover:text-primary-hover">
              {t('stores.ledger.inventoryLink')}
            </Link>
          ) : (
            t('stores.ledger.inventoryHelp')
          )}
        </Alert>
      ) : null}
      <ListView
        list={list}
        title={t('stores.ledger.title')}
        searchLabel={t('stores.ledger.search')}
        filterFields={[
          ...storeFilter(t, places.stores),
          { name: 'kind', label: t('stores.filters.kind'), options: [{ value: '', label: t('stores.filters.allKinds') }, ...MOVEMENT_KINDS.map((kind) => ({ value: kind, label: kindLabel(t, kind) }))] },
          dateField('from', t('stores.filters.from')),
          dateField('to', t('stores.filters.to')),
        ]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('stores.ledger.emptyFiltered') : t('stores.ledger.empty')}
      />
    </>
  )
}
