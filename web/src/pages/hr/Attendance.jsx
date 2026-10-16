import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Checkbox, DataTable, Dialog, ListView, MultiSelect, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { localDateTimeIn } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord, useTimeZone } from '@/lib/useTimeZone'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { ExceptionBadges, ConfirmDialog, EmployeePicker, FormDialog, HrStatus, NoCompany, SectionTabs, TextAreaField } from './HrParts'
import { DAY_STATUSES, enumLabel, enumOptions, formatMinutes, hrKey, PERM, statusLabel, today, useHrCompany, usePlaces, WEEK_DAYS } from './hrData'
import { useClock, useDay, useHrMutation, withZoneOffset } from './hrForms'

function AttendanceHeader({ description, actions }) {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('hr.sections.attendance.title')} description={description} actions={actions} />
      <SectionTabs section="attendance" />
    </>
  )
}

/**
 * HR-25, HR-35: timesheet days (list `hr-timesheets`) with their
 * exceptions as dots and words; holders of `hr.attendance.manage` select
 * days and approve or reopen them.
 */
export default function Timesheets() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const day = useDay()
  const clock = useClock()
  const [selected, setSelected] = useState([])
  const manage = can(PERM.attendanceManage)
  const act = useHrMutation(({ action, ids }) => api.post(`hr/timesheets/${action}`, { day_ids: ids }), {
    success: (response, { action }) => (action === 'approve' ? t('hr.timesheets.approvedDays', { count: response?.data?.approved ?? 0 }) : t('hr.timesheets.reopenedDays', { count: response?.data?.reopened ?? 0 })),
    onDone: () => setSelected([]),
  })
  const toggle = (id) => setSelected((current) => (current.includes(id) ? current.filter((entry) => entry !== id) : [...current, id]))

  const columns = [
    ...(manage
      ? [{ key: 'select', label: t('hr.timesheets.select'), hideable: false, inMenu: false, render: (row) => <Checkbox aria-label={t('hr.timesheets.selectDay', { name: row.employee?.name ?? '', date: day(row.work_date) })} checked={selected.includes(row.id)} onChange={() => toggle(row.id)} /> }]
      : []),
    { key: 'work_date', label: t('hr.columns.workDate'), sortKey: 'work_date', hideable: false, render: (row) => <span className="tabular-nums">{day(row.work_date)}</span> },
    { key: 'employee', label: t('hr.columns.employee'), render: (row) => row.employee?.name ?? '' },
    { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
    { key: 'exceptions', label: t('hr.columns.exceptions'), render: (row) => <ExceptionBadges exceptions={row.exceptions} /> },
    { key: 'first_in', label: t('hr.timesheets.firstIn'), render: (row) => <span className="tabular-nums">{clock(row.first_in, row.company_id)}</span> },
    { key: 'last_out', label: t('hr.timesheets.lastOut'), render: (row) => <span className="tabular-nums">{clock(row.last_out, row.company_id)}</span> },
    ...['worked', 'regular', 'overtime', 'night', 'holiday', 'rest_day', 'late', 'early_leave'].map((kind) => ({
      key: `${kind}_minutes`,
      label: t(`hr.timesheets.minutes.${kind}`),
      align: 'end',
      defaultHidden: ['regular', 'holiday', 'rest_day', 'early_leave'].includes(kind),
      render: (row) => <span className="tabular-nums">{formatMinutes(row[`${kind}_minutes`])}</span>,
    })),
    { key: 'approved', label: t('hr.timesheets.approval'), render: (row) => <HrStatus status={row.approved_at ? 'approved' : 'not_approved'} /> },
  ]
  const list = useServerList({ id: 'hr-timesheets', endpoint: 'hr/timesheets', queryKey: [...hrKey, 'timesheets'], filters: { status: '', from: '', to: '' }, defaultSort: '-work_date', columns })
  const shown = list.rows.map((row) => row.id)
  const allShown = shown.length > 0 && shown.every((id) => selected.includes(id))

  return (
    <>
      <AttendanceHeader description={t('hr.timesheets.description')} />
      {manage ? (
        <div className="flex flex-wrap items-center gap-2">
          <Checkbox label={t('hr.timesheets.selectAll')} checked={allShown} onChange={() => setSelected(allShown ? selected.filter((id) => !shown.includes(id)) : [...new Set([...selected, ...shown])])} />
          <span className="text-caption text-ink-muted tabular-nums">{t('hr.timesheets.selected', { count: selected.length })}</span>
          <Button variant="primary" disabled={!selected.length} loading={act.isPending && act.variables?.action === 'approve'} onClick={() => act.mutate({ action: 'approve', ids: selected })}>
            {t('hr.timesheets.approve')}
          </Button>
          <Button disabled={!selected.length} loading={act.isPending && act.variables?.action === 'reopen'} onClick={() => act.mutate({ action: 'reopen', ids: selected })}>
            {t('hr.timesheets.reopen')}
          </Button>
        </div>
      ) : null}
      {act.isError ? <Alert tone="danger" title={errorMessage(act.error)} /> : null}
      <ListView
        list={list}
        title={t('hr.sections.attendance.timesheets')}
        searchable={false}
        filterFields={[
          { name: 'status', label: t('hr.filters.status'), options: [{ value: '', label: t('hr.filters.allStatuses') }, ...DAY_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] },
          dateField('from', t('hr.filters.from')),
          dateField('to', t('hr.filters.to')),
        ]}
        emptyText={t('hr.timesheets.none')}
      />
    </>
  )
}

