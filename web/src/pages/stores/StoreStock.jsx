import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DecimalInput, ListView, Select } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { negate } from '@/pages/inventory/inventoryData'
import { useUnitOptions } from '@/pages/inventory/inventoryHelpers'
import { ItemPicker, Qty } from './StoresParts'
import { ENTRY_REASONS, STOCK_ADJUST, storeFilter, storeOptions, storesKey, useStorePlaces, useStoresStatus } from './storesData'

/**
 * STR-07: one entry into a store's own ledger: opening stock, or an
 * adjustment up or down with a reason (never below zero).
 */
function StockEntry({ stores, onDone }) {
  const { t } = useTranslation()
  const [store, setStore] = useState('')
  const [kind, setKind] = useState('opening')
  const [direction, setDirection] = useState('in')
  const [reason, setReason] = useState('')
  const [item, setItem] = useState(null)
  const [uom, setUom] = useState('')
  const [qty, setQty] = useState('')
  const units = useUnitOptions(item)
  const save = useMutation({
    mutationFn: () =>
      api.post('stores/stock-entries', {
        location_id: store,
        kind,
        ...(kind === 'adjustment' ? { reason } : {}),
        lines: [{ item_id: item.id, ...(uom ? { uom_id: uom } : {}), qty: kind === 'adjustment' && direction === 'out' ? negate(qty) : qty }],
      }),
    onSuccess: async () => {
      toast.success(t('stores.stock.entered'))
      setQty('')
      await onDone()
    },
  })
  const errors = formErrors(save.error, ['location_id', 'kind', 'reason', 'lines', 'lines.0.item_id', 'lines.0.uom_id', 'lines.0.qty'])
  const ready = store && item && qty && (kind === 'opening' || reason)

  return (
    <Card title={t('stores.stock.entryTitle')} subtitle={t('stores.stock.entryHelp')}>
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Select label={t('stores.fields.store')} placeholder={t('stores.pickers.store')} options={storeOptions(stores)} value={store} required error={errors.fields.location_id} onChange={(event) => setStore(event.target.value)} />
          <Select
            label={t('stores.fields.entryKind')}
            options={['opening', 'adjustment'].map((value) => ({ value, label: t(`stores.entryKinds.${value}`) }))}
            value={kind}
            onChange={(event) => setKind(event.target.value)}
          />
          {kind === 'adjustment' ? (
            <>
              <Select
                label={t('stores.fields.direction')}
                options={['in', 'out'].map((value) => ({ value, label: t(`stores.directions.${value}`) }))}
                value={direction}
                onChange={(event) => setDirection(event.target.value)}
              />
              <Select
                label={t('stores.fields.reason')}
                placeholder={t('stores.pickers.reason')}
                options={ENTRY_REASONS.map((value) => ({ value, label: t(`stores.entryReasons.${value}`) }))}
                value={reason}
                required
                error={errors.fields.reason}
                onChange={(event) => setReason(event.target.value)}
              />
            </>
          ) : null}
          <ItemPicker
            label={t('stores.fields.item')}
            placeholder={t('stores.pickers.item')}
            value={item}
            required
            error={errors.fields['lines.0.item_id']}
            onChange={(next) => {
              setItem(next)
              setUom(next?.base_uom_id ?? '')
            }}
            className="sm:col-span-2"
          />
          <Select label={t('stores.fields.unit')} options={units} value={uom} disabled={!item} error={errors.fields['lines.0.uom_id']} onChange={(event) => setUom(event.target.value)} />
          <DecimalInput label={t('stores.fields.qty')} value={qty} required error={errors.fields['lines.0.qty']} onChange={(value) => setQty(value ?? '')} />
        </div>
        <div>
          <Button variant="primary" loading={save.isPending} disabled={!ready} onClick={() => save.mutate()}>
            {t('stores.stock.enter')}
          </Button>
        </div>
      </div>
    </Card>
  )
}

/**
 * STR-11: what each store holds of each item in its own ledger (Inventory
 * off), with store filter, search and export, and the entry form for
 * holders of stores.stock.adjust. With Inventory on, Inventory holds the
 * stores' stock and this page says so.
 */
export default function StoreStock() {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const companyOf = useCompanyOfRecord()
  const places = useStorePlaces()
  const status = useStoresStatus()
  const { can } = usePermissions()

  const columns = [
    { key: 'code', label: t('stores.columns.code'), render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('stores.columns.item'), sortKey: 'item', hideable: false, render: (row) => <span className="font-medium text-ink">{row.item?.name}</span> },
    { key: 'location', label: t('stores.columns.store'), render: (row) => row.location?.name ?? '' },
    { key: 'qty', label: t('stores.columns.onHand'), sortKey: 'qty', align: 'end', render: (row) => <Qty value={row.qty} unit={row.unit?.code} /> },
    { key: 'last_moved_at', label: t('stores.columns.lastMoved'), render: (row) => <span className="tabular-nums">{formatCompanyTime(row.last_moved_at, locale, companyOf(row.company?.id))}</span> },
  ]

  const list = useServerList({
    id: 'stores-stock',
    endpoint: 'stores/stock',
    queryKey: [...storesKey, 'stock'],
    filters: { location: '' },
    defaultSort: 'item',
    columns,
  })

  return (
    <>
      <PageHeader title={t('stores.stock.title')} description={t('stores.stock.description')} />
      {status?.inventory_active ? <Alert tone="info" title={t('stores.stock.inventoryTitle')}>{t('stores.stock.inventoryHelp')}</Alert> : null}
      {status && !status.inventory_active && can(STOCK_ADJUST) ? <StockEntry stores={places.activeStores} onDone={() => queryClient.invalidateQueries({ queryKey: storesKey })} /> : null}
      <ListView
        list={list}
        title={t('stores.stock.title')}
        searchLabel={t('stores.stock.search')}
        filterFields={storeFilter(t, places.stores)}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('stores.stock.emptyFiltered') : t('stores.stock.empty')}
      />
    </>
  )
}
