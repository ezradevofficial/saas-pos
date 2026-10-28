import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { BranchSwitch, Day, NoProperty, StateBadge } from './HotelParts'
import { RoomStatusDialog } from './Rooms'
import { HK_ASSIGN, HK_UPDATE, hotelKey, ROOM_MANAGE, ROOM_STATUSES, TASK_KINDS, useBranchChoice } from './hotelData'

/** Housekeepers to assign to: the active users when the reader may list them; otherwise none. */
function useHousekeepers() {
  const query = useQuery({ queryKey: ['users', 'hotel-assign'], queryFn: () => api.get('users?status=active&per_page=200'), retry: false, staleTime: 60_000 })
  return query.data?.data ?? []
}

/** HOT-28: assign a task to a housekeeper, or to nobody. */
function AssignDialog({ task, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const users = useHousekeepers()
  const [userId, setUserId] = useState(task.assigned_to?.id ?? '')
  const save = useMutation({
    mutationFn: () => api.post(`hotel/housekeeping/tasks/${task.id}/assign`, { assigned_to: userId || null }),
    onSuccess: async () => {
      toast.success(t('hotel.hk.assigned'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
    },
  })
  return (
    <Dialog open title={t('hotel.hk.assignTitle', { room: task.room?.number ?? '' })} onClose={onClose} footer={<><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{t('hotel.hk.assign')}</Button></>}>
      <div className="flex flex-col gap-3">
        {save.error ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        <Select label={t('hotel.columns.assigned_to')} options={[{ value: '', label: t('hotel.hk.unassigned') }, ...users.map((user) => ({ value: user.id, label: user.name }))]} value={userId} onChange={(event) => setUserId(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** HOT-28: add a task to a room. */
function NewTaskDialog({ rooms, room, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const users = useHousekeepers()
  const [roomId, setRoomId] = useState(room?.id ?? '')
  const [kind, setKind] = useState('deep_clean')
  const [userId, setUserId] = useState('')
  const [notes, setNotes] = useState('')
  const save = useMutation({
    mutationFn: () => api.post('hotel/housekeeping/tasks', { room_id: roomId, kind, ...(userId ? { assigned_to: userId } : {}), ...(notes.trim() ? { notes: notes.trim() } : {}) }),
    onSuccess: async () => {
      toast.success(t('hotel.hk.taskCreated'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['room_id', 'kind', 'assigned_to', 'notes'])
  return (
    <Dialog open title={t('hotel.hk.newTask')} onClose={onClose} footer={<><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button variant="primary" loading={save.isPending} disabled={!roomId} onClick={() => save.mutate()}>{t('hotel.hk.createTask')}</Button></>}>
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select label={t('hotel.columns.room')} placeholder={t('hotel.pickers.room')} options={rooms.map((entry) => ({ value: entry.id, label: entry.number }))} value={roomId} required error={errors.fields.room_id} onChange={(event) => setRoomId(event.target.value)} />
        <Select label={t('hotel.columns.kind')} options={TASK_KINDS.map((entry) => ({ value: entry, label: t(`hotel.taskKinds.${entry}`) }))} value={kind} error={errors.fields.kind} onChange={(event) => setKind(event.target.value)} />
        <Select label={t('hotel.columns.assigned_to')} options={[{ value: '', label: t('hotel.hk.unassigned') }, ...users.map((user) => ({ value: user.id, label: user.name }))]} value={userId} error={errors.fields.assigned_to} onChange={(event) => setUserId(event.target.value)} />
        <TextField label={t('hotel.fields.notes')} value={notes} maxLength={1000} error={errors.fields.notes} onChange={(event) => setNotes(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** HOT-28, HOT-29: the board by floor: each room's status, its open tasks, and the actions on them. */
export default function Housekeeping() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const choice = useBranchChoice()
  const [dialog, setDialog] = useState(null)
  const branchId = choice.branch?.id
  const board = useQuery({ queryKey: [...hotelKey, 'housekeeping', branchId], queryFn: () => api.get(`hotel/housekeeping/board?branch_id=${branchId}`), enabled: Boolean(branchId), refetchInterval: 60_000 })
  const done = useQuery({ queryKey: [...hotelKey, 'housekeeping-done', branchId], queryFn: () => api.get(`hotel/housekeeping/tasks?branch=${branchId}&status=done&per_page=50`), enabled: Boolean(branchId) && can(HK_ASSIGN) })
  const act = useMutation({
    mutationFn: ({ task, step }) => api.post(`hotel/housekeeping/tasks/${task.id}/${step}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: hotelKey }),
    onError: (error) => toast.error(errorMessage(error)),
  })
  const data = board.data?.data
  const floors = data ? [...new Set(data.rooms.map((room) => room.floor ?? ''))] : []
  const canStatus = can(HK_UPDATE) || can(ROOM_MANAGE)
  const taskButton = (task, room) => {
    const busy = act.isPending && act.variables?.task.id === task.id
    return (
      <div className="flex flex-wrap gap-1">
        {task.status === 'pending' && can(HK_UPDATE) ? <Button variant="secondary" loading={busy} aria-label={t('hotel.hk.startLabel', { room: room.number })} onClick={() => act.mutate({ task, step: 'start' })}>{t('hotel.hk.start')}</Button> : null}
        {task.status === 'in_progress' && can(HK_UPDATE) ? <Button variant="secondary" loading={busy} aria-label={t('hotel.hk.finishLabel', { room: room.number })} onClick={() => act.mutate({ task, step: 'finish' })}>{t('hotel.hk.finish')}</Button> : null}
        {can(HK_ASSIGN) ? <Button variant="ghost" aria-label={t('hotel.hk.assignTitle', { room: room.number })} onClick={() => setDialog({ kind: 'assign', task: { ...task, room } })}>{t('hotel.hk.assign')}</Button> : null}
      </div>
    )
  }
  return (
    <>
      <PageHeader
        title={t('hotel.hk.title')}
        description={data ? t('hotel.hk.description', { date: formatCalendarDate(data.business_date, locale) }) : t('hotel.hk.descriptionPlain')}
        actions={
          can(HK_ASSIGN) && data ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'task' })}>
              {t('hotel.hk.newTask')}
            </Button>
          ) : null
        }
      />
      <BranchSwitch choice={choice} />
      {!choice.branch ? <NoProperty choice={choice} /> : null}
      {board.isError ? <Alert tone="danger" title={errorMessage(board.error)} /> : null}
      {data ? (
        <>
          <ul aria-label={t('hotel.hk.counts')} className="flex flex-wrap gap-x-6 gap-y-2">
            {ROOM_STATUSES.map((status) => (
              <li key={status} className="flex items-center gap-2">
                <StateBadge status={status} />
                <span className="text-body tabular-nums text-ink">{formatInteger(data.counts[status] ?? 0, locale)}</span>
              </li>
            ))}
          </ul>
          {floors.map((floor) => (
            <Card key={floor} title={floor ? t('hotel.rooms.floor', { floor }) : t('hotel.rooms.noFloor')}>
              <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {data.rooms
                  .filter((room) => (room.floor ?? '') === floor)
                  .map((room) => (
                    <li key={room.id} className="flex flex-col gap-2 rounded-md border border-border p-3">
                      <div className="flex items-start justify-between gap-2">
                        <div className="flex flex-col">
                          <span className="text-h3 text-ink">{room.number}</span>
                          <span className="text-caption text-ink-muted">{room.room_type?.name}</span>
                        </div>
                        <StateBadge status={room.status} />
                      </div>
                      {room.arriving_today || room.departing_today ? (
                        <p className="text-caption text-ink-muted">{[room.arriving_today ? t('hotel.hk.arriving') : null, room.departing_today ? t('hotel.hk.departing') : null].filter(Boolean).join(' · ')}</p>
                      ) : null}
                      {room.out_of_order ? (
                        <p className="text-caption text-danger">
                          {room.out_of_order.reason}
                          {room.out_of_order.until ? <> · <Day value={room.out_of_order.until} /></> : null}
                        </p>
                      ) : null}
                      {room.tasks.map((task) => (
                        <div key={task.id} className="flex flex-col gap-1 border-t border-border pt-2">
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-body text-ink">{t(`hotel.taskKinds.${task.kind}`)}</span>
                            <StateBadge status={task.status} />
                          </div>
                          <span className="text-caption text-ink-muted">{task.assigned_to?.name ?? t('hotel.hk.unassigned')}</span>
                          {taskButton(task, room)}
                        </div>
                      ))}
                      <div className="flex flex-wrap gap-1 pt-1">
                        {canStatus && room.status !== 'occupied' ? (
                          <Button variant="ghost" aria-label={t('hotel.rooms.statusTitle', { number: room.number })} onClick={() => setDialog({ kind: 'status', room })}>
                            {t('hotel.rooms.changeStatus')}
                          </Button>
                        ) : null}
                        {can(HK_ASSIGN) ? (
                          <Button variant="ghost" aria-label={t('hotel.hk.addTaskTo', { room: room.number })} onClick={() => setDialog({ kind: 'task', room })}>
                            {t('hotel.hk.addTask')}
                          </Button>
                        ) : null}
                      </div>
                    </li>
                  ))}
              </ul>
            </Card>
          ))}
          {done.data?.data?.length ? (
            <Card title={t('hotel.hk.toInspect')}>
              <ul className="flex flex-col gap-2">
                {done.data.data.map((task) => (
                  <li key={task.id} className="flex flex-wrap items-center justify-between gap-3">
                    <span className="text-body text-ink">
                      {t('hotel.reservation.roomNumber', { number: task.room?.number ?? '' })} · {t(`hotel.taskKinds.${task.kind}`)}
                    </span>
                    <Button variant="secondary" loading={act.isPending && act.variables?.task.id === task.id} aria-label={t('hotel.hk.inspectLabel', { room: task.room?.number ?? '' })} onClick={() => act.mutate({ task, step: 'inspect' })}>
                      {t('hotel.hk.inspect')}
                    </Button>
                  </li>
                ))}
              </ul>
            </Card>
          ) : null}
        </>
      ) : null}
      {dialog?.kind === 'assign' ? <AssignDialog task={dialog.task} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'task' ? <NewTaskDialog rooms={data?.rooms ?? []} room={dialog.room} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'status' ? <RoomStatusDialog room={dialog.room} onClose={() => setDialog(null)} /> : null}
    </>
  )
}