/**
 * HR-22: punches (list `hr-punches`), append-only: HR adds a manual punch
 * with a note, and voids a wrong one with a reason.
 */
export function Punches() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const locale = useLocale()
  const companyOf = useCompanyOfRecord()
  const [dialog, setDialog] = useState(null)
  const manage = can(PERM.attendanceManage)
  const voidPunch = useHrMutation(({ row, reason }) => api.post(`hr/punches/${row.id}/void`, { reason }), { success: t('hr.punches.voided'), onDone: () => setDialog(null) })
  const columns = [
    { key: 'occurred_at', label: t('hr.columns.occurredAt'), sortKey: 'occurred_at', hideable: false, render: (row) => <span className="tabular-nums">{formatCompanyTime(row.occurred_at, locale, companyOf(row.company_id))}</span> },
    { key: 'employee', label: t('hr.columns.employee'), render: (row) => row.employee?.name ?? '' },
    { key: 'kind', label: t('hr.columns.kind'), render: (row) => enumLabel(t, 'punchKinds', row.kind) },
    { key: 'source', label: t('hr.columns.source'), render: (row) => enumLabel(t, 'punchSources', row.source) },
    { key: 'note', label: t('hr.fields.note'), render: (row) => row.note ?? '' },
    { key: 'state', label: t('hr.columns.status'), inMenu: false, render: (row) => (row.voided_at ? <HrStatus status="voided" /> : row.flags?.includes('period_closed') ? <HrStatus status="period_closed" /> : null) },
    ...(manage
      ? [{ key: 'actions', label: '', align: 'end', inMenu: false, render: (row) => (row.voided_at ? null : <Button variant="ghost" onClick={() => setDialog({ kind: 'void', row })}>{t('hr.punches.void')}</Button>) }]
      : []),
  ]
  const list = useServerList({ id: 'hr-punches', endpoint: 'hr/punches', queryKey: [...hrKey, 'punches'], filters: { from: '', to: '' }, defaultSort: '-occurred_at', columns })
  return (
    <>
      <AttendanceHeader
        description={t('hr.punches.description')}
        actions={
          manage ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'add' })}>
              {t('hr.punches.add')}
            </Button>
          ) : null
        }
      />
      <ListView list={list} title={t('hr.sections.attendance.punches')} searchable={false} filterFields={[dateField('from', t('hr.filters.from')), dateField('to', t('hr.filters.to'))]} emptyText={t('hr.punches.none')} />
      {dialog?.kind === 'add' ? <ManualPunch onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'void' ? (
        <ConfirmDialog
          open
          title={t('hr.punches.voidTitle')}
          text={t('hr.punches.voidHelp', { name: dialog.row.employee?.name ?? '' })}
          confirmLabel={t('hr.punches.void')}
          reasonLabel={t('hr.fields.reason')}
          reasonRequired
          tone="danger"
          pending={voidPunch.isPending}
          error={voidPunch.error}
          onConfirm={(reason) => voidPunch.mutate({ row: dialog.row, reason })}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </>
  )
}

