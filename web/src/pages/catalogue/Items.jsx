import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Pager } from '@/components/Pager'
import { Alert, Button, DataTable, Icon, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useDebounced } from '@/lib/useDebounced'
import { useLocale } from '@/lib/useLocale'
import { categoryTree, ITEM_TYPES, localName, useItemCategories, useUoms } from './catalogueData'

const PER_PAGE = 25
const STATUSES = ['active', 'archived']

/**
 * MD-02: the catalogue. Search (debounced) reads a code prefix, a name in
 * either language or an exact barcode; filters by category (with those
 * beneath it) and type; active or archived; server pages.
 */
export default function Items() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [type, setType] = useState('')
  const [status, setStatus] = useState('active')
  const [page, setPage] = useState(1)
  const term = useDebounced(search.trim(), 300)
  const categories = useItemCategories()
  const uoms = useUoms()

  const params = new URLSearchParams({ status, per_page: String(PER_PAGE), page: String(page) })
  if (term) params.set('search', term)
  if (category) params.set('category', category)
  if (type) params.set('type', type)
  const items = useQuery({
    queryKey: ['items', 'list', status, term, category, type, page],
    queryFn: () => api.get(`items?${params}`),
    placeholderData: (previous) => previous,
  })
  const rows = items.data?.data ?? []
  const filtered = Boolean(term || category || type)
  const reset = (setter) => (value) => {
    setter(value)
    setPage(1)
  }

  const categoryName = (id) => {
    const found = categories.all.find((entry) => entry.id === id)
    return found ? localName(found, locale) : ''
  }
  const uomCode = (id) => uoms.all.find((uom) => uom.id === id)?.code ?? ''

  const columns = [
    { key: 'code', label: t('items.columns.code'), render: (item) => <span className="font-mono text-caption text-ink-muted">{item.code}</span> },
    { key: 'name', label: t('items.columns.name'), render: (item) => <span className="font-medium text-ink">{localName(item, locale)}</span> },
    { key: 'category', label: t('items.columns.category'), render: (item) => categoryName(item.category_id) },
    { key: 'type', label: t('items.columns.type'), render: (item) => (item.type ? t(`items.types.${item.type}`) : '') },
    { key: 'unit', label: t('items.columns.unit'), render: (item) => uomCode(item.base_uom_id) },
    {
      key: 'barcode',
      label: t('items.columns.barcode'),
      render: (item) =>
        item.barcodes?.length ? (
          <span className="tabular-nums">
            {item.barcodes[0].barcode}
            {item.barcodes.length > 1 ? <span className="text-ink-muted"> {t('items.moreBarcodes', { count: item.barcodes.length - 1 })}</span> : null}
          </span>
        ) : (
          ''
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('catalogue.items.title')}
        description={t('catalogue.items.description')}
        actions={
          can('core.item.create') ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/catalogue/items/new')}>
              {t('items.add')}
            </Button>
          ) : null
        }
      />
      <div className="grid gap-3 sm:grid-cols-3">
        <TextField
          type="search"
          label={t('items.search')}
          placeholder={t('items.searchPlaceholder')}
          prefix={<Icon name="search" className="text-ink-muted" />}
          value={search}
          onChange={(event) => {
            setSearch(event.target.value)
            setPage(1)
          }}
          autoComplete="off"
        />
        <Select
          label={t('items.filters.category')}
          options={[
            { value: '', label: t('items.filters.allCategories') },
            ...categoryTree(categories.all.filter((entry) => !entry.archived_at)).map(({ row, depth }) => ({
              value: row.id,
              label: `${'— '.repeat(depth)}${localName(row, locale)}`,
            })),
          ]}
          value={category}
          onChange={(event) => reset(setCategory)(event.target.value)}
        />
        <Select
          label={t('items.filters.type')}
          options={[{ value: '', label: t('items.filters.allTypes') }, ...ITEM_TYPES.map((value) => ({ value, label: t(`items.types.${value}`) }))]}
          value={type}
          onChange={(event) => reset(setType)(event.target.value)}
        />
      </div>
      <Tabs items={STATUSES.map((value) => ({ value, label: t(`items.status.${value}`) }))} value={status} onChange={reset(setStatus)} />
      {items.isError ? <Alert tone="danger" title={errorMessage(items.error)} action={<Button onClick={() => items.refetch()}>{t('common.retry')}</Button>} /> : null}
      <DataTable
        caption={t('catalogue.items.title')}
        columns={columns}
        rows={rows}
        onRowClick={(item) => navigate(`/catalogue/items/${item.id}`)}
        emptyText={items.isPending ? t('common.loading') : filtered ? t('items.emptyFiltered') : t(`items.empty.${status}`)}
      />
      <Pager page={page} lastPage={items.data?.meta?.last_page ?? 1} onPage={setPage} label={t('items.pages')} />
    </>
  )
}
