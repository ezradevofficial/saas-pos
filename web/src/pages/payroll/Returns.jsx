import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Money, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { saveFile } from '@/lib/files'
import { useHrCompany } from '@/pages/hr/hrData'
import { useDay, useWhen } from '@/pages/hr/hrForms'
import { isDraftFile, payrollKey, PERM, usePayrollMutation } from './payrollData'
import { CompanyPicker, NoCompany, PayrollStatus } from './PayrollParts'

/** The last month ("2026-09") and this year ("2026"): what a return is usually made for. */
function defaultPeriod(period) {
  const now = new Date()
  if (period === 'year') return String(now.getFullYear() - (now.getMonth() === 0 ? 1 : 0))
  const last = new Date(now.getFullYear(), now.getMonth() - 1, 1)
  return `${last.getFullYear()}-${String(last.getMonth() + 1).padStart(2, '0')}`
}

/**
 * PAY-21: a company's statutory returns: the types its country pack
 * offers, generate one for a month (or a year) from its posted runs, the
 * returns made so far, and their CSV files (rebuilt on demand). A layout
 * the pack does not confirm is flagged "Layout to be confirmed".
 */
export default function Returns() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const chosen = useHrCompany()
  const [companyId, setCompanyId] = useState(null)
  const company = companyId ?? chosen?.id ?? null
  const [type, setType] = useState('')
  const types = useQuery({ queryKey: [...payrollKey, 'return-types', company], queryFn: () => api.get(`payroll/returns/types?company=${company}`), enabled: Boolean(company), retry: false })
  const params = new URLSearchParams({ company: company ?? '', per_page: '100' })
  if (type) params.set('type', type)
  const list = useQuery({ queryKey: [...payrollKey, 'returns', params.toString()], queryFn: () => api.get(`payroll/returns?${params}`), enabled: Boolean(company) })
  if (!company) return <NoCompany />
  const typeRows = types.data?.data ?? []
  return (
    <>
      <PageHeader title={t('payroll.returns.title')} description={t('payroll.returns.description')} />
      <div className="flex flex-wrap items-end gap-3">
        <CompanyPicker
          value={company}
          onChange={(id) => {
            setCompanyId(id)
            setType('')
          }}
        />
        <Select
          label={t('payroll.returns.type')}
          options={[{ value: '', label: t('payroll.returns.allTypes') }, ...typeRows.map((row) => ({ value: row.key, label: row.name }))]}
          value={type}
          onChange={(event) => setType(event.target.value)}
        />
      </div>
      {types.isError ? <Alert tone="danger" title={errorMessage(types.error)} /> : null}
      {typeRows.some((row) => row.needs_confirmation) ? <Alert tone="warning" title={t('payroll.returns.unconfirmedTitle')}>{t('payroll.returns.unconfirmedHelp')}</Alert> : null}
      {can(PERM.returnGenerate) && typeRows.length ? <GenerateReturn key={company} company={company} types={typeRows} /> : null}
      <ReturnList query={list} />
    </>
  )
}

