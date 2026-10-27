import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Select, Switch, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { usePlaces } from '@/pages/inventory/inventoryData'
import { ActionsLabel, PlaceBar, QueryNotes, Status, When } from './SalonParts'
import { COLORS, RESOURCE_MANAGE, salonError, salonKey, STAFF_MANAGE, useResources, useSalonPlace, useStaff, WEEKDAYS } from './salonData'

/** Active users, for the staff member's sign-in. */
function useUsers() {
  const query = useQuery({ queryKey: ['users', 'options', 'active'], queryFn: () => api.get('users?status=active&per_page=200'), staleTime: 60_000, retry: false })
  return query.data?.data ?? []
}

/** SAL-03: a staff member: name, home branch, the user they are, the HR employee they are. */
function StaffDialog({ member, companyId, branches, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const users = useUsers()
  const [values, setValues] = useState({ name: member?.name ?? '', title: member?.title ?? '', color: member?.color ?? 'c1', branch_id: member?.branch_id ?? '', user_id: member?.user_id ?? '', employee_id: member?.employee_id ?? '', accepts_online: member?.accepts_online ?? true })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const body = { name: values.name.trim(), title: values.title.trim() || null, color: values.color, branch_id: values.branch_id || null, user_id: values.user_id || null, employee_id: values.employee_id.trim() || null, accepts_online: values.accepts_online }
      return member ? api.put(`salon/staff/${member.id}`, body) : api.post('salon/staff', { ...body, company_id: companyId })
    },
    onSuccess: () => {
      toast.success(t('salon.staff.saved'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['name', 'title', 'color', 'branch_id', 'user_id', 'employee_id'])
  return (
    <Dialog
      open
      title={member ? t('salon.staff.editTitle') : t('salon.staff.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!values.name.trim()} onClick={() => save.mutate()}>
            {t('salon.staff.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <TextField label={t('salon.fields.name')} value={values.name} error={errors.fields.name} onChange={set('name')} />
        <TextField label={t('salon.staff.title')} value={values.title} error={errors.fields.title} onChange={set('title')} />
        <Select label={t('salon.staff.color')} options={COLORS.map((color) => ({ value: color, label: t(`salon.staff.colors.${color}`) }))} value={values.color} onChange={set('color')} />
        <Select label={t('salon.staff.branch')} help={t('salon.staff.branchHelp')} options={[{ value: '', label: t('salon.staff.anyBranch') }, ...branches.map((branch) => ({ value: branch.id, label: branch.name }))]} value={values.branch_id} error={errors.fields.branch_id} onChange={set('branch_id')} />
        <Select label={t('salon.staff.user')} help={t('salon.staff.userHelp')} options={[{ value: '', label: t('salon.staff.noUser') }, ...users.map((user) => ({ value: user.id, label: user.name }))]} value={values.user_id} error={errors.fields.user_id} onChange={set('user_id')} />
        <TextField label={t('salon.staff.employee')} help={t('salon.staff.employeeHelp')} value={values.employee_id} error={errors.fields.employee_id} onChange={set('employee_id')} />
        <Switch label={t('salon.staff.acceptsOnline')} checked={values.accepts_online} onChange={(checked) => setValues((current) => ({ ...current, accepts_online: checked }))} />
      </div>
    </Dialog>
  )
}

/** SAL-04: the weekly work and break spans of a staff member, per branch and weekday. */
function HoursDialog({ member, branches, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: [...salonKey, 'hours', member.id], queryFn: () => api.get(`salon/staff/${member.id}/hours`) })
  const [rows, setRows] = useState(null)
  const spans = rows ?? query.data?.data?.map((row) => ({ branch_id: row.branch_id, weekday: String(row.weekday), kind: row.kind, start: row.start, end: row.end })) ?? []
  const change = (index, patch) => setRows(spans.map((row, at) => (at === index ? { ...row, ...patch } : row)))
  const fallbackBranch = member.branch_id ?? branches[0]?.id ?? ''
  const save = useMutation({
    mutationFn: () => api.put(`salon/staff/${member.id}/hours`, { hours: spans.map((row) => ({ branch_id: row.branch_id, weekday: Number(row.weekday), kind: row.kind, start: row.start, end: row.end })) }),
    onSuccess: () => {
      toast.success(t('salon.hours.saved'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onClose()
    },
  })
  const usable = branches.filter((branch) => !member.branch_id || branch.id === member.branch_id)
  return (
    <Dialog
      open
      title={t('salon.hours.title', { name: member.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            {t('salon.hours.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p className="text-caption text-ink-muted">{t('salon.hours.help')}</p>
        <QueryNotes query={query} />
        {save.isError ? <Alert tone="danger" title={salonError(save.error)} /> : null}
        {spans.map((row, index) => (
          <div key={index} className="flex flex-wrap items-end gap-3">
            {usable.length > 1 ? <Select label={t('salon.fields.branch')} options={usable.map((branch) => ({ value: branch.id, label: branch.name }))} value={row.branch_id} onChange={(event) => change(index, { branch_id: event.target.value })} className="min-w-36" /> : null}
            <Select label={t('salon.hours.day')} options={WEEKDAYS.map((day) => ({ value: String(day), label: t(`salon.days.${day}`) }))} value={String(row.weekday)} onChange={(event) => change(index, { weekday: event.target.value })} className="w-36" />
            <Select label={t('salon.hours.kind')} options={['work', 'break'].map((kind) => ({ value: kind, label: t(`salon.hours.kinds.${kind}`) }))} value={row.kind} onChange={(event) => change(index, { kind: event.target.value })} className="w-32" />
            <TextField label={t('salon.hours.from')} type="time" value={row.start} onChange={(event) => change(index, { start: event.target.value })} className="w-32" />
            <TextField label={t('salon.hours.to')} type="time" value={row.end} onChange={(event) => change(index, { end: event.target.value })} className="w-32" />
            <Button variant="ghost" aria-label={t('salon.hours.removeSpan')} onClick={() => setRows(spans.filter((_, at) => at !== index))}>
              {t('salon.hours.remove')}
            </Button>
          </div>
        ))}
        <div>
          <Button onClick={() => setRows([...spans, { branch_id: fallbackBranch, weekday: '1', kind: 'work', start: '09:00', end: '17:00' }])}>{t('salon.hours.add')}</Button>
        </div>
      </div>
    </Dialog>
  )
}

/** SAL-04: time off of a staff member: list, add, cancel. */
function TimeOffDialog({ member, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: [...salonKey, 'time-off', member.id], queryFn: () => api.get(`salon/staff/${member.id}/time-off`) })
  const [values, setValues] = useState({ starts_at: '', ends_at: '', reason: 'leave' })
  const refresh = () => queryClient.invalidateQueries({ queryKey: salonKey })
  const add = useMutation({
    mutationFn: () => api.post(`salon/staff/${member.id}/time-off`, { starts_at: new Date(values.starts_at).toISOString(), ends_at: new Date(values.ends_at).toISOString(), reason: values.reason }),
    onSuccess: () => {
      toast.success(t('salon.timeOff.saved'))
      setValues({ starts_at: '', ends_at: '', reason: 'leave' })
      refresh()
    },
  })
  const cancel = useMutation({ mutationFn: (id) => api.post(`salon/time-off/${id}/cancel`, {}), onSuccess: refresh })
  const valid = values.starts_at && values.ends_at && new Date(values.ends_at) > new Date(values.starts_at)
  const columns = [
    { key: 'from', label: t('salon.hours.from'), render: (row) => <When value={row.starts_at} /> },
    { key: 'to', label: t('salon.hours.to'), render: (row) => <When value={row.ends_at} /> },
    { key: 'reason', label: t('salon.timeOff.reason'), render: (row) => t(`salon.timeOff.reasons.${row.reason}`) },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) => (
        <Button variant="ghost" aria-label={t('salon.timeOff.cancelNamed')} loading={cancel.isPending} onClick={() => cancel.mutate(row.id)}>
          {t('salon.timeOff.cancel')}
        </Button>
      ),
    },
  ]
  return (
    <Dialog open title={t('salon.timeOff.title', { name: member.name })} onClose={onClose} footer={<Button onClick={onClose}>{t('salon.close')}</Button>}>
      <div className="flex flex-col gap-4 pt-1">
        <QueryNotes query={query} />
        <DataTable caption={t('salon.timeOff.list')} columns={columns} rows={query.data?.data ?? []} emptyText={t('salon.timeOff.empty')} />
        {add.isError ? <Alert tone="danger" title={salonError(add.error)} /> : null}
        <div className="flex flex-wrap items-end gap-3">
          <TextField label={t('salon.hours.from')} type="datetime-local" value={values.starts_at} onChange={(event) => setValues((current) => ({ ...current, starts_at: event.target.value }))} />
          <TextField label={t('salon.hours.to')} type="datetime-local" value={values.ends_at} onChange={(event) => setValues((current) => ({ ...current, ends_at: event.target.value }))} />
          <Select label={t('salon.timeOff.reason')} options={['leave', 'sick', 'training', 'other'].map((reason) => ({ value: reason, label: t(`salon.timeOff.reasons.${reason}`) }))} value={values.reason} onChange={(event) => setValues((current) => ({ ...current, reason: event.target.value }))} className="w-40" />
          <Button variant="primary" loading={add.isPending} disabled={!valid} onClick={() => add.mutate()}>
            {t('salon.timeOff.add')}
          </Button>
        </div>
      </div>
    </Dialog>
  )
}

/** SAL-05: a chair, basin, room or bed of a branch. */
function ResourceDialog({ resource, branches, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState({ branch_id: resource?.branch_id ?? branches[0]?.id ?? '', name: resource?.name ?? '', kind: resource?.kind ?? 'chair' })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const save = useMutation({
    mutationFn: () => (resource ? api.put(`salon/resources/${resource.id}`, { name: values.name.trim(), kind: values.kind.trim() }) : api.post('salon/resources', { branch_id: values.branch_id, name: values.name.trim(), kind: values.kind.trim() })),
    onSuccess: () => {
      toast.success(t('salon.resources.saved'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['branch_id', 'name', 'kind'])
  return (
    <Dialog
      open
      title={resource ? t('salon.resources.editTitle') : t('salon.resources.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!values.name.trim() || !values.branch_id} onClick={() => save.mutate()}>
            {t('salon.resources.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select label={t('salon.fields.branch')} options={branches.map((branch) => ({ value: branch.id, label: branch.name }))} value={values.branch_id} disabled={Boolean(resource)} error={errors.fields.branch_id} onChange={set('branch_id')} />
        <TextField label={t('salon.fields.name')} value={values.name} error={errors.fields.name} onChange={set('name')} />
        <TextField label={t('salon.resources.kind')} help={t('salon.resources.kindHelp')} value={values.kind} error={errors.fields.kind} onChange={set('kind')} />
      </div>
    </Dialog>
  )
}

/** SAL-03 to SAL-05: staff with their hours and time off, and the chairs and rooms. */
export default function StaffPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const manageStaff = can(STAFF_MANAGE)
  const manageResources = can(RESOURCE_MANAGE)
  const place = useSalonPlace()
  const companyId = place.company?.id ?? ''
  const places = usePlaces()
  const branches = places.branches.filter((branch) => !branch.archived_at && branch.company_id === companyId)
  const staff = useStaff(companyId)
  const resources = useResources(companyId)
  const [tab, setTab] = useState('staff')
  const [dialog, setDialog] = useState(null)
  const branchName = (id) => branches.find((branch) => branch.id === id)?.name ?? ''
  const archive = useMutation({
    mutationFn: ({ kind, row }) => api.post(`salon/${kind === 'staff' ? 'staff' : 'resources'}/${row.id}/archive`, {}),
    onSuccess: () => {
      toast.success(t('salon.staff.archived'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      setDialog(null)
    },
  })
  const staffColumns = [
    { key: 'name', label: t('salon.fields.name'), render: (row) => row.name },
    { key: 'title', label: t('salon.staff.title'), render: (row) => row.title ?? '' },
    { key: 'branch', label: t('salon.fields.branch'), render: (row) => (row.branch_id ? branchName(row.branch_id) : t('salon.staff.anyBranch')) },
    { key: 'user', label: t('salon.staff.user'), render: (row) => row.user_name ?? '' },
    { key: 'online', label: t('salon.staff.online'), render: (row) => (row.accepts_online ? t('salon.yes') : t('salon.no')) },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.archived_at ? 'archived' : 'active'} /> },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) =>
        manageStaff && !row.archived_at ? (
          <div className="flex justify-end gap-2">
            <Button variant="ghost" aria-label={t('salon.staff.editNamed', { name: row.name })} onClick={() => setDialog({ type: 'staff', row })}>
              {t('salon.staff.edit')}
            </Button>
            <Button variant="ghost" aria-label={t('salon.staff.hoursNamed', { name: row.name })} onClick={() => setDialog({ type: 'hours', row })}>
              {t('salon.staff.hours')}
            </Button>
            <Button variant="ghost" aria-label={t('salon.staff.timeOffNamed', { name: row.name })} onClick={() => setDialog({ type: 'timeoff', row })}>
              {t('salon.staff.timeOff')}
            </Button>
            <Button variant="ghost" aria-label={t('salon.staff.archiveNamed', { name: row.name })} onClick={() => setDialog({ type: 'archive', kind: 'staff', row })}>
              {t('salon.staff.archive')}
            </Button>
          </div>
        ) : null,
    },
  ]
  const resourceColumns = [
    { key: 'name', label: t('salon.fields.name'), render: (row) => row.name },
    { key: 'kind', label: t('salon.resources.kind'), render: (row) => row.kind },
    { key: 'branch', label: t('salon.fields.branch'), render: (row) => branchName(row.branch_id) },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.archived_at ? 'archived' : 'active'} /> },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) =>
        manageResources && !row.archived_at ? (
          <div className="flex justify-end gap-2">
            <Button variant="ghost" aria-label={t('salon.resources.editNamed', { name: row.name })} onClick={() => setDialog({ type: 'resource', row })}>
              {t('salon.staff.edit')}
            </Button>
            <Button variant="ghost" aria-label={t('salon.resources.archiveNamed', { name: row.name })} onClick={() => setDialog({ type: 'archive', kind: 'resource', row })}>
              {t('salon.staff.archive')}
            </Button>
          </div>
        ) : null,
    },
  ]
  const canAdd = tab === 'staff' ? manageStaff : manageResources
  return (
    <>
      <PageHeader
        title={t('salon.staff.pageTitle')}
        description={t('salon.staff.description')}
        actions={
          canAdd && companyId ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({ type: tab === 'staff' ? 'staff' : 'resource', row: null })}>
              {tab === 'staff' ? t('salon.staff.add') : t('salon.resources.add')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <PlaceBar place={place} branch={false} />
        <Tabs items={[{ value: 'staff', label: t('salon.staff.tab'), count: staff.rows.filter((row) => !row.archived_at).length }, { value: 'resources', label: t('salon.resources.tab'), count: resources.rows.filter((row) => !row.archived_at).length }]} value={tab} onChange={setTab} />
        {tab === 'staff' ? (
          <>
            <QueryNotes query={staff} />
            {!staff.isError ? <DataTable caption={t('salon.staff.pageTitle')} columns={staffColumns} rows={staff.rows} loading={staff.isPending && Boolean(companyId)} emptyText={t('salon.staff.empty')} /> : null}
          </>
        ) : (
          <>
            <QueryNotes query={resources} />
            {!resources.isError ? <DataTable caption={t('salon.resources.title')} columns={resourceColumns} rows={resources.rows} loading={resources.isPending && Boolean(companyId)} emptyText={t('salon.resources.empty')} /> : null}
          </>
        )}
      </div>
      {dialog?.type === 'staff' ? <StaffDialog key={dialog.row?.id ?? 'new'} member={dialog.row} companyId={companyId} branches={branches} onClose={() => setDialog(null)} /> : null}
      {dialog?.type === 'hours' ? <HoursDialog member={dialog.row} branches={branches} onClose={() => setDialog(null)} /> : null}
      {dialog?.type === 'timeoff' ? <TimeOffDialog member={dialog.row} onClose={() => setDialog(null)} /> : null}
      {dialog?.type === 'resource' ? <ResourceDialog key={dialog.row?.id ?? 'new'} resource={dialog.row} branches={branches} onClose={() => setDialog(null)} /> : null}
      <Dialog
        open={dialog?.type === 'archive'}
        title={t('salon.staff.archiveTitle')}
        onClose={() => setDialog(null)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate(dialog)}>
              {t('salon.staff.archive')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          {archive.isError ? <Alert tone="danger" title={salonError(archive.error)} /> : null}
          <p>{t('salon.staff.archiveAsk', { name: dialog?.row?.name ?? '' })}</p>
        </div>
      </Dialog>
    </>
  )
}
