import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, ListView, MoneyInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { useCurrencyCodes } from '@/pages/hr/hrData'
import { employeeLabel, enumLabel, enumOptions, LOAN_KINDS, LOAN_STATUSES, payrollKey, PERM, SETTLE_METHODS, statusLabel, today, usePayrollMutation, withoutBlanks } from './payrollData'
import { Amount, Back, CalendarDate, ConfirmDialog, Detail, DetailState, FormDialog, PayrollStatus, TextAreaField } from './PayrollParts'

/**
 * PAY-13, PAY-25: loans and advances of the employees the user sees, with
 * search, status filter, sort and export (list `payroll-loans`); New loan
 * for those who manage loans.
 */
export default function Loans() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const [creating, setCreating] = useState(false)

  const columns = [
    { key: 'employee', label: t('payroll.columns.employee'), hideable: false, render: (row) => employeeLabel(row.employee) },
    { key: 'kind', label: t('payroll.columns.kind'), render: (row) => enumLabel(t, 'loanKinds', row.kind) },
    { key: 'reference', label: t('payroll.columns.reference'), render: (row) => row.reference ?? '' },
    { key: 'principal', label: t('payroll.columns.principal'), align: 'end', render: (row) => <Amount value={row.principal} /> },
    { key: 'instalment', label: t('payroll.columns.instalment'), align: 'end', render: (row) => <Amount value={row.instalment} /> },
    { key: 'balance', label: t('payroll.columns.balance'), align: 'end', render: (row) => <Amount value={row.balance} /> },
    { key: 'disbursed_on', label: t('payroll.columns.disbursedOn'), sortKey: 'disbursed_on', render: (row) => <CalendarDate value={row.disbursed_on} /> },
    { key: 'starts_on', label: t('payroll.columns.startsOn'), sortKey: 'starts_on', defaultHidden: true, render: (row) => <CalendarDate value={row.starts_on} /> },
    { key: 'status', label: t('payroll.columns.status'), sortKey: 'status', render: (row) => <PayrollStatus status={row.status} /> },
  ]

  const list = useServerList({
    id: 'payroll-loans',
    endpoint: 'payroll/loans',
    queryKey: [...payrollKey, 'loans'],
    filters: { status: 'active' },
    defaultSort: '-disbursed_on',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('payroll.loans.title')}
        description={t('payroll.loans.description')}
        actions={
          can(PERM.loanManage) ? (
            <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
              {t('payroll.loans.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('payroll.loans.title')}
        searchLabel={t('payroll.loans.search')}
        filterFields={[{ name: 'status', label: t('payroll.filters.status'), options: [{ value: 'all', label: t('payroll.filters.allStatuses') }, ...LOAN_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] }]}
        emptyText={list.term ? t('payroll.loans.emptyFiltered') : t('payroll.loans.empty')}
        onRowClick={(row) => navigate(`/payroll/loans/${row.id}`)}
      />
      {creating ? <NewLoanDialog onClose={() => setCreating(false)} onDone={(loan) => navigate(`/payroll/loans/${loan.id}`)} /> : null}
    </>
  )
}

/** PAY-05: a Payroll employee picker that searches the server (payroll/employees?search=). */
function PayEmployeePicker({ value, onChange, error }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const params = new URLSearchParams({ per_page: '20', sort: 'number', status: 'active' })
  if (search) params.set('search', search)
  const query = useQuery({ queryKey: [...payrollKey, 'employee-picker', params.toString()], queryFn: () => api.get(`payroll/employees?${params}`), staleTime: 30_000, retry: false })
  const rows = query.data?.data ?? []
  const known = value && !rows.some((row) => row.id === value.id) ? [value, ...rows] : rows
  const onSearchChange = useCallback((text) => setSearch(text.trim()), [])
  return (
    <Select
      label={t('payroll.fields.employee')}
      placeholder={t('payroll.pickers.employee')}
      options={known.map((row) => ({ value: row.id, label: employeeLabel(row) }))}
      value={value?.id ?? ''}
      required
      error={error ?? (query.isError ? errorMessage(query.error) : undefined)}
      onSearchChange={onSearchChange}
      onChange={(event) => onChange(known.find((row) => row.id === event.target.value) ?? null)}
    />
  )
}

const LOAN_FIELDS = ['payroll_employee_id', 'kind', 'reference', 'principal_minor', 'instalment_minor', 'currency', 'disbursed_on', 'starts_on', 'note']

/** PAY-13: record a loan or advance (its disbursement is paid outside payroll). */
function NewLoanDialog({ onClose, onDone }) {
  const { t } = useTranslation()
  const currencies = useCurrencyCodes(null)
  const [employee, setEmployee] = useState(null)
  const [submitted, setSubmitted] = useState(false)
  const [form, setForm] = useState({ kind: 'loan', reference: '', currency: '', principal_minor: '', instalment_minor: '', disbursed_on: today(), starts_on: '', note: '' })
  const currency = form.currency || employee?.pay_currency || currencies[0] || ''
  const save = usePayrollMutation((body) => api.post('payroll/loans', body), { success: t('payroll.loans.created'), onDone: (response) => onDone(response.data) })
  const errors = save.errors(LOAN_FIELDS)
  const ready = employee && currency && form.principal_minor && form.instalment_minor && form.disbursed_on
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value })
  const submit = () => {
    setSubmitted(true)
    if (ready) save.mutate(withoutBlanks({ payroll_employee_id: employee.id, ...form, currency }))
  }
  return (
    <FormDialog open title={t('payroll.loans.newTitle')} onClose={onClose} submitLabel={t('payroll.loans.create')} pending={save.isPending} disabled={submitted && !ready} errors={errors} onSubmit={submit}>
      {submitted && !ready ? <Alert tone="danger" title={t('payroll.validation.required')} /> : null}
      <PayEmployeePicker value={employee} error={errors.fields.payroll_employee_id} onChange={setEmployee} />
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('payroll.fields.loanKind')} options={enumOptions(t, 'loanKinds', LOAN_KINDS)} value={form.kind} required error={errors.fields.kind} onChange={set('kind')} />
        <TextField label={t('payroll.fields.reference')} value={form.reference} maxLength={80} error={errors.fields.reference} onChange={set('reference')} />
        <Select label={t('payroll.fields.currency')} help={t('payroll.loans.currencyHelp')} options={currencies.map((code) => ({ value: code, label: code }))} value={currency} required error={errors.fields.currency} onChange={set('currency')} />
        <MoneyInput label={t('payroll.fields.principal')} currency={currency} value={form.principal_minor} required error={errors.fields.principal_minor} onChange={(value) => setForm({ ...form, principal_minor: value ?? '' })} />
        <MoneyInput label={t('payroll.fields.instalment')} currency={currency} value={form.instalment_minor} required error={errors.fields.instalment_minor} onChange={(value) => setForm({ ...form, instalment_minor: value ?? '' })} />
        <TextField type="date" label={t('payroll.fields.disbursedOn')} value={form.disbursed_on} required error={errors.fields.disbursed_on} onChange={set('disbursed_on')} />
        <TextField type="date" label={t('payroll.fields.startsOn')} help={t('payroll.loans.startsHelp')} value={form.starts_on} error={errors.fields.starts_on} onChange={set('starts_on')} />
      </div>
      <TextAreaField label={t('payroll.fields.note')} value={form.note} maxLength={500} error={errors.fields.note} onChange={set('note')} />
    </FormDialog>
  )
}

