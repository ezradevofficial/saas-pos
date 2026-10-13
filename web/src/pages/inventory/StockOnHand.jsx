import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DecimalInput, Dialog, ListView, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { useItemCategories } from '@/pages/catalogue/catalogueData'
import { Amount, ItemPicker, LowBadge, Qty } from './InventoryParts'
import { parseSerials, useUnitOptions } from './inventoryHelpers'
import { inventoryKey, locationOptions, OPENING_CREATE, placeFilterFields, useItemStock, usePlaces } from './inventoryData'

const OPENING_FIELDS = ['item_id', 'location_id', 'uom_id', 'qty', 'unit_cost', 'batch_number', 'expires_on', 'serials']
const EMPTY = { item: null, location_id: '', uom_id: '', qty: '', unit_cost: '', batch_number: '', expires_on: '', serials: '' }

/**
 * INV-18: opening stock, one line at a time: item, location, unit (the
 * item's), quantity, unit cost (major units of the company's base
 * currency), and the batch, expiry and serials the item's tracking asks
 * for. `POST inventory/opening-stock`.
 */
export function OpeningStockDialog({ open, onClose, locations }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState(EMPTY)
  const [submitted, setSubmitted] = useState(false)
  const stock = useItemStock(values.item?.id)
  const tracking = stock.data?.data?.settings?.tracking ?? 'none'
  const trackExpiry = Boolean(stock.data?.data?.settings?.track_expiry)
  const units = useUnitOptions(values.item, stock.data?.data?.item?.base_uom)
  const set = (name) => (value) => setValues((current) => ({ ...current, [name]: value }))

  const save = useMutation({
    mutationFn: () =>
      api.post('inventory/opening-stock', {
        item_id: values.item?.id,
        location_id: values.location_id,
        ...(values.uom_id ? { uom_id: values.uom_id } : {}),
        qty: values.qty,
        unit_cost: values.unit_cost,
        ...(tracking === 'batch' ? { batch_number: values.batch_number.trim(), ...(values.expires_on ? { expires_on: values.expires_on } : {}) } : {}),
        ...(tracking === 'serial' ? { serials: parseSerials(values.serials) } : {}),
      }),
    onSuccess: async () => {
      toast.success(t('inventory.opening.saved'))
      setValues(EMPTY)
      setSubmitted(false)
      onClose()
      await queryClient.invalidateQueries({ queryKey: inventoryKey })
    },
  })
  const errors = formErrors(save.error, OPENING_FIELDS)
  const error = (name) => errors.fields[name]
  const ready = values.item && values.location_id && values.qty && values.unit_cost !== '' && values.unit_cost !== null
  const close = () => {
    save.reset()
    setSubmitted(false)
    onClose()
  }

  return (
    <Dialog
      open={open}
      size="lg"
      title={t('inventory.opening.title')}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="primary"
            loading={save.isPending}
            onClick={() => {
              setSubmitted(true)
              if (ready) save.mutate()
            }}
          >
            {t('inventory.opening.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('inventory.opening.help')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <ItemPicker
            label={t('inventory.fields.item')}
            value={values.item}
            required
            error={error('item_id') ?? (submitted && !values.item ? t('inventory.validation.item') : undefined)}
            onChange={(item) => setValues((current) => ({ ...current, item, uom_id: item?.base_uom_id ?? '' }))}
          />
          <Select
            label={t('inventory.fields.location')}
            placeholder={t('inventory.pickers.location')}
            options={locationOptions(locations)}
            value={values.location_id}
            required
            error={error('location_id') ?? (submitted && !values.location_id ? t('inventory.validation.location') : undefined)}
            onChange={(event) => set('location_id')(event.target.value)}
          />
          <Select label={t('inventory.fields.unit')} options={units} value={values.uom_id} disabled={!values.item} error={error('uom_id')} onChange={(event) => set('uom_id')(event.target.value)} />
          <DecimalInput
            label={t('inventory.fields.qty')}
            value={values.qty}
            required
            showErrors={submitted}
            error={error('qty') ?? (submitted && !values.qty ? t('inventory.validation.qty') : undefined)}
            onChange={(value) => set('qty')(value ?? '')}
          />
          <DecimalInput
            label={t('inventory.fields.unitCost')}
            help={t('inventory.fields.unitCostHelp')}
            value={values.unit_cost}
            positive={false}
            required
            showErrors={submitted}
            error={error('unit_cost') ?? (submitted && values.unit_cost === '' ? t('inventory.validation.unitCost') : undefined)}
            onChange={(value) => set('unit_cost')(value ?? '')}
          />
          {tracking === 'batch' ? (
            <>
              <TextField label={t('inventory.fields.batchNumber')} value={values.batch_number} maxLength={60} required error={error('batch_number')} onChange={(event) => set('batch_number')(event.target.value)} />
              <TextField
                type="date"
                label={t('inventory.fields.expiresOn')}
                value={values.expires_on}
                required={trackExpiry}
                error={error('expires_on')}
                onChange={(event) => set('expires_on')(event.target.value)}
              />
            </>
          ) : null}
          {tracking === 'serial' ? (
            <TextField
              label={t('inventory.fields.serials')}
              help={t('inventory.fields.serialsHelp')}
              value={values.serials}
              required
              error={error('serials')}
              onChange={(event) => set('serials')(event.target.value)}
              className="sm:col-span-2"
            />
          ) : null}
        </div>
      </div>
    </Dialog>
  )
}

/**
 * INV-05, INV-11, INV-19: stock on hand, one row per item and location the
 * user sees (RBAC-04), with search, place, category and "low only"
 * filters, sort and export (EXP-01). Values show only to valuation holders
 * (RBAC-05). A row opens the item's stock detail.
 */
export default function StockOnHand() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const places = usePlaces()
  const categories = useItemCategories()
  const [opening, setOpening] = useState(false)

  const columns = [
    { key: 'code', label: t('inventory.columns.code'), sortKey: 'code', render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('inventory.columns.item'), sortKey: 'item', hideable: false, render: (row) => <span className="font-medium text-ink">{row.item?.name}</span> },
    { key: 'location', label: t('inventory.columns.location'), render: (row) => row.location?.name ?? '' },
    { key: 'qty', label: t('inventory.columns.qty'), sortKey: 'qty', align: 'end', render: (row) => <Qty value={row.qty} /> },
    { key: 'unit', label: t('inventory.columns.unit'), render: (row) => row.base_uom?.code ?? '' },
    { key: 'reorder_level', label: t('inventory.columns.reorderLevel'), align: 'end', render: (row) => <Qty value={row.reorder_level} /> },
    { key: 'low', label: t('inventory.columns.low'), render: (row) => <LowBadge low={row.low} /> },
    { key: 'value', label: t('inventory.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
  ]

  const list = useServerList({
    id: 'inventory-stock',
    endpoint: 'inventory/stock',
    queryKey: [...inventoryKey, 'stock'],
    filters: { company: '', branch: '', location: '', category: '', flag: '' },
    defaultSort: 'code',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('inventory.stock.title')}
        description={t('inventory.stock.description')}
        actions={
          can(OPENING_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => setOpening(true)}>
              {t('inventory.stock.addOpening')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('inventory.stock.title')}
        searchLabel={t('inventory.stock.search')}
        filterFields={[
          ...placeFilterFields(t, places),
          ...(categories.all.length
            ? [{ name: 'category', label: t('inventory.filters.category'), options: [{ value: '', label: t('inventory.filters.all.category') }, ...categories.all.map((category) => ({ value: category.id, label: category.name }))] }]
            : []),
          {
            name: 'flag',
            label: t('inventory.filters.low'),
            options: [
              { value: '', label: t('inventory.filters.all.low') },
              { value: 'low', label: t('inventory.filters.lowOnly') },
            ],
          },
        ]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('inventory.stock.emptyFiltered') : t('inventory.stock.empty')}
        onRowClick={(row) => navigate(`/inventory/items/${row.item?.id}`)}
      />
      {can(OPENING_CREATE) ? <OpeningStockDialog open={opening} onClose={() => setOpening(false)} locations={places.activeLocations} /> : null}
    </>
  )
}
