import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, MoneyInput, Select, TextField } from '@/components/ds'
import { decimalsOf, minorToDecimal } from '@/lib/money'
import { Amount, CalendarDate, ChecklistCard, ConfirmDialog, Days, Empty, EmployeePicker, ExceptionBadges, FormDialog, HrStatus, LeaveRequestFields, TextAreaField } from './HrParts'
import {
  CASE_CATEGORIES,
  CONTRACT_TYPES,
  DOCUMENT_CATEGORIES,
  enumLabel,
  enumOptions,
  formatMinutes,
  hrKey,
  isDays,
  monthRange,
  PAY_BASES,
  PERM,
  today,
  useCurrencyCodes,
  useDepartments,
  useLeaveTypes,
  usePlaces,
  usePositions,
  withoutBlanks,
} from './hrData'
import { blankLeave, LEAVE_FIELDS, leaveBody, openLink, useClock, useDay, useHrMutation } from './hrForms'

const useList = (key, path, enabled = true) => useQuery({ queryKey: [...hrKey, ...key], queryFn: () => api.get(path), enabled, retry: false })

/** HR-07: contracts, newest first; pay only when the API shows it (`pay_visible`). */
export function ContractsTab({ employee }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const day = useDay()
  const [dialog, setDialog] = useState(null)
  const query = useList(['contracts', employee.id], `hr/employees/${employee.id}/contracts`)
  const manage = can(PERM.contractManage)
  const payEdit = Boolean(employee.access?.pay?.edit)
  const rows = query.data?.data ?? []
  return (
    <Card
      title={t('hr.profile.tabs.contracts')}
      actions={
        manage && payEdit ? (
          <Button icon="plus" onClick={() => setDialog({ kind: 'add' })}>
            {t('hr.contracts.add')}
          </Button>
        ) : null
      }
    >
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('hr.profile.tabs.contracts')}
        loading={query.isLoading}
        rows={rows}
        emptyText={t('hr.contracts.none')}
        columns={[
          { key: 'type', label: t('hr.contracts.type'), render: (row) => enumLabel(t, 'contractTypes', row.type) },
          { key: 'period', label: t('hr.contracts.period'), render: (row) => <span className="tabular-nums">{`${day(row.start_date)} – ${row.end_date ? day(row.end_date) : t('hr.contracts.open')}`}</span> },
          { key: 'probation', label: t('hr.contracts.probationEnd'), render: (row) => <CalendarDate value={row.probation_end_date} /> },
          { key: 'position', label: t('hr.fields.position'), render: (row) => row.position?.title ?? '' },
          { key: 'basis', label: t('hr.contracts.payBasis'), render: (row) => enumLabel(t, 'payBases', row.pay_basis) },
          { key: 'pay', label: t('hr.contracts.pay'), align: 'end', render: (row) => (row.pay_visible ? <Amount value={row.pay} /> : <span className="text-ink-muted">{t('hr.contracts.payHidden')}</span>) },
          { key: 'hours', label: t('hr.contracts.hours'), align: 'end', render: (row) => <Days value={row.hours_per_week} /> },
          { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              manage && row.status === 'active' ? (
                <span className="flex justify-end gap-2">
                  <Button variant="ghost" icon="edit" onClick={() => setDialog({ kind: 'edit', contract: row })}>
                    {t('hr.actions.edit')}
                  </Button>
                  <Button variant="ghost" onClick={() => setDialog({ kind: 'end', contract: row })}>
                    {t('hr.contracts.end')}
                  </Button>
                </span>
              ) : null,
          },
        ]}
      />
      {dialog?.kind === 'add' || dialog?.kind === 'edit' ? <ContractDialog employee={employee} contract={dialog.contract} payEdit={payEdit} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'end' ? <EndContract contract={dialog.contract} onClose={() => setDialog(null)} /> : null}
    </Card>
  )
}

const CONTRACT_FIELDS = ['type', 'start_date', 'end_date', 'probation_end_date', 'position_id', 'department_id', 'pay_basis', 'pay_amount', 'currency', 'hours_per_week']

