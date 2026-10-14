import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { Amount, DocumentStatus } from './StoresParts'
import { ISSUE_STATUSES, statusFilter, storeFilter, storesKey, useStorePlaces } from './storesData'

/**
 * STR-04, STR-05, STR-18: issues at the stores the user sees, newest
 * first; "Issued" are waiting for the department's acknowledgement.
 * Values show to value holders once Inventory valued them (RBAC-05).
 */
export default function Issues() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const companyOf = useCompanyOfRecord()
  const places = useStorePlaces()

  const columns = [
    { key: 'number', label: t('stores.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'issued_at', label: t('stores.columns.issuedAt'), sortKey: 'issued_at', render: (row) => <span className="tabular-nums">{formatCompanyTime(row.issued_at, locale, companyOf(row.company?.id))}</span> },
    { key: 'status', label: t('stores.columns.status'), sortKey: 'status', render: (row) => <DocumentStatus status={row.status} /> },
    { key: 'requisition', label: t('stores.columns.requisition'), render: (row) => <span className="tabular-nums">{row.requisition?.number ?? ''}</span> },
    { key: 'location', label: t('stores.columns.store'), render: (row) => row.location?.name ?? '' },
    { key: 'department', label: t('stores.columns.department'), render: (row) => row.department?.name ?? '' },
    { key: 'issued_by', label: t('stores.columns.issuedBy'), render: (row) => row.issued_by?.name ?? '' },
    { key: 'value', label: t('stores.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
  ]

  const list = useServerList({
    id: 'stores-issues',
    endpoint: 'stores/issues',
    queryKey: [...storesKey, 'issues'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: '-issued_at',
    columns,
  })

  return (
    <>
      <PageHeader title={t('stores.issues.title')} description={t('stores.issues.description')} />
      <ListView
        list={list}
        title={t('stores.issues.title')}
        searchLabel={t('stores.issues.search')}
        filterFields={[statusFilter(t, ISSUE_STATUSES), ...storeFilter(t, places.stores), dateField('from', t('stores.filters.from')), dateField('to', t('stores.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('stores.issues.emptyFiltered') : t('stores.issues.empty')}
        onRowClick={(row) => navigate(`/stores/issues/${row.id}`)}
      />
    </>
  )
}
