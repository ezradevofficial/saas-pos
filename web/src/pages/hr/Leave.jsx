import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Checkbox, DataTable, Dialog, ListView, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { useDebounced } from '@/lib/useDebounced'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { cn } from '@/lib/utils'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { AdjustDialog, RequestLeaveDialog } from './EmployeeTabs'
import { CalendarDate, ConfirmDialog, Days, Detail, DetailGrid, FormDialog, HrStatus, NoCompany, SectionTabs } from './HrParts'
import { ACCRUALS, addDays, COUNT_BASES, daysFrom, enumLabel, enumOptions, hrKey, isDays, LEAVE_STATUSES, monthRange, PERM, statusLabel, today, useHrCompany } from './hrData'
import { useDay, useHrMutation } from './hrForms'

/** The Leave section's heading and its pages. */
function LeaveHeader({ title, description, actions }) {
  return (
    <>
      <PageHeader title={title} description={description} actions={actions} />
      <SectionTabs section="leave" />
    </>
  )
}

/**
 * HR-14, HR-35: leave requests at the places the user sees (list
 * `hr-leave-requests`), decided in the approvals inbox (WF-01); ask on
 * an employee's behalf and cancel.
 */
export default function LeaveRequests() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const company = useHrCompany()
  const day = useDay()
  const [dialog, setDialog] = useState(null)
  const cancel = useHrMutation(({ row, reason }) => api.post(`hr/leave-requests/${row.id}/cancel`, { reason: reason || null }), { success: t('hr.leave.cancelled'), onDone: () => setDialog(null) })

  const columns = [
    { key: 'number', label: t('hr.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium tabular-nums">{row.number}</span> },
    { key: 'employee', label: t('hr.columns.employee'), render: (row) => row.employee?.name ?? '' },
    { key: 'leave_type', label: t('hr.fields.leaveType'), render: (row) => row.leave_type?.name ?? '' },
    { key: 'start_date', label: t('hr.fields.startDate'), sortKey: 'start_date', render: (row) => <span className="tabular-nums">{day(row.start_date)}</span> },
    { key: 'end_date', label: t('hr.fields.endDate'), render: (row) => <span className="tabular-nums">{day(row.end_date)}</span> },
    { key: 'days', label: t('hr.leave.days'), align: 'end', render: (row) => <Days value={row.days} /> },
    { key: 'status', label: t('hr.columns.status'), sortKey: 'status', render: (row) => <HrStatus status={row.status} /> },
    { key: 'reason', label: t('hr.fields.reason'), defaultHidden: true, render: (row) => row.reason ?? '' },
  ]
  const list = useServerList({ id: 'hr-leave-requests', endpoint: 'hr/leave-requests', queryKey: [...hrKey, 'leave-requests'], filters: { status: '', from: '', to: '' }, defaultSort: '-created_at', columns })

  return (
    <>
      <LeaveHeader
        title={t('hr.sections.leave.title')}
        description={t('hr.leave.description')}
        actions={
          <>
            <Link to="/approvals" className="flex h-control items-center px-2 text-label text-primary hover:text-primary-hover">
              {t('hr.leave.openApprovals')}
            </Link>
            {can(PERM.leaveRequest) ? (
              <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'request' })}>
                {t('hr.leave.request')}
              </Button>
            ) : null}
          </>
        }
      />
      <ListView
        list={list}
        title={t('hr.leave.requestsTitle')}
        searchLabel={t('hr.leave.search')}
        filterFields={[
          { name: 'status', label: t('hr.filters.status'), options: [{ value: '', label: t('hr.filters.allStatuses') }, ...LEAVE_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] },
          dateField('from', t('hr.filters.from')),
          dateField('to', t('hr.filters.to')),
        ]}
        emptyText={t('hr.leave.none')}
        onRowClick={(row) => setDialog({ kind: 'detail', row })}
      />
      {dialog?.kind === 'request' ? <RequestLeaveDialog companyId={company?.id} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'detail' ? <LeaveDetail row={dialog.row} onCancel={can(PERM.leaveRequest) ? () => setDialog({ kind: 'cancel', row: dialog.row }) : null} onClose={() => setDialog(null)} /> : null}
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