function ContractDialog({ employee, contract, payEdit, onClose }) {
  const { t } = useTranslation()
  const places = usePlaces()
  const company = places.companies.find((row) => row.id === employee.company?.id)
  const currencies = useCurrencyCodes(company)
  const positions = usePositions(employee.company?.id)
  const departments = useDepartments(employee.company?.id)
  // HR-07: pay is part of a contract; it is sent only by holders of `hr.pay.edit`.
  const withPay = payEdit && (!contract || contract.pay_visible)
  const [form, setForm] = useState(() => ({
    type: contract?.type ?? 'permanent',
    start_date: contract?.start_date ?? employee.hire_date ?? today(),
    end_date: contract?.end_date ?? '',
    probation_end_date: contract?.probation_end_date ?? '',
    position_id: contract?.position?.id ?? employee.position?.id ?? '',
    department_id: contract?.department?.id ?? employee.department?.id ?? '',
    pay_basis: contract?.pay_basis ?? 'monthly',
    pay_minor: contract?.pay?.amount_minor ?? '',
    currency: contract?.pay?.currency ?? company?.base_currency ?? currencies[0] ?? '',
    hours_per_week: contract?.hours_per_week ?? '',
  }))
  const save = useHrMutation((body) => (contract ? api.patch(`hr/contracts/${contract.id}`, body) : api.post(`hr/employees/${employee.id}/contracts`, body)), {
    success: t('hr.contracts.saved'),
    onDone: onClose,
  })
  const errors = save.errors(CONTRACT_FIELDS)
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const currency = form.currency || currencies[0] || ''
  const ready = form.type && form.start_date && (!withPay || (form.pay_minor !== '' && form.pay_minor !== null && currency))
  const submit = () => {
    const { pay_minor: minor, pay_basis: basis, ...rest } = form
    delete rest.currency
    save.mutate({
      ...withoutBlanks(rest),
      ...(withPay ? { pay_basis: basis, pay_amount: minorToDecimal(minor, decimalsOf(currency)), currency } : {}),
    })
  }
  return (
    <FormDialog open size="lg" title={contract ? t('hr.contracts.editTitle') : t('hr.contracts.addTitle')} onClose={onClose} submitLabel={t('hr.actions.saveChanges')} pending={save.isPending} disabled={!ready} errors={errors} onSubmit={submit}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('hr.contracts.type')} options={enumOptions(t, 'contractTypes', CONTRACT_TYPES)} value={form.type} required error={errors.fields.type} onChange={set('type')} />
        <TextField type="number" label={t('hr.contracts.hours')} value={form.hours_per_week} min={0} max={168} error={errors.fields.hours_per_week} onChange={set('hours_per_week')} />
        <TextField type="date" label={t('hr.fields.startDate')} value={form.start_date} required error={errors.fields.start_date} onChange={set('start_date')} />
        <TextField type="date" label={t('hr.fields.endDate')} help={t('hr.contracts.endHelp')} value={form.end_date} required={form.type === 'fixed_term'} error={errors.fields.end_date} onChange={set('end_date')} />
        <TextField type="date" label={t('hr.contracts.probationEnd')} value={form.probation_end_date} error={errors.fields.probation_end_date} onChange={set('probation_end_date')} />
        <Select
          label={t('hr.fields.position')}
          options={[{ value: '', label: t('hr.pickers.noPosition') }, ...positions.map((row) => ({ value: row.id, label: `${row.title} · ${row.code}` }))]}
          value={form.position_id}
          error={errors.fields.position_id}
          onChange={set('position_id')}
        />
        <Select
          label={t('hr.fields.department')}
          options={[{ value: '', label: t('hr.pickers.noDepartment') }, ...departments.map((row) => ({ value: row.id, label: `${row.name} · ${row.code}` }))]}
          value={form.department_id}
          error={errors.fields.department_id}
          onChange={set('department_id')}
        />
      </div>
      {withPay ? (
        <fieldset className="grid gap-3 sm:grid-cols-3">
          <legend className="mb-2 text-label text-ink">{t('hr.contracts.pay')}</legend>
          <Select label={t('hr.contracts.payBasis')} options={enumOptions(t, 'payBases', PAY_BASES)} value={form.pay_basis} required error={errors.fields.pay_basis} onChange={set('pay_basis')} />
          <Select label={t('hr.payAccounts.currency')} options={currencies.map((code) => ({ value: code, label: code }))} value={currency} required error={errors.fields.currency} onChange={(event) => setForm({ ...form, currency: event.target.value, pay_minor: '' })} />
          <MoneyInput key={currency} label={t('hr.contracts.payAmount')} currency={currency} value={form.pay_minor} required error={errors.fields.pay_amount} onChange={(value) => setForm({ ...form, pay_minor: value ?? '' })} />
        </fieldset>
      ) : (
        <p className="text-caption text-ink-muted">{t('hr.contracts.payNotEditable')}</p>
      )}
    </FormDialog>
  )
}