/** HR-22: a manual punch at a wall-clock time in the employee's company zone, with a note. */
function ManualPunch({ onClose }) {
  const { t } = useTranslation()
  const company = useHrCompany()
  const [employee, setEmployee] = useState(null)
  const zone = useTimeZone(employee?.company?.id ?? company?.id)
  const [form, setForm] = useState(() => ({ kind: 'in', at: localDateTimeIn(zone), note: '' }))
  const save = useHrMutation((body) => api.post('hr/punches', body), { success: t('hr.punches.added'), onDone: onClose })
  const errors = save.errors(['employee_id', 'kind', 'occurred_at', 'note'], { punch_outside_employment: 'occurred_at', punch_out_of_window: 'occurred_at' })
  return (
    <FormDialog
      open
      title={t('hr.punches.addTitle')}
      onClose={onClose}
      submitLabel={t('hr.punches.add')}
      pending={save.isPending}
      disabled={!employee || !form.at || !form.note.trim()}
      errors={errors}
      onSubmit={() => save.mutate({ employee_id: employee.id, kind: form.kind, occurred_at: withZoneOffset(form.at, zone), note: form.note.trim() })}
    >
      <EmployeePicker company={company?.id} value={employee} required error={errors.fields.employee_id} onChange={setEmployee} />
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('hr.columns.kind')} options={enumOptions(t, 'punchKinds', ['in', 'out'])} value={form.kind} required error={errors.fields.kind} onChange={(event) => setForm({ ...form, kind: event.target.value })} />
        <TextField type="datetime-local" label={t('hr.punches.when')} help={zone ? t('hr.punches.zoneHelp', { zone }) : undefined} value={form.at} required error={errors.fields.occurred_at} onChange={(event) => setForm({ ...form, at: event.target.value })} />
      </div>
      <TextAreaField label={t('hr.fields.note')} help={t('hr.punches.noteHelp')} value={form.note} required maxLength={500} error={errors.fields.note} onChange={(event) => setForm({ ...form, note: event.target.value })} />
    </FormDialog>
  )
}

/**
 * HR-26: the company's attendance periods. Closing needs a time rule in
 * force and every day approved; the API says which is missing.
 */
export function Periods() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const day = useDay()
  const company = useHrCompany()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...hrKey, 'periods', company?.id], queryFn: () => api.get(`hr/attendance-periods?company=${company.id}&per_page=100`), enabled: Boolean(company) })
  const close = useHrMutation(({ row, body }) => api.post(`hr/attendance-periods/${row.id}/close`, body), { success: t('hr.periods.closed'), onDone: () => setDialog(null) })
  if (!company) return <NoCompany />
  return (
    <>
      <AttendanceHeader
        description={t('hr.periods.description', { company: company.name })}
        actions={
          can(PERM.attendanceManage) ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'add' })}>
              {t('hr.periods.add')}
            </Button>
          ) : null
        }
      />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('hr.sections.attendance.periods')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.periods.none')}
        columns={[
          { key: 'range', label: t('hr.periods.dates'), render: (row) => <span className="tabular-nums">{`${day(row.start_date)} – ${day(row.end_date)}`}</span> },
          { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
          // HR-26: `totals` holds one row per employee once the period is closed.
          { key: 'employees', label: t('hr.periods.employees'), align: 'end', render: (row) => <span className="tabular-nums">{Array.isArray(row.totals) ? row.totals.length : '—'}</span> },
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              row.status === 'closed' && Array.isArray(row.totals) ? (
                <Button variant="ghost" onClick={() => setDialog({ kind: 'totals', row })}>
                  {t('hr.periods.viewTotals')}
                </Button>
              ) : row.status === 'open' && can(PERM.attendanceClose) ? (
                <Button
                  onClick={() => {
                    close.reset()
                    setDialog({ kind: 'close', row })
                  }}
                >
                  {t('hr.periods.close')}
                </Button>
              ) : null,
          },
        ]}
      />
      {dialog?.kind === 'add' ? <PeriodDialog company={company} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'totals' ? <PeriodTotals period={dialog.row} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'close' ? <ClosePeriodDialog period={dialog.row} close={close} onClose={() => setDialog(null)} /> : null}
    </>
  )
}

/**
 * HR-26: closing a period. Inside the tills' offline window (7 days after
 * its last day, NFR-04) the API answers `period_offline_window`; the
 * dialog then offers to close it now with a reason, which is audited.
 */
