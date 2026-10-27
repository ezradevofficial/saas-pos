import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, KpiTile } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatInteger } from '@/lib/format'
import { moneyText } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { BranchSwitch, Day, NoProperty, Stamp, StateBadge } from './HotelParts'
import { AUDIT_RUN, hotelKey, useBranchChoice } from './hotelData'

/** HOT-30, HOT-31: one night's snapshot: occupancy, ADR, RevPAR and revenue per currency, no-shows and overstays. */
function Snapshot({ audit }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const s = audit.summary ?? {}
  const number = (value) => formatInteger(value ?? 0, locale)
  const revenue = Object.entries(s.revenue ?? {})
  const overstays = s.overstays ?? []
  const errors = (s.errors ?? []).map((entry) => (typeof entry === 'string' ? entry : (entry?.message ?? '')))
  const noShows = Array.isArray(s.no_shows) ? s.no_shows.length : (s.no_shows ?? 0)
  return (
    <Card title={t('hotel.audit.snapshotTitle')} subtitle={<Day value={audit.business_date} />}>
      <div className="flex flex-col gap-5">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <KpiTile label={t('hotel.audit.occupancy')} value={`${new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(s.occupancy_percent ?? 0)} %`} />
          <KpiTile label={t('hotel.audit.roomsSold')} value={`${number(s.rooms_sold)} / ${number(s.rooms_available)}`} />
          <KpiTile label={t('hotel.audit.noShows')} value={number(noShows)} />
          <KpiTile label={t('hotel.audit.inHouseGuests')} value={number(s.in_house_guests)} />
        </div>
        {revenue.length ? (
          <div className="grid gap-3 sm:grid-cols-3">
            {revenue.map(([currency, row]) => (
              <section key={currency} aria-label={currency} className="flex flex-col gap-2">
                <KpiTile label={t('hotel.audit.revenue', { currency })} value={moneyText(row.net_minor, currency, locale)} />
                <KpiTile label={t('hotel.audit.adr', { currency })} value={moneyText(row.adr_minor, currency, locale)} />
                <KpiTile label={t('hotel.audit.revpar', { currency })} value={moneyText(row.revpar_minor, currency, locale)} />
              </section>
            ))}
          </div>
        ) : (
          <p className="text-body text-ink-muted">{t('hotel.audit.noRevenue')}</p>
        )}
        <dl className="grid gap-3 sm:grid-cols-4">
          <div className="flex flex-col gap-1"><dt className="text-caption text-ink-muted">{t('hotel.audit.arrivals')}</dt><dd className="tabular-nums">{number(s.arrivals)}</dd></div>
          <div className="flex flex-col gap-1"><dt className="text-caption text-ink-muted">{t('hotel.audit.departures')}</dt><dd className="tabular-nums">{number(s.departures)}</dd></div>
          <div className="flex flex-col gap-1"><dt className="text-caption text-ink-muted">{t('hotel.audit.chargesPosted')}</dt><dd className="tabular-nums">{number(s.charges_posted)}</dd></div>
          <div className="flex flex-col gap-1"><dt className="text-caption text-ink-muted">{t('hotel.audit.tasksCreated')}</dt><dd className="tabular-nums">{number(s.tasks_created)}</dd></div>
        </dl>
        {overstays.length ? (
          <section className="flex flex-col gap-2">
            <h3 className="text-label text-ink">{t('hotel.audit.overstays')}</h3>
            <ul className="flex flex-col gap-1">
              {overstays.map((stay) => (
                <li key={stay.stay_id} className="text-body">
                  {t('hotel.reservation.roomNumber', { number: stay.room_number ?? '' })} · <Day value={stay.departure_on} />
                </li>
              ))}
            </ul>
          </section>
        ) : null}
        {errors.length ? <Alert tone="warning" title={t('hotel.audit.errors', { count: errors.length })}>{errors.join(' ')}</Alert> : null}
      </div>
    </Card>
  )
}

/** HOT-30: the night audit of a property: the history, a manual run, and each night's snapshot. */
export default function NightAudit() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const choice = useBranchChoice()
  const [picked, setPicked] = useState(null)
  const [confirming, setConfirming] = useState(false)
  const branchId = choice.branch?.id
  const query = useQuery({ queryKey: [...hotelKey, 'night-audits', branchId], queryFn: () => api.get(`hotel/night-audits?branch=${branchId}`), enabled: Boolean(branchId) })
  const rows = query.data?.data ?? []
  const businessDate = query.data?.meta?.business_date
  const selected = rows.find((row) => row.id === picked) ?? rows[0] ?? null
  const run = useMutation({
    mutationFn: () => api.post('hotel/night-audits', { branch_id: branchId }),
    onSuccess: async (response) => {
      toast.success(t('hotel.audit.done', { date: response.data.business_date }))
      setPicked(response.data.id)
      setConfirming(false)
      await queryClient.invalidateQueries({ queryKey: hotelKey })
    },
  })
  const columns = [
    { key: 'business_date', label: t('hotel.columns.business_date'), render: (row) => <span className="font-medium text-ink"><Day value={row.business_date} /></span> },
    { key: 'status', label: t('hotel.columns.status'), render: (row) => <StateBadge status={row.status} /> },
    { key: 'run', label: t('hotel.audit.runBy'), render: (row) => (row.scheduled ? t('hotel.audit.scheduled') : t('hotel.audit.manual')) },
    { key: 'completed_at', label: t('hotel.audit.completedAt'), render: (row) => <Stamp value={row.completed_at} companyId={choice.companyId} /> },
  ]
  return (
    <>
      <PageHeader
        title={t('hotel.audit.title')}
        description={businessDate ? t('hotel.audit.description', { date: businessDate }) : t('hotel.audit.descriptionPlain')}
        actions={
          can(AUDIT_RUN) && choice.branch ? (
            <Button variant="primary" onClick={() => setConfirming(true)}>
              {t('hotel.audit.run')}
            </Button>
          ) : null
        }
      />
      <BranchSwitch choice={choice} />
      {!choice.branch ? <NoProperty choice={choice} /> : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {choice.branch ? <DataTable caption={t('hotel.audit.history')} columns={columns} rows={rows} loading={query.isPending} selectedId={selected?.id} emptyText={t('hotel.audit.empty')} onRowClick={(row) => setPicked(row.id)} /> : null}
      {selected ? <Snapshot audit={selected} /> : null}
      {confirming ? (
        <Dialog
          open
          title={t('hotel.audit.confirmTitle')}
          onClose={() => { run.reset(); setConfirming(false) }}
          footer={<><Button variant="ghost" onClick={() => { run.reset(); setConfirming(false) }}>{t('common.cancel')}</Button><Button variant="primary" loading={run.isPending} onClick={() => run.mutate()}>{t('hotel.audit.runNow')}</Button></>}
        >
          <div className="flex flex-col gap-3">
            {run.error ? <Alert tone="danger" title={errorMessage(run.error)} /> : null}
            <p className="text-body text-ink-muted">{t('hotel.audit.confirmHelp', { date: businessDate ?? '' })}</p>
          </div>
        </Dialog>
      ) : null}
    </>
  )
}