/** PAY-21: generate a return of one type for a month ("YYYY-MM") or a year ("YYYY"). */
function GenerateReturn({ company, types }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => ({ type: types[0].key, period: defaultPeriod(types[0].period) }))
  const chosen = types.find((row) => row.key === form.type) ?? types[0]
  const yearly = chosen.period === 'year'
  const save = usePayrollMutation((body) => api.post('payroll/returns', body), { success: t('payroll.returns.generated') })
  const errors = save.errors(['type', 'period'])
  const valid = yearly ? /^\d{4}$/.test(form.period) : /^\d{4}-\d{2}$/.test(form.period)
  return (
    <Card title={t('payroll.returns.generateTitle')} subtitle={t('payroll.returns.generateHelp')}>
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          if (valid) save.mutate({ company_id: company, type: form.type, period: form.period })
        }}
      >
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="flex flex-wrap items-end gap-3">
          <Select
            label={t('payroll.returns.type')}
            options={types.map((row) => ({ value: row.key, label: row.needs_confirmation ? t('payroll.returns.typeToConfirm', { name: row.name }) : row.name }))}
            value={form.type}
            required
            error={errors.fields.type}
            onChange={(event) => {
              const next = types.find((row) => row.key === event.target.value)
              setForm({ type: event.target.value, period: next && next.period !== chosen.period ? defaultPeriod(next.period) : form.period })
            }}
          />
          {yearly ? (
            <TextField label={t('payroll.returns.year')} inputMode="numeric" maxLength={4} value={form.period} required error={errors.fields.period ?? (valid ? undefined : t('payroll.returns.yearInvalid'))} onChange={(event) => setForm({ ...form, period: event.target.value.trim() })} />
          ) : (
            <TextField type="month" label={t('payroll.returns.month')} value={form.period} required error={errors.fields.period ?? (valid ? undefined : t('payroll.returns.monthInvalid'))} onChange={(event) => setForm({ ...form, period: event.target.value })} />
          )}
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!valid}>
            {t('payroll.returns.generate')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

/** PAY-21: the returns made, with their totals per component and their files. */
function ReturnList({ query }) {
  const { t } = useTranslation()
  const day = useDay()
  const when = useWhen()
  const [busy, setBusy] = useState(null)
  const download = async (row) => {
    setBusy(row.id)
    try {
      const { blob, filename } = await api.download(`payroll/returns/${row.id}/file`)
      const name = filename ?? `${row.type}-${row.period_start.slice(0, 7)}.csv`
      saveFile(blob, name)
      if (isDraftFile(name)) toast.warning(t('payroll.returns.draftDownloaded'))
      else toast.success(t('payroll.returns.downloaded'))
    } catch (error) {
      toast.error(errorMessage(error))
    } finally {
      setBusy(null)
    }
  }
  return (
    <>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={t('payroll.returns.title')}
        loading={query.isLoading}
        rows={query.data?.data ?? []}
        emptyText={t('payroll.returns.empty')}
        columns={[
          { key: 'name', label: t('payroll.columns.return'), render: (row) => <span className="font-medium text-ink">{row.name}</span> },
          { key: 'period', label: t('payroll.columns.period'), render: (row) => <span className="tabular-nums">{t('payroll.run.period', { start: day(row.period_start), end: day(row.period_end) })}</span> },
          { key: 'runs', label: t('payroll.columns.runs'), align: 'end', render: (row) => <span className="tabular-nums">{row.runs}</span> },
          { key: 'employees', label: t('payroll.columns.employees'), align: 'end', render: (row) => <span className="tabular-nums">{row.employee_count}</span> },
          {
            key: 'totals',
            label: t('payroll.columns.totals'),
            wrap: true,
            render: (row) =>
              row.totals?.length ? (
                <ul className="flex flex-col gap-1">
                  {row.totals.map((total) => (
                    <li key={total.code} className="flex flex-wrap items-baseline gap-2">
                      <span className="text-ink-muted">{total.name}</span>
                      {total.employee ? <Money amount={total.employee.amount_minor} currency={total.employee.currency} /> : null}
                      {total.employer && total.employer.amount_minor !== '0' ? (
                        <span className="text-caption text-ink-muted">
                          {t('payroll.returns.employerShare')} <Money amount={total.employer.amount_minor} currency={total.employer.currency} />
                        </span>
                      ) : null}
                    </li>
                  ))}
                </ul>
              ) : (
                <span className="text-ink-muted">—</span>
              ),
          },
          { key: 'layout', label: t('payroll.columns.layout'), render: (row) => (row.needs_confirmation ? <PayrollStatus status="to_confirm" label={t('payroll.returns.toConfirm')} /> : <PayrollStatus status="ready" label={t('payroll.returns.confirmed')} />) },
          { key: 'created_at', label: t('payroll.columns.generated'), render: (row) => <span className="tabular-nums">{when(row.created_at, row.company_id)}</span> },
          {
            key: 'file',
            label: '',
            align: 'end',
            render: (row) => (
              <Button variant="ghost" icon="download" loading={busy === row.id} onClick={() => download(row)}>
                {t('payroll.returns.download')}
              </Button>
            ),
          },
        ]}
      />
    </>
  )
}
