import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, ListView, MoneyInput, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { useCurrencyCodes, useHrCompany } from '@/pages/hr/hrData'
import {
  codeLabel,
  EMPLOYEE_STATUSES,
  enumLabel,
  enumOptions,
  payrollKey,
  PAYMENT_METHODS,
  PERM,
  RECURRENCES,
  statusLabel,
  today,
  usePayGroups,
  usePayItems,
  usePayrollMutation,
  withoutBlanks,
} from './payrollData'
import { Amount, Back, CalendarDate, ConfirmDialog, Detail, DetailState, FormDialog, PayrollStatus, TextAreaField } from './PayrollParts'

/**
 * PAY-05, PAY-25: Payroll's copy of the employees at the places the user
 * sees, with their pay setup; search, status, pay group and "no pay group"
 * filters, sort and export (list `payroll-employees`). Sync from HR for
 * those who manage pay setup.
 */
export default function PayEmployees() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const company = useHrCompany()
  const payGroups = usePayGroups(null)
  const sync = usePayrollMutation(() => api.post('payroll/employees/sync', { company_id: company.id }), {
    success: (response) => t('payroll.employees.synced', { count: response?.data?.employees ?? 0 }),
  })

  const columns = [
    { key: 'number', label: t('payroll.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'name', label: t('payroll.columns.name'), sortKey: 'name', hideable: false, render: (row) => row.name },
    { key: 'company', label: t('payroll.columns.company'), defaultHidden: true, render: (row) => row.company?.name ?? '' },
    { key: 'branch', label: t('payroll.columns.branch'), render: (row) => row.branch?.name ?? '' },
    { key: 'department', label: t('payroll.columns.department'), render: (row) => row.department?.name ?? '' },
    { key: 'pay_group', label: t('payroll.columns.payGroup'), render: (row) => (row.pay_group ? codeLabel(row.pay_group) : <span className="text-ink-muted">{t('payroll.employees.noPayGroup')}</span>) },
    { key: 'pay_currency', label: t('payroll.columns.currency'), render: (row) => row.pay_currency ?? '' },
    { key: 'payment_method', label: t('payroll.columns.paymentMethod'), render: (row) => enumLabel(t, 'methods', row.payment_method) },
    { key: 'hire_date', label: t('payroll.columns.hireDate'), sortKey: 'hire_date', defaultHidden: true, render: (row) => <CalendarDate value={row.hire_date} /> },
    { key: 'status', label: t('payroll.columns.status'), render: (row) => (row.on_hold ? <PayrollStatus status="on_hold" /> : <PayrollStatus status={row.status} />) },
  ]

  const list = useServerList({
    id: 'payroll-employees',
    endpoint: 'payroll/employees',
    queryKey: [...payrollKey, 'employees'],
    filters: { status: 'active', pay_group: '', unassigned: '' },
    defaultSort: 'number',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('payroll.employees.title')}
        description={t('payroll.employees.description')}
        actions={
          can(PERM.employeeManage) && company ? (
            <Button icon="sync" loading={sync.isPending} onClick={() => sync.mutate()}>
              {t('payroll.employees.sync', { company: company.name })}
            </Button>
          ) : null
        }
      />
      {sync.isError ? <Alert tone="danger" title={errorMessage(sync.error)} /> : null}
      <ListView
        list={list}
        title={t('payroll.employees.title')}
        searchLabel={t('payroll.employees.search')}
        filterFields={[
          { name: 'status', label: t('payroll.filters.status'), options: [{ value: 'all', label: t('payroll.filters.allStatuses') }, ...EMPLOYEE_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] },
          { name: 'pay_group', label: t('payroll.filters.payGroup'), options: [{ value: '', label: t('payroll.filters.allPayGroups') }, ...payGroups.map((group) => ({ value: group.id, label: codeLabel(group) }))] },
          { name: 'unassigned', label: t('payroll.filters.assignment'), options: [{ value: '', label: t('payroll.filters.anyAssignment') }, { value: '1', label: t('payroll.filters.unassigned') }] },
        ]}
        emptyText={list.term ? t('payroll.employees.emptyFiltered') : t('payroll.employees.empty')}
        onRowClick={(row) => navigate(`/payroll/employees/${row.id}`)}
      />
    </>
  )
}

/** The pay setup form's starting values. */
const setupForm = (employee) => ({
  pay_group_id: employee.pay_group?.id ?? '',
  pay_currency: employee.pay_currency ?? '',
  payment_method: employee.payment_method ?? '',
  dependants: String(employee.dependants ?? 0),
  children: String(employee.children ?? 0),
  on_hold: Boolean(employee.on_hold),
})

const SETUP_FIELDS = ['pay_group_id', 'pay_currency', 'payment_method', 'dependants', 'children', 'on_hold']

/**
 * PAY-05, PAY-06: one employee's pay: the HR contract in force (never
 * typed again), the pay setup (pay group, currency, method, dependants,
 * children, hold) and their recurring and one-off items.
 */
export function PayEmployeeDetail() {
  const { t } = useTranslation()
  const { employeeId } = useParams()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const key = [...payrollKey, 'employee', employeeId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`payroll/employees/${employeeId}`) })
  const employee = query.data?.data
  const back = <Back to="/payroll/employees">{t('payroll.employees.back')}</Back>
  if (!employee) return <DetailState query={query} back={back} notFound={t('payroll.employee.notFound')} />
  const manage = can(PERM.employeeManage)
  const contract = employee.contract
  return (
    <>
      {back}
      <PageHeader
        eyebrow={employee.number}
        title={employee.name}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <PayrollStatus status={employee.status} />
            {employee.on_hold ? <PayrollStatus status="on_hold" /> : null}
            <span>{[employee.company?.name, employee.branch?.name, employee.department?.name].filter(Boolean).join(' · ')}</span>
          </span>
        }
      />
      <div className="flex flex-col gap-5">
        <Card title={t('payroll.employee.contract')} subtitle={t('payroll.employee.contractHelp')}>
          {contract ? (
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <Detail label={t('payroll.fields.pay')}>
                <Amount value={contract.pay} />
              </Detail>
              <Detail label={t('payroll.fields.payBasis')}>{t(`hr.payBases.${contract.pay_basis}`, { defaultValue: contract.pay_basis })}</Detail>
              <Detail label={t('payroll.fields.contractType')}>{t(`hr.contractTypes.${contract.type}`, { defaultValue: contract.type })}</Detail>
              <Detail label={t('payroll.fields.startDate')}>
                <CalendarDate value={contract.start_date} />
              </Detail>
              <Detail label={t('payroll.fields.endDate')}>
                <CalendarDate value={contract.end_date} />
              </Detail>
              {contract.hours_per_week ? <Detail label={t('payroll.fields.hoursPerWeek')}>{contract.hours_per_week}</Detail> : null}
            </dl>
          ) : (
            <p className="text-body text-ink-muted">{'contract' in employee ? t('payroll.employee.noContract') : t('payroll.employee.amountsHidden')}</p>
          )}
        </Card>
        <PaySetup key={employee.synced_at ?? employee.id} employee={employee} manage={manage} onSaved={(response) => queryClient.setQueryData(key, response)} />
        <EmployeeItems employee={employee} manage={manage} />
      </div>
    </>
  )
}

