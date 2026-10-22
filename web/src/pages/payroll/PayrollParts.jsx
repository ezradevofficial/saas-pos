import { useTranslation } from 'react-i18next'
import { Alert, Card, DataTable, Dialog, Money, Select, StatusBadge } from '@/components/ds'
import { useCompanies } from '@/layouts/companySelection'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { Amount, Detail } from '@/pages/inventory/InventoryParts'
import { formatPct, STATUS_TONES, statusLabel } from './payrollData'

// The presentational pieces Payroll shares with other back-office modules.
export { Amount, Back, CalendarDate, Detail, DetailState, DocumentFlow } from '@/pages/inventory/InventoryParts'
export { ConfirmDialog, DetailGrid, Empty, FormDialog, TextAreaField } from '@/pages/hr/HrParts'

/** A Payroll status as a dot and a word. */
export function PayrollStatus({ status, label }) {
  const { t } = useTranslation()
  if (!status) return null
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{label ?? statusLabel(t, status)}</StatusBadge>
}

/** A signed percentage (PAY-15), tabular; a dash when there was nothing before. */
export function Pct({ value }) {
  const locale = useLocale()
  return <span className="tabular-nums">{formatPct(value, locale)}</span>
}

/** Shown while the user has no company to set Payroll up for. */
export function NoCompany() {
  const { t } = useTranslation()
  const { ready } = useCompanies()
  if (!ready) return <p className="text-ink-muted">{t('common.loading')}</p>
  return <Alert tone="info" title={t('payroll.noCompany')} />
}

/** The companies the user sees, as a picker (settings, returns). */
export function CompanyPicker({ value, onChange, className }) {
  const { t } = useTranslation()
  const { companies } = useCompanies()
  return (
    <Select
      label={t('payroll.fields.company')}
      options={companies.filter((row) => !row.archived_at).map((row) => ({ value: row.id, label: row.name }))}
      value={value ?? ''}
      onChange={(event) => onChange(event.target.value)}
      className={className}
    />
  )
}

/**
 * PAY-08: what the country pack lacks: each component and its missing
 * values in the user's language (stored codes such as `paye` and `bands`
 * are never shown; an unknown code falls back to itself). The API's
 * worded `message` shows only when there is no list.
 */
export function MissingRates({ message, items = [] }) {
  const { t } = useTranslation()
  const label = (group, code) => t(`payroll.missing.${group}.${code}`, { defaultValue: code })
  return (
    <Alert tone="danger" title={t('payroll.missing.title')}>
      <div className="flex flex-col gap-2">
        {message && !items.length ? <p>{message}</p> : null}
        {items.length ? (
          <ul className="flex flex-col gap-1">
            {items.map((item) => (
              <li key={item.component}>{t('payroll.missing.item', { component: label('components', item.display ?? item.component), fields: (item.fields ?? []).map((field) => label('fields', field)).join(', ') })}</li>
            ))}
          </ul>
        ) : null}
        <p className="text-caption">{t('payroll.missing.help')}</p>
      </div>
    </Alert>
  )
}

/**
 * PAY-09 to PAY-13, PAY-19: one employee's line: errors and warnings, the
 * totals, earnings, statutory lines (employee and employer shares),
 * deductions, loans and the exchange rates used. Amounts the reader's
 * field rules hide are absent (PAY-23).
 */
