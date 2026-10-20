import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, KpiTile, ListView, Money, MultiSelect, Select, StageTracker, TextField } from '@/components/ds'
import { ResearchedNotice } from '@/components/ResearchedNotice'
import { useCompanies } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { saveFile } from '@/lib/files'
import { formatInteger } from '@/lib/format'
import { moneyText } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useHrCompany } from '@/pages/hr/hrData'
import { useDay, useWhen } from '@/pages/hr/hrForms'
import {
  codeLabel,
  CORRECTION_MODES,
  enumLabel,
  enumOptions,
  isDraftFile,
  payrollKey,
  PERM,
  RUN_KINDS,
  RUN_STAGES,
  RUN_STATUSES,
  stageOf,
  statusLabel,
  today,
  usePayGroups,
  usePayrollMutation,
  variance,
} from './payrollData'
import { Amount, Back, CalendarDate, ConfirmDialog, Detail, DetailState, DocumentFlow, FormDialog, LineDialog, MissingRates, Pct, PayrollStatus, TextAreaField } from './PayrollParts'

/** "1 Oct 2026 – 31 Oct 2026": a run's period. */
function usePeriod() {
  const { t } = useTranslation()
  const day = useDay()
  return (run) => (run ? t('payroll.run.period', { start: day(run.period_start), end: day(run.period_end) }) : '')
}

/**
 * PAY-14, PAY-25: the runs of the companies the user sees, with search,
 * status, kind and pay group filters, sort and export (list `payroll-runs`);
 * New run for preparers.
 */