function PaySetup({ employee, manage, onSaved }) {
  const { t } = useTranslation()
  const payGroups = usePayGroups(employee.company?.id ?? null)
  const currencies = useCurrencyCodes(null)
  const [form, setForm] = useState(() => setupForm(employee))
  const save = usePayrollMutation((body) => api.patch(`payroll/employees/${employee.id}`, body), { success: t('payroll.employee.saved'), onDone: onSaved })
  const errors = save.errors(SETUP_FIELDS)
  const count = (value) => /^\d{1,2}$/.test(String(value).trim())
  const valid = count(form.dependants) && count(form.children)
  const submit = (event) => {
    event.preventDefault()
    if (!valid) return
    save.mutate({
      pay_group_id: form.pay_group_id || null,
      pay_currency: form.pay_currency || null,
      payment_method: form.payment_method || null,
      dependants: Number(form.dependants),
      children: Number(form.children),
      on_hold: form.on_hold,
    })
  }
  const groups = payGroups.some((group) => group.id === form.pay_group_id) || !employee.pay_group ? payGroups : [employee.pay_group, ...payGroups]
  return (
    <Card title={t('payroll.employee.setup')} subtitle={t('payroll.employee.setupHelp')}>
      <form noValidate onSubmit={submit} className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Select
            label={t('payroll.fields.payGroup')}
            options={[{ value: '', label: t('payroll.employees.noPayGroup') }, ...groups.map((group) => ({ value: group.id, label: codeLabel(group) }))]}
            value={form.pay_group_id}
            disabled={!manage}
            error={errors.fields.pay_group_id}
            onChange={(event) => setForm({ ...form, pay_group_id: event.target.value })}
          />
          <Select
            label={t('payroll.fields.payCurrency')}
            help={t('payroll.employee.currencyHelp')}
            options={[{ value: '', label: t('payroll.employee.contractCurrency') }, ...currencies.map((code) => ({ value: code, label: code }))]}
            value={form.pay_currency}
            disabled={!manage}
            error={errors.fields.pay_currency}
            onChange={(event) => setForm({ ...form, pay_currency: event.target.value })}
          />
          <Select
            label={t('payroll.fields.paymentMethod')}
            options={[{ value: '', label: t('payroll.employee.noMethod') }, ...enumOptions(t, 'methods', PAYMENT_METHODS)]}
            value={form.payment_method}
            disabled={!manage}
            error={errors.fields.payment_method}
            onChange={(event) => setForm({ ...form, payment_method: event.target.value })}
          />
          <TextField
            type="number"
            inputMode="numeric"
            min={0}
            max={50}
            label={t('payroll.fields.dependants')}
            value={form.dependants}
            disabled={!manage}
            error={errors.fields.dependants ?? (count(form.dependants) ? undefined : t('payroll.validation.count'))}
            onChange={(event) => setForm({ ...form, dependants: event.target.value })}
          />
          <TextField
            type="number"
            inputMode="numeric"
            min={0}
            max={50}
            label={t('payroll.fields.children')}
            value={form.children}
            disabled={!manage}
            error={errors.fields.children ?? (count(form.children) ? undefined : t('payroll.validation.count'))}
            onChange={(event) => setForm({ ...form, children: event.target.value })}
          />
          <div className="flex items-end pb-2">
            <Switch label={t('payroll.fields.onHold')} checked={form.on_hold} disabled={!manage} onChange={(value) => setForm({ ...form, on_hold: value })} />
          </div>
        </div>
        {form.on_hold ? <p className="text-caption text-ink-muted">{t('payroll.employee.onHoldHelp')}</p> : null}
        {manage ? (
          <div>
            <Button type="submit" variant="primary" loading={save.isPending} disabled={!valid}>
              {t('payroll.employee.saveSetup')}
            </Button>
          </div>
        ) : null}
      </form>
    </Card>
  )
}

