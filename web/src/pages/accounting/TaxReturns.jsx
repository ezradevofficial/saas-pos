import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, KpiTile, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { chooseCompany, useCompanies } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { downloadFile } from '@/lib/files'
import { CalendarDate, Minor, NoCompany } from './AccountingParts'
import { companyPath, useAccountingCompany } from './accountingData'

const query = (params) => new URLSearchParams(Object.entries(params).filter(([, value]) => value !== '' && value !== undefined && value !== null)).toString()

/** The first and last day of last month in the browser's calendar, as Y-m-d (the period asked for by default). */
function lastMonth() {
  const now = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  const first = new Date(now.getFullYear(), now.getMonth() - 1, 1)
  const last = new Date(now.getFullYear(), now.getMonth(), 0)
  const text = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
  return { from: text(first), to: text(last) }
}

/** The page and form names by country; any other country gets the generic wording (no statutory layout is assumed). */
const FORMS = { vat_return: 'vat', tva_declaration: 'tva', tax_return: 'generic' }

/** A tax rate as "12.5 %", or the reason there is none. */
function RateCell({ row, t }) {
  if (row.rate !== null && row.rate !== undefined) return <span className="tabular-nums">{`${row.rate} %`}</span>
  if (row.rate_needed) return <span className="text-warning">{t('accounting.taxReturn.rateNeeded')}</span>
  return <span className="text-ink-muted">—</span>
}

/** ACC-36: the documents (invoices, credit notes, journals) behind one row of the return. */
function EntriesDialog({ company, from, to, row, onClose }) {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const path = companyPath(company, `reports/tax-return/entries?${query({ from, to, direction: row.direction, tax_code: row.tax_code_id ?? 'none', rate: row.rate ?? 'none', page })}`)
  const entries = useQuery({ queryKey: ['accounting-tax-return-entries', path], queryFn: () => api.get(path), placeholderData: (previous) => previous })
  const meta = entries.data?.meta
  const last = meta ? Math.max(1, Math.ceil(meta.total / meta.per_page)) : 1
  const currency = meta?.currency ?? company.base_currency
  const title = [t(`accounting.taxReturn.sections.${row.direction}`), row.code ?? t('accounting.taxReturn.noCode'), row.rate !== null && row.rate !== undefined ? `${row.rate} %` : null].filter(Boolean).join(' · ')

  return (
    <Dialog open title={title} onClose={onClose} footer={<Button variant="ghost" onClick={onClose}>{t('accounting.taxReturn.close')}</Button>}>
      <div className="flex flex-col gap-4">
        {entries.isError ? <Alert tone="danger" title={errorMessage(entries.error)} /> : null}
        <DataTable
          caption={title}
          loading={entries.isLoading}
          emptyText={t('accounting.taxReturn.noEntries')}
          rows={(entries.data?.data ?? []).map((entry, index) => ({ ...entry, id: `${entry.journal_id}-${index}` }))}
          columns={[
            { key: 'date', label: t('accounting.columns.date'), render: (entry) => <CalendarDate value={entry.date} /> },
            {
              key: 'document',
              label: t('accounting.taxReturn.document'),
              render: (entry) => (entry.document_id
                ? <Link to={`/accounting/documents/${entry.document_id}`} className="text-primary hover:text-primary-hover">{`${t(`accounting.documentKinds.${entry.document_kind}`)} ${entry.document_number}`}</Link>
                : <Link to={`/accounting/journals/${entry.journal_id}`} className="text-primary hover:text-primary-hover">{`${t('accounting.taxReturn.journal')} ${entry.journal_number ?? ''}`.trim()}</Link>),
            },
            {
              key: 'fiscal',
              label: t('accounting.taxReturn.fiscal'),
              render: (entry) => (entry.fiscal ? <StatusBadge tone={entry.fiscal.status === 'accepted' ? 'success' : entry.fiscal.status === 'rejected' ? 'danger' : 'warning'}>{t(`accounting.fiscalStatuses.${entry.fiscal.status}`)}</StatusBadge> : <span className="text-ink-muted">—</span>),
            },
            { key: 'taxable', label: t('accounting.taxReturn.taxable'), align: 'end', render: (entry) => <Minor amount={entry.taxable_minor} currency={currency} /> },
            { key: 'tax', label: t('accounting.taxReturn.tax'), align: 'end', render: (entry) => <Minor amount={entry.tax_minor} currency={currency} /> },
          ]}
        />
        {last > 1 ? (
          <div className="flex items-center justify-between">
            <Button variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('accounting.taxReturn.previous')}</Button>
            <span className="text-caption text-ink-muted">{t('accounting.taxReturn.pageOf', { page, last })}</span>
            <Button variant="ghost" disabled={page >= last} onClick={() => setPage(page + 1)}>{t('accounting.taxReturn.next')}</Button>
          </div>
        ) : null}
      </div>
    </Dialog>
  )
}