function ClosePeriodDialog({ period, close, onClose }) {
  const { t } = useTranslation()
  const day = useDay()
  const [forcing, setForcing] = useState(false)
  const [reason, setReason] = useState('')
  const offline = close.error?.code === 'period_offline_window'
  const errors = close.errors(['reason'])
  const submit = () => {
    if (forcing || offline) {
      setForcing(true)
      close.mutate({ row: period, body: { force: true, reason } })
    } else {
      close.mutate({ row: period, body: {} })
    }
  }
  const showForce = forcing || offline
  return (
    <Dialog
      open
      title={t('hr.periods.closeTitle', { from: day(period.start_date), to: day(period.end_date) })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('hr.actions.keep')}
          </Button>
          <Button variant="pay" loading={close.isPending} disabled={showForce && reason.trim() === ''} onClick={submit}>
            {showForce ? t('hr.periods.forceClose') : t('hr.periods.close')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p>{t('hr.periods.closeHelp')}</p>
        {showForce ? (
          <>
            <Alert tone="warning" title={t('hr.periods.offlineTitle')}>
              {offline ? errorMessage(close.error) : t('hr.periods.offlineHelp')}
            </Alert>
            <TextAreaField label={t('hr.periods.forceReason')} required value={reason} maxLength={500} error={errors.fields.reason} onChange={(event) => setReason(event.target.value)} />
          </>
        ) : null}
        {close.isError && !offline && !errors.fields.reason ? (
          <Alert tone="danger" title={errorMessage(close.error)}>
            {close.error?.code === 'days_unapproved' ? (
              <Link to={`/hr/attendance/timesheets?from=${period.start_date}&to=${period.end_date}`} className="text-label text-primary hover:text-primary-hover">
                {t('hr.periods.openTimesheets')}
              </Link>
            ) : close.error?.code === 'time_rules_missing' ? (
              <Link to="/hr/attendance/rules" className="text-label text-primary hover:text-primary-hover">
                {t('hr.periods.openRules')}
              </Link>
            ) : null}
          </Alert>
        ) : null}
      </div>
    </Dialog>
  )
}

/** HR-26: a closed period's totals per employee (days and hours), as Payroll receives them. */
function PeriodTotals({ period, onClose }) {
  const { t } = useTranslation()
  const day = useDay()
  // The totals carry employee ids only: names come from the company's employees.
  const employees = useQuery({ queryKey: [...hrKey, 'employees', 'period-names', period.company_id], queryFn: () => api.get(`hr/employees?company=${period.company_id}&status=all&per_page=200`), retry: false })
  const nameOf = (id) => (employees.data?.data ?? []).find((row) => row.id === id)?.name ?? id
  const hours = (key) => ({ key, label: t(`hr.periods.totals.${key}`), align: 'end', render: (row) => <span className="tabular-nums">{formatMinutes(row[key])}</span> })
  const days = (key) => ({ key, label: t(`hr.periods.totals.${key}`), align: 'end', render: (row) => <span className="tabular-nums">{row[key] ?? 0}</span> })
  return (
    <Dialog open size="lg" title={t('hr.periods.totalsTitle', { from: day(period.start_date), to: day(period.end_date) })} onClose={onClose} footer={<Button onClick={onClose}>{t('hr.actions.close')}</Button>}>
      <div className="flex flex-col gap-3 overflow-x-auto">
        <p className="text-caption text-ink-muted">{t('hr.periods.totalsHelp')}</p>
        <DataTable
          caption={t('hr.periods.totalsTitle', { from: day(period.start_date), to: day(period.end_date) })}
          rows={period.totals.map((row) => ({ id: row.employee_id, ...row }))}
          emptyText={t('hr.periods.noTotals')}
          columns={[
            { key: 'employee', label: t('hr.columns.employee'), render: (row) => nameOf(row.employee_id) },
            days('days_present'),
            days('days_absent'),
            days('days_leave'),
            hours('worked_minutes'),
            hours('overtime_minutes'),
            hours('night_minutes'),
            hours('holiday_minutes'),
            hours('rest_day_minutes'),
            hours('late_minutes'),
          ]}
        />
      </div>
    </Dialog>
  )
}

function PeriodDialog({ company, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ start_date: '', end_date: '' })
  const save = useHrMutation((body) => api.post('hr/attendance-periods', { company_id: company.id, ...body }), { success: t('hr.periods.added'), onDone: onClose })
  const errors = save.errors(['start_date', 'end_date'])
  return (
    <FormDialog open title={t('hr.periods.addTitle')} onClose={onClose} submitLabel={t('hr.periods.add')} pending={save.isPending} disabled={!form.start_date || !form.end_date} errors={errors} onSubmit={() => save.mutate(form)}>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField type="date" label={t('hr.fields.startDate')} value={form.start_date} required error={errors.fields.start_date} onChange={(event) => setForm({ ...form, start_date: event.target.value })} />
        <TextField type="date" label={t('hr.fields.endDate')} value={form.end_date} required error={errors.fields.end_date} onChange={(event) => setForm({ ...form, end_date: event.target.value })} />
      </div>
    </FormDialog>
  )
}

