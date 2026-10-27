import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { RecordStatus } from './PharmacyParts'
import { choiceField, dateField, locationFilter, pharmacyKey, usePlaces, WRITE_OFF_STATUSES, WRITEOFF_CREATE } from './pharmacyData'

/** PHA-12: write-offs at the places the user sees, newest first, with search (number or note), status and place filters, sort and export. */
export default function WriteOffs() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const places = usePlaces()
  const columns = [
    { key: 'number', label: t('pharmacy.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'created_at', label: t('pharmacy.columns.created_at'), sortKey: 'created_at', render: (row) => <span className="tabular-nums">{formatCompanyTime(row.created_at, locale, companyOf(row.company?.id))}</span> },
    { key: 'status', label: t('pharmacy.columns.status'), sortKey: 'status', render: (row) => <RecordStatus status={row.status} /> },
    { key: 'reason', label: t('pharmacy.columns.reason'), render: (row) => t(`pharmacy.reasons.${row.reason}`, { defaultValue: row.reason }) },
    { key: 'location', label: t('pharmacy.columns.location'), render: (row) => row.location?.name ?? '' },
    { key: 'requested_by', label: t('pharmacy.columns.requested_by'), render: (row) => row.requested_by?.name ?? '' },
  ]
  const list = useServerList({
    id: 'pharmacy-write-offs',
    endpoint: 'pharmacy/write-offs',
    queryKey: [...pharmacyKey, 'write-offs'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: '-created_at',
    columns,
  })
  return (
    <>
      <PageHeader
        title={t('pharmacy.writeOffs.title')}
        description={t('pharmacy.writeOffs.description')}
        actions={
          can(WRITEOFF_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/pharmacy/write-offs/new')}>
              {t('pharmacy.writeOffs.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('pharmacy.writeOffs.title')}
        searchLabel={t('pharmacy.writeOffs.search')}
        filterFields={[
          choiceField(t, 'status', WRITE_OFF_STATUSES, t('pharmacy.columns.status'), 'pharmacy.states'),
          ...locationFilter(t, places),
          dateField('from', t('pharmacy.filters.from')),
          dateField('to', t('pharmacy.filters.to')),
        ]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('pharmacy.writeOffs.emptyFiltered') : t('pharmacy.writeOffs.empty')}
        onRowClick={(row) => navigate(`/pharmacy/write-offs/${row.id}`)}
      />
    </>
  )
}
