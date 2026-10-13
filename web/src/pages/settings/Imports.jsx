import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Button, ListView, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { IMPORT_STATUSES, STATUS_TONE, useImporters } from './imports/importData'

/**
 * IMP-01..IMP-03: past imports, newest first, with search (file name),
 * filters (type, status), sort, pages and export (EXP-01). "New import"
 * opens the wizard; a row opens its import where it stands.
 */
export default function Imports() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const locale = useLocale()
  const timeZone = useTimeZone()
  const importers = useImporters()
  const count = (value) => formatInteger(value ?? 0, locale)

  const columns = [
    { key: 'created_at', label: t('imports.columns.created_at'), sortKey: 'created_at', render: (row) => formatDateTime(row.created_at, locale, timeZone) },
    { key: 'importer', label: t('imports.columns.importer'), sortKey: 'importer', render: (row) => row.importer_label },
    { key: 'file_name', label: t('imports.columns.file_name'), sortKey: 'file_name', hideable: false, render: (row) => <span className="font-medium text-ink">{row.file_name}</span> },
    { key: 'status', label: t('imports.columns.status'), sortKey: 'status', render: (row) => <StatusBadge tone={STATUS_TONE[row.status]}>{t(`imports.statuses.${row.status}`)}</StatusBadge> },
    { key: 'total_rows', label: t('imports.columns.total_rows'), sortKey: 'total_rows', align: 'end', numeric: true, render: (row) => count(row.total_rows) },
    { key: 'created_rows', label: t('imports.columns.created_rows'), align: 'end', numeric: true, render: (row) => count(row.created_rows) },
    { key: 'updated_rows', label: t('imports.columns.updated_rows'), align: 'end', numeric: true, render: (row) => count(row.updated_rows) },
    { key: 'failed_rows', label: t('imports.columns.failed_rows'), align: 'end', numeric: true, render: (row) => count(row.failed_rows) },
    { key: 'user', label: t('imports.columns.user'), defaultHidden: true, render: (row) => row.user?.name ?? '' },
  ]

  const list = useServerList({
    id: 'imports',
    endpoint: 'imports',
    queryKey: ['imports', 'list'],
    filters: { importer: '', status: '' },
    columns,
  })
  const filtered = Boolean(list.term || list.filters.importer || list.filters.status)

  return (
    <>
      <PageHeader
        title={t('imports.title')}
        description={t('imports.description')}
        actions={
          <Button variant="primary" icon="plus" onClick={() => navigate('/settings/imports/new')}>
            {t('imports.new')}
          </Button>
        }
      />
      <ListView
        list={list}
        title={t('imports.title')}
        searchLabel={t('imports.search')}
        filterFields={[
          {
            name: 'importer',
            label: t('imports.columns.importer'),
            options: [{ value: '', label: t('imports.filters.allTypes') }, ...(importers.data ?? []).map((importer) => ({ value: importer.key, label: importer.label }))],
          },
          {
            name: 'status',
            label: t('imports.columns.status'),
            options: [{ value: '', label: t('imports.filters.allStatuses') }, ...IMPORT_STATUSES.map((value) => ({ value, label: t(`imports.statuses.${value}`) }))],
          },
        ]}
        onRowClick={(row) => navigate(`/settings/imports/${row.id}`)}
        emptyText={filtered ? t('imports.emptyFiltered') : t('imports.empty')}
      />
    </>
  )
}