/** HR-20: time rules as dated data: overtime thresholds, the night window, grace minutes and rest days (HR counts, Payroll pays). */
export function TimeRules() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const day = useDay()
  const company = useHrCompany()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...hrKey, 'time-rules', company?.id], queryFn: () => api.get(`hr/time-rules?company=${company.id}&per_page=100`), enabled: Boolean(company) })
  if (!company) return <NoCompany />
  const manage = can(PERM.attendanceManage)
  const minutes = (value) => (value === null || value === undefined ? '—' : t('hr.setup.minutes', { count: value }))
  return (
    <>
      <AttendanceHeader
        description={t('hr.rules.description', { company: company.name })}
        actions={
          manage ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({})}>
              {t('hr.rules.add')}
            </Button>
          ) : null
        }
      />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('hr.sections.attendance.rules')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.rules.none')}
        columns={[
          { key: 'from', label: t('hr.rules.effective'), render: (row) => <span className="tabular-nums">{`${day(row.effective_from)} – ${row.effective_to ? day(row.effective_to) : t('hr.rules.open')}`}</span> },
          { key: 'daily', label: t('hr.rules.dailyOvertime'), align: 'end', render: (row) => minutes(row.daily_overtime_after_minutes) },
          { key: 'weekly', label: t('hr.rules.weeklyOvertime'), align: 'end', render: (row) => minutes(row.weekly_overtime_after_minutes) },
          { key: 'night', label: t('hr.rules.night'), render: (row) => (row.night_start ? <span className="tabular-nums">{`${row.night_start} – ${row.night_end}`}</span> : '—') },
          { key: 'grace', label: t('hr.rules.grace'), render: (row) => t('hr.rules.graceValue', { late: row.late_grace_minutes ?? 0, early: row.early_leave_grace_minutes ?? 0 }) },
          { key: 'rest', label: t('hr.rules.restDays'), render: (row) => (row.rest_days ?? []).map((value) => enumLabel(t, 'weekDays', value)).join(', ') || '—' },
          { key: 'actions', label: '', align: 'end', render: (row) => (manage ? <Button variant="ghost" icon="edit" onClick={() => setDialog({ row })}>{t('hr.actions.edit')}</Button> : null) },
        ]}
      />
      {dialog ? <TimeRuleDialog company={company} row={dialog.row} onClose={() => setDialog(null)} /> : null}
    </>
  )
}

const RULE_FIELDS = ['effective_from', 'effective_to', 'daily_overtime_after_minutes', 'weekly_overtime_after_minutes', 'night_start', 'night_end', 'late_grace_minutes', 'early_leave_grace_minutes', 'rest_days']

