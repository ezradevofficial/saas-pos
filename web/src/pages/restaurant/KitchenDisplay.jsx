import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Select, StatusBadge } from '@/components/ds'
import { usePageTitle } from '@/lib/usePageTitle'
import { cn } from '@/lib/utils'
import { ageMinutes, restaurantKey, STATUS_TONES, statusLabel, TICKET_UPDATE, useLocations } from './restaurantData'

const POLL_MS = 5000
const AGE_MS = 15000
/** A full refresh (no `since`) every this many polls, so a missed change heals within a minute. */
const FULL_EVERY = 12
const COLUMNS = ['new', 'preparing', 'ready']
/** What each column's buttons do: [label key, next status, variant]. */
const ACTIONS = {
  new: [['start', 'preparing', 'primary']],
  preparing: [
    ['ready', 'ready', 'primary'],
    ['recall', 'new', 'secondary'],
  ],
  ready: [['recall', 'preparing', 'secondary']],
}

/** The clock of the screen: re-renders every `ms` so ticket ages move without asking the API. */
function useNow(ms) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), ms)
    return () => clearInterval(timer)
  }, [ms])
  return now
}

/** The newest time a ticket carries, as milliseconds. */
const touched = (ticket) => Math.max(...['created_at', 'preparing_at', 'ready_at', 'served_at'].map((field) => (ticket[field] ? Date.parse(ticket[field]) : 0)))

function Ticket({ ticket, now, canUpdate, busy, onMove }) {
  const { t } = useTranslation()
  const minutes = ageMinutes(ticket.created_at, now)
  const place = ticket.table_name ? t('restaurant.kds.table', { name: ticket.table_name }) : ticket.tab_name ? t('restaurant.kds.tab', { name: ticket.tab_name }) : ''
  return (
    <article aria-label={t('restaurant.kds.ticketLabel', { number: ticket.number })} className="flex flex-col gap-3 rounded-lg border border-border bg-surface-200 p-4">
      <header className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="text-h2 tabular-nums text-ink">{t('restaurant.kds.ticket', { number: ticket.number })}</h3>
          <p className="truncate text-body-lg text-ink">{place}</p>
          <p className="text-caption text-ink-muted">{[ticket.waiter_name, ticket.course ? t('restaurant.kds.course', { number: ticket.course }) : null].filter(Boolean).join(' · ')}</p>
        </div>
        <div className="flex flex-col items-end gap-1">
          <span className={cn('text-h2 tabular-nums', minutes >= 20 ? 'text-danger' : minutes >= 10 ? 'text-warning' : 'text-ink')}>{t('restaurant.kds.age', { count: minutes })}</span>
          {ticket.kind === 'resend' ? <StatusBadge tone="warning">{t('restaurant.kds.resend')}</StatusBadge> : null}
          {ticket.kind === 'void' ? <StatusBadge tone="danger">{t('restaurant.kds.void')}</StatusBadge> : null}
        </div>
      </header>
      <ul className="flex flex-col gap-2 border-t border-border pt-3">
        {(ticket.lines ?? []).map((line) => (
          <li key={line.id} className={cn('flex flex-col gap-1', line.void && 'text-ink-muted')}>
            <div className="flex items-baseline gap-2 text-body-lg">
              <span className="font-medium tabular-nums">{line.qty}</span>
              <span className={cn(line.void && 'line-through')}>{line.name}</span>
              {line.void ? <StatusBadge tone="danger">{t('restaurant.kds.void')}</StatusBadge> : null}
            </div>
            {(line.modifiers ?? []).length ? <p className="pl-6 text-body text-ink-muted">{line.modifiers.map((modifier) => modifier.name).join(', ')}</p> : null}
            {line.note ? <p className="pl-6 text-body text-ink">{line.note}</p> : null}
          </li>
        ))}
      </ul>
      {canUpdate ? (
        <footer className="flex flex-wrap gap-2">
          {ACTIONS[ticket.status]?.map(([label, next, variant]) => (
            <Button key={label} size="lg" variant={variant} disabled={busy} className="min-h-12 flex-1" onClick={() => onMove(ticket, next)}>
              {t(`restaurant.kds.${label}`)}
            </Button>
          ))}
        </footer>
      ) : null}
    </article>
  )
}

/**
 * REST-12: one station of one location, full screen. Active tickets in three
 * columns (new, preparing, ready), oldest first; the feed refreshes every 5
 * seconds with `since`, and ages tick every 15 seconds on this screen.
 * Changing a ticket needs restaurant.ticket.update. Printing is the till's job.
 */
