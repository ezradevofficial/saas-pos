import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DecimalInput, Dialog, ListView, Select } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { ItemPicker, LowBadge, Qty } from './InventoryParts'
import { inventoryKey, locationOptions, placeFilterFields, REORDER_EDIT, usePlaces } from './inventoryData'

const FIELDS = ['item_id', 'location_id', 'reorder_level', 'reorder_qty']

/**
 * INV-11: set an item's reorder level and quantity at a location
 * (`PUT inventory/reorder-rules`); an empty level removes the rule (it is
 * archived, TEN-06). `rule` prefills an existing rule.
 */
export function ReorderDialog({ open, rule, locations, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [item, setItem] = useState(rule?.item ?? null)
  const [location, setLocation] = useState(rule?.location?.id ?? '')
  const [level, setLevel] = useState(rule && !rule.archived_at ? (rule.reorder_level ?? '') : '')
  const [qty, setQty] = useState(rule?.reorder_qty ?? '')
  const [submitted, setSubmitted] = useState(false)
  const save = useMutation({
    mutationFn: () => api.put('inventory/reorder-rules', { item_id: item.id, location_id: location, reorder_level: level === '' ? null : level, reorder_qty: qty === '' ? null : qty }),
    onSuccess: async () => {
      toast.success(level === '' ? t('inventory.reorder.removed') : t('inventory.reorder.saved'))
      onClose()
      await queryClient.invalidateQueries({ queryKey: inventoryKey })
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const ready = item && location && level !== null && qty !== null

  return (
    <Dialog
      open={open}
      title={rule ? t('inventory.reorder.editTitle') : t('inventory.reorder.newTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
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
            {level === '' && rule && !rule.archived_at ? t('inventory.reorder.remove') : t('inventory.reorder.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <ItemPicker
          label={t('inventory.fields.item')}
          value={item}
          required
          disabled={Boolean(rule)}
          error={errors.fields.item_id ?? (submitted && !item ? t('inventory.validation.item') : undefined)}
          onChange={setItem}
        />
        <Select
          label={t('inventory.fields.location')}
          placeholder={t('inventory.pickers.location')}
          options={locationOptions(rule?.location && !locations.some((entry) => entry.id === rule.location.id) ? [rule.location, ...locations] : locations)}
          value={location}
          required
          disabled={Boolean(rule)}
          error={errors.fields.location_id ?? (submitted && !location ? t('inventory.validation.location') : undefined)}
          onChange={(event) => setLocation(event.target.value)}
        />
        <DecimalInput
          label={t('inventory.fields.reorderLevel')}
          help={t('inventory.reorder.levelHelp')}
          value={level}
          positive={false}
          showErrors={submitted}
          error={errors.fields.reorder_level}
          onChange={(value) => setLevel(value)}
        />
        <DecimalInput
          label={t('inventory.fields.reorderQty')}
          help={t('inventory.reorder.qtyHelp')}
          value={qty}
          positive={false}
          showErrors={submitted}
          error={errors.fields.reorder_qty}
          onChange={(value) => setQty(value)}
        />
      </div>
    </Dialog>
  )
}

/**
 * INV-11, INV-19: reorder rules at the places the user sees, with the
 * stock there now and whether it is low; active, archived or all; "low
 * only"; export. Holders of inventory.reorder.edit add and change rules.
 */
export default function ReorderRules() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const places = usePlaces()
  const [editing, setEditing] = useState(null) // null | 'new' | rule
  const canEdit = can(REORDER_EDIT)

  const columns = [
    { key: 'code', label: t('inventory.columns.code'), sortKey: 'code', render: (row) => <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span> },
    { key: 'item', label: t('inventory.columns.item'), sortKey: 'item', hideable: false, render: (row) => <span className="font-medium text-ink">{row.item?.name}</span> },
    { key: 'location', label: t('inventory.columns.location'), render: (row) => row.location?.name ?? '' },
    { key: 'reorder_level', label: t('inventory.columns.reorderLevel'), sortKey: 'reorder_level', align: 'end', render: (row) => <Qty value={row.reorder_level} unit={row.base_uom?.code} /> },
    { key: 'reorder_qty', label: t('inventory.columns.reorderQty'), align: 'end', render: (row) => <Qty value={row.reorder_qty} unit={row.base_uom?.code} /> },
    { key: 'qty', label: t('inventory.columns.onHand'), align: 'end', render: (row) => <Qty value={row.on_hand} unit={row.base_uom?.code} /> },
    { key: 'low', label: t('inventory.columns.low'), render: (row) => (row.archived_at ? <span className="text-caption text-ink-muted">{t('inventory.reorder.archived')}</span> : <LowBadge low={row.low} />) },
    {
      key: 'low_since',
      label: t('inventory.columns.lowSince'),
      sortKey: 'low_since',
      defaultHidden: true,
      render: (row) => (row.low_since ? <span className="tabular-nums">{formatCompanyTime(row.low_since, locale, companyOf(row.company_id))}</span> : ''),
    },
  ]

  const list = useServerList({
    id: 'inventory-reorder-rules',
    endpoint: 'inventory/reorder-rules',
    queryKey: [...inventoryKey, 'reorder-rules'],
    filters: { status: '', flag: '', location: '' },
    defaultSort: 'code',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('inventory.reorder.title')}
        description={t('inventory.reorder.description')}
        actions={
          canEdit ? (
            <Button variant="primary" icon="plus" onClick={() => setEditing('new')}>
              {t('inventory.reorder.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('inventory.reorder.title')}
        searchLabel={t('inventory.reorder.search')}
        filterFields={[
          {
            name: 'status',
            label: t('inventory.filters.status'),
            options: [
              { value: '', label: t('inventory.reorder.statuses.active') },
              { value: 'archived', label: t('inventory.reorder.statuses.archived') },
              { value: 'all', label: t('inventory.reorder.statuses.all') },
            ],
          },
          {
            name: 'flag',
            label: t('inventory.filters.low'),
            options: [
              { value: '', label: t('inventory.filters.all.low') },
              { value: 'low', label: t('inventory.filters.lowOnly') },
            ],
          },
          ...placeFilterFields(t, places, ['location']),
        ]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('inventory.reorder.emptyFiltered') : t('inventory.reorder.empty')}
        onRowClick={canEdit ? (row) => setEditing(row) : undefined}
      />
      {canEdit && editing ? (
        <ReorderDialog key={editing === 'new' ? 'new' : editing.id} open rule={editing === 'new' ? null : editing} locations={places.activeLocations} onClose={() => setEditing(null)} />
      ) : null}
    </>
  )
}