function EndContract({ contract, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ end_date: today(), reason: '' })
  const save = useHrMutation((body) => api.post(`hr/contracts/${contract.id}/end`, body), { success: t('hr.contracts.ended'), onDone: onClose })
  const errors = save.errors(['end_date', 'reason'])
  return (
    <FormDialog open title={t('hr.contracts.endTitle')} onClose={onClose} variant="danger" submitLabel={t('hr.contracts.end')} pending={save.isPending} disabled={!form.end_date} errors={errors} onSubmit={() => save.mutate({ end_date: form.end_date, reason: form.reason.trim() || null })}>
      <TextField type="date" label={t('hr.fields.endDate')} value={form.end_date} required error={errors.fields.end_date} onChange={(event) => setForm({ ...form, end_date: event.target.value })} />
      <TextField label={t('hr.fields.reason')} value={form.reason} maxLength={500} error={errors.fields.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} />
    </FormDialog>
  )
}

/** HR-13, HR-15: balances of each type (entitled this year, taken, pending, available). */
export function BalancesTable({ balances, caption }) {
  const { t } = useTranslation()
  return (
    <DataTable
      caption={caption}
      rows={balances.map((row) => ({ id: row.leave_type.id, ...row }))}
      emptyText={t('hr.balances.none')}
      columns={[
        { key: 'type', label: t('hr.fields.leaveType'), render: (row) => `${row.leave_type.name} · ${row.leave_type.code}` },
        { key: 'entitled', label: t('hr.balances.entitled'), align: 'end', render: (row) => (row.days_needed ? <HrStatus status="days_needed" /> : <Days value={row.entitled} />) },
        { key: 'taken', label: t('hr.balances.taken'), align: 'end', render: (row) => <Days value={row.taken} /> },
        { key: 'pending', label: t('hr.balances.pending'), align: 'end', render: (row) => <Days value={row.pending} /> },
        { key: 'available', label: t('hr.balances.available'), align: 'end', render: (row) => <span className="font-medium">{<Days value={row.available} />}</span> },
      ]}
    />
  )
}

/** HR-14: leave requests in a table, with cancel for those still open. */
export function LeaveRequestsTable({ rows, loading, caption, onCancel, showEmployee = false }) {
  const { t } = useTranslation()
  const day = useDay()
  return (
    <DataTable
      caption={caption}
      loading={loading}
      rows={rows}
      emptyText={t('hr.leave.none')}
      columns={[
        ...(showEmployee ? [{ key: 'employee', label: t('hr.columns.employee'), render: (row) => row.employee?.name ?? '' }] : []),
        { key: 'number', label: t('hr.columns.number'), render: (row) => <span className="tabular-nums">{row.number}</span> },
        { key: 'type', label: t('hr.fields.leaveType'), render: (row) => row.leave_type?.name ?? '' },
        { key: 'dates', label: t('hr.leave.dates'), render: (row) => <span className="tabular-nums">{row.start_date === row.end_date ? day(row.start_date) : `${day(row.start_date)} – ${day(row.end_date)}`}</span> },
        { key: 'days', label: t('hr.leave.days'), align: 'end', render: (row) => <Days value={row.days} /> },
        { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
        {
          key: 'actions',
          label: '',
          align: 'end',
          render: (row) =>
            onCancel && (row.status === 'pending' || (row.status === 'approved' && row.end_date >= today())) ? (
              <Button variant="ghost" onClick={() => onCancel(row)}>
                {t('hr.leave.cancel')}
              </Button>
            ) : null,
        },
      ]}
    />
  )
}

/**
 * HR-14: ask for leave on an employee's behalf (`hr.leave.request`).
 * Without `employee`, the dialog asks who.
 */
export function RequestLeaveDialog({ employee, companyId, onClose }) {
  const { t } = useTranslation()
  const [who, setWho] = useState(employee ?? null)
  const [form, setForm] = useState(blankLeave)
  const types = useLeaveTypes(employee?.company?.id ?? companyId)
  const save = useHrMutation((body) => api.post('hr/leave-requests', body), { success: (response) => t('hr.leave.requested', { number: response?.data?.number ?? '' }), onDone: onClose })
  const errors = save.errors(LEAVE_FIELDS)
  const ready = who && form.leave_type_id && form.start_date && form.end_date
  return (
    <FormDialog open title={t('hr.leave.requestTitle')} onClose={onClose} submitLabel={t('hr.leave.sendRequest')} pending={save.isPending} disabled={!ready} errors={errors} onSubmit={() => save.mutate({ employee_id: who.id, ...leaveBody(form) })}>
      <LeaveRequestFields
        form={form}
        setForm={setForm}
        types={types}
        errors={errors}
        employeePicker={employee ? null : <EmployeePicker company={companyId} value={who} required error={errors.fields.employee_id} onChange={setWho} />}
      />
    </FormDialog>
  )
}

/** HR-13: a manual adjustment (signed days, reason) by `hr.leave.manage`. */
export function AdjustDialog({ employee, companyId, onClose }) {
  const { t } = useTranslation()
  const [who, setWho] = useState(employee ?? null)
  const types = useLeaveTypes(employee?.company?.id ?? employee?.company_id ?? companyId)
  const [form, setForm] = useState({ leave_type_id: '', days: '', effective_on: today(), reason: '' })
  const save = useHrMutation((body) => api.post('hr/leave-adjustments', body), { success: t('hr.balances.adjusted'), onDone: onClose })
  const errors = save.errors(['employee_id', 'leave_type_id', 'days', 'effective_on', 'reason'])
  const ready = who && form.leave_type_id && isDays(form.days, { signed: true }) && Number(form.days) !== 0 && form.reason.trim()
  return (
    <FormDialog open title={t('hr.balances.adjustTitle')} onClose={onClose} submitLabel={t('hr.balances.adjust')} pending={save.isPending} disabled={!ready} errors={errors} onSubmit={() => save.mutate({ employee_id: who.id, ...form, days: form.days.trim(), reason: form.reason.trim() })}>
      {employee ? null : <EmployeePicker company={companyId} value={who} required error={errors.fields.employee_id} onChange={setWho} />}
      <Select label={t('hr.fields.leaveType')} placeholder={t('hr.pickers.leaveType')} options={types.map((type) => ({ value: type.id, label: `${type.name} · ${type.code}` }))} value={form.leave_type_id} required error={errors.fields.leave_type_id} onChange={(event) => setForm({ ...form, leave_type_id: event.target.value })} />
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('hr.balances.days')} help={t('hr.balances.daysHelp')} inputMode="decimal" value={form.days} required error={errors.fields.days} onChange={(event) => setForm({ ...form, days: event.target.value })} />
        <TextField type="date" label={t('hr.balances.effectiveOn')} value={form.effective_on} error={errors.fields.effective_on} onChange={(event) => setForm({ ...form, effective_on: event.target.value })} />
      </div>
      <TextField label={t('hr.fields.reason')} value={form.reason} required maxLength={500} error={errors.fields.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} />
    </FormDialog>
  )
}