function TimeRuleDialog({ company, row, onClose }) {
  const { t } = useTranslation()
  const text = (value) => (value === null || value === undefined ? '' : String(value))
  const [form, setForm] = useState(() => ({
    effective_from: row?.effective_from ?? today(),
    effective_to: row?.effective_to ?? '',
    daily_overtime_after_minutes: text(row?.daily_overtime_after_minutes ?? 480),
    weekly_overtime_after_minutes: text(row?.weekly_overtime_after_minutes ?? 2700),
    night_start: row?.night_start ?? '',
    night_end: row?.night_end ?? '',
    late_grace_minutes: text(row?.late_grace_minutes ?? 5),
    early_leave_grace_minutes: text(row?.early_leave_grace_minutes ?? 5),
    rest_days: row?.rest_days ?? ['sun'],
  }))
  const save = useHrMutation((body) => (row ? api.patch(`hr/time-rules/${row.id}`, body) : api.post('hr/time-rules', { company_id: company.id, ...body })), { success: t('hr.rules.saved'), onDone: onClose })
  const errors = save.errors(RULE_FIELDS)
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const number = (value) => (value === '' ? null : Number(value))
  const submit = () =>
    save.mutate({
      ...form,
      effective_to: form.effective_to || null,
      daily_overtime_after_minutes: number(form.daily_overtime_after_minutes),
      weekly_overtime_after_minutes: number(form.weekly_overtime_after_minutes),
      night_start: form.night_start || null,
      night_end: form.night_end || null,
      late_grace_minutes: number(form.late_grace_minutes) ?? 0,
      early_leave_grace_minutes: number(form.early_leave_grace_minutes) ?? 0,
    })
  const minutesField = (key, label, max, help) => <TextField type="number" min={0} max={max} label={label} help={help} suffix={t('hr.rules.min')} value={form[key]} error={errors.fields[key]} onChange={set(key)} />
  return (
    <FormDialog open size="lg" title={row ? t('hr.rules.editTitle') : t('hr.rules.addTitle')} onClose={onClose} submitLabel={t('hr.actions.saveChanges')} pending={save.isPending} disabled={!form.effective_from} errors={errors} onSubmit={submit}>
      <p className="text-caption text-ink-muted">{t('hr.rules.help')}</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField type="date" label={t('hr.rules.effectiveFrom')} value={form.effective_from} required error={errors.fields.effective_from} onChange={set('effective_from')} />
        <TextField type="date" label={t('hr.rules.effectiveTo')} value={form.effective_to} error={errors.fields.effective_to} onChange={set('effective_to')} />
        {minutesField('daily_overtime_after_minutes', t('hr.rules.dailyOvertime'), 1440, t('hr.rules.dailyHelp'))}
        {minutesField('weekly_overtime_after_minutes', t('hr.rules.weeklyOvertime'), 10080, t('hr.rules.weeklyHelp'))}
        <TextField type="time" label={t('hr.rules.nightStart')} value={form.night_start} error={errors.fields.night_start} onChange={set('night_start')} />
        <TextField type="time" label={t('hr.rules.nightEnd')} value={form.night_end} error={errors.fields.night_end} onChange={set('night_end')} />
        {minutesField('late_grace_minutes', t('hr.rules.lateGrace'), 240)}
        {minutesField('early_leave_grace_minutes', t('hr.rules.earlyGrace'), 240)}
      </div>
      <MultiSelect label={t('hr.rules.restDays')} options={enumOptions(t, 'weekDays', WEEK_DAYS)} value={form.rest_days} error={errors.fields.rest_days} onChange={(values) => setForm({ ...form, rest_days: values })} />
    </FormDialog>
  )
}

/**
 * HR-24: attendance devices per location. A device's token is shown once,
 * when it is added or rotated; it is never shown again.
 */
export function Devices() {
  const { t } = useTranslation()
  const locale = useLocale()
  const companyOf = useCompanyOfRecord()
  const company = useHrCompany()
  const places = usePlaces()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...hrKey, 'devices', company?.id], queryFn: () => api.get(`hr/attendance-devices?company=${company.id}&per_page=100`), enabled: Boolean(company) })
  const act = useHrMutation(({ row, action }) => api.post(`hr/attendance-devices/${row.id}/${action}`, {}), {
    success: (_, { action }) => (action === 'token' ? null : t(`hr.devices.${action}Done`)),
    onDone: (response, { action, row }) => setDialog(action === 'token' ? { kind: 'token', token: response.data.token, name: row.name } : null),
  })
  if (!company) return <NoCompany />
  const locationName = (id) => places.locations.find((location) => location.id === id)?.name ?? ''
  return (
    <>
      <AttendanceHeader
        description={t('hr.devices.description')}
        actions={
          <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'add' })}>
            {t('hr.devices.add')}
          </Button>
        }
      />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('hr.sections.attendance.devices')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.devices.none')}
        columns={[
          { key: 'name', label: t('hr.devices.name'), render: (row) => <span className="font-medium">{row.name}</span> },
          { key: 'location', label: t('hr.fields.location'), render: (row) => locationName(row.location_id) },
          { key: 'vendor', label: t('hr.devices.vendor'), render: (row) => row.vendor ?? '' },
          { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
          { key: 'seen', label: t('hr.devices.lastSeen'), render: (row) => (row.last_seen_at ? <span className="tabular-nums">{formatCompanyTime(row.last_seen_at, locale, companyOf(row.company_id))}</span> : t('hr.devices.never')) },
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              row.status === 'retired' ? null : (
                <span className="flex justify-end gap-2">
                  <Button variant="ghost" icon="key" onClick={() => setDialog({ kind: 'confirm', action: 'token', row })}>
                    {t('hr.devices.rotate')}
                  </Button>
                  {row.status === 'active' ? (
                    <Button variant="ghost" onClick={() => setDialog({ kind: 'confirm', action: 'suspend', row })}>
                      {t('hr.devices.suspend')}
                    </Button>
                  ) : (
                    <Button variant="ghost" onClick={() => setDialog({ kind: 'confirm', action: 'resume', row })}>
                      {t('hr.devices.resume')}
                    </Button>
                  )}
                  <Button variant="ghost" onClick={() => setDialog({ kind: 'confirm', action: 'retire', row })}>
                    {t('hr.devices.retire')}
                  </Button>
                </span>
              ),
          },
        ]}
      />
      {dialog?.kind === 'add' ? <DeviceDialog company={company} onClose={() => setDialog(null)} onToken={(token, name) => setDialog({ kind: 'token', token, name })} /> : null}
      {dialog?.kind === 'confirm' ? (
        <ConfirmDialog
          open
          title={t(`hr.devices.${dialog.action}Title`, { name: dialog.row.name })}
          text={t(`hr.devices.${dialog.action}Help`)}
          confirmLabel={t(`hr.devices.${dialog.action === 'token' ? 'rotate' : dialog.action}`)}
          tone={dialog.action === 'resume' ? 'primary' : 'danger'}
          pending={act.isPending}
          error={act.error}
          onConfirm={() => act.mutate({ row: dialog.row, action: dialog.action })}
          onClose={() => setDialog(null)}
        />
      ) : null}
      {dialog?.kind === 'token' ? <TokenOnce name={dialog.name} token={dialog.token} onClose={() => setDialog(null)} /> : null}
    </>
  )
}

