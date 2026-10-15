import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { Back, CalendarDate, ConfirmDialog, Detail, DetailGrid, DetailState, EmployeePicker, FormDialog, HrStatus, NoCompany, SectionTabs } from './HrParts'
import { addDays, branchesOf, daysFrom, employeeLabel, hrKey, PERM, statusLabel, SWAP_STATUSES, today, useDepartments, useHrCompany, usePlaces, useRotations, useShifts, weekStart } from './hrData'
import { useClock, useDay, useHrMutation } from './hrForms'

function RostersHeader({ description, actions }) {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('hr.sections.rosters.title')} description={description} actions={actions} />
      <SectionTabs section="rosters" />
    </>
  )
}

/**
 * HR-18: planned shifts over a week by employee and day, filtered by
 * branch and department, with each shift's code, times and whether the day
 * is a public holiday; holders of `hr.roster.manage` add, move or cancel
 * single shifts.
 */
export default function RosterWeek() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { can } = usePermissions()
  const clock = useClock()
  const company = useHrCompany()
  const places = usePlaces()
  const departments = useDepartments(company?.id)
  const [from, setFrom] = useState(() => weekStart(today()))
  const [branch, setBranch] = useState('')
  const [department, setDepartment] = useState('')
  const [dialog, setDialog] = useState(null)
  const to = addDays(from, 6)
  const params = new URLSearchParams({ from, to })
  if (company) params.set('company', company.id)
  if (branch) params.set('branch', branch)
  if (department) params.set('department', department)
  const query = useQuery({ queryKey: [...hrKey, 'roster-shifts', params.toString()], queryFn: () => api.get(`hr/roster-shifts?${params}`) })
  const shifts = query.data?.data ?? []
  const days = daysFrom(from, 7)
  const employees = []
  for (const shift of shifts) if (!employees.some((entry) => entry.id === shift.employee.id)) employees.push(shift.employee)
  employees.sort((a, b) => a.name.localeCompare(b.name))
  const manage = can(PERM.rosterManage)
  const holidays = new Set(shifts.filter((shift) => shift.holiday).map((shift) => shift.date))
  const dayLabel = (date) => new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : locale, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`))

  return (
    <>
      <RostersHeader
        description={t('hr.week.description')}
        actions={
          manage ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'add' })}>
              {t('hr.week.addShift')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex items-center gap-2">
          <Button variant="ghost" icon="chevronLeft" aria-label={t('hr.week.previous')} onClick={() => setFrom(addDays(from, -7))} />
          <h2 className="text-h3 text-ink tabular-nums">{t('hr.week.range', { from: formatDate(`${from}T12:00:00Z`, locale), to: formatDate(`${to}T12:00:00Z`, locale) })}</h2>
          <Button variant="ghost" icon="chevronRight" aria-label={t('hr.week.next')} onClick={() => setFrom(addDays(from, 7))} />
          <Button variant="ghost" onClick={() => setFrom(weekStart(today()))}>
            {t('hr.week.thisWeek')}
          </Button>
        </div>
        <div className="ml-auto flex flex-wrap gap-3">
          <Select label={t('hr.fields.branch')} options={[{ value: '', label: t('hr.filters.allBranches') }, ...branchesOf(places, company?.id).map((row) => ({ value: row.id, label: row.name }))]} value={branch} onChange={(event) => setBranch(event.target.value)} />
          <Select label={t('hr.fields.department')} options={[{ value: '', label: t('hr.filters.allDepartments') }, ...departments.map((row) => ({ value: row.id, label: row.name }))]} value={department} onChange={(event) => setDepartment(event.target.value)} />
        </div>
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {!query.isLoading && employees.length === 0 ? <p className="text-body text-ink-muted">{t('hr.week.none')}</p> : null}
      {employees.length ? (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full border-collapse text-caption">
            <caption className="sr-only">{t('hr.week.caption')}</caption>
            <thead>
              <tr>
                <th scope="col" className="sticky left-0 bg-surface-200 px-3 py-2 text-left font-normal text-ink-muted">
                  {t('hr.columns.employee')}
                </th>
                {days.map((date) => (
                  <th key={date} scope="col" className="min-w-28 px-2 py-2 text-left font-normal text-ink-muted">
                    <span className="block whitespace-nowrap">{dayLabel(date)}</span>
                    {holidays.has(date) ? <StatusBadge tone="info">{t('hr.week.holiday')}</StatusBadge> : null}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {employees.map((employee) => (
                <tr key={employee.id} className="border-t border-border">
                  <th scope="row" className="sticky left-0 bg-surface-200 px-3 py-2 text-left font-medium whitespace-nowrap text-ink">
                    {employee.name}
                  </th>
                  {days.map((date) => {
                    const shift = shifts.find((entry) => entry.employee.id === employee.id && entry.date === date)
                    return (
                      <td key={date} className="px-2 py-2 align-top">
                        {shift ? (
                          <button
                            type="button"
                            disabled={!manage}
                            onClick={() => setDialog({ kind: 'shift', shift })}
                            aria-label={t('hr.week.shiftLabel', { employee: employee.name, day: dayLabel(date), shift: shift.shift?.code ?? '' })}
                            className="flex w-full flex-col items-start gap-0.5 rounded-md border border-border px-2 py-1 text-left hover:bg-surface-300 disabled:cursor-default disabled:hover:bg-transparent"
                          >
                            <span className="font-medium text-ink">{shift.shift?.code}</span>
                            <span className="text-ink-muted tabular-nums">{`${clock(shift.start_at)} – ${clock(shift.end_at)}`}</span>
                            {shift.holiday ? <span className="text-ink-muted">{t('hr.week.holiday')}</span> : null}
                          </button>
                        ) : null}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {dialog?.kind === 'add' ? <PlannedShiftDialog company={company} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'shift' ? <PlannedShiftDialog company={company} shift={dialog.shift} onClose={() => setDialog(null)} onCancelShift={() => setDialog({ kind: 'cancel', shift: dialog.shift })} /> : null}
      {dialog?.kind === 'cancel' ? <CancelShift shift={dialog.shift} onClose={() => setDialog(null)} /> : null}
    </>
  )
}

/** HR-18: add a single shift, or move one (date, shift). */
function PlannedShiftDialog({ company, shift, onClose, onCancelShift }) {
  const { t } = useTranslation()
  const shifts = useShifts(company?.id)
  const [employee, setEmployee] = useState(shift?.employee ?? null)
  const [form, setForm] = useState({ date: shift?.date ?? today(), shift_id: shift?.shift?.id ?? '' })
  const save = useHrMutation((body) => (shift ? api.patch(`hr/roster-shifts/${shift.id}`, body) : api.post('hr/roster-shifts', body)), { success: shift ? t('hr.week.moved') : t('hr.week.added'), onDone: onClose })
  const errors = save.errors(['employee_id', 'date', 'shift_id'])
  return (
    <FormDialog
      open
      title={shift ? t('hr.week.moveTitle', { name: shift.employee.name }) : t('hr.week.addTitle')}
      onClose={onClose}
      submitLabel={shift ? t('hr.week.move') : t('hr.week.addShift')}
      pending={save.isPending}
      disabled={!employee || !form.date || !form.shift_id}
      errors={errors}
      onSubmit={() => save.mutate(shift ? form : { employee_id: employee.id, ...form })}
    >
      {shift ? null : <EmployeePicker company={company?.id} value={employee} required error={errors.fields.employee_id} onChange={setEmployee} />}
      <TextField type="date" label={t('hr.fields.date')} value={form.date} required error={errors.fields.date} onChange={(event) => setForm({ ...form, date: event.target.value })} />
      <Select
        label={t('hr.fields.shift')}
        placeholder={t('hr.pickers.shift')}
        options={shifts.map((row) => ({ value: row.id, label: `${row.code} · ${row.start_time} – ${row.end_time}` }))}
        value={form.shift_id}
        required
        error={errors.fields.shift_id}
        onChange={(event) => setForm({ ...form, shift_id: event.target.value })}
      />
      {shift && onCancelShift ? (
        <div>
          <Button variant="danger" onClick={onCancelShift}>
            {t('hr.week.cancelShift')}
          </Button>
        </div>
      ) : null}
    </FormDialog>
  )
}

function CancelShift({ shift, onClose }) {
  const { t } = useTranslation()
  const day = useDay()
  const cancel = useHrMutation(() => api.post(`hr/roster-shifts/${shift.id}/cancel`, {}), { success: t('hr.week.cancelled'), onDone: onClose })
  return (
    <ConfirmDialog
      open
      title={t('hr.week.cancelTitle')}
      text={t('hr.week.cancelHelp', { name: shift.employee.name, date: day(shift.date) })}
      confirmLabel={t('hr.week.cancelShift')}
      tone="danger"
      pending={cancel.isPending}
      error={cancel.error}
      onConfirm={() => cancel.mutate()}
      onClose={onClose}
    />
  )
}

/** HR-17: the company's rotating rosters (per branch or department). */
export function RosterList() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const company = useHrCompany()
  const [adding, setAdding] = useState(false)
  const query = useQuery({ queryKey: [...hrKey, 'rosters', company?.id], queryFn: () => api.get(`hr/rosters?company=${company.id}&per_page=100`), enabled: Boolean(company) })
  const rotations = useRotations(company?.id)
  if (!company) return <NoCompany />
  return (
    <>
      <RostersHeader
        description={t('hr.rosters.description', { company: company.name })}
        actions={
          can(PERM.rosterManage) ? (
            <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>
              {t('hr.rosters.add')}
            </Button>
          ) : null
        }
      />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('hr.sections.rosters.list')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.rosters.none')}
        onRowClick={(row) => navigate(`/hr/rosters/list/${row.id}`)}
        columns={[
          { key: 'name', label: t('hr.rosters.name'), render: (row) => <span className="font-medium">{row.name}</span> },
          { key: 'branch', label: t('hr.fields.branch'), render: (row) => row.branch?.name ?? '' },
          { key: 'department', label: t('hr.fields.department'), render: (row) => row.department?.name ?? '' },
          { key: 'rotation', label: t('hr.rosters.rotation'), render: (row) => rotations.find((rotation) => rotation.id === row.rotation_id)?.name ?? '' },
          { key: 'anchor', label: t('hr.rosters.anchor'), render: (row) => <CalendarDate value={row.anchor_date} /> },
        ]}
      />
      {adding ? <RosterDialog company={company} onClose={() => setAdding(false)} onDone={(response) => navigate(`/hr/rosters/list/${response.data.id}`)} /> : null}
    </>
  )
}

function RosterDialog({ company, roster, onClose, onDone }) {
  const { t } = useTranslation()
  const places = usePlaces()
  const departments = useDepartments(company.id)
  const rotations = useRotations(company.id)
  const [form, setForm] = useState(() => ({ name: roster?.name ?? '', branch_id: roster?.branch?.id ?? '', department_id: roster?.department?.id ?? '', rotation_id: roster?.rotation_id ?? '', anchor_date: roster?.anchor_date ?? today() }))
  const save = useHrMutation((body) => (roster ? api.patch(`hr/rosters/${roster.id}`, body) : api.post('hr/rosters', { company_id: company.id, ...body })), {
    success: t('hr.rosters.saved'),
    onDone: (response) => (onDone ? onDone(response) : onClose()),
  })
  const errors = save.errors(['name', 'branch_id', 'department_id', 'rotation_id', 'anchor_date'])
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  return (
    <FormDialog
      open
      title={roster ? t('hr.rosters.editTitle') : t('hr.rosters.addTitle')}
      onClose={onClose}
      submitLabel={t('hr.actions.saveChanges')}
      pending={save.isPending}
      disabled={!form.name.trim() || !form.rotation_id || !form.anchor_date}
      errors={errors}
      onSubmit={() => save.mutate({ ...form, name: form.name.trim(), branch_id: form.branch_id || null, department_id: form.department_id || null })}
    >
      <TextField label={t('hr.rosters.name')} value={form.name} required maxLength={120} error={errors.fields.name} onChange={set('name')} />
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('hr.fields.branch')} options={[{ value: '', label: t('hr.pickers.noBranch') }, ...branchesOf(places, company.id).map((row) => ({ value: row.id, label: row.name }))]} value={form.branch_id} error={errors.fields.branch_id} onChange={set('branch_id')} />
        <Select label={t('hr.fields.department')} options={[{ value: '', label: t('hr.pickers.noDepartment') }, ...departments.map((row) => ({ value: row.id, label: row.name }))]} value={form.department_id} error={errors.fields.department_id} onChange={set('department_id')} />
        <Select label={t('hr.rosters.rotation')} placeholder={t('hr.pickers.rotation')} options={rotations.map((row) => ({ value: row.id, label: row.name }))} value={form.rotation_id} required error={errors.fields.rotation_id} onChange={set('rotation_id')} />
        <TextField type="date" label={t('hr.rosters.anchor')} help={t('hr.rosters.anchorHelp')} value={form.anchor_date} required error={errors.fields.anchor_date} onChange={set('anchor_date')} />
      </div>
    </FormDialog>
  )
}

/** HR-17: a roster, its members (offset into the cycle, optional period) and generation over a range (at most 92 days). */
export function RosterDetail() {
  const { t } = useTranslation()
  const { rosterId } = useParams()
  const { can } = usePermissions()
  const company = useHrCompany()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...hrKey, 'roster', rosterId], queryFn: () => api.get(`hr/rosters/${rosterId}`) })
  const rotations = useRotations(query.data?.data?.company_id)
  const back = <Back to="/hr/rosters/list">{t('hr.rosters.back')}</Back>
  if (!query.data) return <DetailState query={query} back={back} notFound={t('hr.rosters.notFound')} />
  const roster = query.data.data
  const manage = can(PERM.rosterManage) && !roster.archived_at
  const rotation = rotations.find((row) => row.id === roster.rotation_id)
  const owner = { id: roster.company_id, name: company?.id === roster.company_id ? company.name : '' }
  return (
    <>
      {back}
      <PageHeader
        title={roster.name}
        description={[roster.branch?.name, roster.department?.name].filter(Boolean).join(' · ')}
        actions={
          manage ? (
            <>
              <Button icon="edit" onClick={() => setDialog('edit')}>
                {t('hr.actions.edit')}
              </Button>
              <Button variant="primary" onClick={() => setDialog('generate')}>
                {t('hr.rosters.generate')}
              </Button>
            </>
          ) : null
        }
      />
      <Card title={t('hr.rosters.details')}>
        <DetailGrid>
          <Detail label={t('hr.rosters.rotation')}>{rotation ? `${rotation.name} · ${t('hr.rotations.cycle', { count: rotation.cycle_days })}` : '—'}</Detail>
          <Detail label={t('hr.rosters.anchor')}>
            <CalendarDate value={roster.anchor_date} />
          </Detail>
          <Detail label={t('hr.columns.status')}>
            <HrStatus status={roster.archived_at ? 'archived' : 'active'} />
          </Detail>
        </DetailGrid>
      </Card>
      <Members roster={roster} manage={manage} />
      {dialog === 'edit' ? <RosterDialog company={owner} roster={roster} onClose={() => setDialog(null)} /> : null}
      {dialog === 'generate' ? <GenerateDialog roster={roster} onClose={() => setDialog(null)} /> : null}
    </>
  )
}

function Members({ roster, manage }) {
  const { t } = useTranslation()
  const [rows, setRows] = useState(() => (roster.members ?? []).map((member) => ({ employee: member.employee, offset_days: String(member.offset_days ?? 0), from_date: member.from_date ?? '', to_date: member.to_date ?? '' })))
  const [adding, setAdding] = useState(null)
  const [dirty, setDirty] = useState(false)
  const save = useHrMutation((body) => api.put(`hr/rosters/${roster.id}/members`, body), { success: t('hr.rosters.membersSaved'), onDone: () => setDirty(false) })
  const errors = save.errors([])
  const change = (index, key, value) => {
    setRows(rows.map((row, at) => (at === index ? { ...row, [key]: value } : row)))
    setDirty(true)
  }
  return (
    <Card
      title={t('hr.rosters.members')}
      subtitle={t('hr.rosters.membersHelp')}
      actions={
        manage ? (
          <Button variant="primary" loading={save.isPending} disabled={!dirty} onClick={() => save.mutate({ members: rows.map((row) => ({ employee_id: row.employee.id, offset_days: Number(row.offset_days) || 0, from_date: row.from_date || null, to_date: row.to_date || null })) })}>
            {t('hr.rosters.saveMembers')}
          </Button>
        ) : null
      }
    >
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <DataTable
          caption={t('hr.rosters.members')}
          rows={rows.map((row, index) => ({ id: row.employee.id, index, ...row }))}
          emptyText={t('hr.rosters.noMembers')}
          columns={[
            { key: 'employee', label: t('hr.columns.employee'), render: (row) => employeeLabel(row.employee) },
            { key: 'offset', label: t('hr.rosters.offset'), render: (row) => (manage ? <TextField type="number" aria-label={t('hr.rosters.offsetOf', { name: row.employee.name })} min={0} max={55} value={row.offset_days} onChange={(event) => change(row.index, 'offset_days', event.target.value)} /> : row.offset_days) },
            { key: 'from', label: t('hr.rosters.from'), render: (row) => (manage ? <TextField type="date" aria-label={t('hr.rosters.fromOf', { name: row.employee.name })} value={row.from_date} onChange={(event) => change(row.index, 'from_date', event.target.value)} /> : <CalendarDate value={row.from_date} />) },
            { key: 'to', label: t('hr.rosters.to'), render: (row) => (manage ? <TextField type="date" aria-label={t('hr.rosters.toOf', { name: row.employee.name })} value={row.to_date} onChange={(event) => change(row.index, 'to_date', event.target.value)} /> : <CalendarDate value={row.to_date} />) },
            ...(manage
              ? [
                  {
                    key: 'remove',
                    label: '',
                    align: 'end',
                    render: (row) => (
                      <Button
                        variant="ghost"
                        icon="remove"
                        onClick={() => {
                          setRows(rows.filter((_, at) => at !== row.index))
                          setDirty(true)
                        }}
                      >
                        {t('hr.rosters.removeMember')}
                      </Button>
                    ),
                  },
                ]
              : []),
          ]}
        />
        {manage ? (
          <div className="flex flex-wrap items-end gap-2">
            <EmployeePicker label={t('hr.rosters.addMember')} company={roster.company_id} value={adding} exclude={rows.map((row) => row.employee.id)} onChange={setAdding} className="min-w-64" />
            <Button
              icon="plus"
              disabled={!adding}
              onClick={() => {
                setRows([...rows, { employee: adding, offset_days: '0', from_date: '', to_date: '' }])
                setAdding(null)
                setDirty(true)
              }}
            >
              {t('hr.rosters.add')}
            </Button>
          </div>
        ) : null}
      </div>
    </Card>
  )
}

function GenerateDialog({ roster, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ from: today(), to: addDays(today(), 27) })
  const [result, setResult] = useState(null)
  const save = useHrMutation((body) => api.post(`hr/rosters/${roster.id}/generate`, body), { onDone: (response) => setResult(response.data) })
  const errors = save.errors(['from', 'to'])
  if (result) {
    return (
      <FormDialog open title={t('hr.rosters.generated')} onClose={onClose} submitLabel={t('hr.actions.close')} onSubmit={onClose}>
        <p>{t('hr.rosters.generatedHelp', { created: result.created ?? 0, kept: result.kept ?? 0, leave: result.leave ?? 0 })}</p>
      </FormDialog>
    )
  }
  return (
    <FormDialog open title={t('hr.rosters.generateTitle')} onClose={onClose} submitLabel={t('hr.rosters.generate')} pending={save.isPending} disabled={!form.from || !form.to} errors={errors} onSubmit={() => save.mutate(form)}>
      <p>{t('hr.rosters.generateHelp')}</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField type="date" label={t('hr.rosters.from')} value={form.from} required error={errors.fields.from} onChange={(event) => setForm({ ...form, from: event.target.value })} />
        <TextField type="date" label={t('hr.rosters.to')} value={form.to} required error={errors.fields.to} onChange={(event) => setForm({ ...form, to: event.target.value })} />
      </div>
    </FormDialog>
  )
}

/** HR-16: shifts (times may cross midnight, unpaid break) and rotations (a cycle of days, each a shift or rest). */
export function RosterSetup() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const company = useHrCompany()
  const [dialog, setDialog] = useState(null)
  const shiftsQuery = useQuery({ queryKey: [...hrKey, 'shifts', company?.id], queryFn: () => api.get(`hr/shifts?company=${company.id}&per_page=200`), enabled: Boolean(company) })
  const rotationsQuery = useQuery({ queryKey: [...hrKey, 'rotations', company?.id], queryFn: () => api.get(`hr/rotations?company=${company.id}&per_page=200`), enabled: Boolean(company) })
  const archive = useHrMutation(({ kind, row }) => api.post(`hr/${kind}/${row.id}/archive`, {}), { success: t('hr.setup.archived'), onDone: () => setDialog(null) })
  if (!company) return <NoCompany />
  const manage = can(PERM.rosterManage)
  const shifts = shiftsQuery.data?.data ?? []
  const actions = (kind, row) =>
    manage ? (
      <span className="flex justify-end gap-2">
        <Button variant="ghost" icon="edit" onClick={() => setDialog({ kind: kind === 'shifts' ? 'shift' : 'rotation', row })}>
          {t('hr.actions.edit')}
        </Button>
        <Button variant="ghost" icon="archive" onClick={() => setDialog({ kind: 'archive', target: kind, row })}>
          {t('hr.actions.archive')}
        </Button>
      </span>
    ) : null
  return (
    <>
      <RostersHeader description={t('hr.setup.description', { company: company.name })} />
      <Card
        title={t('hr.setup.shifts')}
        actions={
          manage ? (
            <Button icon="plus" onClick={() => setDialog({ kind: 'shift' })}>
              {t('hr.setup.addShift')}
            </Button>
          ) : null
        }
      >
        <DataTable
          caption={t('hr.setup.shifts')}
          loading={shiftsQuery.isLoading}
          rows={shifts}
          emptyText={t('hr.setup.noShifts')}
          columns={[
            { key: 'code', label: t('hr.columns.code'), render: (row) => <span className="font-medium">{row.code}</span> },
            { key: 'name', label: t('hr.setup.name'), render: (row) => row.name },
            { key: 'times', label: t('hr.setup.times'), render: (row) => <span className="tabular-nums">{`${row.start_time} – ${row.end_time}`}</span> },
            { key: 'break', label: t('hr.setup.break'), align: 'end', render: (row) => <span className="tabular-nums">{t('hr.setup.minutes', { count: row.break_minutes ?? 0 })}</span> },
            { key: 'actions', label: '', align: 'end', render: (row) => actions('shifts', row) },
          ]}
        />
      </Card>
      <Card
        title={t('hr.setup.rotations')}
        actions={
          manage ? (
            <Button icon="plus" disabled={!shifts.length} onClick={() => setDialog({ kind: 'rotation' })}>
              {t('hr.setup.addRotation')}
            </Button>
          ) : null
        }
      >
        <DataTable
          caption={t('hr.setup.rotations')}
          loading={rotationsQuery.isLoading}
          rows={rotationsQuery.data?.data ?? []}
          emptyText={t('hr.setup.noRotations')}
          columns={[
            { key: 'name', label: t('hr.setup.name'), render: (row) => <span className="font-medium">{row.name}</span> },
            { key: 'cycle', label: t('hr.setup.cycle'), render: (row) => t('hr.rotations.cycle', { count: row.cycle_days }) },
            { key: 'pattern', label: t('hr.setup.pattern'), render: (row) => <span className="tabular-nums">{(row.days ?? []).map((id) => shifts.find((shift) => shift.id === id)?.code ?? (id ? '?' : '–')).join(' ')}</span> },
            { key: 'actions', label: '', align: 'end', render: (row) => actions('rotations', row) },
          ]}
        />
      </Card>
      {dialog?.kind === 'shift' ? <ShiftDialog company={company} row={dialog.row} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'rotation' ? <RotationDialog company={company} shifts={shifts} row={dialog.row} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'archive' ? (
        <ConfirmDialog
          open
          title={t('hr.setup.archiveTitle', { name: dialog.row.name })}
          text={t('hr.setup.archiveHelp')}
          confirmLabel={t('hr.actions.archive')}
          tone="danger"
          pending={archive.isPending}
          error={archive.error}
          onConfirm={() => archive.mutate({ kind: dialog.target, row: dialog.row })}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </>
  )
}

function ShiftDialog({ company, row, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => ({ code: row?.code ?? '', name: row?.name ?? '', start_time: row?.start_time ?? '08:00', end_time: row?.end_time ?? '17:00', break_minutes: String(row?.break_minutes ?? 60) }))
  const save = useHrMutation((body) => (row ? api.patch(`hr/shifts/${row.id}`, body) : api.post('hr/shifts', { company_id: company.id, ...body })), { success: t('hr.setup.saved'), onDone: onClose })
  const errors = save.errors(['code', 'name', 'start_time', 'end_time', 'break_minutes'])
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  return (
    <FormDialog
      open
      title={row ? t('hr.setup.editShift') : t('hr.setup.addShift')}
      onClose={onClose}
      submitLabel={t('hr.actions.saveChanges')}
      pending={save.isPending}
      disabled={!form.code.trim() || !form.name.trim() || !form.start_time || !form.end_time}
      errors={errors}
      onSubmit={() => save.mutate({ ...form, code: form.code.trim(), name: form.name.trim(), break_minutes: Number(form.break_minutes) || 0 })}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('hr.columns.code')} value={form.code} required maxLength={40} error={errors.fields.code} onChange={set('code')} />
        <TextField label={t('hr.setup.name')} value={form.name} required maxLength={120} error={errors.fields.name} onChange={set('name')} />
        <TextField type="time" label={t('hr.setup.start')} value={form.start_time} required error={errors.fields.start_time} onChange={set('start_time')} />
        <TextField type="time" label={t('hr.setup.end')} help={t('hr.setup.endHelp')} value={form.end_time} required error={errors.fields.end_time} onChange={set('end_time')} />
        <TextField type="number" label={t('hr.setup.breakMinutes')} min={0} max={720} value={form.break_minutes} error={errors.fields.break_minutes} onChange={set('break_minutes')} />
      </div>
    </FormDialog>
  )
}

function RotationDialog({ company, shifts, row, onClose }) {
  const { t } = useTranslation()
  const [name, setName] = useState(row?.name ?? '')
  const [days, setDays] = useState(() => row?.days ?? Array.from({ length: 7 }, () => ''))
  const save = useHrMutation((body) => (row ? api.patch(`hr/rotations/${row.id}`, body) : api.post('hr/rotations', { company_id: company.id, ...body })), { success: t('hr.setup.saved'), onDone: onClose })
  const errors = save.errors(['name', 'days'])
  const options = [{ value: '', label: t('hr.rotations.rest') }, ...shifts.map((shift) => ({ value: shift.id, label: `${shift.code} · ${shift.start_time} – ${shift.end_time}` }))]
  const resize = (count) => {
    const size = Math.min(56, Math.max(1, Number(count) || 1))
    setDays(Array.from({ length: size }, (_, index) => days[index] ?? ''))
  }
  return (
    <FormDialog
      open
      size="lg"
      title={row ? t('hr.setup.editRotation') : t('hr.setup.addRotation')}
      onClose={onClose}
      submitLabel={t('hr.actions.saveChanges')}
      pending={save.isPending}
      disabled={!name.trim() || days.length === 0}
      errors={errors}
      onSubmit={() => save.mutate({ name: name.trim(), days: days.map((id) => id || null) })}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('hr.setup.name')} value={name} required maxLength={120} error={errors.fields.name} onChange={(event) => setName(event.target.value)} />
        <TextField type="number" label={t('hr.rotations.cycleDays')} help={t('hr.rotations.cycleHelp')} min={1} max={56} value={String(days.length)} onChange={(event) => resize(event.target.value)} />
      </div>
      {errors.fields.days ? <Alert tone="danger" title={errors.fields.days} /> : null}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {days.map((value, index) => (
          <Select key={index} label={t('hr.rotations.day', { number: index + 1 })} options={options} value={value ?? ''} onChange={(event) => setDays(days.map((entry, at) => (at === index ? event.target.value : entry)))} />
        ))}
      </div>
    </FormDialog>
  )
}

/** HR-19: shift swaps; the peer accepts or declines, then the swap's flow decides (WF-01). */
export function Swaps() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const day = useDay()
  const [status, setStatus] = useState('')
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...hrKey, 'swaps', status], queryFn: () => api.get(`hr/shift-swaps?per_page=100${status ? `&status=${status}` : ''}`) })
  const act = useHrMutation(({ row, action }) => api.post(`hr/shift-swaps/${row.id}/${action}`, {}), { success: (_, { action }) => t(`hr.swaps.${action}Done`), onDone: () => setDialog(null) })
  const manage = can(PERM.rosterManage)
  const shiftText = (shift) => (shift ? `${day(shift.date)} · ${shift.shift?.code ?? ''}` : t('hr.swaps.giveAway'))
  return (
    <>
      <RostersHeader description={t('hr.swaps.description')} />
      <Select label={t('hr.filters.status')} options={[{ value: '', label: t('hr.filters.allStatuses') }, ...SWAP_STATUSES.map((value) => ({ value, label: statusLabel(t, value) }))]} value={status} onChange={(event) => setStatus(event.target.value)} className="w-64" />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('hr.sections.rosters.swaps')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.swaps.none')}
        columns={[
          { key: 'employee', label: t('hr.swaps.asking'), render: (row) => row.employee?.name ?? '' },
          { key: 'shift', label: t('hr.swaps.shift'), render: (row) => <span className="tabular-nums">{shiftText(row.roster_shift)}</span> },
          { key: 'peer', label: t('hr.swaps.peer'), render: (row) => row.peer?.name ?? '' },
          { key: 'peerShift', label: t('hr.swaps.peerShift'), render: (row) => <span className="tabular-nums">{shiftText(row.peer_roster_shift)}</span> },
          { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              manage ? (
                <span className="flex justify-end gap-2">
                  {row.status === 'awaiting_peer' ? (
                    <>
                      <Button variant="ghost" onClick={() => setDialog({ row, action: 'accept' })}>
                        {t('hr.swaps.accept')}
                      </Button>
                      <Button variant="ghost" onClick={() => setDialog({ row, action: 'decline' })}>
                        {t('hr.swaps.decline')}
                      </Button>
                    </>
                  ) : null}
                  {row.status === 'awaiting_peer' || row.status === 'pending' ? (
                    <Button variant="ghost" onClick={() => setDialog({ row, action: 'cancel' })}>
                      {t('hr.swaps.cancel')}
                    </Button>
                  ) : null}
                </span>
              ) : null,
          },
        ]}
      />
      {dialog ? (
        <ConfirmDialog
          open
          title={t(`hr.swaps.${dialog.action}Title`)}
          text={t('hr.swaps.confirmHelp', { employee: dialog.row.employee?.name ?? '', peer: dialog.row.peer?.name ?? '' })}
          confirmLabel={t(`hr.swaps.${dialog.action}`)}
          tone={dialog.action === 'accept' ? 'primary' : 'danger'}
          pending={act.isPending}
          error={act.error}
          onConfirm={() => act.mutate(dialog)}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </>
  )
}