/** HR-13 to HR-15: the employee's balances and requests; ask on their behalf, cancel, adjust. */
export function LeaveTab({ employee }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [dialog, setDialog] = useState(null)
  const balances = useList(['leave-balances', employee.id], `hr/leave-balances?employee=${employee.id}`)
  const requests = useList(['leave-requests', 'employee', employee.id], `hr/leave-requests?employee=${employee.id}&status=all&sort=-start_date&per_page=50`)
  const cancel = useHrMutation(({ row, reason }) => api.post(`hr/leave-requests/${row.id}/cancel`, { reason: reason || null }), { success: t('hr.leave.cancelled'), onDone: () => setDialog(null) })
  return (
    <>
      <Card
        title={t('hr.balances.title')}
        actions={
          can(PERM.leaveManage) ? (
            <Button onClick={() => setDialog({ kind: 'adjust' })}>{t('hr.balances.adjust')}</Button>
          ) : null
        }
      >
        {balances.isError ? <Alert tone="danger" title={errorMessage(balances.error)} /> : <BalancesTable caption={t('hr.balances.title')} balances={balances.data?.data?.[0]?.balances ?? []} />}
      </Card>
      <Card
        title={t('hr.leave.requestsTitle')}
        actions={
          can(PERM.leaveRequest) && employee.status === 'active' ? (
            <Button icon="plus" onClick={() => setDialog({ kind: 'request' })}>
              {t('hr.leave.request')}
            </Button>
          ) : null
        }
      >
        <LeaveRequestsTable caption={t('hr.leave.requestsTitle')} loading={requests.isLoading} rows={requests.data?.data ?? []} onCancel={can(PERM.leaveRequest) ? (row) => setDialog({ kind: 'cancel', row }) : null} />
      </Card>
      {dialog?.kind === 'request' ? <RequestLeaveDialog employee={employee} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'adjust' ? <AdjustDialog employee={employee} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'cancel' ? (
        <ConfirmDialog
          open
          title={t('hr.leave.cancelTitle', { number: dialog.row.number })}
          text={t('hr.leave.cancelHelp')}
          confirmLabel={t('hr.leave.cancel')}
          reasonLabel={t('hr.fields.reason')}
          tone="danger"
          pending={cancel.isPending}
          error={cancel.error}
          onConfirm={(reason) => cancel.mutate({ row: dialog.row, reason })}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </>
  )
}

/** HR-25: timesheet days in a table (worked, overtime, night, late), exceptions as dots and words. */
export function TimesheetTable({ rows, loading, caption, showEmployee = false, select }) {
  const { t } = useTranslation()
  const day = useDay()
  const clock = useClock()
  return (
    <DataTable
      caption={caption}
      loading={loading}
      rows={rows}
      emptyText={t('hr.timesheets.none')}
      columns={[
        ...(select ? [select] : []),
        { key: 'work_date', label: t('hr.columns.workDate'), render: (row) => <span className="tabular-nums">{day(row.work_date)}</span> },
        ...(showEmployee ? [{ key: 'employee', label: t('hr.columns.employee'), render: (row) => row.employee?.name ?? '' }] : []),
        { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
        { key: 'exceptions', label: t('hr.columns.exceptions'), render: (row) => <ExceptionBadges exceptions={row.exceptions} /> },
        { key: 'in', label: t('hr.timesheets.firstIn'), render: (row) => <span className="tabular-nums">{clock(row.first_in)}</span> },
        { key: 'out', label: t('hr.timesheets.lastOut'), render: (row) => <span className="tabular-nums">{clock(row.last_out)}</span> },
        { key: 'worked', label: t('hr.timesheets.worked'), align: 'end', render: (row) => <span className="tabular-nums">{formatMinutes(row.worked_minutes)}</span> },
        { key: 'overtime', label: t('hr.timesheets.overtime'), align: 'end', render: (row) => <span className="tabular-nums">{formatMinutes(row.overtime_minutes)}</span> },
        { key: 'night', label: t('hr.timesheets.night'), align: 'end', render: (row) => <span className="tabular-nums">{formatMinutes(row.night_minutes)}</span> },
        { key: 'late', label: t('hr.timesheets.late'), align: 'end', render: (row) => <span className="tabular-nums">{formatMinutes(row.late_minutes)}</span> },
        { key: 'approved', label: t('hr.timesheets.approval'), render: (row) => <HrStatus status={row.approved_at ? 'approved' : 'not_approved'} /> },
      ]}
    />
  )
}

/** HR-22: punches in a table. */
export function PunchTable({ rows, loading, caption, showEmployee = false, actions }) {
  const { t } = useTranslation()
  const day = useDay()
  const clock = useClock()
  return (
    <DataTable
      caption={caption}
      loading={loading}
      rows={rows}
      emptyText={t('hr.punches.none')}
      columns={[
        { key: 'when', label: t('hr.columns.occurredAt'), render: (row) => <span className="tabular-nums">{`${day(row.occurred_at?.slice(0, 10))} ${clock(row.occurred_at)}`}</span> },
        ...(showEmployee ? [{ key: 'employee', label: t('hr.columns.employee'), render: (row) => row.employee?.name ?? '' }] : []),
        { key: 'kind', label: t('hr.columns.kind'), render: (row) => enumLabel(t, 'punchKinds', row.kind) },
        { key: 'source', label: t('hr.columns.source'), render: (row) => enumLabel(t, 'punchSources', row.source) },
        { key: 'note', label: t('hr.fields.note'), render: (row) => row.note ?? '' },
        { key: 'state', label: t('hr.columns.status'), render: (row) => (row.voided_at ? <HrStatus status="voided" /> : row.flags?.includes('period_closed') ? <HrStatus status="period_closed" /> : null) },
        ...(actions ? [actions] : []),
      ]}
    />
  )
}

/** HR-22 to HR-25: the employee's days and punches this month; their clock-in PIN (HR-23). */
export function AttendanceTab({ employee }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [dialog, setDialog] = useState(null)
  const { first } = monthRange(today())
  const range = `employee=${employee.id}&from=${first}&to=${today()}&per_page=100`
  const days = useList(['timesheets', 'employee', employee.id], `hr/timesheets?${range}&sort=work_date`)
  const punches = useList(['punches', 'employee', employee.id], `hr/punches?${range}&sort=-occurred_at`)
  const manage = can(PERM.attendanceManage)
  const clear = useHrMutation(() => api.post(`hr/employees/${employee.id}/clock-pin/clear`, {}), { success: t('hr.pin.cleared'), onDone: () => setDialog(null) })
  return (
    <>
      <Card
        title={t('hr.pin.title')}
        subtitle={t('hr.pin.help')}
        actions={
          manage ? (
            <>
              <Button icon="key" onClick={() => setDialog('set')}>
                {employee.has_clock_pin ? t('hr.pin.change') : t('hr.pin.set')}
              </Button>
              {employee.has_clock_pin ? (
                <Button variant="ghost" onClick={() => setDialog('clear')}>
                  {t('hr.pin.clear')}
                </Button>
              ) : null}
            </>
          ) : null
        }
      >
        <HrStatus status={employee.has_clock_pin ? 'pin_set' : 'pin_none'} />
      </Card>
      <Card title={t('hr.timesheets.thisMonth')}>
        <TimesheetTable caption={t('hr.timesheets.thisMonth')} loading={days.isLoading} rows={days.data?.data ?? []} />
      </Card>
      <Card title={t('hr.punches.thisMonth')}>
        <PunchTable caption={t('hr.punches.thisMonth')} loading={punches.isLoading} rows={punches.data?.data ?? []} />
      </Card>
      {dialog === 'set' ? <PinDialog employee={employee} onClose={() => setDialog(null)} /> : null}
      {dialog === 'clear' ? (
        <ConfirmDialog open title={t('hr.pin.clearTitle')} text={t('hr.pin.clearHelp')} confirmLabel={t('hr.pin.clear')} tone="danger" pending={clear.isPending} error={clear.error} onConfirm={() => clear.mutate()} onClose={() => setDialog(null)} />
      ) : null}
    </>
  )
}

function PinDialog({ employee, onClose }) {
  const { t } = useTranslation()
  const [pin, setPin] = useState('')
  const save = useHrMutation((body) => api.put(`hr/employees/${employee.id}/clock-pin`, body), { success: t('hr.pin.saved'), onDone: onClose })
  const errors = save.errors(['pin'])
  return (
    <FormDialog open title={t('hr.pin.setTitle', { name: employee.name })} onClose={onClose} submitLabel={t('hr.pin.save')} pending={save.isPending} disabled={!/^\d{4,6}$/.test(pin)} errors={errors} onSubmit={() => save.mutate({ pin })}>
      <p>{t('hr.pin.setHelp')}</p>
      <TextField type="password" inputMode="numeric" autoComplete="new-password" label={t('hr.pin.pin')} value={pin} maxLength={6} required error={errors.fields.pin} onChange={(event) => setPin(event.target.value.replace(/\D/g, ''))} />
    </FormDialog>
  )
}

/** HR-27: documents of the categories the reader sees; upload, download (a signed link), archive. */
export function DocumentsTab({ employee }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const day = useDay()
  const [dialog, setDialog] = useState(null)
  const [error, setError] = useState(null)
  const query = useList(['documents', employee.id], `hr/employees/${employee.id}/documents`)
  const manage = can(PERM.documentManage)
  const archive = useHrMutation((row) => api.post(`hr/documents/${row.id}/archive`, {}), { success: t('hr.documents.archived'), onDone: () => setDialog(null) })
  const download = async (row) => {
    setError(null)
    try {
      const response = await api.get(`hr/documents/${row.id}/link`)
      openLink(response.data.url)
    } catch (caught) {
      setError(errorMessage(caught))
    }
  }
  return (
    <Card
      title={t('hr.profile.tabs.documents')}
      actions={
        manage ? (
          <Button icon="upload" onClick={() => setDialog({ kind: 'upload' })}>
            {t('hr.documents.upload')}
          </Button>
        ) : null
      }
    >
      {error ? <Alert tone="danger" title={error} className="mb-3" /> : null}
      <DataTable
        caption={t('hr.profile.tabs.documents')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.documents.none')}
        columns={[
          { key: 'title', label: t('hr.documents.title'), render: (row) => row.title },
          { key: 'category', label: t('hr.documents.category'), render: (row) => enumLabel(t, 'documentCategories', row.category) },
          { key: 'file', label: t('hr.documents.file'), render: (row) => row.file_name },
          { key: 'expires', label: t('hr.documents.expiresOn'), render: (row) => <span className="tabular-nums">{row.expires_on ? day(row.expires_on) : '—'}</span> },
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) => (
              <span className="flex justify-end gap-2">
                <Button variant="ghost" icon="download" onClick={() => download(row)}>
                  {t('hr.documents.download')}
                </Button>
                {manage ? (
                  <Button variant="ghost" icon="archive" onClick={() => setDialog({ kind: 'archive', row })}>
                    {t('hr.actions.archive')}
                  </Button>
                ) : null}
              </span>
            ),
          },
        ]}
      />
      {dialog?.kind === 'upload' ? <UploadDocument employee={employee} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'archive' ? (
        <ConfirmDialog open title={t('hr.documents.archiveTitle')} text={t('hr.documents.archiveHelp', { title: dialog.row.title })} confirmLabel={t('hr.actions.archive')} tone="danger" pending={archive.isPending} error={archive.error} onConfirm={() => archive.mutate(dialog.row)} onClose={() => setDialog(null)} />
      ) : null}
    </Card>
  )
}

function UploadDocument({ employee, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ category: 'other', title: '', expires_on: '' })
  const [file, setFile] = useState(null)
  const save = useHrMutation(
    () => {
      const data = new FormData()
      data.append('category', form.category)
      data.append('title', form.title.trim())
      if (form.expires_on) data.append('expires_on', form.expires_on)
      data.append('file', file)
      return api.upload(`hr/employees/${employee.id}/documents`, data)
    },
    { success: t('hr.documents.uploaded'), onDone: onClose },
  )
  const errors = save.errors(['category', 'title', 'expires_on', 'file'])
  return (
    <FormDialog open title={t('hr.documents.uploadTitle')} onClose={onClose} submitLabel={t('hr.documents.upload')} pending={save.isPending} disabled={!file || !form.title.trim()} errors={errors} onSubmit={() => save.mutate()}>
      <Select label={t('hr.documents.category')} help={t('hr.documents.categoryHelp')} options={enumOptions(t, 'documentCategories', DOCUMENT_CATEGORIES)} value={form.category} required error={errors.fields.category} onChange={(event) => setForm({ ...form, category: event.target.value })} />
      <TextField label={t('hr.documents.title')} value={form.title} required maxLength={200} error={errors.fields.title} onChange={(event) => setForm({ ...form, title: event.target.value })} />
      <TextField type="date" label={t('hr.documents.expiresOn')} value={form.expires_on} error={errors.fields.expires_on} onChange={(event) => setForm({ ...form, expires_on: event.target.value })} />
      <TextField type="file" label={t('hr.documents.file')} required error={errors.fields.file} onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
    </FormDialog>
  )
}

/** HR-28: the employee's checklists; start one from a template of their company. */
export function ChecklistsTab({ employee }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [starting, setStarting] = useState(false)
  const query = useList(['checklists', 'employee', employee.id], `hr/checklists?employee=${employee.id}&per_page=50`)
  const manage = can(PERM.checklistManage)
  const rows = query.data?.data ?? []
  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-h3 text-ink">{t('hr.profile.tabs.checklists')}</h2>
        {manage ? (
          <Button icon="plus" onClick={() => setStarting(true)}>
            {t('hr.checklists.start')}
          </Button>
        ) : null}
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {!query.isLoading && rows.length === 0 ? <Empty>{t('hr.checklists.none')}</Empty> : null}
      {rows.map((checklist) => (
        <ChecklistCard key={checklist.id} checklist={checklist} canManage={manage} />
      ))}
      {starting ? <StartChecklist employee={employee} onClose={() => setStarting(false)} /> : null}
    </>
  )
}