/** A request's details, the link to its flow (WF-10) and cancel while it is open. */
function LeaveDetail({ row, onCancel, onClose }) {
  const { t } = useTranslation()
  const open = row.status === 'pending' || (row.status === 'approved' && row.end_date >= today())
  return (
    <Dialog
      open
      title={row.number}
      onClose={onClose}
      footer={
        <>
          <Link to={`/document-workflows/hr.leave_request/${row.id}`} className="flex h-control items-center px-2 text-label text-primary hover:text-primary-hover">
            {t('hr.leave.openFlow')}
          </Link>
          {open && onCancel ? (
            <Button variant="danger" onClick={onCancel}>
              {t('hr.leave.cancel')}
            </Button>
          ) : null}
        </>
      }
    >
      <DetailGrid>
        <Detail label={t('hr.columns.employee')}>{row.employee?.name}</Detail>
        <Detail label={t('hr.fields.leaveType')}>{row.leave_type?.name}</Detail>
        <Detail label={t('hr.columns.status')}>
          <HrStatus status={row.status} />
        </Detail>
        <Detail label={t('hr.fields.startDate')}>
          <CalendarDate value={row.start_date} />
          {row.start_half ? ` · ${t('hr.leave.halfDay')}` : ''}
        </Detail>
        <Detail label={t('hr.fields.endDate')}>
          <CalendarDate value={row.end_date} />
          {row.end_half ? ` · ${t('hr.leave.halfDay')}` : ''}
        </Detail>
        <Detail label={t('hr.leave.days')}>
          <Days value={row.days} />
        </Detail>
        <Detail label={t('hr.fields.reason')}>{row.reason ?? '—'}</Detail>
        <Detail label={t('hr.leave.requestedBy')}>{row.requested_by?.name ?? '—'}</Detail>
        {row.cancel_reason ? <Detail label={t('hr.leave.cancelReason')}>{row.cancel_reason}</Detail> : null}
      </DetailGrid>
    </Dialog>
  )
}

/**
 * HR-13, HR-15: balances per employee and type at a date (available
 * days; entitled, taken and pending on hover); adjustments and the
 * accrual run for `hr.leave.manage`.
 */
