import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, KpiTile, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { CheckInDialog, CheckOutDialog, MoveRoomDialog, WalkInDialog } from './FrontDeskDialogs'
import { BranchSwitch, Day, NoProperty, StateBadge } from './HotelParts'
import { CHECKIN, CHECKOUT, hotelKey, MOVE, RESERVATION_CREATE, useBranchChoice } from './hotelData'

const TABS = ['arrivals', 'in-house', 'departures']

/** One of the day's lists from the reports (the property's business date is the server's). */
function useDayList(kind, branchId) {
  return useQuery({
    queryKey: [...hotelKey, 'front-desk', kind, branchId],
    queryFn: () => api.get(`hotel/reports/${kind}?branch=${branchId}&per_page=100&sort=arrival_on`),
    enabled: Boolean(branchId),
    refetchInterval: 60_000,
  })
}

/** HOT-17 to HOT-19, HOT-37: today's arrivals, the guests in house and today's departures, with the desk's actions. */
export default function FrontDesk() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { can } = usePermissions()
  const choice = useBranchChoice()
  const [params, setParams] = useSearchParams()
  const tab = TABS.includes(params.get('tab')) ? params.get('tab') : 'arrivals'
  const [action, setAction] = useState(null)
  const branchId = choice.branch?.id
  const arrivals = useDayList('arrivals', branchId)
  const inHouse = useDayList('in-house', branchId)
  const departures = useDayList('departures', branchId)
  const queries = { arrivals, 'in-house': inHouse, departures }
  const rows = queries[tab].data?.data ?? []
  const count = (query) => formatInteger(query.data?.meta?.total ?? query.data?.data?.length ?? 0, locale)

  const columns = [
    {
      key: 'guest',
      label: t('hotel.columns.guest'),
      render: (row) => (
        <Link to={`/hotel/reservations/${row.reservation_id}`} className="font-medium text-ink hover:text-primary">
          {row.guest?.name}
        </Link>
      ),
    },
    { key: 'room', label: t('hotel.columns.room'), render: (row) => row.room?.number ?? <span className="text-ink-muted">{t('hotel.frontDesk.notAssigned')}</span> },
    { key: 'room_type', label: t('hotel.columns.room_type'), render: (row) => row.room_type?.name ?? '' },
    { key: 'arrival_on', label: t('hotel.columns.arrival_on'), render: (row) => <Day value={row.arrival_on} /> },
    { key: 'departure_on', label: t('hotel.columns.departure_on'), render: (row) => <Day value={row.departure_on} /> },
    { key: 'party', label: t('hotel.columns.party'), render: (row) => `${row.adults}+${row.children}` },
    { key: 'status', label: t('hotel.columns.status'), render: (row) => <StateBadge status={row.status} /> },
    {
      key: 'actions',
      label: <span className="sr-only">{t('hotel.columns.actions')}</span>,
      render: (row) => (
        <div className="flex flex-wrap justify-end gap-1">
          {row.status === 'reserved' && can(CHECKIN) ? (
            <Button variant="primary" aria-label={t('hotel.frontDesk.checkInTitle', { guest: row.guest?.name })} onClick={() => setAction({ kind: 'in', stay: row })}>
              {t('hotel.frontDesk.checkIn')}
            </Button>
          ) : null}
          {row.status === 'checked_in' && can(CHECKOUT) ? (
            <Button variant="primary" aria-label={t('hotel.frontDesk.checkOutTitle', { guest: row.guest?.name })} onClick={() => setAction({ kind: 'out', stay: row })}>
              {t('hotel.frontDesk.checkOut')}
            </Button>
          ) : null}
          {row.status === 'checked_in' && can(MOVE) ? (
            <Button variant="ghost" aria-label={t('hotel.frontDesk.moveTitle', { guest: row.guest?.name })} onClick={() => setAction({ kind: 'move', stay: row })}>
              {t('hotel.frontDesk.move')}
            </Button>
          ) : null}
          {row.folio_id ? (
            <Link to={`/hotel/folios/${row.folio_id}`} className="inline-flex h-control items-center px-3 text-label text-primary hover:text-primary-hover">
              {t('hotel.frontDesk.folio')}
            </Link>
          ) : null}
        </div>
      ),
    },
  ]

  const failed = queries[tab].isError ? queries[tab].error : null
  const close = () => setAction(null)
  return (
    <>
      <PageHeader
        title={t('hotel.frontDesk.title')}
        description={t('hotel.frontDesk.description')}
        actions={
          can(CHECKIN) && can(RESERVATION_CREATE) && choice.branch ? (
            <Button variant="primary" icon="plus" onClick={() => setAction({ kind: 'walkin' })}>
              {t('hotel.frontDesk.walkIn')}
            </Button>
          ) : null
        }
      />
      <BranchSwitch choice={choice} />
      {!choice.branch ? <NoProperty choice={choice} /> : null}
      {choice.branch ? (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <KpiTile label={t('hotel.frontDesk.arrivals')} value={count(arrivals)} />
            <KpiTile label={t('hotel.frontDesk.inHouse')} value={count(inHouse)} />
            <KpiTile label={t('hotel.frontDesk.departures')} value={count(departures)} />
          </div>
          <Tabs
            items={TABS.map((value) => ({ value, label: t(`hotel.frontDesk.tab.${value}`) }))}
            value={tab}
            onChange={(next) => setParams(next === 'arrivals' ? {} : { tab: next }, { replace: true })}
          />
          {failed ? <Alert tone="danger" title={errorMessage(failed)} /> : null}
          <DataTable
            caption={t(`hotel.frontDesk.tab.${tab}`)}
            columns={columns}
            rows={rows}
            loading={queries[tab].isPending}
            emptyText={t(`hotel.frontDesk.empty.${tab}`)}
          />
        </>
      ) : null}
      {action?.kind === 'in' ? <CheckInDialog stay={action.stay} onClose={close} /> : null}
      {action?.kind === 'out' ? <CheckOutDialog stay={action.stay} companyId={choice.companyId} onClose={close} /> : null}
      {action?.kind === 'move' ? <MoveRoomDialog stay={action.stay} onClose={close} /> : null}
      {action?.kind === 'walkin' ? <WalkInDialog branchId={branchId} companyId={choice.companyId} onClose={close} /> : null}
    </>
  )
}
