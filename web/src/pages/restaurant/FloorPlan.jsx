import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, DataTable, Dialog, Select, StatusBadge, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { cn } from '@/lib/utils'
import { ConfirmDialog, LocationPicker } from './RestaurantParts'
import { FLOOR_MANAGE, MANUAL_STATUSES, restaurantKey, STATUS_TONES, statusLabel, TABLE_SHAPES, toInt, useCanAt, useLocationChoice } from './restaurantData'

const SHAPE_CLASS = { square: 'rounded-md', rect: 'rounded-md', round: 'rounded-pill' }

/** REST-02: add or rename an area. */
function AreaDialog({ area, location, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [name, setName] = useState(area?.name ?? '')
  const save = useMutation({
    mutationFn: () => (area?.id ? api.put(`restaurant/areas/${area.id}`, { location_id: location.id, name: name.trim(), sort: area.sort ?? 0 }) : api.post('restaurant/areas', { location_id: location.id, name: name.trim(), sort: 0 })),
    onSuccess: () => {
      toast.success(t('restaurant.floor.areaSaved'))
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['name'])
  return (
    <Dialog
      open
      title={area?.id ? t('restaurant.floor.renameArea') : t('restaurant.floor.addArea')}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!name.trim()} onClick={() => save.mutate()}>
            {area?.id ? t('restaurant.floor.saveArea') : t('restaurant.floor.addArea')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <TextField label={t('restaurant.fields.name')} value={name} error={errors.fields.name} onChange={(event) => setName(event.target.value)} />
      </div>
    </Dialog>
  )
}

const NUMBER_FIELDS = [
  ['x', 'x', 0],
  ['y', 'y', 0],
  ['width', 'width', 1],
  ['height', 'height', 1],
]

/** REST-02: add or edit a table, with its place on the 100 by 100 plan and its manual status. */
function TableDialog({ table, area, areas, location, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [form, setForm] = useState({
    area_id: table?.area_id ?? area?.id ?? '',
    name: table?.name ?? '',
    seats: String(table?.seats ?? 4),
    x: String(table?.x ?? 0),
    y: String(table?.y ?? 0),
    width: String(table?.width ?? 10),
    height: String(table?.height ?? 10),
    shape: table?.shape ?? 'square',
    status: table?.manual_status ?? 'free',
  })
  const set = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value }))
  const numbers = Object.fromEntries(NUMBER_FIELDS.map(([field]) => [field, toInt(form[field])]))
  const bad = (field, min) => numbers[field] === null || numbers[field] < min || numbers[field] > 99
  const seats = toInt(form.seats)
  const invalid = !form.name.trim() || !form.area_id || seats === null || seats < 1 || NUMBER_FIELDS.some(([field, , min]) => bad(field, min))
  const save = useMutation({
    mutationFn: async () => {
      const body = { area_id: form.area_id, name: form.name.trim(), seats, x: numbers.x, y: numbers.y, width: numbers.width, height: numbers.height, shape: form.shape }
      const saved = table?.id ? await api.put(`restaurant/tables/${table.id}`, body) : await api.post('restaurant/tables', { location_id: location.id, ...body })
      const id = table?.id ?? saved?.data?.id
      if (id && form.status !== (table?.manual_status ?? 'free')) await api.post(`restaurant/tables/${id}/status`, { status: form.status })
      return saved
    },
    onSuccess: () => {
      toast.success(t('restaurant.floor.tableSaved'))
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['name', 'seats', 'x', 'y', 'width', 'height', 'shape', 'area_id'])
  const numberField = ([field, label, min]) => (
    <TextField
      key={field}
      label={t(`restaurant.floor.${label}`)}
      type="number"
      inputMode="numeric"
      min={min}
      max={99}
      value={form[field]}
      error={errors.fields[field] ?? (bad(field, min) ? t('restaurant.floor.gridRange', { min }) : undefined)}
      onChange={set(field)}
    />
  )
  return (
    <Dialog
      open
      size="lg"
      title={table?.id ? t('restaurant.floor.editTable') : t('restaurant.floor.addTable')}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={invalid} onClick={() => save.mutate()}>
            {t('restaurant.floor.saveTable')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('restaurant.fields.name')} value={form.name} error={errors.fields.name} onChange={set('name')} />
          <Select label={t('restaurant.floor.area')} options={areas.map((entry) => ({ value: entry.id, label: entry.name }))} value={form.area_id} error={errors.fields.area_id} onChange={set('area_id')} />
          <TextField label={t('restaurant.floor.seats')} type="number" inputMode="numeric" min={1} value={form.seats} error={errors.fields.seats} onChange={set('seats')} />
          <Select label={t('restaurant.floor.shape')} options={TABLE_SHAPES.map((shape) => ({ value: shape, label: t(`restaurant.floor.shapes.${shape}`) }))} value={form.shape} onChange={set('shape')} />
          {NUMBER_FIELDS.map(numberField)}
          <Select label={t('restaurant.floor.manualStatus')} options={MANUAL_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))} value={form.status} onChange={set('status')} />
        </div>
        <p className="text-caption text-ink-muted">{t('restaurant.floor.gridHelp', { location: location.name })}</p>
      </div>
    </Dialog>
  )
}

/** The plan: each table placed on a 100 by 100 grid. Only the placement is inline (runtime values from the table). */
function Plan({ tables, onPick, t }) {
  return (
    <div className="relative aspect-video w-full overflow-hidden rounded-lg border border-border bg-surface-200" role="group" aria-label={t('restaurant.floor.plan')}>
      {tables.map((table) => (
        <button
          key={table.id}
          type="button"
          onClick={() => onPick(table)}
          aria-label={t('restaurant.floor.tableLabel', { name: table.name, seats: table.seats })}
          style={{ left: `${table.x}%`, top: `${table.y}%`, width: `${table.width}%`, height: `${table.height}%` }}
          className={cn(
            'absolute flex flex-col items-center justify-center gap-1 overflow-hidden border border-border-strong bg-surface-100 p-1 text-center text-caption text-ink hover:bg-surface-300',
            SHAPE_CLASS[table.shape] ?? 'rounded-md',
          )}
        >
          <span className="max-w-full truncate font-medium">{table.name}</span>
          <StatusBadge tone={STATUS_TONES[table.status] ?? 'neutral'}>{statusLabel(t, table.status)}</StatusBadge>
        </button>
      ))}
    </div>
  )
}

/**
 * REST-02: per location, the areas and tables of the floor, a visual plan of
 * the chosen area, and the manual status of each table. Setting up needs
 * restaurant.floor.manage at the location.
 */
export default function FloorPlan() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const places = useLocationChoice()
  const { location, isPending: placesPending } = places
  const canManage = useCanAt(location)(FLOOR_MANAGE)
  const [areaId, setAreaId] = useState('')
  const [dialog, setDialog] = useState(null)
  const areasQuery = useQuery({ queryKey: [...restaurantKey, 'areas', location?.id], queryFn: () => api.get(`restaurant/areas?location_id=${location.id}`), enabled: Boolean(location) })
  const tablesQuery = useQuery({ queryKey: [...restaurantKey, 'tables', location?.id], queryFn: () => api.get(`restaurant/tables?location_id=${location.id}`), enabled: Boolean(location) })
  const areas = (areasQuery.data?.data ?? []).filter((entry) => !entry.archived_at)
  const allTables = (tablesQuery.data?.data ?? []).filter((entry) => !entry.archived_at)
  const area = areas.find((entry) => entry.id === areaId) ?? areas[0] ?? null
  const tables = allTables.filter((entry) => entry.area_id === area?.id)
  const archive = useMutation({
    mutationFn: ({ kind, id }) => api.post(`restaurant/${kind}/${id}/archive`),
    onSuccess: () => {
      toast.success(t('restaurant.floor.archived'))
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      setDialog(null)
    },
  })
  const failure = areasQuery.error ?? tablesQuery.error
  const columns = [
    { key: 'name', label: t('restaurant.fields.name'), render: (row) => row.name },
    { key: 'seats', label: t('restaurant.floor.seats'), numeric: true, render: (row) => row.seats },
    { key: 'status', label: t('restaurant.fields.status'), render: (row) => <StatusBadge tone={STATUS_TONES[row.status] ?? 'neutral'}>{statusLabel(t, row.status)}</StatusBadge> },
    {
      key: 'order',
      label: t('restaurant.floor.openOrder'),
      render: (row) => (row.order ? `${row.order.waiter_name ?? ''} · ${t('restaurant.floor.covers', { count: row.order.covers })}` : ''),
    },
    {
      key: 'actions',
      label: <span className="sr-only">{t('restaurant.fields.actions')}</span>,
      align: 'end',
      render: (row) =>
        canManage ? (
          <div className="flex justify-end gap-2">
            <Button onClick={() => setDialog({ type: 'table', table: row })}>{t('restaurant.floor.editTable')}</Button>
            <Button variant="ghost" onClick={() => setDialog({ type: 'archiveTable', table: row })}>
              {t('restaurant.floor.archiveTable')}
            </Button>
          </div>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title={t('restaurant.floor.title')}
        description={t('restaurant.floor.description')}
        actions={
          canManage ? (
            <>
              <Button onClick={() => setDialog({ type: 'area' })}>{t('restaurant.floor.addArea')}</Button>
              <Button variant="primary" disabled={!area} onClick={() => setDialog({ type: 'table' })}>
                {t('restaurant.floor.addTable')}
              </Button>
            </>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <LocationPicker choice={places} />
        {placesPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {!placesPending && !location ? <p className="text-ink-muted">{t('restaurant.noLocation')}</p> : null}
        {failure ? <Alert tone="danger" title={errorMessage(failure)} /> : null}
        {location && (areasQuery.isPending || tablesQuery.isPending) && !failure ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {location && areasQuery.isSuccess && !areas.length ? <p className="text-ink-muted">{t('restaurant.floor.noAreas')}</p> : null}
        {area ? (
          <>
            <div className="flex flex-wrap items-end justify-between gap-3">
              <Tabs items={areas.map((entry) => ({ value: entry.id, label: entry.name }))} value={area.id} onChange={setAreaId} className="min-w-0 flex-1" />
              {canManage ? (
                <div className="flex gap-2">
                  <Button onClick={() => setDialog({ type: 'area', area })}>{t('restaurant.floor.renameArea')}</Button>
                  <Button variant="ghost" onClick={() => setDialog({ type: 'archiveArea', area })}>
                    {t('restaurant.floor.archiveArea')}
                  </Button>
                </div>
              ) : null}
            </div>
            <Plan tables={tables} t={t} onPick={(table) => (canManage ? setDialog({ type: 'table', table }) : null)} />
            <DataTable caption={t('restaurant.floor.tables')} columns={columns} rows={tables} emptyText={t('restaurant.floor.noTables')} />
          </>
        ) : null}
      </div>

      {dialog?.type === 'area' ? <AreaDialog key={dialog.area?.id ?? 'new'} area={dialog.area} location={location} onClose={() => setDialog(null)} /> : null}
      {dialog?.type === 'table' ? <TableDialog key={dialog.table?.id ?? 'new'} table={dialog.table} area={area} areas={areas} location={location} onClose={() => setDialog(null)} /> : null}
      <ConfirmDialog
        open={dialog?.type === 'archiveArea' || dialog?.type === 'archiveTable'}
        title={dialog?.type === 'archiveArea' ? t('restaurant.floor.archiveArea') : t('restaurant.floor.archiveTable')}
        message={t(dialog?.type === 'archiveArea' ? 'restaurant.floor.archiveAreaAsk' : 'restaurant.floor.archiveTableAsk', { name: dialog?.area?.name ?? dialog?.table?.name ?? '' })}
        confirmLabel={dialog?.type === 'archiveArea' ? t('restaurant.floor.archiveArea') : t('restaurant.floor.archiveTable')}
        loading={archive.isPending}
        error={archive.error ? errorMessage(archive.error) : null}
        onClose={() => setDialog(null)}
        onConfirm={() => archive.mutate(dialog.type === 'archiveArea' ? { kind: 'areas', id: dialog.area.id } : { kind: 'tables', id: dialog.table.id })}
      />
    </>
  )
}
