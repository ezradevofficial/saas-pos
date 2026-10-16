import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, DataTable, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { placeFilterFields, usePlaces } from '@/pages/inventory/inventoryData'
import { Amount, Qty } from './ManufacturingParts'
import { BACKFLUSH_KINDS, kindFilter, kindLabel, manufacturingKey } from './manufacturingData'

/** MFG-07 to MFG-09: why a sold line was not backflushed. */
const skipLabel = (t, code) => t(`manufacturing.skips.${code}`, { defaultValue: t('manufacturing.skips.other') })

/** One backflush's ingredients (quantity in the base unit, value when shown) and the sold lines it skipped. */
function BackflushLines({ backflush }) {
  const { t } = useTranslation()
  const columns = [
    { key: 'sold', label: t('manufacturing.columns.menuItem'), render: (row) => row.sold_item?.name ?? '' },
    {
      key: 'item',
      label: t('manufacturing.columns.ingredient'),
      wrap: true,
      render: (row) => (
        <span className="flex flex-col">
          <span className="text-ink">{row.item?.name}</span>
          <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span>
        </span>
      ),
    },
    { key: 'qty', label: t('manufacturing.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty_base} /> },
    { key: 'returned', label: t('manufacturing.columns.returned'), align: 'end', render: (row) => <Qty value={row.qty_returned_base} /> },
    { key: 'value', label: t('manufacturing.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
  ]
  const skipped = backflush.skipped ?? []
  return (
    <Card title={t('manufacturing.backflushes.linesOf', { reference: backflush.reference ?? '' })}>
      <div className="flex flex-col gap-4">
        <DataTable caption={t('manufacturing.backflushes.lines')} columns={columns} rows={backflush.lines ?? []} emptyText={t('manufacturing.backflushes.noLines')} />
        {skipped.length ? (
          <section aria-label={t('manufacturing.backflushes.skipped')} className="flex flex-col gap-2">
            <h3 className="text-h3 text-ink">{t('manufacturing.backflushes.skipped')}</h3>
            <ul className="flex flex-col gap-1">
              {skipped.map((entry, index) => (
                <li key={`${entry.sale_line_id}-${index}`} className="text-body text-ink">
                  {skipLabel(t, entry.code)}
                </li>
              ))}
            </ul>
          </section>
        ) : null}
      </div>
    </Card>
  )
}

/**
 * MFG-07 to MFG-09, MFG-27: what each sale's recipes took from stock (or
 * its void or refund gave back): date, receipt, kind, location, menu
 * items, ingredients and value; a row opens its lines and the sold lines
 * skipped and why. Kind, location and date filters, sort and export.
 */
export default function Backflushes() {
  const { t } = useTranslation()
  const locale = useLocale()
  const places = usePlaces()
  const companyOf = useCompanyOfRecord()
  const [selected, setSelected] = useState(null)

  const columns = [
    { key: 'occurred_at', label: t('manufacturing.columns.date'), sortKey: 'occurred_at', hideable: false, render: (row) => <span className="tabular-nums">{formatCompanyTime(row.occurred_at, locale, companyOf(null))}</span> },
    { key: 'reference', label: t('manufacturing.columns.receipt'), sortKey: 'reference', render: (row) => <span className="font-medium tabular-nums">{row.reference ?? ''}</span> },
    { key: 'kind', label: t('manufacturing.columns.kind'), render: (row) => kindLabel(t, row.kind) },
    { key: 'location', label: t('manufacturing.columns.location'), render: (row) => row.location?.name ?? '' },
    { key: 'menu_items', label: t('manufacturing.columns.menuItems'), wrap: true, render: (row) => (row.menu_items ?? []).map((item) => item?.name).filter(Boolean).join(', ') },
    {
      key: 'ingredients',
      label: t('manufacturing.columns.ingredients'),
      align: 'end',
      numeric: true,
      render: (row) => (
        <span className="flex flex-col items-end">
          <span>{(row.lines ?? []).length}</span>
          {row.skipped?.length ? <span className="text-caption text-ink-muted">{t('manufacturing.backflushes.skippedCount', { count: row.skipped.length })}</span> : null}
        </span>
      ),
    },
    { key: 'value', label: t('manufacturing.columns.value'), align: 'end', render: (row) => (row.valued ? <Amount value={row.value} /> : <span className="text-ink-muted">{t('manufacturing.notValued')}</span>) },
  ]

  const list = useServerList({
    id: 'manufacturing-backflushes',
    endpoint: 'manufacturing/backflushes',
    queryKey: [...manufacturingKey, 'backflushes'],
    filters: { kind: '', location: '', from: '', to: '' },
    defaultSort: '-occurred_at',
    columns,
  })
  const current = selected ? list.rows.find((row) => row.id === selected) : null

  return (
    <>
      <PageHeader title={t('manufacturing.backflushes.title')} description={t('manufacturing.backflushes.description')} />
      <ListView
        list={list}
        title={t('manufacturing.backflushes.title')}
        searchLabel={t('manufacturing.backflushes.search')}
        filterFields={[kindFilter(t, BACKFLUSH_KINDS), ...placeFilterFields(t, places, ['location']), dateField('from', t('manufacturing.filters.from')), dateField('to', t('manufacturing.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('manufacturing.backflushes.emptyFiltered') : t('manufacturing.backflushes.empty')}
        selectedId={selected}
        onRowClick={(row) => setSelected((id) => (id === row.id ? null : row.id))}
      />
      {current ? <BackflushLines backflush={current} /> : null}
    </>
  )
}
