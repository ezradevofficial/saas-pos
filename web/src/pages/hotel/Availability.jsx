import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { cn } from '@/lib/utils'
import { addDays, daysOf, hotelKey, RESERVATION_CREATE, useBranchChoice } from './hotelData'
import { usePermissions } from '@/auth/usePermissions'
import { BranchSwitch, Day, NoProperty } from './HotelParts'

const SPANS = ['7', '14', '30']

/** A day column heading. */
function DayHead({ day }) {
  return (
    <th scope="col" className="min-w-14 px-2 py-2 text-center text-caption font-normal text-ink-muted tabular-nums">
      <Day value={day} />
    </th>
  )
}

/** HOT-11: room types by night with the rooms free, and rooms by day with the stays holding them. */
export default function Availability() {
  const { t } = useTranslation()
  const choice = useBranchChoice()
  const { can } = usePermissions()
  const navigate = useNavigate()
  const [from, setFrom] = useState(() => todayIn(undefined))
  const [span, setSpan] = useState('14')
  const to = addDays(from, Number(span))
  const branchId = choice.branch?.id
  const query = useQuery({
    queryKey: [...hotelKey, 'availability', branchId, from, to],
    queryFn: () => api.get(`hotel/availability?branch_id=${branchId}&from=${from}&to=${to}`),
    enabled: Boolean(branchId),
  })
  const grid = query.data?.data
  const days = daysOf(from, to)
  const step = Number(span)
  return (
    <>
      <PageHeader
        title={t('hotel.availability.title')}
        description={t('hotel.availability.description')}
        actions={
          can(RESERVATION_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/hotel/reservations/new')}>
              {t('hotel.reservations.new')}
            </Button>
          ) : null
        }
      />
      <BranchSwitch choice={choice} />
      <div className="flex flex-wrap items-end gap-3">
        <Button variant="secondary" icon="chevronLeft" aria-label={t('hotel.availability.earlier')} onClick={() => setFrom(addDays(from, -step))} />
        <TextField type="date" label={t('hotel.availability.from')} value={from} onChange={(event) => event.target.value && setFrom(event.target.value)} />
        <Select label={t('hotel.availability.span')} options={SPANS.map((value) => ({ value, label: t('hotel.availability.days', { count: Number(value) }) }))} value={span} onChange={(event) => setSpan(event.target.value)} />
        <Button variant="secondary" icon="chevronRight" aria-label={t('hotel.availability.later')} onClick={() => setFrom(addDays(from, step))} />
        <Button variant="ghost" onClick={() => setFrom(todayIn(undefined))}>
          {t('hotel.availability.today')}
        </Button>
      </div>
      {!choice.branch ? <NoProperty choice={choice} /> : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending && choice.branch ? <p className="text-body text-ink-muted">{t('common.loading')}</p> : null}
      {grid ? (
        <>
          <Card title={t('hotel.availability.byType')} subtitle={t('hotel.availability.byTypeHelp')}>
            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-body">
                <caption className="sr-only">{t('hotel.availability.byType')}</caption>
                <thead>
                  <tr>
                    <th scope="col" className="px-2 py-2 text-left text-caption font-normal text-ink-muted">
                      {t('hotel.columns.room_type')}
                    </th>
                    {days.map((day) => (
                      <DayHead key={day} day={day} />
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {grid.types.map((type) => (
                    <tr key={type.id} className="border-t border-border">
                      <th scope="row" className="px-2 py-2 text-left font-medium text-ink">
                        {type.name}
                      </th>
                      {type.nights.map((night) => (
                        <td key={night.date} className={cn('px-2 py-2 text-center tabular-nums', night.free <= 0 ? 'text-danger' : 'text-ink')} title={t('hotel.availability.cellTitle', { free: night.free, rooms: night.rooms, booked: night.booked, out: night.out_of_order })}>
                          <span className="font-medium" aria-label={t('hotel.availability.freeLabel', { count: night.free })}>
                            {night.free}
                          </span>
                          <span className="block text-caption text-ink-muted">
                            {night.booked}/{night.rooms}
                          </span>
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>

          <Card title={t('hotel.availability.byRoom')} subtitle={t('hotel.availability.byRoomHelp')}>
            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-body">
                <caption className="sr-only">{t('hotel.availability.byRoom')}</caption>
                <thead>
                  <tr>
                    <th scope="col" className="px-2 py-2 text-left text-caption font-normal text-ink-muted">
                      {t('hotel.columns.room')}
                    </th>
                    {days.map((day) => (
                      <DayHead key={day} day={day} />
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {grid.rooms.map((room) => (
                    <tr key={room.id} className="border-t border-border">
                      <th scope="row" className="px-2 py-2 text-left font-medium text-ink whitespace-nowrap">
                        {room.number}
                        {room.floor ? <span className="block text-caption font-normal text-ink-muted">{t('hotel.rooms.floor', { floor: room.floor })}</span> : null}
                      </th>
                      {days.map((day) => {
                        const stay = room.stays.find((entry) => entry.arrival_on <= day && entry.departure_on > day)
                        const out = room.out_of_order && (!room.out_of_order.from || room.out_of_order.from <= day) && (!room.out_of_order.until || room.out_of_order.until >= day)
                        if (stay) {
                          return (
                            <td key={day} className="p-0.5">
                              <Link to={`/hotel/reservations/${stay.reservation_id}`} className={cn('block truncate rounded-sm px-1.5 py-1 text-caption', stay.status === 'checked_in' ? 'bg-primary-tint text-ink' : 'border border-border bg-surface-300 text-ink')} title={stay.guest ?? ''}>
                                {stay.arrival_on === day ? (stay.guest ?? t('hotel.availability.guest')) : '·'}
                              </Link>
                            </td>
                          )
                        }
                        return (
                          <td key={day} className="p-0.5">
                            <span className={cn('block rounded-sm px-1.5 py-1 text-center text-caption', out ? 'bg-danger-tint text-danger' : 'text-ink-muted')}>{out ? t('hotel.availability.out') : ''}</span>
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {grid.unassigned.length ? (
              <p className="mt-3 text-caption text-ink-muted">{t('hotel.availability.unassigned', { count: grid.unassigned.length })}</p>
            ) : null}
          </Card>
        </>
      ) : null}
    </>
  )
}
