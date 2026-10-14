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
import { ADJUSTMENT_CREATE, ADJUSTMENT_STATUSES, inventoryKey, placeFilterFields, usePlaces } from './inventoryData'
import { dateField, statusField } from './inventoryHelpers'

/**
 * INV-10, INV-19: adjustments at the places the user sees, newest first,
 * with search (number or note), status, place and date filters, sort and
 * export. Values in and out show to valuation holders only (RBAC-05).
 */
export default function Adjustments() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const places = usePlaces()

  const columns = [
    { key: 'number', label: t('inventory.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'created_at', label: t('inventory.columns.createdAt'), sortKey: 'created_at', render: (row) => <span className="tabular-nums">{formatCompanyTime(row.created_at, locale, companyOf(row.company?.id))}</span> },
    { key: 'status', label: t('inventory.columns.status'), sortKey: 'status', render: (row) => <DocumentStatus status={row.status} /> },
    { key: 'reason', label: t('inventory.columns.reason'), render: (row) => t(`inventory.reasons.${row.reason}`, { defaultValue: row.reason }) },
    { key: 'location', label: t('inventory.columns.location'), render: (row) => row.location?.name ?? '' },
    { key: 'requested_by', label: t('inventory.columns.requestedBy'), render: (row) => row.requested_by?.name ?? '' },
    { key: 'value_in', label: t('inventory.columns.valueIn'), align: 'end', render: (row) => <Amount value={row.value_in} /> },
    { key: 'value_out', label: t('inventory.columns.valueOut'), align: 'end', render: (row) => <Amount value={row.value_out} /> },
  ]

  const list = useServerList({
    id: 'inventory-adjustments',
    endpoint: 'inventory/adjustments',
    queryKey: [...inventoryKey, 'adjustments'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: '-created_at',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('inventory.adjustments.title')}
        description={t('inventory.adjustments.description')}
        actions={
          can(ADJUSTMENT_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/inventory/adjustments/new')}>
              {t('inventory.adjustments.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('inventory.adjustments.title')}
        searchLabel={t('inventory.adjustments.search')}
        filterFields={[statusField(t, ADJUSTMENT_STATUSES), ...placeFilterFields(t, places, ['location']), dateField('from', t('inventory.filters.from')), dateField('to', t('inventory.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('inventory.adjustments.emptyFiltered') : t('inventory.adjustments.empty')}
        onRowClick={(row) => navigate(`/inventory/adjustments/${row.id}`)}
      />
    </>
  )
}