/** PAY-06: an employee's recurring and one-off items; archived, never deleted (TEN-06). */
function EmployeeItems({ employee, manage }) {
  const { t } = useTranslation()
  const [dialog, setDialog] = useState(null) // {kind: 'edit', row?} | {kind: 'archive', row}
  const query = useQuery({ queryKey: [...payrollKey, 'employee-items', employee.id], queryFn: () => api.get(`payroll/employees/${employee.id}/items`) })
  const archive = usePayrollMutation((row) => api.post(`payroll/employee-items/${row.id}/archive`, {}), { success: t('payroll.employeeItems.archived'), onDone: () => setDialog(null) })
  const rows = query.data?.data ?? []
  return (
    <Card
      title={t('payroll.employeeItems.title')}
      subtitle={t('payroll.employeeItems.help')}
      actions={
        manage ? (
          <Button icon="plus" onClick={() => setDialog({ kind: 'edit' })}>
            {t('payroll.employeeItems.add')}
          </Button>
        ) : null
      }
    >
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('payroll.employeeItems.title')}
        loading={query.isLoading}
        rows={rows}
        emptyText={t('payroll.employeeItems.empty')}
        columns={[
          { key: 'item', label: t('payroll.columns.item'), wrap: true, render: (row) => codeLabel(row.item) },
          { key: 'kind', label: t('payroll.columns.kind'), render: (row) => enumLabel(t, 'itemKinds', row.item?.kind) },
          { key: 'amount', label: t('payroll.columns.amount'), align: 'end', render: (row) => <Amount value={row.amount} /> },
          { key: 'recurrence', label: t('payroll.columns.recurrence'), render: (row) => enumLabel(t, 'recurrences', row.recurrence) },
          {
            key: 'dates',
            label: t('payroll.columns.dates'),
            render: (row) =>
              row.recurrence === 'once' ? (
                <CalendarDate value={row.period_date} />
              ) : (
                <span className="flex gap-1">
                  <CalendarDate value={row.from_date} />
                  <span className="text-ink-muted">–</span>
                  <CalendarDate value={row.to_date} />
                </span>
              ),
          },
          { key: 'status', label: t('payroll.columns.status'), render: (row) => <PayrollStatus status={row.archived_at ? 'archived' : row.consumed_run_id ? 'consumed' : 'active'} /> },
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              manage && !row.archived_at && !row.consumed_run_id ? (
                <span className="flex justify-end gap-2">
                  <Button variant="ghost" icon="edit" onClick={() => setDialog({ kind: 'edit', row })}>
                    {t('payroll.actions.edit')}
                  </Button>
                  <Button variant="ghost" icon="archive" onClick={() => setDialog({ kind: 'archive', row })}>
                    {t('payroll.actions.archive')}
                  </Button>
                </span>
              ) : null,
          },
        ]}
      />
      {dialog?.kind === 'edit' ? <EmployeeItemDialog employee={employee} row={dialog.row} onClose={() => setDialog(null)} /> : null}
      <ConfirmDialog
        open={dialog?.kind === 'archive'}
        title={t('payroll.employeeItems.archiveTitle', { item: codeLabel(dialog?.row?.item) })}
        text={t('payroll.employeeItems.archiveHelp')}
        confirmLabel={t('payroll.actions.archive')}
        tone="danger"
        pending={archive.isPending}
        error={archive.error}
        onConfirm={() => archive.mutate(dialog.row)}
        onClose={() => setDialog(null)}
      />
    </Card>
  )
}