/** The rows of one direction (output or input) as a table: per tax code and rate. */
function Section({ direction, rows, currency, onOpen }) {
  const { t } = useTranslation()
  const mine = rows.filter((row) => row.direction === direction).map((row, index) => ({ ...row, id: `${direction}-${row.tax_code_id}-${row.rate}-${index}` }))
  const total = mine.reduce((sum, row) => sum + row.tax_minor, 0)

  return (
    <Card title={t(`accounting.taxReturn.sections.${direction}`)}>
      <DataTable
        caption={t(`accounting.taxReturn.sections.${direction}`)}
        emptyText={t('accounting.taxReturn.noRows')}
        rows={mine}
        onRowClick={onOpen}
        columns={[
          { key: 'code', label: t('accounting.taxReturn.taxCode'), render: (row) => (row.code ? <span>{row.code}<span className="text-ink-muted">{row.name ? ` · ${row.name}` : ''}</span></span> : <span className="text-ink-muted">{t('accounting.taxReturn.noCode')}</span>) },
          { key: 'kind', label: t('accounting.taxReturn.kind'), render: (row) => (row.kind ? t(`accounting.taxReturn.kinds.${row.kind}`) : '—') },
          { key: 'rate', label: t('accounting.taxReturn.rate'), align: 'end', render: (row) => <RateCell row={row} t={t} /> },
          { key: 'taxable', label: t('accounting.taxReturn.taxable'), align: 'end', render: (row) => (row.taxable_minor === null ? <span className="text-ink-muted">—</span> : <span><Minor amount={row.taxable_minor} currency={currency} />{row.taxable_complete ? '' : ' *'}</span>) },
          { key: 'tax', label: t('accounting.taxReturn.tax'), align: 'end', render: (row) => <Minor amount={row.tax_minor} currency={currency} /> },
          { key: 'entries', label: t('accounting.taxReturn.lines'), align: 'end', render: (row) => <span className="tabular-nums">{row.entries}</span> },
        ]}
      />
      <div className="flex items-center justify-between border-t border-border pt-2 text-label">
        <span>{t('accounting.taxReturn.totalOf', { section: t(`accounting.taxReturn.sections.${direction}`) })}</span>
        <Minor amount={total} currency={currency} />
      </div>
    </Card>
  )
}

/**
 * ACC-36: the tax return of a company for a period (VAT return summary in Kenya, TVA declaration summary in the DR
 * Congo): output tax, input tax and the net payable per tax code and rate, from posted transactions only, with the
 * documents behind each row and a CSV export. Statutory form boxes are not mapped (the country packs hold none): what
 * cannot be worked out from the ledger is said so under the totals.
 */