export function LineBreakdown({ line }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const hidden = !line.net
  return (
    <div className="flex flex-col gap-4">
      {line.errors?.length ? (
        <Alert tone="danger" title={t('payroll.line.errorsTitle')}>
          <ul className="flex flex-col gap-1">
            {line.errors.map((error) => (
              <li key={error.code}>{error.message}</li>
            ))}
          </ul>
        </Alert>
      ) : null}
      {line.warnings?.length ? (
        <Alert tone="warning" title={t('payroll.line.warningsTitle')}>
          <ul className="flex flex-col gap-1">
            {line.warnings.map((warning) => (
              <li key={warning.code}>{warning.message}</li>
            ))}
          </ul>
        </Alert>
      ) : null}
      {hidden ? <Alert tone="info" title={t('payroll.line.hidden')} /> : null}
      {hidden ? null : (
        <>
          <dl className="grid gap-4 sm:grid-cols-3">
            <Detail label={t('payroll.columns.gross')}>
              <Amount value={line.gross} />
            </Detail>
            <Detail label={t('payroll.columns.statutory')}>
              <Amount value={line.statutory} />
            </Detail>
            <Detail label={t('payroll.columns.deductions')}>
              <Amount value={line.deductions} />
            </Detail>
            <Detail label={t('payroll.columns.loans')}>
              <Amount value={line.loans} />
            </Detail>
            <Detail label={t('payroll.columns.net')}>
              <Amount value={line.net} />
            </Detail>
            <Detail label={t('payroll.columns.employer')}>
              <Amount value={line.employer} />
            </Detail>
            <Detail label={t('payroll.line.taxablePay')}>
              <Amount value={line.taxable_pay} />
            </Detail>
            {line.net_base && line.net_base.currency !== line.net?.currency ? (
              <Detail label={t('payroll.line.netBase')}>
                <Amount value={line.net_base} />
              </Detail>
            ) : null}
          </dl>
          <Card title={t('payroll.line.earnings')}>
            <DataTable
              caption={t('payroll.line.earnings')}
              rows={(line.earnings ?? []).map((row, index) => ({ ...row, id: `${row.code}-${index}` }))}
              emptyText={t('payroll.line.none')}
              columns={[
                { key: 'name', label: t('payroll.columns.item'), wrap: true, render: (row) => row.name },
                { key: 'source', label: t('payroll.columns.source'), render: (row) => t(`payroll.sources.${row.source}`, { defaultValue: row.source }) },
                { key: 'quantity', label: t('payroll.columns.quantity'), align: 'end', render: (row) => (row.quantity ? <span className="tabular-nums">{formatDecimal(row.quantity, locale)}</span> : '') },
                { key: 'flags', label: t('payroll.columns.flags'), render: (row) => [row.taxable ? t('payroll.flags.taxable') : null, row.contributory ? t('payroll.flags.contributory') : null].filter(Boolean).join(', ') },
                { key: 'amount', label: t('payroll.columns.amount'), align: 'end', render: (row) => <Amount value={row.amount} /> },
              ]}
            />
          </Card>
          <Card title={t('payroll.line.statutoryTitle')}>
            <DataTable
              caption={t('payroll.line.statutoryTitle')}
              rows={(line.statutory_lines ?? []).map((row) => ({ ...row, id: row.code }))}
              emptyText={t('payroll.line.none')}
              columns={[
                { key: 'name', label: t('payroll.columns.component'), wrap: true, render: (row) => row.name },
                { key: 'employee', label: t('payroll.columns.employeeShare'), align: 'end', render: (row) => <StatutoryAmount pay={row.employee} statutory={row.employee_statutory} /> },
                { key: 'employer', label: t('payroll.columns.employerShare'), align: 'end', render: (row) => <StatutoryAmount pay={row.employer} statutory={row.employer_statutory} /> },
              ]}
            />
          </Card>
          <Card title={t('payroll.line.deductionsTitle')}>
            <DataTable
              caption={t('payroll.line.deductionsTitle')}
              rows={[...(line.deduction_lines ?? []).map((row, index) => ({ ...row, id: `d-${row.code}-${index}` })), ...(line.loan_lines ?? []).map((row) => ({ id: `l-${row.loan_id}`, code: row.reference, name: t(`payroll.loanKinds.${row.kind}`, { defaultValue: row.kind }), amount: row.amount }))]}
              emptyText={t('payroll.line.none')}
              columns={[
                { key: 'name', label: t('payroll.columns.item'), wrap: true, render: (row) => [row.name, row.code].filter(Boolean).join(' · ') },
                { key: 'amount', label: t('payroll.columns.amount'), align: 'end', render: (row) => <Amount value={row.amount} /> },
              ]}
            />
          </Card>
          {line.fx?.length ? (
            <Card title={t('payroll.line.rates')}>
              <ul className="flex flex-col gap-1">
                {line.fx.map((rate) => (
                  <li key={`${rate.base}-${rate.quote}`} className="tabular-nums">
                    {t('payroll.line.rate', { base: rate.base, quote: rate.quote, rate: formatDecimal(rate.rate, locale) })}
                  </li>
                ))}
              </ul>
            </Card>
          ) : null}
        </>
      )}
    </div>
  )
}

/** A statutory share in the pay currency, with the pack currency's amount under it when they differ (PAY-10). */
function StatutoryAmount({ pay, statutory }) {
  if (!pay) return <span className="text-ink-muted">—</span>
  const secondary = statutory && statutory.currency !== pay.currency ? { amount: statutory.amount_minor, currency: statutory.currency } : undefined
  return <Money amount={pay.amount_minor} currency={pay.currency} secondary={secondary} />
}

/** A run line in a dialog (the run page and a payslip's detail). */
export function LineDialog({ line, title, onClose }) {
  return (
    <Dialog open={Boolean(line)} size="lg" title={title} onClose={onClose}>
      {line ? (
        <div className="pt-1">
          <LineBreakdown line={line} />
        </div>
      ) : null}
    </Dialog>
  )
}