const ITEM_FIELDS = ['item_id', 'amount_minor', 'currency', 'recurrence', 'from_date', 'to_date', 'period_date', 'note']

/** PAY-06: add an item to an employee (or change an item's amount, dates and note). */
function EmployeeItemDialog({ employee, row, onClose }) {
  const { t } = useTranslation()
  const items = usePayItems(employee.company?.id)
  const currencies = useCurrencyCodes(null)
  const [submitted, setSubmitted] = useState(false)
  const [form, setForm] = useState(() => ({
    item_id: row?.item?.id ?? '',
    amount_minor: row?.amount?.amount_minor ?? '',
    currency: row?.currency ?? employee.pay_currency ?? employee.contract?.pay?.currency ?? currencies[0] ?? '',
    recurrence: row?.recurrence ?? 'recurring',
    from_date: row?.from_date ?? today(),
    to_date: row?.to_date ?? '',
    period_date: row?.period_date ?? today(),
    note: row?.note ?? '',
  }))
  const save = usePayrollMutation(
    (body) => (row ? api.patch(`payroll/employee-items/${row.id}`, body) : api.post(`payroll/employees/${employee.id}/items`, body)),
    { success: t('payroll.employeeItems.saved'), onDone: onClose },
  )
  const errors = save.errors(ITEM_FIELDS)
  const once = form.recurrence === 'once'
  const ready = form.item_id && form.amount_minor && form.currency && (once ? form.period_date : form.from_date)
  const submit = () => {
    setSubmitted(true)
    if (!ready) return
    const dates = once ? { period_date: form.period_date } : { from_date: form.from_date, to_date: form.to_date }
    if (row) save.mutate(withoutBlanks({ amount_minor: form.amount_minor, ...dates, note: form.note }))
    else save.mutate(withoutBlanks({ item_id: form.item_id, amount_minor: form.amount_minor, currency: form.currency, recurrence: form.recurrence, ...dates, note: form.note }))
  }
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  return (
    <FormDialog open title={row ? t('payroll.employeeItems.editTitle') : t('payroll.employeeItems.addTitle')} onClose={onClose} submitLabel={t('payroll.employeeItems.saveItem')} pending={save.isPending} disabled={submitted && !ready} errors={errors} onSubmit={submit}>
      {submitted && !ready ? <Alert tone="danger" title={t('payroll.validation.required')} /> : null}
      <Select
        label={t('payroll.fields.item')}
        placeholder={t('payroll.pickers.item')}
        options={(row?.item && !items.some((item) => item.id === row.item.id) ? [row.item, ...items] : items).map((item) => ({ value: item.id, label: `${codeLabel(item)} · ${enumLabel(t, 'itemKinds', item.kind)}` }))}
        value={form.item_id}
        required
        disabled={Boolean(row)}
        error={errors.fields.item_id}
        onChange={set('item_id')}
      />
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('payroll.fields.currency')} options={currencies.map((code) => ({ value: code, label: code }))} value={form.currency} required disabled={Boolean(row)} error={errors.fields.currency} onChange={set('currency')} />
        <MoneyInput label={t('payroll.fields.amount')} currency={form.currency} value={form.amount_minor} required error={errors.fields.amount_minor} onChange={(value) => setForm({ ...form, amount_minor: value ?? '' })} />
        <Select label={t('payroll.fields.recurrence')} options={enumOptions(t, 'recurrences', RECURRENCES)} value={form.recurrence} required disabled={Boolean(row)} error={errors.fields.recurrence} onChange={set('recurrence')} />
        {once ? (
          <TextField type="date" label={t('payroll.fields.periodDate')} help={t('payroll.employeeItems.periodHelp')} value={form.period_date} required error={errors.fields.period_date} onChange={set('period_date')} />
        ) : (
          <>
            <TextField type="date" label={t('payroll.fields.fromDate')} value={form.from_date} required error={errors.fields.from_date} onChange={set('from_date')} />
            <TextField type="date" label={t('payroll.fields.toDate')} help={t('payroll.employeeItems.toHelp')} value={form.to_date} error={errors.fields.to_date} onChange={set('to_date')} />
          </>
        )}
      </div>
      <TextAreaField label={t('payroll.fields.note')} value={form.note} maxLength={500} error={errors.fields.note} onChange={set('note')} />
    </FormDialog>
  )
}
