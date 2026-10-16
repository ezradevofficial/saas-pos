import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { placeFilterFields, usePlaces } from '@/pages/inventory/inventoryData'
import { Qty } from './ManufacturingParts'
import { BOM_KINDS, BOM_MANAGE, kindFilter, kindLabel, manufacturingKey } from './manufacturingData'

/**
 * MFG-02, MFG-03, MFG-27: the BOMs and recipes of the companies the user
 * sees: output item, kind, name, the version in force and since when;
 * search, kind, status and company filters, sort and export; New BOM for
 * those who may manage them.
 */
export default function Boms() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const places = usePlaces()

  const columns = [
    { key: 'code', label: t('manufacturing.columns.code'), hideable: false, render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('manufacturing.columns.item'), sortKey: 'item', render: (row) => <span className="font-medium text-ink">{row.item?.name}</span> },
    { key: 'kind', label: t('manufacturing.columns.kind'), sortKey: 'kind', render: (row) => kindLabel(t, row.kind) },
    { key: 'name', label: t('manufacturing.columns.name'), render: (row) => row.name ?? '' },
    { key: 'company', label: t('manufacturing.columns.company'), defaultHidden: true, render: (row) => row.company?.name ?? '' },
    {
      key: 'version',
      label: t('manufacturing.columns.versionInForce'),
      render: (row) =>
        row.current_version ? (
          <span className="tabular-nums">{t('manufacturing.boms.versionNo', { number: row.current_version.version_no })}</span>
        ) : (
          <span className="text-ink-muted">{row.archived_at ? t('manufacturing.boms.archived') : t('manufacturing.boms.noneInForce')}</span>
        ),
    },
    {
      key: 'output',
      label: t('manufacturing.columns.output'),
      exportKey: null,
      align: 'end',
      render: (row) => (row.current_version ? <Qty value={row.current_version.output_qty} /> : ''),
    },
    {
      key: 'effective_from',
      label: t('manufacturing.columns.effectiveFrom'),
      render: (row) => <span className="tabular-nums">{row.current_version?.effective_from ? formatCompanyTime(row.current_version.effective_from, locale, companyOf(row.company?.id)) : ''}</span>,
    },
  ]

  const list = useServerList({
    id: 'manufacturing-boms',
    endpoint: 'manufacturing/boms',
    queryKey: [...manufacturingKey, 'boms'],
    filters: { kind: '', status: 'active', company: '' },
    defaultSort: 'item',
    columns,
  })

  const statusField = {
    name: 'status',
    label: t('manufacturing.filters.status'),
    options: ['active', 'archived', 'all'].map((value) => ({ value, label: t(`manufacturing.boms.statuses.${value}`) })),
  }

  return (
    <>
      <PageHeader
        title={t('manufacturing.boms.title')}
        description={t('manufacturing.boms.description')}
        actions={
          can(BOM_MANAGE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/manufacturing/boms/new')}>
              {t('manufacturing.boms.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('manufacturing.boms.title')}
        searchLabel={t('manufacturing.boms.search')}
        filterFields={[kindFilter(t, BOM_KINDS), statusField, ...placeFilterFields(t, places, ['company'])]}
        emptyText={list.term || list.filters.kind || list.filters.company ? t('manufacturing.boms.emptyFiltered') : t('manufacturing.boms.empty')}
        onRowClick={(row) => navigate(`/manufacturing/boms/${row.id}`)}
      />
    </>
  )
}