/** PAY-13: one loan with its balance and ledger; settle (outside payroll or by the next run), cancel, or change its instalment. */
export function LoanDetail() {
  const { t } = useTranslation()
  const { loanId } = useParams()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const [dialog, setDialog] = useState(null) // 'settle' | 'cancel' | 'edit'
  const key = [...payrollKey, 'loan', loanId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`payroll/loans/${loanId}`) })
  const loan = query.data?.data
  const onSaved = (response) => {
    if (response?.data) queryClient.setQueryData(key, response)
    setDialog(null)
  }
  const cancel = usePayrollMutation((reason) => api.post(`payroll/loans/${loanId}/cancel`, reason ? { reason } : {}), { success: t('payroll.loan.cancelled'), onDone: onSaved })
  const back = <Back to="/payroll/loans">{t('payroll.loans.back')}</Back>
  if (!loan) return <DetailState query={query} back={back} notFound={t('payroll.loan.notFound')} />
  const manage = can(PERM.loanManage) && loan.status === 'active'
  return (
    <>
      {back}
      <PageHeader
        eyebrow={enumLabel(t, 'loanKinds', loan.kind)}
        title={loan.reference || employeeLabel(loan.employee)}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <PayrollStatus status={loan.status} />
            <span>{employeeLabel(loan.employee)}</span>
          </span>
        }
        actions={
          manage ? (
            <>
              <Button variant="danger" onClick={() => setDialog('cancel')}>
                {t('payroll.loan.cancel')}
              </Button>
              <Button icon="edit" onClick={() => setDialog('edit')}>
                {t('payroll.actions.edit')}
              </Button>
              <Button variant="primary" icon="check" onClick={() => setDialog('settle')}>
                {t('payroll.loan.settle')}
              </Button>
            </>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        {loan.settle_next_run && loan.status === 'active' ? <Alert tone="info" title={t('payroll.loan.settleNextRun')} /> : null}
        <Card title={t('payroll.loan.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('payroll.fields.employee')}>{employeeLabel(loan.employee)}</Detail>
            <Detail label={t('payroll.fields.principal')}>
              <Amount value={loan.principal} />
            </Detail>
            <Detail label={t('payroll.fields.instalment')}>
              <Amount value={loan.instalment} />
            </Detail>
            <Detail label={t('payroll.fields.balance')}>
              <Amount value={loan.balance} />
            </Detail>
            <Detail label={t('payroll.fields.disbursedOn')}>
              <CalendarDate value={loan.disbursed_on} />
            </Detail>
            <Detail label={t('payroll.fields.startsOn')}>
              <CalendarDate value={loan.starts_on} />
            </Detail>
            {loan.note ? <Detail label={t('payroll.fields.note')}>{loan.note}</Detail> : null}
          </dl>
        </Card>
        <Card title={t('payroll.loan.ledger')} subtitle={t('payroll.loan.ledgerHelp')}>
          {loan.entries ? (
            <DataTable
              caption={t('payroll.loan.ledger')}
              rows={loan.entries}
              emptyText={t('payroll.loan.noEntries')}
              columns={[
                { key: 'entry_date', label: t('payroll.columns.date'), render: (row) => <CalendarDate value={row.entry_date} /> },
                { key: 'kind', label: t('payroll.columns.entry'), render: (row) => enumLabel(t, 'entryKinds', row.kind) },
                { key: 'amount', label: t('payroll.columns.amount'), align: 'end', render: (row) => <Amount value={row.amount} /> },
                { key: 'note', label: t('payroll.columns.note'), wrap: true, render: (row) => row.note ?? '' },
              ]}
            />
          ) : (
            <p className="text-body text-ink-muted">{t('payroll.employee.amountsHidden')}</p>
          )}
        </Card>
      </div>
      {dialog === 'settle' ? <SettleDialog loan={loan} onClose={() => setDialog(null)} onDone={onSaved} /> : null}
      {dialog === 'edit' ? <EditLoanDialog loan={loan} onClose={() => setDialog(null)} onDone={onSaved} /> : null}
      <ConfirmDialog
        open={dialog === 'cancel'}
        title={t('payroll.loan.cancelTitle')}
        text={t('payroll.loan.cancelHelp')}
        confirmLabel={t('payroll.loan.cancel')}
        reasonLabel={t('payroll.fields.reason')}
        tone="danger"
        pending={cancel.isPending}
        error={cancel.error}
        onConfirm={(reason) => cancel.mutate(reason)}
        onClose={() => setDialog(null)}
      />
    </>
  )
}

/** PAY-13: early settlement: paid outside payroll (a settlement entry) or taken in full by the next run. */
function SettleDialog({ loan, onClose, onDone }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ method: 'external', date: today(), note: '' })
  const save = usePayrollMutation((body) => api.post(`payroll/loans/${loan.id}/settle`, body), { success: t('payroll.loan.settled'), onDone })
  const errors = save.errors(['method', 'date', 'note'])
  return (
    <FormDialog
      open
      title={t('payroll.loan.settleTitle')}
      onClose={onClose}
      submitLabel={t('payroll.loan.settle')}
      pending={save.isPending}
      errors={errors}
      onSubmit={() => save.mutate(withoutBlanks(form.method === 'external' ? form : { method: form.method, note: form.note }))}
    >
      <Select label={t('payroll.fields.settleMethod')} options={enumOptions(t, 'settleMethods', SETTLE_METHODS)} value={form.method} required error={errors.fields.method} onChange={(event) => setForm({ ...form, method: event.target.value })} />
      <p className="text-caption text-ink-muted">{t(`payroll.settleHelp.${form.method}`)}</p>
      {form.method === 'external' ? <TextField type="date" label={t('payroll.fields.date')} value={form.date} error={errors.fields.date} onChange={(event) => setForm({ ...form, date: event.target.value })} /> : null}
      <TextAreaField label={t('payroll.fields.note')} value={form.note} maxLength={500} error={errors.fields.note} onChange={(event) => setForm({ ...form, note: event.target.value })} />
    </FormDialog>
  )
}