export default function KitchenDisplay() {
  const { t } = useTranslation()
  usePageTitle(t('restaurant.kds.title'))
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const canUpdate = can(TICKET_UPDATE)
  const [params, setParams] = useSearchParams()
  const { locations, isPending: placesPending } = useLocations()
  const location = locations.find((entry) => entry.id === params.get('location')) ?? locations[0] ?? null
  const stationsQuery = useQuery({ queryKey: [...restaurantKey, 'stations', location?.id], queryFn: () => api.get(`restaurant/stations?location_id=${location.id}`), enabled: Boolean(location) })
  const stations = (stationsQuery.data?.data ?? []).filter((entry) => !entry.archived_at)
  const station = stations.find((entry) => entry.id === params.get('station')) ?? stations.find((entry) => entry.is_default) ?? stations[0] ?? null
  const now = useNow(AGE_MS)

  // The merged feed of this station: kept between polls so `since` only brings changes.
  const feed = useRef({ key: '', tickets: new Map(), cursor: 0, polls: 0 })
  const feedKey = [...restaurantKey, 'kds', location?.id, station?.id]
  const tickets = useQuery({
    queryKey: feedKey,
    enabled: Boolean(location && station),
    refetchInterval: POLL_MS,
    queryFn: async () => {
      const store = feed.current
      const key = `${location.id}:${station.id}`
      if (store.key !== key) Object.assign(store, { key, tickets: new Map(), cursor: 0, polls: 0 })
      const full = !store.cursor || store.polls % FULL_EVERY === 0
      const base = `restaurant/tickets?location_id=${location.id}&station_id=${station.id}`
      const response = await api.get(full ? `${base}&status=active` : `${base}&status=all&since=${encodeURIComponent(new Date(store.cursor).toISOString())}`)
      if (full) store.tickets = new Map()
      for (const ticket of response.data ?? []) store.tickets.set(ticket.id, ticket)
      store.cursor = Math.max(store.cursor, ...[...store.tickets.values()].map(touched))
      store.polls += 1
      return [...store.tickets.values()]
    },
  })
  const move = useMutation({
    mutationFn: ({ ticket, status }) => api.post(`restaurant/tickets/${ticket.id}/status`, { status }),
    onSuccess: (response, { ticket, status }) => {
      const updated = { ...ticket, ...(response?.data?.id === ticket.id ? response.data : {}), status }
      feed.current.tickets.set(ticket.id, updated)
      queryClient.setQueryData(feedKey, [...feed.current.tickets.values()])
    },
  })

  const live = (tickets.data ?? []).filter((ticket) => COLUMNS.includes(ticket.status)).sort((a, b) => Date.parse(a.created_at) - Date.parse(b.created_at))
  const update = (next) => setParams(Object.fromEntries(Object.entries({ location: location?.id, station: station?.id, ...next }).filter(([, value]) => value)),{ replace: true })

  return (
    <>
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-wrap items-end gap-4">
          <h1 className="text-h1 text-ink">{t('restaurant.kds.title')}</h1>
          {locations.length > 1 ? (
            <Select label={t('restaurant.fields.location')} options={locations.map((entry) => ({ value: entry.id, label: entry.name }))} value={location?.id ?? ''} onChange={(event) => update({ location: event.target.value, station: undefined })} />
          ) : null}
          {stations.length ? <Select label={t('restaurant.kds.station')} options={stations.map((entry) => ({ value: entry.id, label: entry.name }))} value={station?.id ?? ''} onChange={(event) => update({ station: event.target.value })} /> : null}
        </div>
        <Link to="/restaurant/orders" className="text-label text-ink-muted underline hover:text-ink">
          {t('restaurant.kds.leave')}
        </Link>
      </header>
      {placesPending || stationsQuery.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {!placesPending && !location ? <p className="text-ink-muted">{t('restaurant.noLocation')}</p> : null}
      {location && stationsQuery.isSuccess && !station ? <p className="text-ink-muted">{t('restaurant.kds.noStation')}</p> : null}
      {stationsQuery.isError ? <Alert tone="danger" title={errorMessage(stationsQuery.error)} /> : null}
      {tickets.isError ? <Alert tone="danger" title={t('restaurant.kds.feedFailed')} /> : null}
      {move.isError ? <Alert tone="danger" title={errorMessage(move.error)} /> : null}
      {station ? (
        <div className="grid items-start gap-4 lg:grid-cols-3">
          {COLUMNS.map((column) => {
            const inColumn = live.filter((ticket) => ticket.status === column)
            return (
              <section key={column} aria-label={statusLabel(t, column)} className="flex flex-col gap-3">
                <h2 className="flex items-center gap-2 text-h2 text-ink">
                  <StatusBadge tone={STATUS_TONES[column]}>{statusLabel(t, column)}</StatusBadge>
                  <span className="text-ink-muted tabular-nums">{inColumn.length}</span>
                </h2>
                {tickets.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
                {tickets.isSuccess && !inColumn.length ? <p className="text-ink-muted">{t('restaurant.kds.noTickets')}</p> : null}
                {inColumn.map((ticket) => (
                  <Ticket key={ticket.id} ticket={ticket} now={now} canUpdate={canUpdate} busy={move.isPending} onMove={(target, status) => move.mutate({ ticket: target, status })} />
                ))}
              </section>
            )
          })}
        </div>
      ) : null}
    </>
  )
}
