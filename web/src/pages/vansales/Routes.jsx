import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { usePlaces } from '@/pages/inventory/inventoryData'
import { ActionsLabel, DayToggles, QueryNotes, Status } from './VanSalesParts'
import { ROUTE_MANAGE, useDayNames, useRoutes, useVans, vanError, vanSalesKey } from './vansalesData'

/** VAN-03: a route's name, branch, visit days and default van. */
function RouteDialog({ route, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const { active: vans } = useVans()
  const [values, setValues] = useState({ name: route?.name ?? '', branch_id: route?.branch_id ?? '', van_id: route?.van_id ?? '', days: route?.visit_days ?? [] })
  const save = useMutation({
    mutationFn: () => {
      const branch = places.branches.find((entry) => entry.id === values.branch_id)
      const body = { company_id: branch?.company_id ?? route?.company_id, branch_id: values.branch_id, name: values.name.trim(), visit_days: values.days, van_id: values.van_id || null }
      return route ? api.put(`van-sales/routes/${route.id}`, body) : api.post('van-sales/routes', body)
    },
    onSuccess: () => {
      toast.success(t('vansales.routes.saved'))
      queryClient.invalidateQueries({ queryKey: vanSalesKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['name', 'branch_id', 'visit_days', 'van_id'])
  const branches = places.branches.filter((branch) => !branch.archived_at)
  return (
    <Dialog
      open
      title={route ? t('vansales.routes.editTitle') : t('vansales.routes.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!values.name.trim() || !values.branch_id || !values.days.length} onClick={() => save.mutate()}>
            {t('vansales.routes.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={vanError(t, save.error)} /> : null}
        <TextField label={t('vansales.fields.name')} value={values.name} error={errors.fields.name} onChange={(event) => setValues((current) => ({ ...current, name: event.target.value }))} />
        <Select
          label={t('vansales.fields.branch')}
          placeholder={t('vansales.vans.pickBranch')}
          options={branches.map((branch) => ({ value: branch.id, label: branch.name }))}
          value={values.branch_id}
          error={errors.fields.branch_id}
          onChange={(event) => setValues((current) => ({ ...current, branch_id: event.target.value }))}
        />
        <div className="flex flex-col gap-2">
          <span className="text-label text-ink">{t('vansales.routes.visitDays')}</span>
          <DayToggles value={values.days} onChange={(days) => setValues((current) => ({ ...current, days }))} />
          {errors.fields.visit_days ? <p className="text-caption text-danger">{errors.fields.visit_days}</p> : null}
        </div>
        <Select
          label={t('vansales.routes.defaultVan')}
          options={[{ value: '', label: t('vansales.routes.noVan') }, ...vans.map((van) => ({ value: van.id, label: van.name }))]}
          value={values.van_id}
          error={errors.fields.van_id}
          onChange={(event) => setValues((current) => ({ ...current, van_id: event.target.value }))}
        />
      </div>
    </Dialog>
  )
}

/** VAN-03: the routes with their days and stops; open one to order its stops. */
export default function Routes() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const manage = can(ROUTE_MANAGE)
  const dayNames = useDayNames()
  const { routes, ...query } = useRoutes()
  const [editing, setEditing] = useState(null)
  const [archiving, setArchiving] = useState(null)
  const archive = useMutation({
    mutationFn: (route) => api.post(`van-sales/routes/${route.id}/archive`, {}),
    onSuccess: () => {
      toast.success(t('vansales.routes.archived'))
      queryClient.invalidateQueries({ queryKey: vanSalesKey })
      setArchiving(null)
    },
  })
  const columns = [
    { key: 'name', label: t('vansales.fields.name'), render: (route) => route.name },
    { key: 'days', label: t('vansales.routes.visitDays'), render: (route) => dayNames(route.visit_days) },
    { key: 'van', label: t('vansales.routes.defaultVan'), render: (route) => route.van_name ?? '' },
    { key: 'stops', label: t('vansales.routes.stops'), numeric: true, align: 'end', render: (route) => route.stop_count },
    { key: 'status', label: t('vansales.fields.status'), render: (route) => <Status status={route.archived_at ? 'archived' : 'open'} /> },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (route) =>
        manage && !route.archived_at ? (
          <div className="flex justify-end gap-2">
            <Button
              variant="ghost"
              aria-label={t('vansales.routes.editNamed', { name: route.name })}
              onClick={(event) => {
                event.stopPropagation()
                setEditing(route)
              }}
            >
              {t('vansales.routes.edit')}
            </Button>
            <Button
              variant="ghost"
              aria-label={t('vansales.routes.archiveNamed', { name: route.name })}
              onClick={(event) => {
                event.stopPropagation()
                setArchiving(route)
              }}
            >
              {t('vansales.routes.archive')}
            </Button>
          </div>
        ) : null,
    },
  ]
  return (
    <>
      <PageHeader
        title={t('vansales.routes.title')}
        description={t('vansales.routes.description')}
        actions={
          manage ? (
            <Button variant="primary" icon="plus" onClick={() => setEditing({})}>
              {t('vansales.routes.add')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        {query.isError ? <QueryNotes query={query} /> : null}
        {!query.isError ? <DataTable caption={t('vansales.routes.title')} columns={columns} rows={routes} loading={query.isPending} onRowClick={(route) => navigate(`/van-sales/routes/${route.id}`)} emptyText={t('vansales.routes.empty')} /> : null}
      </div>
      {editing ? <RouteDialog key={editing.id ?? 'new'} route={editing.id ? editing : null} onClose={() => setEditing(null)} /> : null}
      <Dialog
        open={Boolean(archiving)}
        title={t('vansales.routes.archiveTitle')}
        onClose={() => setArchiving(null)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setArchiving(null)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate(archiving)}>
              {t('vansales.routes.archive')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          {archive.isError ? <Alert tone="danger" title={errorMessage(archive.error)} /> : null}
          <p>{t('vansales.routes.archiveAsk', { name: archiving?.name ?? '' })}</p>
        </div>
      </Dialog>
    </>
  )
}