function StartChecklist({ employee, onClose }) {
  const { t } = useTranslation()
  const [template, setTemplate] = useState('')
  const templates = useList(['checklist-templates', employee.company?.id], `hr/checklist-templates?company=${employee.company?.id}&per_page=100`)
  const save = useHrMutation((body) => api.post(`hr/employees/${employee.id}/checklists`, body), { success: t('hr.checklists.started'), onDone: onClose })
  const errors = save.errors(['template_id'])
  return (
    <FormDialog open title={t('hr.checklists.startTitle')} onClose={onClose} submitLabel={t('hr.checklists.start')} pending={save.isPending} disabled={!template} errors={errors} onSubmit={() => save.mutate({ template_id: template })}>
      <Select
        label={t('hr.checklists.template')}
        placeholder={t('hr.pickers.template')}
        options={(templates.data?.data ?? []).map((row) => ({ value: row.id, label: `${row.name} · ${enumLabel(t, 'checklistKinds', row.kind)}` }))}
        value={template}
        required
        error={errors.fields.template_id}
        onChange={(event) => setTemplate(event.target.value)}
      />
    </FormDialog>
  )
}

/** HR-29: open a disciplinary case (or change one): category, incident date, description, action taken. */
export function CaseDialog({ employee, record, companyId, onClose, onDone }) {
  const { t } = useTranslation()
  const [who, setWho] = useState(employee ?? null)
  const [form, setForm] = useState(() => ({ category: record?.category ?? '', incident_date: record?.incident_date ?? today(), description: record?.description ?? '', action_taken: record?.action_taken ?? '' }))
  const save = useHrMutation((body) => (record ? api.patch(`hr/disciplinary-cases/${record.id}`, body) : api.post('hr/disciplinary-cases', body)), {
    success: record ? t('hr.cases.saved') : (response) => t('hr.cases.opened', { number: response?.data?.number ?? '' }),
    onDone: (response) => (onDone ? onDone(response) : onClose()),
  })
  const errors = save.errors(['employee_id', 'category', 'incident_date', 'description', 'action_taken'])
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const ready = (record || who) && form.category && form.incident_date && form.description.trim()
  return (
    <FormDialog
      open
      size="lg"
      title={record ? t('hr.cases.editTitle') : t('hr.cases.openTitle')}
      onClose={onClose}
      submitLabel={record ? t('hr.actions.saveChanges') : t('hr.cases.open')}
      pending={save.isPending}
      disabled={!ready}
      errors={errors}
      onSubmit={() => save.mutate({ ...(record ? {} : { employee_id: who.id }), ...form, description: form.description.trim(), action_taken: form.action_taken.trim() || null })}
    >
      {record || employee ? null : <EmployeePicker company={companyId} value={who} required error={errors.fields.employee_id} onChange={setWho} />}
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('hr.cases.category')} placeholder={t('hr.pickers.category')} options={enumOptions(t, 'caseCategories', CASE_CATEGORIES)} value={form.category} required error={errors.fields.category} onChange={set('category')} />
        <TextField type="date" label={t('hr.cases.incidentDate')} value={form.incident_date} required max={today()} error={errors.fields.incident_date} onChange={set('incident_date')} />
      </div>
      <TextAreaField label={t('hr.cases.descriptionField')} value={form.description} required rows={4} maxLength={5000} error={errors.fields.description} onChange={set('description')} />
      <TextAreaField label={t('hr.cases.actionTaken')} value={form.action_taken} maxLength={5000} error={errors.fields.action_taken} onChange={set('action_taken')} />
    </FormDialog>
  )
}

