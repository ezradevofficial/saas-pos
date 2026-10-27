import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { usePlaces } from '@/pages/inventory/inventoryData'
import { ActionsLabel, QueryNotes, Status } from './VanSalesParts'
import { useDevices, useUsers, useVans, vanError, VAN_MANAGE, vanSalesKey } from './vansalesData'

const FIELDS = ['name', 'plate', 'branch_id', 'rep_id', 'device_id', 'location_id']

/** VAN-02: a van's name, plate, branch, rep, stock location and device. */
function VanDialog({ van, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const users = useUsers()
  const [values, setValues] = useState({
    name: van?.name ?? '',
    plate: van?.plate ?? '',
    branch_id: van?.branch_id ?? '',
    rep_id: van?.rep_id ?? '',
    location_id: van?.location_id ?? '',
    device_id: van?.device_id ?? '',
  })
  const devices = useDevices(values.location_id)
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const body = { branch_id: values.branch_id, name: values.name.trim(), plate: values.plate.trim() || null, rep_id: values.rep_id, device_id: values.device_id || null }
      if (values.location_id) body.location_id = values.location_id
      return van ? api.put(`van-sales/vans/${van.id}`, body) : api.post('van-sales/vans', body)
    },
    onSuccess: () => {
      toast.success(t('vansales.vans.saved'))
      queryClient.invalidateQueries({ queryKey: vanSalesKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const valid = values.name.trim() && values.branch_id && values.rep_id
  const branches = places.branches.filter((branch) => !branch.archived_at)
  return (
    <Dialog
      open
      title={van ? t('vansales.vans.editTitle') : t('vansales.vans.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>
            {t('vansales.vans.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={vanError(t, save.error)} /> : null}
        <TextField label={t('vansales.fields.name')} value={values.name} error={errors.fields.name} onChange={set('name')} />
        <TextField label={t('vansales.vans.plate')} value={values.plate} error={errors.fields.plate} onChange={set('plate')} />
        <Select
          label={t('vansales.fields.branch')}
          placeholder={t('vansales.vans.pickBranch')}
          options={branches.map((branch) => ({ value: branch.id, label: branch.name }))}
          value={values.branch_id}
          error={errors.fields.branch_id}
          onChange={set('branch_id')}
        />
        <Select
          label={t('vansales.vans.rep')}
          placeholder={t('vansales.vans.pickRep')}
          options={users.map((user) => ({ value: user.id, label: user.name }))}
          value={values.rep_id}
          error={errors.fields.rep_id}
          onChange={set('rep_id')}
        />
        <Select
          label={t('vansales.vans.location')}
          help={t('vansales.vans.locationHelp')}
          options={[{ value: '', label: t('vansales.vans.newLocation') }, ...places.activeLocations.map((location) => ({ value: location.id, label: location.name }))]}
          value={values.location_id}
          disabled={Boolean(van)}
          error={errors.fields.location_id}
          onChange={(event) => setValues((current) => ({ ...current, location_id: event.target.value, device_id: '' }))}
        />
        <Select
          label={t('vansales.vans.device')}
          help={values.location_id ? undefined : t('vansales.vans.deviceHelp')}
          options={[{ value: '', label: t('vansales.vans.noDevice') }, ...devices.map((device) => ({ value: device.id, label: device.name }))]}
          value={values.device_id}
          disabled={!values.location_id}
          error={errors.fields.device_id}
          onChange={set('device_id')}
        />
      </div>
    </Dialog>
  )
}

/** VAN-02: the vans of the tenant, with their rep and device; set up and archive. */
export default function Vans() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const manage = can(VAN_MANAGE)
  const { vans, ...query } = useVans()
  const [editing, setEditing] = useState(null)
  const [archiving, setArchiving] = useState(null)
  const archive = useMutation({
    mutationFn: (van) => api.post(`van-sales/vans/${van.id}/archive`, {}),
    onSuccess: () => {
      toast.success(t('vansales.vans.archived'))
      queryClient.invalidateQueries({ queryKey: vanSalesKey })
      setArchiving(null)
    },
  })
  const columns = [
    { key: 'name', label: t('vansales.fields.name'), render: (van) => van.name },
    { key: 'plate', label: t('vansales.vans.plate'), render: (van) => van.plate ?? '' },
    { key: 'rep', label: t('vansales.vans.rep'), render: (van) => van.rep_name ?? '' },
    { key: 'location', label: t('vansales.vans.location'), render: (van) => van.location_name ?? '' },
    { key: 'device', label: t('vansales.vans.device'), render: (van) => van.device_name ?? '' },
    { key: 'status', label: t('vansales.fields.status'), render: (van) => <Status status={van.archived_at ? 'archived' : 'open'} /> },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (van) =>
        manage && !van.archived_at ? (
          <div className="flex justify-end gap-2">
            <Button variant="ghost" aria-label={t('vansales.vans.editNamed', { name: van.name })} onClick={() => setEditing(van)}>
              {t('vansales.vans.edit')}
            </Button>
            <Button variant="ghost" aria-label={t('vansales.vans.archiveNamed', { name: van.name })} onClick={() => setArchiving(van)}>
              {t('vansales.vans.archive')}
            </Button>
          </div>
        ) : null,
    },
  ]
  return (
    <>
      <PageHeader
        title={t('vansales.vans.title')}
        description={t('vansales.vans.description')}
        actions={
          manage ? (
            <Button variant="primary" icon="plus" onClick={() => setEditing({})}>
              {t('vansales.vans.add')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        {query.isError ? <QueryNotes query={query} /> : null}
        {!query.isError ? <DataTable caption={t('vansales.vans.title')} columns={columns} rows={vans} loading={query.isPending} emptyText={t('vansales.vans.empty')} /> : null}
      </div>
      {editing ? <VanDialog key={editing.id ?? 'new'} van={editing.id ? editing : null} onClose={() => setEditing(null)} /> : null}
      <Dialog
        open={Boolean(archiving)}
        title={t('vansales.vans.archiveTitle')}
        onClose={() => setArchiving(null)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setArchiving(null)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate(archiving)}>
              {t('vansales.vans.archive')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          {archive.isError ? <Alert tone="danger" title={errorMessage(archive.error)} /> : null}
          <p>{t('vansales.vans.archiveAsk', { name: archiving?.name ?? '' })}</p>
        </div>
      </Dialog>
    </>
  )
}