/** PAY-13: change a loan's instalment, first deduction date, reference or note. */
function EditLoanDialog({ loan, onClose, onDone }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ instalment_minor: loan.instalment?.amount_minor ?? '', starts_on: loan.starts_on ?? '', reference: loan.reference ?? '', note: loan.note ?? '' })
  const save = usePayrollMutation((body) => api.patch(`payroll/loans/${loan.id}`, body), { success: t('payroll.loan.saved'), onDone })
  const errors = save.errors(['instalment_minor', 'starts_on', 'reference', 'note'])
  return (
    <FormDialog
      open
      title={t('payroll.loan.editTitle')}
      onClose={onClose}
      submitLabel={t('payroll.actions.saveChanges')}
      pending={save.isPending}
      disabled={!form.starts_on}
      errors={errors}
      onSubmit={() => save.mutate({ ...(form.instalment_minor ? { instalment_minor: form.instalment_minor } : {}), starts_on: form.starts_on, reference: form.reference.trim() || null, note: form.note.trim() || null })}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {loan.instalment ? <MoneyInput label={t('payroll.fields.instalment')} currency={loan.currency} value={form.instalment_minor} error={errors.fields.instalment_minor} onChange={(value) => setForm({ ...form, instalment_minor: value ?? '' })} /> : null}
        <TextField type="date" label={t('payroll.fields.startsOn')} value={form.starts_on} required error={errors.fields.starts_on} onChange={(event) => setForm({ ...form, starts_on: event.target.value })} />
        <TextField label={t('payroll.fields.reference')} value={form.reference} maxLength={80} error={errors.fields.reference} onChange={(event) => setForm({ ...form, reference: event.target.value })} />
      </div>
      <TextAreaField label={t('payroll.fields.note')} value={form.note} maxLength={500} error={errors.fields.note} onChange={(event) => setForm({ ...form, note: event.target.value })} />
    </FormDialog>
  )
}