/** HR-29: the employee's cases (only with `hr.disciplinary.view`, the tab is hidden otherwise). */
export function DisciplinaryTab({ employee }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const day = useDay()
  const [opening, setOpening] = useState(false)
  const query = useList(['cases', 'employee', employee.id], `hr/disciplinary-cases?employee=${employee.id}&per_page=50`)
  return (
    <Card
      title={t('hr.profile.tabs.disciplinary')}
      actions={
        employee.access?.disciplinary?.edit ? (
          <Button icon="plus" onClick={() => setOpening(true)}>
            {t('hr.cases.open')}
          </Button>
        ) : null
      }
    >
      <DataTable
        caption={t('hr.profile.tabs.disciplinary')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.cases.none')}
        onRowClick={(row) => navigate(`/hr/disciplinary/${row.id}`)}
        columns={[
          { key: 'number', label: t('hr.columns.number'), render: (row) => <span className="tabular-nums">{row.number}</span> },
          { key: 'category', label: t('hr.cases.category'), render: (row) => enumLabel(t, 'caseCategories', row.category) },
          { key: 'date', label: t('hr.cases.incidentDate'), render: (row) => <span className="tabular-nums">{day(row.incident_date)}</span> },
          { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
        ]}
      />
      {opening ? <CaseDialog employee={employee} onClose={() => setOpening(false)} /> : null}
    </Card>
  )
}

/** HR-30: the employee's appraisals; each opens its review. */
export function AppraisalsTab({ employee }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const query = useList(['appraisals', 'employee', employee.id], `hr/appraisals?employee=${employee.id}&per_page=50`)
  return (
    <Card title={t('hr.profile.tabs.appraisals')}>
      <DataTable
        caption={t('hr.profile.tabs.appraisals')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.appraisals.none')}
        onRowClick={(row) => navigate(`/hr/appraisals/${row.id}`)}
        columns={[
          { key: 'cycle', label: t('hr.appraisals.cycle'), render: (row) => row.cycle?.name ?? '' },
          { key: 'reviewer', label: t('hr.appraisals.reviewer'), render: (row) => row.reviewer?.name ?? '—' },
          { key: 'overall', label: t('hr.appraisals.overall'), align: 'end', render: (row) => <Days value={row.overall_rating} /> },
          { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
        ]}
      />
    </Card>
  )
}