export default function Runs() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const period = usePeriod()
  const [creating, setCreating] = useState(false)
  const payGroups = usePayGroups(null)

  const columns = [
    { key: 'number', label: t('payroll.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'company', label: t('payroll.columns.company'), defaultHidden: true, render: (row) => row.company?.name ?? '' },
    { key: 'pay_group', label: t('payroll.columns.payGroup'), render: (row) => codeLabel(row.pay_group) },
    { key: 'kind', label: t('payroll.columns.kind'), render: (row) => enumLabel(t, 'kinds', row.kind) },
    { key: 'period_start', label: t('payroll.columns.period'), sortKey: 'period_start', render: (row) => <span className="tabular-nums">{period(row)}</span> },
    { key: 'pay_date', label: t('payroll.columns.payDate'), sortKey: 'pay_date', render: (row) => <CalendarDate value={row.pay_date} /> },
    { key: 'status', label: t('payroll.columns.status'), sortKey: 'status', render: (row) => <PayrollStatus status={row.status} /> },
    { key: 'employees', label: t('payroll.columns.employees'), align: 'end', render: (row) => <span className="tabular-nums">{row.employees}</span> },
    { key: 'net_base', label: t('payroll.columns.netBase'), align: 'end', render: (row) => <Amount value={row.totals?.base?.net} /> },
  ]

  const list = useServerList({
    id: 'payroll-runs',
    endpoint: 'payroll/runs',
    queryKey: [...payrollKey, 'runs'],
    filters: { status: 'all', kind: '', pay_group: '' },
    defaultSort: '-period_start',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('payroll.runs.title')}
        description={t('payroll.runs.description')}
        actions={
          can(PERM.runPrepare) ? (
            <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
              {t('payroll.runs.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('payroll.runs.title')}
        searchLabel={t('payroll.runs.search')}
        filterFields={[
          { name: 'status', label: t('payroll.filters.status'), options: [{ value: 'all', label: t('payroll.filters.allStatuses') }, ...RUN_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] },
          { name: 'kind', label: t('payroll.filters.kind'), options: [{ value: '', label: t('payroll.filters.allKinds') }, ...enumOptions(t, 'kinds', RUN_KINDS)] },
          ...(payGroups.length > 1 ? [{ name: 'pay_group', label: t('payroll.filters.payGroup'), options: [{ value: '', label: t('payroll.filters.allPayGroups') }, ...payGroups.map((group) => ({ value: group.id, label: codeLabel(group) }))] }] : []),
        ]}
        emptyText={list.term ? t('payroll.runs.emptyFiltered') : t('payroll.runs.empty')}
        onRowClick={(row) => navigate(`/payroll/runs/${row.id}`)}
      />
      {creating ? <NewRunDialog onClose={() => setCreating(false)} onDone={(run) => navigate(`/payroll/runs/${run.id}`)} /> : null}
    </>
  )
}

/** PAY-03, PAY-14: a regular run of a pay group for the period holding a date; the pay date defaults to the group's pay day. */
function NewRunDialog({ onClose, onDone }) {
  const { t } = useTranslation()
  const chosen = useHrCompany()
  const { companies } = useCompanies()
  const [company, setCompany] = useState(chosen?.id ?? '')
  const payGroups = usePayGroups(company || null)
  const [form, setForm] = useState({ pay_group_id: '', period_date: today(), pay_date: '' })
  const save = usePayrollMutation((body) => api.post('payroll/runs', body), {
    success: (response) => t('payroll.runs.created', { number: response?.data?.number ?? '' }),
    onDone: (response) => onDone(response.data),
  })
  const errors = save.errors(['pay_group_id', 'period_date', 'pay_date'])
  const active = companies.filter((row) => !row.archived_at)
  return (
    <FormDialog
      open
      title={t('payroll.runs.newTitle')}
      onClose={onClose}
      submitLabel={t('payroll.runs.create')}
      pending={save.isPending}
      disabled={!form.pay_group_id || !form.period_date}
      errors={errors}
      onSubmit={() => save.mutate({ pay_group_id: form.pay_group_id, period_date: form.period_date, ...(form.pay_date ? { pay_date: form.pay_date } : {}) })}
    >
      {active.length > 1 ? (
        <Select
          label={t('payroll.fields.company')}
          options={active.map((row) => ({ value: row.id, label: row.name }))}
          value={company}
          onChange={(event) => {
            setCompany(event.target.value)
            setForm({ ...form, pay_group_id: '' })
          }}
        />
      ) : null}
      <Select
        label={t('payroll.fields.payGroup')}
        placeholder={t('payroll.pickers.payGroup')}
        options={payGroups.map((group) => ({ value: group.id, label: `${codeLabel(group)} · ${enumLabel(t, 'frequencies', group.frequency)}` }))}
        value={form.pay_group_id}
        required
        error={errors.fields.pay_group_id}
        onChange={(event) => setForm({ ...form, pay_group_id: event.target.value })}
      />
      {payGroups.length === 0 ? <p className="text-caption text-ink-muted">{t('payroll.runs.noPayGroups')}</p> : null}
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField type="date" label={t('payroll.fields.periodDate')} help={t('payroll.runs.periodDateHelp')} value={form.period_date} required error={errors.fields.period_date} onChange={(event) => setForm({ ...form, period_date: event.target.value })} />
        <TextField type="date" label={t('payroll.fields.payDate')} help={t('payroll.runs.payDateHelp')} value={form.pay_date} error={errors.fields.pay_date} onChange={(event) => setForm({ ...form, pay_date: event.target.value })} />
      </div>
    </FormDialog>
  )
}

/**
 * PAY-14 to PAY-20: one run: where it is (stages), its totals by currency
 * against the previous posted run (PAY-15), what the pack lacks (PAY-08),
 * its lines (errors first to the eye), and the actions its status and the
 * user's permissions allow: calculate, submit, cancel, post, correct, the
 * payment file. Approval happens in the approvals inbox (PAY-16).
 */
export function RunDetail() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { runId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const period = usePeriod()
  const when = useWhen()
  const [dialog, setDialog] = useState(null) // 'cancel' | 'post' | 'correct'
  const key = [...payrollKey, 'run', runId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`payroll/runs/${runId}`) })
  const run = query.data?.data
  const done = async (response, message) => {
    setDialog(null)
    if (response?.data) queryClient.setQueryData(key, response)
    if (message) toast.success(message)
    await queryClient.invalidateQueries({ queryKey: payrollKey })
  }
  const calculate = useRunAction(runId, 'calculate', (response) => done(response, t('payroll.run.calculated')))
  const submit = useRunAction(runId, 'submit', (response) => done(response, t('payroll.run.submitted')))
  const post = useRunAction(runId, 'post', (response) => done(response, t('payroll.run.posted')))
  const cancel = useRunAction(runId, 'cancel', (response) => done(response, t('payroll.run.cancelled')))
  const back = <Back to="/payroll/runs">{t('payroll.runs.back')}</Back>

  if (!run) return <DetailState query={query} back={back} notFound={t('payroll.run.notFound')} />

  const prepare = can(PERM.runPrepare)
  const status = run.status
  const failed = [calculate, submit, post].find((mutation) => mutation.isError)
  const missingError = calculate.error?.code === 'statutory_rates_missing' ? calculate.error : null
  const stage = stageOf(run)

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t(run.kind === 'correction' ? 'payroll.run.eyebrowCorrection' : 'payroll.run.eyebrow')}
        title={run.number}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <PayrollStatus status={status} />
            <span>{[codeLabel(run.pay_group), period(run)].filter(Boolean).join(' · ')}</span>
          </span>
        }
        actions={
          <>
            {prepare && (status === 'draft' || status === 'calculated') ? (
              <Button variant="danger" onClick={() => setDialog('cancel')}>
                {t('payroll.run.cancel')}
              </Button>
            ) : null}
            {prepare && (status === 'draft' || status === 'calculated') ? (
              <Button variant={status === 'draft' ? 'primary' : 'secondary'} icon="sync" loading={calculate.isPending} onClick={() => calculate.mutate()}>
                {status === 'draft' ? t('payroll.run.calculate') : t('payroll.run.recalculate')}
              </Button>
            ) : null}
            {prepare && status === 'calculated' ? (
              <Button variant="pay" icon="check" loading={submit.isPending} disabled={run.lines_with_errors > 0} onClick={() => submit.mutate()}>
                {t('payroll.run.submit')}
              </Button>
            ) : null}
            {status === 'pending' ? (
              <Button variant="primary" icon="approvals" onClick={() => navigate('/approvals')}>
                {t('payroll.run.openApprovals')}
              </Button>
            ) : null}
            {can(PERM.runPost) && status === 'approved' ? (
              <Button variant="pay" icon="lock" onClick={() => setDialog('post')}>
                {t('payroll.run.post')}
              </Button>
            ) : null}
            {prepare && status === 'posted' ? (
              <Button icon="undo" onClick={() => setDialog('correct')}>
                {t('payroll.run.correct')}
              </Button>
            ) : null}
            {can(PERM.payslipView) && status === 'posted' ? (
              <Button icon="templates" onClick={() => navigate(`/payroll/payslips?run=${run.id}`)}>
                {t('payroll.run.payslips')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        {missingError ? <MissingRates message={missingError.message} items={missingError.data?.missing ?? []} /> : null}
        {!missingError && run.missing ? <MissingRates message={run.missing.message} items={run.missing.items ?? []} /> : null}
        {failed && failed.error !== missingError ? <Alert tone="danger" title={errorMessage(failed.error)} /> : null}
        {status === 'calculated' && run.lines_with_errors > 0 ? <Alert tone="warning" title={t('payroll.run.errorsBlock', { count: run.lines_with_errors })} /> : null}
        {status === 'calculated' && run.rejected_at ? <Alert tone="warning" title={t('payroll.run.rejected', { when: when(run.rejected_at, run.company_id) })} /> : null}
        {status === 'pending' ? <Alert tone="info" title={t('payroll.run.pendingTitle')}>{t('payroll.run.pendingHelp')}</Alert> : null}
        {status === 'approved' ? <Alert tone="info" title={t('payroll.run.approvedTitle')}>{t('payroll.run.approvedHelp')}</Alert> : null}
        {status !== 'posted' && status !== 'cancelled' ? <RunReview review={run.review} /> : null}
        {run.uses_researched_values ? <ResearchedNotice to="/payroll/settings?tab=statutory" /> : null}
        {run.statutory?.not_applicable?.length ? (
          <Alert tone="info" title={t('payroll.run.notApplicable', { components: run.statutory.not_applicable.map((row) => row.name ?? row.component).join(', ') })}>
            {run.statutory.not_applicable.map((row) => row.reason).join(' · ')}
          </Alert>
        ) : null}
        <Card>
          <StageTracker stages={RUN_STAGES.map((name) => t(`payroll.stages.${name}`))} current={t(`payroll.stages.${stage}`)} blocked={status === 'cancelled'} />
        </Card>
        <RunKpis run={run} />
        <Card title={t('payroll.run.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('payroll.fields.company')}>{run.company?.name}</Detail>
            <Detail label={t('payroll.fields.payGroup')}>{codeLabel(run.pay_group)}</Detail>
            <Detail label={t('payroll.fields.period')}>{period(run)}</Detail>
            <Detail label={t('payroll.fields.payDate')}>
              <CalendarDate value={run.pay_date} />
            </Detail>
            <Detail label={t('payroll.fields.kind')}>{enumLabel(t, 'kinds', run.kind)}</Detail>
            {run.kind === 'correction' ? <Detail label={t('payroll.fields.correctionMode')}>{enumLabel(t, 'correctionModes', run.correction_mode)}</Detail> : null}
            {run.pack ? <Detail label={t('payroll.run.pack')}>{[t(`payroll.run.packSources.${run.pack.source}`), run.pack.sha256 ? run.pack.sha256.slice(0, 12) : null].filter(Boolean).join(' · ')}</Detail> : null}
            {run.prepared_by ? <Detail label={t('payroll.run.preparedBy')}>{[run.prepared_by.name, when(run.calculated_at, run.company_id)].filter(Boolean).join(' · ')}</Detail> : null}
            {run.submitted_by ? <Detail label={t('payroll.run.submittedBy')}>{[run.submitted_by.name, when(run.submitted_at, run.company_id)].filter(Boolean).join(' · ')}</Detail> : null}
            {run.approved_at ? <Detail label={t('payroll.run.approvedAt')}>{when(run.approved_at, run.company_id)}</Detail> : null}
            {run.posted_by ? <Detail label={t('payroll.run.postedBy')}>{[run.posted_by.name, when(run.posted_at, run.company_id)].filter(Boolean).join(' · ')}</Detail> : null}
            {run.cancelled_at ? <Detail label={t('payroll.run.cancelledAt')}>{when(run.cancelled_at, run.company_id)}</Detail> : null}
            {run.corrects_run_id ? (
              <Detail label={t('payroll.run.corrects')}>
                <Button variant="ghost" className="px-0" onClick={() => navigate(`/payroll/runs/${run.corrects_run_id}`)}>
                  {t('payroll.run.openCorrected')}
                </Button>
              </Detail>
            ) : null}
            {run.note ? <Detail label={t('payroll.fields.note')}>{run.note}</Detail> : null}
          </dl>
        </Card>
        {run.totals ? <RunTotals totals={run.totals} /> : null}
        {status === 'posted' && can(PERM.paymentExport) ? <PaymentFilePanel run={run} /> : null}
        {status !== 'draft' && can(PERM.payView) ? <RunLines run={run} /> : null}
        {status !== 'draft' && !can(PERM.payView) ? (
          <Card title={t('payroll.lines.title')}>
            <p className="text-body text-ink-muted">{t('payroll.lines.payViewNeeded')}</p>
          </Card>
        ) : null}
        {status === 'pending' || status === 'approved' ? <DocumentFlow type="payroll.run" id={run.id} /> : null}
      </div>
      <ConfirmDialog
        open={dialog === 'cancel'}
        title={t('payroll.run.cancelTitle', { number: run.number })}
        text={t('payroll.run.cancelHelp')}
        confirmLabel={t('payroll.run.cancel')}
        reasonLabel={t('payroll.fields.reason')}
        tone="danger"
        pending={cancel.isPending}
        error={cancel.error}
        onConfirm={(reason) => cancel.mutate(reason ? { reason } : {})}
        onClose={() => setDialog(null)}
      />
      <ConfirmDialog
        open={dialog === 'post'}
        title={t('payroll.run.postTitle', { number: run.number })}
        text={t('payroll.run.postHelp')}
        confirmLabel={t('payroll.run.post')}
        tone="pay"
        pending={post.isPending}
        error={post.error}
        onConfirm={() => post.mutate()}
        onClose={() => setDialog(null)}
      />
      {dialog === 'correct' ? (
        <CorrectionDialog
          run={run}
          onClose={() => setDialog(null)}
          // PAY-18: the dialog closes before the new correction run opens on the same screen.
          onDone={(created) => {
            setDialog(null)
            navigate(`/payroll/runs/${created.id}`)
          }}
        />
      ) : null}
    </>
  )
}

/** A run action (POST payroll/runs/{id}/{name}); `onSuccess(response)`. */
function useRunAction(runId, name, onSuccess) {
  return useMutation({ mutationFn: (body = {}) => api.post(`payroll/runs/${runId}/${name}`, body), onSuccess })
}

/** The run at a glance: employees, lines with errors, net pay and employer cost (base currency). */
function RunKpis({ run }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const base = run.totals?.base
  const single = run.totals?.currencies?.length === 1 ? run.totals.currencies[0] : null
  const change = single?.previous ? variance(single.net, single.previous.net) : null
  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <KpiTile label={t('payroll.kpi.employees')} value={formatInteger(run.employees ?? 0, locale)} />
      <KpiTile label={t('payroll.kpi.errors')} value={formatInteger(run.lines_with_errors ?? 0, locale)} />
      <KpiTile
        label={t('payroll.kpi.net')}
        value={base?.net ? moneyText(base.net.amount_minor, base.net.currency, locale) : '—'}
        delta={change?.pct ? Number(change.pct) : null}
        period={t('payroll.kpi.vsPrevious')}
      />
      <KpiTile label={t('payroll.kpi.employer')} value={base?.employer ? moneyText(base.employer.amount_minor, base.employer.currency, locale) : '—'} />
    </div>
  )
}

/**
 * payroll.md decisions 29, 33 and 40: what the reviewer should know beyond
 * the lines: attendance periods still open in HR (paid later as arrears),
 * HR changes after the last posted run (a correction may be needed), and
 * employees without a pay group (paid by no run).
 */
function RunReview({ review }) {
  const { t } = useTranslation()
  const day = useDay()
  if (!review) return null
  const people = (rows) => rows.map((employee) => [employee.number, employee.name].filter(Boolean).join(' · ')).join(', ')
  const open = review.attendance_open ?? []
  const late = review.late_changes ?? []
  const noGroup = review.no_pay_group ?? []
  return (
    <>
      {open.length ? (
        <Alert tone="warning" title={t('payroll.review.attendanceOpen', { count: open.length })}>
          {open.map((period) => t('payroll.run.period', { start: day(period.start_date), end: day(period.end_date) })).join(', ')}
        </Alert>
      ) : null}
      {late.length ? (
        <Alert tone="warning" title={t('payroll.review.lateChanges', { count: late.length, run: late[0].run_number })}>
          {people(late)}
        </Alert>
      ) : null}
      {noGroup.length ? (
        <Alert tone="info" title={t('payroll.review.noPayGroup', { count: noGroup.length })}>
          {people(noGroup)}
        </Alert>
      ) : null}
    </>
  )
}

/** PAY-15: totals per pay currency, the previous posted run's gross and net with the change, and who is no longer paid. */
function RunTotals({ totals }) {
  const { t } = useTranslation()
  const departments = (totals.departments ?? []).map((row) => ({ ...row, id: `${row.department?.id ?? 'company'}-${row.currency}` }))
  const summary = totals.variance
  const rows = (totals.currencies ?? []).map((row) => ({
    ...row,
    id: row.currency,
    grossChange: row.previous ? variance(row.gross, row.previous.gross) : null,
    netChange: row.previous ? variance(row.net, row.previous.net) : null,
  }))
  const change = (value, currency) => (value ? <Money amount={value.diff} currency={currency} /> : <span className="text-ink-muted">—</span>)
  return (
    <Card title={t('payroll.totals.title')} subtitle={totals.previous_run_id ? t('payroll.totals.againstPrevious') : t('payroll.totals.noPrevious')}>
      <div className="flex flex-col gap-4">
        <DataTable
          caption={t('payroll.totals.title')}
          rows={rows}
          columns={[
            { key: 'currency', label: t('payroll.columns.currency'), render: (row) => <span className="font-medium">{row.currency}</span> },
            { key: 'employees', label: t('payroll.columns.employees'), align: 'end', render: (row) => <span className="tabular-nums">{row.employees}</span> },
            { key: 'gross', label: t('payroll.columns.gross'), align: 'end', render: (row) => <Amount value={row.gross} /> },
            { key: 'statutory', label: t('payroll.columns.statutory'), align: 'end', render: (row) => <Amount value={row.statutory} /> },
            { key: 'deductions', label: t('payroll.columns.deductions'), align: 'end', render: (row) => <Amount value={row.deductions} /> },
            { key: 'loans', label: t('payroll.columns.loans'), align: 'end', render: (row) => <Amount value={row.loans} /> },
            { key: 'net', label: t('payroll.columns.net'), align: 'end', render: (row) => <Amount value={row.net} /> },
            { key: 'employer', label: t('payroll.columns.employer'), align: 'end', render: (row) => <Amount value={row.employer} /> },
            { key: 'previous_net', label: t('payroll.columns.previousNet'), align: 'end', render: (row) => <Amount value={row.previous?.net} /> },
            { key: 'net_change', label: t('payroll.columns.netChange'), align: 'end', render: (row) => change(row.netChange, row.currency) },
            { key: 'net_pct', label: t('payroll.columns.netPct'), align: 'end', render: (row) => <Pct value={row.netChange?.pct} /> },
            { key: 'gross_pct', label: t('payroll.columns.grossPct'), align: 'end', render: (row) => <Pct value={row.grossChange?.pct} /> },
          ]}
        />
        {summary ? (
          <p className="text-body text-ink-muted">
            {t('payroll.totals.varianceSummary', { new: summary.new, gone: summary.gone, changed: summary.changed, large: summary.changed_over_10_pct })}
          </p>
        ) : null}
        {departments.length ? (
          <DataTable
            caption={t('payroll.totals.byDepartment')}
            rows={departments}
            columns={[
              { key: 'department', label: t('payroll.columns.department'), render: (row) => row.name ?? <span className="text-ink-muted">{t('payroll.totals.companyLevel')}</span> },
              { key: 'currency', label: t('payroll.columns.currency') },
              { key: 'employees', label: t('payroll.columns.employees'), align: 'end', render: (row) => <span className="tabular-nums">{row.employees}</span> },
              { key: 'gross', label: t('payroll.columns.gross'), align: 'end', render: (row) => <Amount value={row.gross} /> },
              { key: 'net', label: t('payroll.columns.net'), align: 'end', render: (row) => <Amount value={row.net} /> },
              { key: 'employer', label: t('payroll.columns.employer'), align: 'end', render: (row) => <Amount value={row.employer} /> },
            ]}
          />
        ) : null}
        {totals.gone?.length ? (
          <div className="flex flex-col gap-1">
            <p className="text-label text-ink">{t('payroll.totals.gone', { count: totals.gone.length })}</p>
            <ul className="flex flex-col gap-1 text-body text-ink-muted">
              {totals.gone.map((employee) => (
                <li key={employee.number}>{[employee.number, employee.name].filter(Boolean).join(' · ')}</li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
    </Card>
  )
}

/** PAY-14, PAY-15, PAY-25: the run's lines, those with errors marked; a line opens its breakdown. */
function RunLines({ run }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(null)
  const columns = [
    { key: 'number', label: t('payroll.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.employee?.number}</span> },
    { key: 'name', label: t('payroll.columns.name'), sortKey: 'name', hideable: false, render: (row) => row.employee?.name },
    { key: 'department', label: t('payroll.columns.department'), defaultHidden: true, render: (row) => row.department?.name ?? '' },
    { key: 'gross', label: t('payroll.columns.gross'), sortKey: 'gross', align: 'end', render: (row) => <Amount value={row.gross} /> },
    { key: 'statutory', label: t('payroll.columns.statutory'), align: 'end', render: (row) => <Amount value={row.statutory} /> },
    { key: 'deductions', label: t('payroll.columns.deductions'), align: 'end', defaultHidden: true, render: (row) => <Amount value={row.deductions} /> },
    { key: 'loans', label: t('payroll.columns.loans'), align: 'end', defaultHidden: true, render: (row) => <Amount value={row.loans} /> },
    { key: 'net', label: t('payroll.columns.net'), sortKey: 'net', align: 'end', render: (row) => <Amount value={row.net} /> },
    { key: 'employer', label: t('payroll.columns.employer'), align: 'end', defaultHidden: true, render: (row) => <Amount value={row.employer} /> },
    {
      key: 'variance',
      label: t('payroll.columns.netChange'),
      align: 'end',
      render: (row) =>
        row.variance?.status === 'new' ? (
          <PayrollStatus status="calculated" label={t('payroll.lines.new')} />
        ) : row.variance?.net_diff ? (
          <span className="flex flex-col items-end">
            <Amount value={row.variance.net_diff} />
            <span className="text-caption text-ink-muted">
              <Pct value={row.variance.net_pct} />
            </span>
          </span>
        ) : (
          <span className="text-ink-muted">—</span>
        ),
    },
    {
      key: 'check',
      label: t('payroll.columns.check'),
      render: (row) =>
        row.errors?.length ? (
          <PayrollStatus status="errors" label={t('payroll.lines.errors', { count: row.errors.length })} />
        ) : row.warnings?.length ? (
          <PayrollStatus status="warnings" label={t('payroll.lines.warnings', { count: row.warnings.length })} />
        ) : (
          <PayrollStatus status="ready" label={t('payroll.lines.ok')} />
        ),
    },
  ]
  const list = useServerList({
    id: 'payroll-run-lines',
    endpoint: `payroll/runs/${run.id}/lines`,
    queryKey: [...payrollKey, 'run-lines', run.id],
    filters: { errors: '' },
    defaultSort: 'number',
    columns,
  })
  return (
    <Card title={t('payroll.lines.title')}>
      <ListView
        list={list}
        title={t('payroll.lines.title')}
        searchLabel={t('payroll.lines.search')}
        filterFields={[{ name: 'errors', label: t('payroll.filters.lines'), options: [{ value: '', label: t('payroll.filters.allLines') }, { value: '1', label: t('payroll.filters.withErrors') }] }]}
        emptyText={list.term ? t('payroll.lines.emptyFiltered') : t('payroll.lines.empty')}
        onRowClick={(row) => setOpen(row)}
      />
      <LineDialog line={open} title={open ? [open.employee?.number, open.employee?.name].filter(Boolean).join(' · ') : ''} onClose={() => setOpen(null)} />
    </Card>
  )
}

/**
 * PAY-20: a payment file of a posted run: choose a format, see who it pays
 * and who is left out (and why), then download it. A layout the pack does
 * not confirm comes as a draft file and is flagged.
 */
function PaymentFilePanel({ run }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [format, setFormat] = useState('')
  const [downloading, setDownloading] = useState(false)
  const [error, setError] = useState(null)
  const [draft, setDraft] = useState(false)
  const formats = useQuery({ queryKey: [...payrollKey, 'payment-formats'], queryFn: () => api.get('payroll/payment-formats'), staleTime: 300_000 })
  const summary = useQuery({
    queryKey: [...payrollKey, 'payment-summary', run.id, format],
    queryFn: () => api.get(`payroll/runs/${run.id}/payment-summary?format=${encodeURIComponent(format)}`),
    enabled: Boolean(format),
    retry: false,
  })
  const data = summary.data?.data
  const download = async () => {
    setDownloading(true)
    setError(null)
    try {
      const { blob, filename } = await api.download(`payroll/runs/${run.id}/payment-file?format=${encodeURIComponent(format)}`)
      const name = filename ?? `${format}-${run.number}.csv`
      saveFile(blob, name)
      setDraft(isDraftFile(name))
      toast.success(t('payroll.payment.downloaded'))
    } catch (failure) {
      setError(failure)
    } finally {
      setDownloading(false)
    }
  }
  return (
    <Card title={t('payroll.payment.title')} subtitle={t('payroll.payment.help')}>
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-end gap-3">
          <Select
            label={t('payroll.payment.format')}
            placeholder={t('payroll.pickers.format')}
            options={(formats.data?.data ?? []).map((row) => ({ value: row.key, label: row.name }))}
            value={format}
            onChange={(event) => {
              setFormat(event.target.value)
              setDraft(false)
              setError(null)
            }}
            className="w-full sm:w-auto"
          />
          <Button variant="primary" icon="download" disabled={!format || !data} loading={downloading} onClick={download}>
            {t('payroll.payment.download')}
          </Button>
        </div>
        {summary.isError ? <Alert tone="danger" title={errorMessage(summary.error)} /> : null}
        {error ? <Alert tone="danger" title={errorMessage(error)} /> : null}
        {draft ? <Alert tone="warning" title={t('payroll.payment.draftTitle')}>{t('payroll.payment.draftHelp')}</Alert> : null}
        {data ? (
          <>
            <dl className="grid gap-4 sm:grid-cols-3">
              <Detail label={t('payroll.payment.employees')}>{formatInteger(data.employees, locale)}</Detail>
              <Detail label={t('payroll.payment.total')}>
                <span className="flex flex-col">
                  {Object.entries(data.totals ?? {}).length ? Object.entries(data.totals).map(([currency, minor]) => <Money key={currency} amount={minor} currency={currency} />) : '—'}
                </span>
              </Detail>
              <Detail label={t('payroll.payment.cash')}>{formatInteger(data.cash ?? 0, locale)}</Detail>
            </dl>
            {data.missing?.length ? (
              <Alert tone="warning" title={t('payroll.payment.leftOut', { count: data.missing.length })}>
                <ul className="flex flex-col gap-1">
                  {data.missing.map((row) => (
                    <li key={`${row.number}-${row.reason}`}>{`${[row.number, row.name].filter(Boolean).join(' · ')}: ${row.message}`}</li>
                  ))}
                </ul>
              </Alert>
            ) : null}
          </>
        ) : null}
      </div>
    </Card>
  )
}

/**
 * PAY-18: a correction run on a posted run: reverse what was posted, or
 * recalculate with today's inputs, for chosen employees (none: all).
 */
function CorrectionDialog({ run, onClose, onDone }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ mode: 'recalculate', employee_ids: [], pay_date: '', note: '' })
  const lines = useQuery({ queryKey: [...payrollKey, 'run-lines', run.id, 'picker'], queryFn: () => api.get(`payroll/runs/${run.id}/lines?per_page=100&sort=number`), retry: false })
  const save = usePayrollMutation((body) => api.post(`payroll/runs/${run.id}/corrections`, body), {
    success: (response) => t('payroll.run.correctionCreated', { number: response?.data?.number ?? '' }),
    onDone: (response) => onDone(response.data),
  })
  const errors = save.errors(['mode', 'employee_ids', 'pay_date', 'note'])
  return (
    <FormDialog
      open
      title={t('payroll.run.correctTitle', { number: run.number })}
      onClose={onClose}
      submitLabel={t('payroll.run.createCorrection')}
      pending={save.isPending}
      errors={errors}
      onSubmit={() =>
        save.mutate({
          mode: form.mode,
          ...(form.employee_ids.length ? { employee_ids: form.employee_ids } : {}),
          ...(form.pay_date ? { pay_date: form.pay_date } : {}),
          note: form.note.trim() || null,
        })
      }
    >
      <p>{t('payroll.run.correctHelp')}</p>
      <Select label={t('payroll.fields.correctionMode')} options={enumOptions(t, 'correctionModes', CORRECTION_MODES)} value={form.mode} required error={errors.fields.mode} onChange={(event) => setForm({ ...form, mode: event.target.value })} />
      <p className="text-caption text-ink-muted">{t(`payroll.correctionHelp.${form.mode}`)}</p>
      <MultiSelect
        label={t('payroll.fields.employees')}
        help={t('payroll.run.correctEmployeesHelp')}
        options={(lines.data?.data ?? []).map((line) => ({ value: line.employee?.id, label: [line.employee?.number, line.employee?.name].filter(Boolean).join(' · ') }))}
        value={form.employee_ids}
        error={errors.fields.employee_ids}
        onChange={(values) => setForm({ ...form, employee_ids: values })}
      />
      <TextField type="date" label={t('payroll.fields.payDate')} value={form.pay_date} error={errors.fields.pay_date} onChange={(event) => setForm({ ...form, pay_date: event.target.value })} />
      <TextAreaField label={t('payroll.fields.note')} value={form.note} maxLength={500} error={errors.fields.note} onChange={(event) => setForm({ ...form, note: event.target.value })} />
    </FormDialog>
  )
}