export function LeaveBalances() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const company = useHrCompany()
  const [asOf, setAsOf] = useState(today())
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [dialog, setDialog] = useState(null)
  const term = useDebounced(search.trim(), 300)
  const params = new URLSearchParams({ company: company?.id ?? '', as_of: asOf, per_page: '50', page: String(page) })
  if (term) params.set('search', term)
  const query = useQuery({ queryKey: [...hrKey, 'leave-balances', params.toString()], queryFn: () => api.get(`hr/leave-balances?${params}`), enabled: Boolean(company) })
  const run = useHrMutation(() => api.post('hr/leave-accruals/run', { company_id: company.id }), {
    success: (response) => t('hr.balances.accrued', { count: response?.data?.posted ?? 0 }),
    onDone: (response) => setDialog(response?.data?.skipped_types?.length ? { kind: 'skipped', types: response.data.skipped_types } : null),
  })
  if (!company) return <NoCompany />

  const rows = query.data?.data ?? []
  const types = []
  for (const row of rows) for (const balance of row.balances) if (!types.some((type) => type.id === balance.leave_type.id)) types.push(balance.leave_type)
  const manage = can(PERM.leaveManage)
  const meta = query.data?.meta

  return (
    <>
      <LeaveHeader
        title={t('hr.sections.leave.title')}
        description={t('hr.balances.description', { company: company.name })}
        actions={
          manage ? (
            <>
              <Button onClick={() => setDialog({ kind: 'adjust' })}>{t('hr.balances.adjust')}</Button>
              <Button variant="primary" loading={run.isPending} onClick={() => run.mutate()}>
                {t('hr.balances.runAccrual')}
              </Button>
            </>
          ) : null
        }
      />
      {run.isError ? <Alert tone="danger" title={errorMessage(run.error)} /> : null}
      <div className="flex flex-wrap items-end gap-3">
        <TextField type="search" label={t('hr.balances.search')} value={search} onChange={(event) => setSearch(event.target.value)} />
        <TextField type="date" label={t('hr.balances.asOf')} value={asOf} onChange={(event) => setAsOf(event.target.value || today())} />
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('hr.balances.title')}
        loading={query.isLoading}
        rows={rows.map((row) => ({ id: row.employee.id, ...row }))}
        emptyText={t('hr.balances.noEmployees')}
        columns={[
          { key: 'employee', label: t('hr.columns.employee'), render: (row) => <span className="font-medium">{`${row.employee.name} · ${row.employee.number}`}</span> },
          ...types.map((type) => ({
            key: type.id,
            label: type.name,
            align: 'end',
            render: (row) => {
              const balance = row.balances.find((entry) => entry.leave_type.id === type.id)
              if (!balance) return '—'
              if (balance.days_needed) return <HrStatus status="days_needed" />
              return (
                <span title={t('hr.balances.hover', { entitled: balance.entitled, taken: balance.taken, pending: balance.pending })}>
                  <Days value={balance.available} />
                </span>
              )
            },
          })),
          ...(manage
            ? [{ key: 'actions', label: '', align: 'end', render: (row) => <Button variant="ghost" onClick={() => setDialog({ kind: 'adjust', employee: { ...row.employee, company_id: row.company_id } })}>{t('hr.balances.adjust')}</Button> }]
            : []),
        ]}
      />
      {meta && meta.last_page > 1 ? (
        <div className="flex items-center justify-end gap-2">
          <Button variant="ghost" icon="chevronLeft" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t('hr.actions.previous')}
          </Button>
          <span className="text-caption text-ink-muted tabular-nums">{t('hr.pageOf', { page: meta.current_page, pages: meta.last_page })}</span>
          <Button variant="ghost" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>
            {t('hr.actions.next')}
          </Button>
        </div>
      ) : null}
      {dialog?.kind === 'adjust' ? <AdjustDialog employee={dialog.employee} companyId={company.id} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'skipped' ? (
        <Dialog open title={t('hr.balances.skippedTitle')} onClose={() => setDialog(null)} footer={<Button onClick={() => setDialog(null)}>{t('hr.actions.close')}</Button>}>
          <div className="flex flex-col gap-3">
            <p>{t('hr.balances.skippedHelp')}</p>
            <ul className="list-inside list-disc text-body text-ink">
              {dialog.types.map((type) => (
                <li key={type.id}>{type.code}</li>
              ))}
            </ul>
            <Link to="/hr/leave/types" className="w-fit text-label text-primary hover:text-primary-hover">
              {t('hr.balances.openTypes')}
            </Link>
          </div>
        </Dialog>
      ) : null}
    </>
  )
}

/**
 * HR-15: pending and approved leave over a month, one row per employee and
 * a column per day (a filled mark for approved days, an outline for pending).
 */
