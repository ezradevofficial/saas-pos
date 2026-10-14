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
import { COUNT_CREATE, COUNT_STATUSES, inventoryKey, placeFilterFields, usePlaces } from './inventoryData'
import { dateField, statusField } from './inventoryHelpers'

/**
 * INV-09, INV-19: stock counts at the places the user sees, newest first,
 * with search by number, status, place and date filters, sort and export.
 * Gains and losses show to valuation holders only (RBAC-05).
 */
export default function Counts() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const places = usePlaces()

  const columns = [
    { key: 'number', label: t('inventory.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'created_at', label: t('inventory.columns.createdAt'), sortKey: 'created_at', render: (row) => <span className="tabular-nums">{formatCompanyTime(row.created_at, locale, companyOf(row.company?.id))}</span> },
    { key: 'kind', label: t('inventory.columns.countKind'), render: (row) => t(`inventory.countKinds.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'status', label: t('inventory.columns.status'), sortKey: 'status', render: (row) => <DocumentStatus status={row.status} /> },
    { key: 'location', label: t('inventory.columns.location'), render: (row) => row.location?.name ?? '' },
    { key: 'lines_count', label: t('inventory.columns.lines'), align: 'end', numeric: true, render: (row) => row.lines_count },
    { key: 'gain', label: t('inventory.columns.gain'), align: 'end', render: (row) => <Amount value={row.gain} /> },
    { key: 'loss', label: t('inventory.columns.loss'), align: 'end', render: (row) => <Amount value={row.loss} /> },
  ]

  const list = useServerList({
    id: 'inventory-counts',
    endpoint: 'inventory/counts',
    queryKey: [...inventoryKey, 'counts'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: '-created_at',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('inventory.counts.title')}
        description={t('inventory.counts.description')}
        actions={
          can(COUNT_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/inventory/counts/new')}>
              {t('inventory.counts.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('inventory.counts.title')}
        searchLabel={t('inventory.counts.search')}
        filterFields={[statusField(t, COUNT_STATUSES), ...placeFilterFields(t, places, ['location']), dateField('from', t('inventory.filters.from')), dateField('to', t('inventory.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('inventory.counts.emptyFiltered') : t('inventory.counts.empty')}
        onRowClick={(row) => navigate(`/inventory/counts/${row.id}`)}
      />
    </>
  )
}
