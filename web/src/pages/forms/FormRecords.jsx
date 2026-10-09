import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { customFiltersActive, useCustomListFields } from '@/components/customFieldList'
import { Alert, Button, ListView, Money, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { RECORD_STATUSES, STATUS_TONES, useFormType } from '@/lib/customForms'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'

/** A record's status: a dot and a word. */
export function RecordStatus({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{t(`customForms.statuses.${status}`, { defaultValue: status })}</StatusBadge>
}

function Records({ type }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const companyOf = useCompanyOfRecord()
  const custom = useCustomListFields(type.entity)
  const place = (record) => [record.location?.name, record.branch?.name, record.company?.name].filter(Boolean).join(' · ')

  const columns = [
    { key: 'number', label: t('customForms.columns.number'), sortKey: 'number', hideable: false, render: (record) => <span className="font-medium text-ink">{record.number}</span> },
    { key: 'status', label: t('customForms.columns.status'), sortKey: 'status', render: (record) => <RecordStatus status={record.status} /> },
    { key: 'company', label: t('customForms.columns.place'), render: place },
    {
      key: 'amount',
      label: t('customForms.columns.amount'),
      sortKey: 'amount',
      align: 'end',
      numeric: true,
      render: (record) => (record.amount ? <Money amount={record.amount.amount_minor} currency={record.amount.currency} /> : ''),
    },
    { key: 'created_by', label: t('customForms.columns.createdBy'), render: (record) => record.created_by?.name ?? '' },
    {
      key: 'created_at',
      label: t('customForms.columns.createdAt'),
      sortKey: 'created_at',
      render: (record) => <span className="tabular-nums">{formatCompanyTime(record.created_at, locale, companyOf(record.company?.id))}</span>,
    },
    ...custom.columns,
  ]

  const list = useServerList({
    id: `custom-forms-${type.key}`,
    endpoint: `custom-form-types/${type.id}/records`,
    queryKey: ['custom-form-records', type.id],
    filters: { status: '', ...custom.filterDefaults },
    columns,
  })

  return (
    <>
      <PageHeader
        title={type.name}
        description={type.description ?? undefined}
        actions={
          type.can?.create ? (
            <Button variant="primary" icon="plus" onClick={() => navigate(`/forms/${type.key}/new`)}>
              {t('customForms.records.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={type.name}
        searchLabel={t('customForms.records.search')}
        filterFields={[
          {
            name: 'status',
            label: t('customForms.columns.status'),
            options: [{ value: '', label: t('customForms.records.allOpen') }, ...[...RECORD_STATUSES, 'archived', 'all'].map((value) => ({ value, label: t(`customForms.statuses.${value}`) }))],
          },
          ...custom.filterFields,
        ]}
        onRowClick={(record) => navigate(`/forms/${type.key}/${record.id}`)}
        emptyText={list.term || list.filters.status || customFiltersActive(list, custom) ? t('customForms.records.emptyFiltered') : t('customForms.records.empty')}
      />
    </>
  )
}

/**
 * CF-04: the records of one custom form the user may see (at their places,
 * RBAC-04): search by number, status and custom field filters, columns,
 * saved views, sort, pages and export (EXP-01, LAY-04). A row opens the record.
 */
export default function FormRecords() {
  const { t } = useTranslation()
  const { type, isPending, error } = useFormType()
  if (isPending) return null
  if (!type) {
    return (
      <>
        <PageHeader title={t('customForms.records.notFoundTitle')} />
        <Alert tone="warning" title={error ? t('customForms.records.loadFailed') : t('customForms.records.notFound')} />
        <Link to="/" className="w-fit text-label text-primary hover:text-primary-hover">
          {t('customForms.records.home')}
        </Link>
      </>
    )
  }
  return <Records key={type.id} type={type} />
}
