import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { BranchSwitch, Day, NoProperty, StateBadge } from './HotelParts'
import { hotelKey, ROOM_MANAGE, ROOM_STATUSES, useBranchChoice } from './hotelData'

const TYPE_BLANK = { code: '', name: '', description: '', base_adults: '2', max_adults: '2', max_children: '0', max_occupancy: '2', beds: '', sort: '0' }
const TYPE_FIELDS = Object.keys(TYPE_BLANK)

/** HOT-03: add or change a room type. */
function RoomTypeDialog({ type, companyId, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(() => (type ? Object.fromEntries(TYPE_FIELDS.map((field) => [field, String(type[field] ?? '')])) : TYPE_BLANK))
  const set = (name) => (event) => setForm((current) => ({ ...current, [name]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const body = { ...form, base_adults: Number(form.base_adults), max_adults: Number(form.max_adults), max_children: Number(form.max_children), max_occupancy: Number(form.max_occupancy), sort: Number(form.sort), beds: form.beds.trim() || null, description: form.description.trim() || null }
      if (type) {
        const { code: _code, ...rest } = body
        return api.patch(`hotel/room-types/${type.id}`, rest)
      }
      return api.post('hotel/room-types', { ...body, company_id: companyId })
    },
    onSuccess: async () => {
      toast.success(t('hotel.rooms.typeSaved'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, TYPE_FIELDS)
  return (
    <Dialog
      open
      size="lg"
      title={type ? t('hotel.rooms.editType') : t('hotel.rooms.newType')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!form.name.trim() || (!type && !form.code.trim())} onClick={() => save.mutate()}>
            {t('hotel.rooms.saveType')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField label={t('hotel.columns.code')} value={form.code} maxLength={20} required disabled={Boolean(type)} error={errors.fields.code} onChange={set('code')} />
          <TextField label={t('hotel.columns.name')} value={form.name} maxLength={100} required error={errors.fields.name} onChange={set('name')} />
          <TextField label={t('hotel.rooms.baseAdults')} inputMode="numeric" value={form.base_adults} error={errors.fields.base_adults} onChange={set('base_adults')} />
          <TextField label={t('hotel.rooms.maxAdults')} inputMode="numeric" value={form.max_adults} error={errors.fields.max_adults} onChange={set('max_adults')} />
          <TextField label={t('hotel.rooms.maxChildren')} inputMode="numeric" value={form.max_children} error={errors.fields.max_children} onChange={set('max_children')} />
          <TextField label={t('hotel.rooms.maxOccupancy')} inputMode="numeric" value={form.max_occupancy} error={errors.fields.max_occupancy} onChange={set('max_occupancy')} />
          <TextField label={t('hotel.rooms.beds')} value={form.beds} maxLength={100} error={errors.fields.beds} onChange={set('beds')} />
          <TextField label={t('hotel.rooms.sort')} inputMode="numeric" value={form.sort} error={errors.fields.sort} onChange={set('sort')} />
          <TextField label={t('hotel.rooms.description')} value={form.description} maxLength={2000} error={errors.fields.description} onChange={set('description')} className="sm:col-span-2" />
        </div>
      </div>
    </Dialog>
  )
}

/** HOT-04: add or change a room. */
function RoomDialog({ room, branchId, types, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ room_type_id: room?.room_type?.id ?? '', number: room?.number ?? '', floor: room?.floor ?? '', features: (room?.features ?? []).join(', '), notes: room?.notes ?? '' })
  const set = (name) => (event) => setForm((current) => ({ ...current, [name]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const features = form.features.split(',').map((tag) => tag.trim().toLowerCase().replace(/\s+/g, '_')).filter(Boolean)
      const body = { room_type_id: form.room_type_id, number: form.number.trim(), floor: form.floor.trim() || null, features, notes: form.notes.trim() || null }
      return room ? api.patch(`hotel/rooms/${room.id}`, body) : api.post('hotel/rooms', { ...body, branch_id: branchId })
    },
    onSuccess: async () => {
      toast.success(t('hotel.rooms.roomSaved'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['room_type_id', 'number', 'floor', 'features', 'notes'])
  return (
    <Dialog
      open
      title={room ? t('hotel.rooms.editRoom') : t('hotel.rooms.newRoom')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!form.room_type_id || !form.number.trim()} onClick={() => save.mutate()}>
            {t('hotel.rooms.saveRoom')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select label={t('hotel.columns.room_type')} placeholder={t('hotel.pickers.roomType')} options={types.map((type) => ({ value: type.id, label: `${type.name} · ${type.code}` }))} value={form.room_type_id} required error={errors.fields.room_type_id} onChange={set('room_type_id')} />
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField label={t('hotel.columns.number')} value={form.number} maxLength={20} required error={errors.fields.number} onChange={set('number')} />
          <TextField label={t('hotel.columns.floor')} value={form.floor} maxLength={20} error={errors.fields.floor} onChange={set('floor')} />
        </div>
        <TextField label={t('hotel.rooms.features')} help={t('hotel.rooms.featuresHelp')} value={form.features} error={errors.fields.features} onChange={set('features')} />
        <TextField label={t('hotel.rooms.notes')} value={form.notes} maxLength={1000} error={errors.fields.notes} onChange={set('notes')} />
      </div>
    </Dialog>
  )
}

/** HOT-05: a room's status, including out of order with a reason and optional dates. */
export function RoomStatusDialog({ room, onClose, onDone }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const manager = can(ROOM_MANAGE)
  const [status, setStatus] = useState(room.status === 'occupied' ? 'vacant_dirty' : room.status)
  const [reason, setReason] = useState(room.out_of_order?.reason ?? '')
  const [from, setFrom] = useState(room.out_of_order?.from ?? '')
  const [until, setUntil] = useState(room.out_of_order?.until ?? '')
  const out = status === 'out_of_order'
  const save = useMutation({
    mutationFn: () => api.post(`hotel/rooms/${room.id}/status`, out ? { status, reason: reason.trim(), from: from || null, until: until || null } : { status }),
    onSuccess: async () => {
      toast.success(t('hotel.rooms.statusSaved'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onDone?.()
      onClose()
    },
  })
  const errors = formErrors(save.error, ['status', 'reason', 'from', 'until'])
  const choices = ROOM_STATUSES.filter((entry) => entry !== 'occupied' && (manager || entry !== 'out_of_order'))
  return (
    <Dialog
      open
      title={t('hotel.rooms.statusTitle', { number: room.number })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={out && !reason.trim()} onClick={() => save.mutate()}>
            {t('hotel.rooms.saveStatus')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select label={t('hotel.columns.status')} options={choices.map((entry) => ({ value: entry, label: t(`hotel.states.${entry}`) }))} value={status} error={errors.fields.status} onChange={(event) => setStatus(event.target.value)} />
        {out ? (
          <>
            <TextField label={t('hotel.rooms.oooReason')} value={reason} maxLength={500} required error={errors.fields.reason} onChange={(event) => setReason(event.target.value)} />
            <div className="grid gap-3 sm:grid-cols-2">
              <TextField type="date" label={t('hotel.rooms.oooFrom')} value={from} error={errors.fields.from} onChange={(event) => setFrom(event.target.value)} />
              <TextField type="date" label={t('hotel.rooms.oooUntil')} value={until} error={errors.fields.until} onChange={(event) => setUntil(event.target.value)} />
            </div>
          </>
        ) : null}
      </div>
    </Dialog>
  )
}

function RoomTypes({ companyId, canManage }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(undefined)
  const query = useQuery({ queryKey: [...hotelKey, 'room-types', companyId], queryFn: () => api.get(`hotel/room-types?company=${companyId}&status=active`), enabled: Boolean(companyId) })
  const archive = useMutation({
    mutationFn: (type) => api.post(`hotel/room-types/${type.id}/archive`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: hotelKey }),
    onError: (error) => toast.error(errorMessage(error)),
  })
  const columns = [
    { key: 'code', label: t('hotel.columns.code'), render: (row) => <span className="font-mono">{row.code}</span> },
    { key: 'name', label: t('hotel.columns.name'), render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'occupancy', label: t('hotel.rooms.maxOccupancy'), render: (row) => row.max_occupancy },
    { key: 'beds', label: t('hotel.rooms.beds'), render: (row) => row.beds ?? '' },
    { key: 'rooms', label: t('hotel.rooms.roomsCount'), render: (row) => row.rooms_count ?? 0 },
    ...(canManage
      ? [
          {
            key: 'actions',
            label: <span className="sr-only">{t('hotel.columns.actions')}</span>,
            render: (row) => (
              <div className="flex justify-end gap-1">
                <Button variant="ghost" onClick={() => setEditing(row)}>
                  {t('hotel.rooms.editType')}
                </Button>
                <Button variant="ghost" loading={archive.isPending && archive.variables?.id === row.id} onClick={() => archive.mutate(row)}>
                  {t('hotel.archive')}
                </Button>
              </div>
            ),
          },
        ]
      : []),
  ]
  return (
    <section className="flex flex-col gap-3">
      {canManage ? (
        <div>
          <Button variant="primary" icon="plus" onClick={() => setEditing(null)}>
            {t('hotel.rooms.newType')}
          </Button>
        </div>
      ) : null}
      <DataTable caption={t('hotel.rooms.types')} columns={columns} rows={query.data?.data ?? []} loading={query.isPending} emptyText={t('hotel.rooms.noTypes')} />
      {editing !== undefined ? <RoomTypeDialog type={editing} companyId={companyId} onClose={() => setEditing(undefined)} /> : null}
    </section>
  )
}

function RoomList({ branchId, companyId, canManage }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(undefined)
  const [statusFor, setStatusFor] = useState(null)
  const rooms = useQuery({ queryKey: [...hotelKey, 'rooms', branchId], queryFn: () => api.get(`hotel/rooms?branch=${branchId}`), enabled: Boolean(branchId) })
  const types = useQuery({ queryKey: [...hotelKey, 'room-types', companyId], queryFn: () => api.get(`hotel/room-types?company=${companyId}&status=active`), enabled: Boolean(companyId) })
  const archive = useMutation({
    mutationFn: (room) => api.post(`hotel/rooms/${room.id}/archive`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: hotelKey }),
    onError: (error) => toast.error(errorMessage(error)),
  })
  const rows = rooms.data?.data ?? []
  const floors = [...new Set(rows.map((room) => room.floor ?? ''))]
  const columns = [
    { key: 'number', label: t('hotel.columns.number'), render: (row) => <span className="font-medium text-ink">{row.number}</span> },
    { key: 'room_type', label: t('hotel.columns.room_type'), render: (row) => row.room_type?.name ?? '' },
    { key: 'status', label: t('hotel.columns.status'), render: (row) => <StateBadge status={row.status} /> },
    {
      key: 'ooo',
      label: t('hotel.rooms.outOfOrder'),
      render: (row) =>
        row.out_of_order ? (
          <span className="text-ink-muted">
            {row.out_of_order.reason}
            {row.out_of_order.until ? <> · <Day value={row.out_of_order.until} /></> : null}
          </span>
        ) : (
          ''
        ),
    },
    { key: 'features', label: t('hotel.rooms.features'), render: (row) => row.features.join(', ') },
    ...(canManage
      ? [
          {
            key: 'actions',
            label: <span className="sr-only">{t('hotel.columns.actions')}</span>,
            render: (row) => (
              <div className="flex justify-end gap-1">
                <Button variant="ghost" aria-label={t('hotel.rooms.statusTitle', { number: row.number })} onClick={() => setStatusFor(row)}>
                  {t('hotel.rooms.changeStatus')}
                </Button>
                <Button variant="ghost" onClick={() => setEditing(row)}>
                  {t('hotel.rooms.editRoom')}
                </Button>
                <Button variant="ghost" onClick={() => archive.mutate(row)}>
                  {t('hotel.archive')}
                </Button>
              </div>
            ),
          },
        ]
      : []),
  ]
  return (
    <section className="flex flex-col gap-4">
      {canManage ? (
        <div>
          <Button variant="primary" icon="plus" onClick={() => setEditing(null)}>
            {t('hotel.rooms.newRoom')}
          </Button>
        </div>
      ) : null}
      {!rooms.isPending && rows.length === 0 ? <Alert tone="info" title={t('hotel.rooms.noRooms')} /> : null}
      {floors.map((floor) => (
        <Card key={floor} title={floor ? t('hotel.rooms.floor', { floor }) : t('hotel.rooms.noFloor')}>
          <DataTable columns={columns} rows={rows.filter((room) => (room.floor ?? '') === floor)} caption={floor ? t('hotel.rooms.floor', { floor }) : t('hotel.rooms.noFloor')} />
        </Card>
      ))}
      {editing !== undefined ? <RoomDialog room={editing} branchId={branchId} types={types.data?.data ?? []} onClose={() => setEditing(undefined)} /> : null}
      {statusFor ? <RoomStatusDialog room={statusFor} onClose={() => setStatusFor(null)} /> : null}
    </section>
  )
}

/** HOT-03 to HOT-05: room types and rooms by floor, with their status. */
export default function Rooms() {
  const { t } = useTranslation()
  const choice = useBranchChoice()
  const { can } = usePermissions()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'types' ? 'types' : 'rooms'
  const canManage = can(ROOM_MANAGE)
  return (
    <>
      <PageHeader title={t('hotel.rooms.title')} description={t('hotel.rooms.description')} />
      <BranchSwitch choice={choice} />
      {choice.branch ? (
        <>
          <Tabs
            items={[
              { value: 'rooms', label: t('hotel.rooms.tabRooms') },
              { value: 'types', label: t('hotel.rooms.tabTypes') },
            ]}
            value={tab}
            onChange={(next) => setParams(next === 'rooms' ? {} : { tab: next }, { replace: true })}
          />
          {tab === 'rooms' ? <RoomList key={choice.branch.id} branchId={choice.branch.id} companyId={choice.companyId} canManage={canManage} /> : <RoomTypes key={choice.companyId} companyId={choice.companyId} canManage={canManage} />}
        </>
      ) : (
        <NoProperty choice={choice} />
      )}
    </>
  )
}
