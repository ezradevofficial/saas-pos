import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { CustomerPicker } from '@/pages/crm/CrmParts'
import { ActionsLabel, DayToggles, QueryNotes, TextLink } from './VanSalesParts'
import { ROUTE_MANAGE, useDayNames, vanError, vanSalesKey } from './vansalesData'

/** The editable stop list of a loaded route; remounted when the saved list changes. */
function StopsEditor({ route, canEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const dayNames = useDayNames()
  const [stops, setStops] = useState(() => (route.stops ?? []).map((stop) => ({ key: stop.id, customer_id: stop.customer_id, customer_name: stop.customer_name, visit_days: stop.visit_days ?? [] })))
  const [picked, setPicked] = useState(null)
  const [editingDays, setEditingDays] = useState(null)
  const save = useMutation({
    mutationFn: () => api.put(`van-sales/routes/${route.id}/stops`, { stops: stops.map((stop) => (stop.visit_days.length ? { customer_id: stop.customer_id, visit_days: stop.visit_days } : { customer_id: stop.customer_id })) }),
    onSuccess: () => {
      toast.success(t('vansales.stops.saved'))
      queryClient.invalidateQueries({ queryKey: vanSalesKey })
    },
  })
  const move = (index, by) =>
    setStops((current) => {
      const next = [...current]
      const [entry] = next.splice(index, 1)
      next.splice(index + by, 0, entry)
      return next
    })
  const add = () => {
    if (!picked || stops.some((stop) => stop.customer_id === picked.id)) return
    setStops((current) => [...current, { key: picked.id, customer_id: picked.id, customer_name: picked.name, visit_days: [] }])
    setPicked(null)
  }
  const columns = [
    { key: 'position', label: t('vansales.stops.position'), numeric: true, render: (_stop, index) => index + 1 },
    {
      key: 'customer',
      label: t('vansales.stops.customer'),
      render: (stop) => (
        <div className="flex flex-col gap-2">
          <span>{stop.customer_name}</span>
          {editingDays === stop.key ? <DayToggles value={stop.visit_days} disabled={!canEdit} onChange={(days) => setStops((current) => current.map((entry) => (entry.key === stop.key ? { ...entry, visit_days: days } : entry)))} /> : null}
        </div>
      ),
    },
    { key: 'days', label: t('vansales.stops.days'), render: (stop) => (stop.visit_days.length ? dayNames(stop.visit_days) : t('vansales.stops.routeDays')) },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (stop, index) =>
        canEdit ? (
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="ghost" icon="up" aria-label={t('vansales.stops.moveUp', { name: stop.customer_name })} disabled={index === 0} onClick={() => move(index, -1)} />
            <Button variant="ghost" icon="down" aria-label={t('vansales.stops.moveDown', { name: stop.customer_name })} disabled={index === stops.length - 1} onClick={() => move(index, 1)} />
            <Button variant="ghost" aria-label={t('vansales.stops.daysNamed', { name: stop.customer_name })} onClick={() => setEditingDays(editingDays === stop.key ? null : stop.key)}>
              {t('vansales.stops.editDays')}
            </Button>
            <Button variant="ghost" aria-label={t('vansales.stops.removeNamed', { name: stop.customer_name })} onClick={() => setStops((current) => current.filter((entry) => entry.key !== stop.key))}>
              {t('vansales.stops.remove')}
            </Button>
          </div>
        ) : null,
    },
  ]
  return (
    <Card title={t('vansales.stops.title')} subtitle={t('vansales.stops.help')}>
      <div className="flex flex-col gap-4">
        {save.isError ? <Alert tone="danger" title={vanError(t, save.error)} /> : null}
        {canEdit ? (
          <div className="flex flex-wrap items-end gap-3">
            <CustomerPicker label={t('vansales.stops.addCustomer')} value={picked} onChange={setPicked} className="min-w-64 flex-1" />
            <Button disabled={!picked} onClick={add}>
              {t('vansales.stops.add')}
            </Button>
          </div>
        ) : null}
        <DataTable caption={t('vansales.stops.title')} columns={columns.map((column) => ({ ...column, render: (row) => column.render(row, stops.indexOf(row)) }))} rows={stops.map((stop) => ({ ...stop, id: stop.key }))} emptyText={t('vansales.stops.empty')} />
        {canEdit ? (
          <div>
            <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
              {t('vansales.stops.save')}
            </Button>
          </div>
        ) : null}
      </div>
    </Card>
  )
}

/** VAN-04: one route with its ordered stops. */
export default function RouteDetail() {
  const { t } = useTranslation()
  const { routeId } = useParams()
  const { can } = usePermissions()
  const dayNames = useDayNames()
  const query = useQuery({ queryKey: [...vanSalesKey, 'route', routeId], queryFn: () => api.get(`van-sales/routes/${routeId}`) })
  const route = query.data?.data
  return (
    <>
      <TextLink to="/van-sales/routes">{t('vansales.routes.back')}</TextLink>
      <PageHeader title={route?.name ?? t('vansales.routes.title')} description={route ? `${dayNames(route.visit_days)}${route.van_name ? ` · ${route.van_name}` : ''}` : undefined} />
      <div className="flex flex-col gap-5">
        <QueryNotes query={query} />
        {route ? <StopsEditor key={(route.stops ?? []).map((stop) => `${stop.id}:${(stop.visit_days ?? []).join('')}`).join('|')} route={route} canEdit={can(ROUTE_MANAGE) && !route.archived_at} /> : null}
      </div>
    </>
  )
}