export function LeaveCalendar() {
  const { t } = useTranslation()
  const locale = useLocale()
  const company = useHrCompany()
  const [month, setMonth] = useState(() => monthRange(today()).first)
  const { first, last } = monthRange(month)
  const query = useQuery({
    queryKey: [...hrKey, 'leave-calendar', company?.id, first],
    queryFn: () => api.get(`hr/leave-calendar?from=${first}&to=${last}${company ? `&company=${company.id}` : ''}`),
  })
  const rows = query.data?.data ?? []
  const days = daysFrom(first, Number(last.slice(8, 10)))
  const employees = []
  for (const row of rows) if (!employees.some((entry) => entry.id === row.employee.id)) employees.push(row.employee)
  const on = (employeeId, date) => rows.find((row) => row.employee.id === employeeId && row.start_date <= date && row.end_date >= date)
  const monthName = new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${first}T12:00:00Z`))

  return (
    <>
      <LeaveHeader title={t('hr.sections.leave.title')} description={t('hr.calendar.description')} />
      <div className="flex flex-wrap items-center gap-2">
        <Button variant="ghost" icon="chevronLeft" aria-label={t('hr.calendar.previous')} onClick={() => setMonth(monthRange(addDays(first, -1)).first)} />
        <h2 className="min-w-40 text-center text-h3 text-ink">{monthName}</h2>
        <Button variant="ghost" icon="chevronRight" aria-label={t('hr.calendar.next')} onClick={() => setMonth(addDays(last, 1))} />
        <span className="ml-auto flex items-center gap-4 text-caption text-ink-muted">
          <span className="flex items-center gap-1">
            <span className="size-3 rounded-sm bg-primary" aria-hidden="true" />
            {statusLabel(t, 'approved')}
          </span>
          <span className="flex items-center gap-1">
            <span className="size-3 rounded-sm border border-primary" aria-hidden="true" />
            {statusLabel(t, 'pending')}
          </span>
        </span>
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {!query.isLoading && employees.length === 0 ? <p className="text-body text-ink-muted">{t('hr.calendar.none')}</p> : null}
      {employees.length ? (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full border-collapse text-caption">
            <caption className="sr-only">{monthName}</caption>
            <thead>
              <tr>
                <th scope="col" className="sticky left-0 bg-surface-200 px-3 py-2 text-left font-normal text-ink-muted">
                  {t('hr.columns.employee')}
                </th>
                {days.map((date) => (
                  <th key={date} scope="col" className="px-1 py-2 text-center font-normal text-ink-muted tabular-nums">
                    {Number(date.slice(8, 10))}
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
                    const leave = on(employee.id, date)
                    return (
                      <td key={date} className="px-1 py-2 text-center">
                        {leave ? (
                          <span
                            title={`${leave.leave_type?.name} · ${formatDate(`${date}T12:00:00Z`, locale)} · ${statusLabel(t, leave.status)}`}
                            className={cn('mx-auto block size-3 rounded-sm', leave.status === 'approved' ? 'bg-primary' : 'border border-primary')}
                          >
                            <span className="sr-only">{`${leave.leave_type?.name} ${statusLabel(t, leave.status)}`}</span>
                          </span>
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
    </>
  )
}

/**
 * HR-12: the company's leave types. Statutory types come from the country
 * pack as "Days needed" until a holder of `hr.leave.manage` enters and
 * confirms the days (days are never guessed).
 */
export function LeaveTypes() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const company = useHrCompany()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...hrKey, 'leave-types', company?.id], queryFn: () => api.get(`hr/leave-types?company=${company.id}&status=all&per_page=200`), enabled: Boolean(company) })
  const manage = can(PERM.leaveManage)
  const pack = useHrMutation(() => api.post('hr/leave-types/apply-pack', { company_id: company.id }), { success: (response) => t('hr.types.packApplied', { count: response?.data?.length ?? 0 }) })
  const archive = useHrMutation((row) => api.post(`hr/leave-types/${row.id}/archive`, {}), { success: t('hr.types.archived'), onDone: () => setDialog(null) })
  if (!company) return <NoCompany />

  return (
    <>
      <LeaveHeader
        title={t('hr.sections.leave.title')}
        description={t('hr.types.description', { company: company.name })}
        actions={
          manage ? (
            <>
              <Button loading={pack.isPending} onClick={() => pack.mutate()}>
                {t('hr.types.applyPack')}
              </Button>
              <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'edit' })}>
                {t('hr.types.add')}
              </Button>
            </>
          ) : null
        }
      />
      {pack.isError ? <Alert tone="danger" title={errorMessage(pack.error)} /> : null}
      {(query.data?.data ?? []).some((row) => row.days_needed && !row.archived_at) ? <Alert tone="warning" title={t('hr.types.daysNeededTitle')}>{t('hr.types.daysNeededHelp')}</Alert> : null}
      <DataTable
        caption={t('hr.sections.leave.types')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('hr.types.none')}
        columns={[
          { key: 'code', label: t('hr.columns.code'), render: (row) => <span className="font-medium tabular-nums">{row.code}</span> },
          { key: 'name', label: t('hr.types.name'), render: (row) => (row.statutory_label && row.statutory_label !== row.name ? `${row.name} · ${row.statutory_label}` : row.name) },
          { key: 'paid', label: t('hr.types.paid'), render: (row) => (row.paid ? t('hr.types.paidYes') : t('hr.types.paidNo')) },
          { key: 'accrual', label: t('hr.types.accrual'), render: (row) => enumLabel(t, 'accruals', row.accrual) },
          { key: 'days', label: t('hr.types.entitlement'), align: 'end', render: (row) => <Days value={row.entitlement_days} /> },
          { key: 'basis', label: t('hr.types.countBasis'), render: (row) => enumLabel(t, 'countBases', row.count_basis) },
          { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.archived_at ? 'archived' : row.days_needed ? 'days_needed' : 'active'} /> },
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              manage && !row.archived_at ? (
                <span className="flex justify-end gap-2">
                  {row.days_needed ? (
                    <Button variant="secondary" onClick={() => setDialog({ kind: 'confirm', row })}>
                      {t('hr.types.enterDays')}
                    </Button>
                  ) : null}
                  <Button variant="ghost" icon="edit" onClick={() => setDialog({ kind: 'edit', row })}>
                    {t('hr.actions.edit')}
                  </Button>
                  <Button variant="ghost" icon="archive" onClick={() => setDialog({ kind: 'archive', row })}>
                    {t('hr.actions.archive')}
                  </Button>
                </span>
              ) : null,
          },
        ]}
      />
      {dialog?.kind === 'edit' ? <LeaveTypeDialog company={company} row={dialog.row} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'confirm' ? <ConfirmDaysDialog row={dialog.row} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'archive' ? (
        <ConfirmDialog open title={t('hr.types.archiveTitle', { name: dialog.row.name })} text={t('hr.types.archiveHelp')} confirmLabel={t('hr.actions.archive')} tone="danger" pending={archive.isPending} error={archive.error} onConfirm={() => archive.mutate(dialog.row)} onClose={() => setDialog(null)} />
      ) : null}
    </>
  )
}

/** HR-12: enter a statutory type's days and confirm them (PATCH with `confirm: true`); the company then owns them. */
function ConfirmDaysDialog({ row, onClose }) {
  const { t } = useTranslation()
  const [days, setDays] = useState('')
  const [checked, setChecked] = useState(false)
  const save = useHrMutation((body) => api.patch(`hr/leave-types/${row.id}`, body), { success: t('hr.types.confirmed', { name: row.name }), onDone: onClose })
  const errors = save.errors(['entitlement_days', 'confirm'])
  return (
    <FormDialog
      open
      title={t('hr.types.confirmTitle', { name: row.name })}
      onClose={onClose}
      submitLabel={t('hr.types.confirmDays')}
      pending={save.isPending}
      disabled={!isDays(days) || !checked}
      errors={errors}
      onSubmit={() => save.mutate({ entitlement_days: days.trim(), confirm: true })}
    >
      <p>{t('hr.types.confirmHelp')}</p>
      <TextField label={t('hr.types.entitlement')} help={t('hr.types.entitlementHelp')} inputMode="decimal" value={days} required error={errors.fields.entitlement_days} onChange={(event) => setDays(event.target.value)} />
      <Checkbox label={t('hr.types.confirmCheck')} checked={checked} onChange={(event) => setChecked(event.target.checked)} />
    </FormDialog>
  )
}

const TYPE_FIELDS = ['code', 'name', 'paid', 'accrual', 'entitlement_days', 'count_basis', 'carry_over_max_days', 'allow_negative', 'requires_document', 'accrues_from']

function LeaveTypeDialog({ company, row, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => ({
    code: row?.code ?? '',
    name: row?.name ?? '',
    paid: row?.paid ?? true,
    accrual: row?.accrual ?? 'annual',
    entitlement_days: row?.entitlement_days ?? '',
    count_basis: row?.count_basis ?? 'working_days',
    carry_over_max_days: row?.carry_over_max_days ?? '',
    allow_negative: row?.allow_negative ?? false,
    requires_document: row?.requires_document ?? false,
    accrues_from: row?.accrues_from ?? '',
  }))
  const save = useHrMutation((body) => (row ? api.patch(`hr/leave-types/${row.id}`, body) : api.post('hr/leave-types', { company_id: company.id, ...body })), { success: t('hr.types.saved'), onDone: onClose })
  const errors = save.errors(TYPE_FIELDS)
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const check = (key) => (event) => setForm({ ...form, [key]: event.target.checked })
  const daysOk = (value) => value === '' || isDays(value)
  const ready = form.code.trim() && form.name.trim() && daysOk(form.entitlement_days) && daysOk(form.carry_over_max_days)
  const submit = () =>
    save.mutate({
      ...form,
      code: form.code.trim(),
      name: form.name.trim(),
      entitlement_days: form.entitlement_days === '' ? null : String(form.entitlement_days).trim(),
      carry_over_max_days: form.carry_over_max_days === '' ? null : String(form.carry_over_max_days).trim(),
      accrues_from: form.accrues_from || null,
    })
  return (
    <FormDialog open size="lg" title={row ? t('hr.types.editTitle') : t('hr.types.addTitle')} onClose={onClose} submitLabel={t('hr.actions.saveChanges')} pending={save.isPending} disabled={!ready} errors={errors} onSubmit={submit}>
      {row?.days_needed ? <Alert tone="warning" title={t('hr.types.daysNeededTitle')}>{t('hr.types.editDaysNeeded')}</Alert> : null}
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('hr.columns.code')} value={form.code} required maxLength={40} error={errors.fields.code} onChange={set('code')} />
        <TextField label={t('hr.types.name')} value={form.name} required maxLength={120} error={errors.fields.name} onChange={set('name')} />
        <Select label={t('hr.types.accrual')} options={enumOptions(t, 'accruals', ACCRUALS)} value={form.accrual} required error={errors.fields.accrual} onChange={set('accrual')} />
        <Select label={t('hr.types.countBasis')} options={enumOptions(t, 'countBases', COUNT_BASES)} value={form.count_basis} required error={errors.fields.count_basis} onChange={set('count_basis')} />
        {row?.days_needed ? null : <TextField label={t('hr.types.entitlement')} help={t('hr.types.entitlementHelp')} inputMode="decimal" value={form.entitlement_days} error={errors.fields.entitlement_days} onChange={set('entitlement_days')} />}
        <TextField label={t('hr.types.carryOver')} help={t('hr.types.carryOverHelp')} inputMode="decimal" value={form.carry_over_max_days} error={errors.fields.carry_over_max_days} onChange={set('carry_over_max_days')} />
        <TextField type="date" label={t('hr.types.accruesFrom')} value={form.accrues_from} error={errors.fields.accrues_from} onChange={set('accrues_from')} />
      </div>
      <div className="flex flex-col gap-2">
        <Checkbox label={t('hr.types.paidCheck')} checked={form.paid} onChange={check('paid')} />
        <Checkbox label={t('hr.types.allowNegative')} checked={form.allow_negative} onChange={check('allow_negative')} />
        <Checkbox label={t('hr.types.requiresDocument')} checked={form.requires_document} onChange={check('requires_document')} />
      </div>
    </FormDialog>
  )
}
