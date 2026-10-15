import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { Amount, CalendarDate, FaStatus } from './FixedAssetsParts'
import { ASSET_MANAGE, ASSET_STATUSES, faKey, statusLabel, useCategories, useLocations } from './fixedAssetsData'

/**
 * FA-04, FA-26: the asset register at the places the user sees, by tag;
 * drafts and assets in use by default. Values show to value holders only
 * (RBAC-05).
 */
export default function Assets() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const categories = useCategories(true)
  const locations = useLocations()

  const columns = [
    { key: 'number', label: t('fixedAssets.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'name', label: t('fixedAssets.columns.name'), sortKey: 'name', render: (row) => row.name },
    { key: 'status', label: t('fixedAssets.columns.status'), sortKey: 'status', render: (row) => <FaStatus status={row.archived_at ? 'archived' : row.status} /> },
    { key: 'category', label: t('fixedAssets.columns.category'), render: (row) => row.category?.name ?? '' },
    { key: 'location', label: t('fixedAssets.columns.location'), render: (row) => row.location?.name ?? '' },
    { key: 'custodian', label: t('fixedAssets.columns.custodian'), render: (row) => row.custodian?.name ?? '' },
    { key: 'in_service_on', label: t('fixedAssets.columns.inService'), sortKey: 'in_service_on', render: (row) => <CalendarDate value={row.in_service_on} /> },
    { key: 'base_cost', label: t('fixedAssets.columns.cost'), align: 'end', render: (row) => <Amount value={row.base_cost} /> },
    { key: 'book_value', label: t('fixedAssets.columns.bookValue'), align: 'end', render: (row) => <Amount value={row.book_value} /> },
  ]

  const list = useServerList({
    id: 'fixed-assets-register',
    endpoint: 'fixed-assets/assets',
    queryKey: [...faKey, 'assets'],
    filters: { status: '', category: '', location: '' },
    defaultSort: 'number',
    columns,
  })

  const filterFields = [
    { name: 'status', label: t('fixedAssets.filters.status'), options: [{ value: '', label: t('fixedAssets.filters.inUse') }, ...[...ASSET_STATUSES, 'all'].map((value) => ({ value, label: value === 'all' ? t('fixedAssets.filters.all') : statusLabel(t, value) }))] },
    { name: 'category', label: t('fixedAssets.filters.category'), options: [{ value: '', label: t('fixedAssets.filters.allCategories') }, ...categories.map((category) => ({ value: category.id, label: category.name }))] },
    { name: 'location', label: t('fixedAssets.filters.location'), options: [{ value: '', label: t('fixedAssets.filters.allLocations') }, ...locations.map((location) => ({ value: location.id, label: location.name }))] },
  ]

  return (
    <>
      <PageHeader
        title={t('fixedAssets.assets.title')}
        description={t('fixedAssets.assets.description')}
        actions={can(ASSET_MANAGE) ? <Button variant="primary" icon="plus" onClick={() => navigate('/fixed-assets/assets/new')}>{t('fixedAssets.assets.new')}</Button> : null}
      />
      <ListView
        list={list}
        title={t('fixedAssets.assets.title')}
        searchLabel={t('fixedAssets.assets.search')}
        filterFields={filterFields}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('fixedAssets.assets.emptyFiltered') : t('fixedAssets.assets.empty')}
        onRowClick={(row) => navigate(`/fixed-assets/assets/${row.id}`)}
      />
    </>
  )
}
