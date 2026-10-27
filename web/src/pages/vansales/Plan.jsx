import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { DataTable, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { useTimeZone } from '@/lib/useTimeZone'
import { QueryNotes, Status } from './VanSalesParts'
import { useRoutes, vanSalesKey } from './vansalesData'

/** VAN-05: the stops due on a day, in order, with each one's status for that day's run. */
export default function Plan() {
  const { t } = useTranslation()
  const today = todayIn(useTimeZone())
  const [date, setDate] = useState('')
  const [routeId, setRouteId] = useState('')
  const { active } = useRoutes()
  const day = date || today
  const params = new URLSearchParams({ date: day })
  if (routeId) params.set('route_id', routeId)
  const query = useQuery({ queryKey: [...vanSalesKey, 'plan', params.toString()], queryFn: () => api.get(`van-sales/plan?${params.toString()}`) })
  const routes = query.data?.data?.routes ?? []
  const columns = [
    { key: 'position', label: t('vansales.stops.position'), numeric: true, render: (stop) => stop.position },
    { key: 'customer', label: t('vansales.stops.customer'), render: (stop) => stop.customer_name },
    { key: 'status', label: t('vansales.fields.status'), render: (stop) => <Status status={stop.status} /> },
  ]
  return (
    <>
      <PageHeader title={t('vansales.plan.title')} description={t('vansales.plan.description')} />
      <div className="flex flex-col gap-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('vansales.plan.date')} type="date" value={day} onChange={(event) => setDate(event.target.value)} />
          <Select label={t('vansales.plan.route')} options={[{ value: '', label: t('vansales.plan.allRoutes') }, ...active.map((route) => ({ value: route.id, label: route.name }))]} value={routeId} onChange={(event) => setRouteId(event.target.value)} />
        </div>
        <QueryNotes query={query} />
        {query.data && routes.length === 0 ? <p className="text-ink-muted">{t('vansales.plan.empty')}</p> : null}
        {routes.map((route) => (
          <section key={route.route_id} aria-label={route.name} className="flex flex-col gap-3">
            <h2 className="text-h3 text-ink">{route.name}</h2>
            <DataTable caption={route.name} columns={columns} rows={route.stops.map((stop) => ({ ...stop, id: stop.stop_id }))} emptyText={t('vansales.plan.noStops')} />
          </section>
        ))}
      </div>
    </>
  )
}