export default function TaxReturns() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const company = useAccountingCompany()
  const { companies } = useCompanies()
  const [range, setRange] = useState(lastMonth)
  const [open, setOpen] = useState(null)
  const path = company && range.from && range.to && range.from <= range.to ? companyPath(company, `reports/tax-return?${query(range)}`) : null
  const report = useQuery({ queryKey: ['accounting-tax-return', path], queryFn: () => api.get(path), enabled: Boolean(path) })
  const periods = useQuery({
    queryKey: ['accounting-tax-return-periods', company?.id, range.to.slice(0, 4)],
    queryFn: () => api.get(companyPath(company, `periods?year=${range.to.slice(0, 4)}`)),
    enabled: Boolean(company) && can('accounting.period.view'),
    retry: false,
  })
  const exportCsv = useMutation({ mutationFn: () => downloadFile(companyPath(company, `reports/tax-return/export?${query(range)}`), `tax-return-${range.from}-${range.to}.csv`) })

  if (!company) return <NoCompany />

  const data = report.data?.data
  const form = FORMS[data?.form ?? ({ KE: 'vat_return', CD: 'tva_declaration' }[company.country] ?? 'tax_return')]
  const currency = data?.currency ?? company.base_currency
  const periodOptions = (periods.data?.data ?? []).map((period) => ({ value: `${period.starts_on}|${period.ends_on}`, label: `${period.starts_on} – ${period.ends_on}` }))
  const periodValue = `${range.from}|${range.to}`

  return (
    <>
      <PageHeader
        title={t(`accounting.taxReturn.title.${form}`)}
        description={t(`accounting.taxReturn.description.${form}`)}
        actions={can('accounting.report.view') ? <Button icon="download" loading={exportCsv.isPending} disabled={!path} onClick={() => exportCsv.mutate()}>{t('accounting.taxReturn.export')}</Button> : null}
      />
      <div className="flex flex-wrap items-end gap-3">
        {companies.length > 1 ? <Select label={t('accounting.reports.company')} options={companies.map((entry) => ({ value: entry.id, label: entry.name }))} value={company.id} onChange={(event) => chooseCompany(event.target.value)} className="min-w-field" /> : null}
        {periodOptions.length ? (
          <Select
            label={t('accounting.taxReturn.period')}
            placeholder={t('accounting.taxReturn.customPeriod')}
            options={periodOptions}
            value={periodOptions.some((option) => option.value === periodValue) ? periodValue : ''}
            onChange={(event) => {
              const [from, to] = event.target.value.split('|')
              if (from && to) setRange({ from, to })
            }}
            className="min-w-field"
          />
        ) : null}
        <TextField type="date" label={t('accounting.from')} value={range.from} onChange={(event) => setRange({ ...range, from: event.target.value })} />
        <TextField type="date" label={t('accounting.to')} value={range.to} onChange={(event) => setRange({ ...range, to: event.target.value })} />
      </div>
      {range.from > range.to ? <Alert tone="danger" title={t('accounting.taxReturn.rangeInvalid')} /> : null}
      {report.isError ? <Alert tone="danger" title={errorMessage(report.error)} /> : null}
      {exportCsv.isError ? <Alert tone="danger" title={errorMessage(exportCsv.error)} /> : null}
      {report.isLoading ? <p className="text-ink-muted">{t('accounting.loading')}</p> : null}
      {data ? (
        <div className="flex flex-col gap-4">
          <div className="grid gap-3 sm:grid-cols-3">
            <KpiTile label={t('accounting.taxReturn.sections.output')} value={<Money amount={String(data.totals.output_minor)} currency={currency} />} />
            <KpiTile label={t('accounting.taxReturn.sections.input')} value={<Money amount={String(data.totals.input_minor)} currency={currency} />} />
            <KpiTile label={data.totals.net_payable_minor < 0 ? t('accounting.taxReturn.netRefundable') : t('accounting.taxReturn.netPayable')} value={<Money amount={String(data.totals.net_payable_minor)} currency={currency} />} />
          </div>
          <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
            <StatusBadge tone={data.reconciled ? 'success' : 'danger'}>{data.reconciled ? t('accounting.taxReturn.reconciled') : t('accounting.taxReturn.notReconciled')}</StatusBadge>
            {data.fiscal.documents > 0 ? <span className="text-caption text-ink-muted">{t(`accounting.taxReturn.fiscalSummary.${data.company.country === 'CD' ? 'CD' : 'KE'}`, { accepted: data.fiscal.accepted, total: data.fiscal.documents })}</span> : null}
          </div>
          {data.notes.filter((note) => note !== 'not_reconciled').map((note) => <Alert key={note} tone="info" title={t(`accounting.taxReturn.notes.${note}`)} />)}
          {data.notes.includes('not_reconciled') ? <Alert tone="danger" title={t('accounting.taxReturn.notes.not_reconciled', { output: data.ledger.output_minor, input: data.ledger.input_minor })} /> : null}
          <Section direction="output" rows={data.rows} currency={currency} onOpen={setOpen} />
          <Section direction="input" rows={data.rows} currency={currency} onOpen={setOpen} />
        </div>
      ) : null}
      {open ? <EntriesDialog company={company} from={range.from} to={range.to} row={open} onClose={() => setOpen(null)} /> : null}
    </>
  )
}