function DeviceDialog({ company, onClose, onToken }) {
  const { t } = useTranslation()
  const places = usePlaces()
  const branches = places.branches.filter((branch) => branch.company_id === company.id).map((branch) => branch.id)
  const locations = places.locations.filter((location) => !location.archived_at && branches.includes(location.branch_id ?? location.branch?.id))
  const [form, setForm] = useState({ location_id: '', name: '', vendor: '' })
  const save = useHrMutation((body) => api.post('hr/attendance-devices', body), { success: t('hr.devices.added'), onDone: (response) => onToken(response.meta.token, response.data.name) })
  const errors = save.errors(['location_id', 'name', 'vendor'])
  return (
    <FormDialog
      open
      title={t('hr.devices.addTitle')}
      onClose={onClose}
      submitLabel={t('hr.devices.add')}
      pending={save.isPending}
      disabled={!form.location_id || !form.name.trim()}
      errors={errors}
      onSubmit={() => save.mutate({ location_id: form.location_id, name: form.name.trim(), vendor: form.vendor.trim() || null })}
    >
      <Select label={t('hr.fields.location')} placeholder={t('hr.pickers.location')} options={locations.map((location) => ({ value: location.id, label: location.name }))} value={form.location_id} required error={errors.fields.location_id} onChange={(event) => setForm({ ...form, location_id: event.target.value })} />
      <TextField label={t('hr.devices.name')} value={form.name} required maxLength={120} error={errors.fields.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
      <TextField label={t('hr.devices.vendor')} help={t('hr.devices.vendorHelp')} value={form.vendor} maxLength={120} error={errors.fields.vendor} onChange={(event) => setForm({ ...form, vendor: event.target.value })} />
    </FormDialog>
  )
}

/** HR-24: the device's token, shown this once (stored hashed); copy it into the device or its bridge. */
function TokenOnce({ name, token, onClose }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(token)
      setCopied(true)
    } catch {
      setCopied(false)
    }
  }
  return (
    <Dialog
      open
      title={t('hr.devices.tokenShownTitle', { name })}
      onClose={onClose}
      footer={
        <>
          <Button icon="copy" onClick={copy}>
            {copied ? t('hr.devices.copied') : t('hr.devices.copy')}
          </Button>
          <Button variant="primary" onClick={onClose}>
            {t('hr.devices.done')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Alert tone="warning" title={t('hr.devices.tokenOnce')} />
        <p className="rounded-md border border-border bg-surface-300 p-3 font-mono text-body break-all text-ink" data-testid="device-token">
          {token}
        </p>
      </div>
    </Dialog>
  )
}
