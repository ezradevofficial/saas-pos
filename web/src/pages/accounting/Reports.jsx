import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Card, DataTable, Select, StatusBadge, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { CalendarDate, Minor, NoCompany } from './AccountingParts'
import { companyPath, sourceLink, useAccountingCompany } from './accountingData'

const TABS = ['trial-balance', 'profit-and-loss', 'balance-sheet', 'cash-flow', 'ageing', 'consolidation']

function useRange() {
  const [params, setParams] = useSearchParams()
  const set = (patch) => setParams({ ...Object.fromEntries(params), ...patch })
  return { params, set, from: params.get('from') ?? '', to: params.get('to') ?? '' }
}

const query = (params) => new URLSearchParams(Object.entries(params).filter(([, value]) => value)).toString()

/** A statement line: an account (drill-down to its GL detail) and an amount. */
function AccountRows({ rows, currency, caption, from, to, extra = [] }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  return (
    <DataTable
      caption={caption}
      rows={rows.map((row) => ({ ...row, id: row.account.id }))}
      onRowClick={(row) => navigate(`/accounting/reports/ledger?${query({ account: row.account.id, from, to })}`)}
      columns={[
        { key: 'code', label: t('accounting.columns.code'), render: (row) => <span className="tabular-nums">{row.account.code}</span> },
        { key: 'name', label: t('accounting.columns.name'), render: (row) => row.account.name },
        ...extra,
        { key: 'amount', label: t('accounting.columns.amount'), align: 'end', render: (row) => <Minor amount={row.amount ?? row.closing} currency={currency} /> },
      ]}
    />
  )
}

function TrialBalance({ data }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const c = data.currency
  return (
    <>
      <StatusBadge tone={data.balanced ? 'success' : 'danger'}>{data.balanced ? t('accounting.reports.balanced') : t('accounting.reports.unbalanced')}</StatusBadge>
      <DataTable
        caption={t('accounting.reports.tabs.trial-balance')}
        rows={data.rows.map((row) => ({ ...row, id: row.account.id }))}
        onRowClick={(row) => navigate(`/accounting/reports/ledger?${query({ account: row.account.id, from: data.from, to: data.to })}`)}
        columns={[
          { key: 'code', label: t('accounting.columns.code'), render: (row) => <span className="tabular-nums">{row.account.code}</span> },
          { key: 'name', label: t('accounting.columns.name'), render: (row) => row.account.name },
          { key: 'opening', label: t('accounting.reports.opening'), align: 'end', render: (row) => <Minor amount={row.opening} currency={c} /> },
          { key: 'debit', label: t('accounting.columns.debit'), align: 'end', render: (row) => <Minor amount={row.debit} currency={c} /> },
          { key: 'credit', label: t('accounting.columns.credit'), align: 'end', render: (row) => <Minor amount={row.credit} currency={c} /> },
          { key: 'closing', label: t('accounting.reports.closing'), align: 'end', render: (row) => <Minor amount={row.closing} currency={c} /> },
        ]}
      />
      <dl className="flex flex-wrap justify-end gap-6">
        <div><dt className="text-caption text-ink-muted">{t('accounting.columns.debit')}</dt><dd><Minor amount={data.totals.debit} currency={c} /></dd></div>
        <div><dt className="text-caption text-ink-muted">{t('accounting.columns.credit')}</dt><dd><Minor amount={data.totals.credit} currency={c} /></dd></div>
      </dl>
    </>
  )
}

function Total({ label, amount, currency }) {
  return (
    <div className="flex items-center justify-between border-t border-border pt-2 text-label">
      <span>{label}</span>
      <Minor amount={amount} currency={currency} />
    </div>
  )
}

function ProfitAndLoss({ data }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-4">
      {Object.entries(data.sections).map(([key, section]) => (section.rows.length ? (
        <Card key={key} title={t(`accounting.reports.sections.${key}`)}>
          <AccountRows rows={section.rows} currency={data.currency} caption={t(`accounting.reports.sections.${key}`)} from={data.from} to={data.to} />
          <Total label={t('accounting.reports.total')} amount={section.total} currency={data.currency} />
        </Card>
      ) : null))}
      <Total label={t('accounting.reports.grossProfit')} amount={data.gross_profit} currency={data.currency} />
      <Total label={t('accounting.reports.netProfit')} amount={data.net_profit} currency={data.currency} />
    </div>
  )
}

function BalanceSheet({ data }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-4">
      <StatusBadge tone={data.balanced ? 'success' : 'danger'}>{data.balanced ? t('accounting.reports.balanced') : t('accounting.reports.unbalanced')}</StatusBadge>
      {['asset', 'liability', 'equity'].map((key) => (
        <Card key={key} title={t(`accounting.reports.sections.${key}`)}>
          <AccountRows rows={data.sections[key].rows} currency={data.currency} caption={t(`accounting.reports.sections.${key}`)} to={data.to} />
          {key === 'equity' ? <Total label={t('accounting.reports.currentEarnings')} amount={data.current_earnings} currency={data.currency} /> : null}
          <Total label={t('accounting.reports.total')} amount={key === 'equity' ? data.total_equity : data.sections[key].total} currency={data.currency} />
        </Card>
      ))}
    </div>
  )
}

function CashFlow({ data }) {
  const { t } = useTranslation()
  const c = data.currency
  return (
    <div className="flex flex-col gap-4">
      <Card title={t('accounting.reports.cash.operating')}>
        <Total label={t('accounting.reports.netProfit')} amount={data.net_profit} currency={c} />
        <AccountRows rows={data.non_cash.rows} currency={c} caption={t('accounting.reports.cash.nonCash')} from={data.from} to={data.to} />
        <AccountRows rows={data.working_capital.rows} currency={c} caption={t('accounting.reports.cash.workingCapital')} from={data.from} to={data.to} />
        <Total label={t('accounting.reports.cash.operating')} amount={data.operating} currency={c} />
      </Card>
      {['investing', 'financing'].map((key) => (
        <Card key={key} title={t(`accounting.reports.cash.${key}`)}>
          <AccountRows rows={data[key].rows} currency={c} caption={t(`accounting.reports.cash.${key}`)} from={data.from} to={data.to} />
          <Total label={t('accounting.reports.total')} amount={data[key].total} currency={c} />
        </Card>
      ))}
      <Total label={t('accounting.reports.cash.netChange')} amount={data.net_change} currency={c} />
      <Total label={t('accounting.reports.cash.opening')} amount={data.opening_cash} currency={c} />
      <Total label={t('accounting.reports.cash.closing')} amount={data.closing_cash} currency={c} />
    </div>
  )
}

function Ageing({ data, kind, onKind }) {
  const { t } = useTranslation()
  return (
    <>
      <Select label={t('accounting.reports.ageingOf')} options={['customer', 'supplier'].map((value) => ({ value, label: t(`accounting.reports.sides.${value}`) }))} value={kind} onChange={(event) => onKind(event.target.value)} />
      <DataTable
        caption={t('accounting.reports.tabs.ageing')}
        emptyText={t('accounting.reports.nothingOpen')}
        rows={data.rows.map((row) => ({ ...row, id: `${row.party.id}|${row.currency}` }))}
        columns={[
          { key: 'party', label: t('accounting.columns.party'), render: (row) => row.party.name },
          ...['current', '1_30', '31_60', '61_90', 'over_90'].map((bucket) => ({ key: bucket, label: t(`accounting.reports.buckets.${bucket}`), align: 'end', render: (row) => <Minor amount={row.buckets[bucket]} currency={row.currency} /> })),
          { key: 'total', label: t('accounting.reports.total'), align: 'end', render: (row) => <Minor amount={row.total} currency={row.currency} /> },
        ]}
      />
    </>
  )
}

function Consolidation({ data }) {
  const { t } = useTranslation()
  const c = data.currency
  const sections = ['asset', 'liability', 'equity', 'income', 'expense']
  return (
    <div className="flex flex-col gap-4">
      <StatusBadge tone={data.balanced ? 'success' : 'danger'}>{data.balanced ? t('accounting.reports.balanced') : t('accounting.reports.unbalanced')}</StatusBadge>
      <DataTable
        caption={t('accounting.reports.tabs.consolidation')}
        rows={data.companies.map((row) => ({ ...row, id: row.company.id }))}
        columns={[
          { key: 'company', label: t('accounting.reports.company'), render: (row) => row.company.name },
          ...sections.map((key) => ({ key, label: t(`accounting.reports.sections.${key}`), align: 'end', render: (row) => <Minor amount={row.sections[key]} currency={c} /> })),
          { key: 'ic', label: t('accounting.reports.intercompany'), align: 'end', render: (row) => <Minor amount={row.intercompany} currency={c} /> },
          { key: 'reserve', label: t('accounting.reports.translationReserve'), align: 'end', render: (row) => <Minor amount={row.translation_reserve} currency={c} /> },
        ]}
      />
      <Card title={t('accounting.reports.group')}>
        {sections.map((key) => <Total key={key} label={t(`accounting.reports.sections.${key}`)} amount={data.sections[key]} currency={c} />)}
        <Total label={t('accounting.reports.eliminatedAssets')} amount={data.eliminations.assets} currency={c} />
        <Total label={t('accounting.reports.eliminationDifference')} amount={data.eliminations.difference} currency={c} />
        <Total label={t('accounting.reports.translationReserve')} amount={data.translation_reserve} currency={c} />
        <Total label={t('accounting.reports.netProfit')} amount={data.net_profit} currency={c} />
        <Total label={t('accounting.reports.totalEquity')} amount={data.total_equity} currency={c} />
      </Card>
    </div>
  )
}

/**
 * ACC-11, ACC-17, ACC-18: the trial balance, profit and loss, balance
 * sheet, cash flow, ageing and the group's consolidation, in base (or the
 * reporting) currency. Every account line opens its GL detail, every GL
 * line its journal, and a journal its source.
 */
export default function Reports() {
  const { t } = useTranslation()
  const { can, hasModule } = usePermissions()
  const company = useAccountingCompany()
  const { params, set, from, to } = useRange()
  const tab = TABS.includes(params.get('report')) && (params.get('report') !== 'consolidation' || hasModule('accounting_pro')) ? params.get('report') : 'trial-balance'
  const kind = params.get('kind') === 'supplier' ? 'supplier' : 'customer'
  const currency = params.get('currency') ?? ''
  const path = tab === 'consolidation'
    ? `accounting/consolidation?${query({ from, to, currency })}`
    : company ? companyPath(company, `reports/${tab}?${query({ from, to, kind: tab === 'ageing' ? kind : '' })}`) : null
  const report = useQuery({ queryKey: ['accounting-report', path], queryFn: () => api.get(path), enabled: Boolean(path) })

  if (!company) return <NoCompany />

  // ACC-18: group consolidation is an Accounting Pro feature.
  const tabs = TABS.filter((key) => key !== 'consolidation' || (hasModule('accounting_pro') && can('accounting.report.consolidate')))
  const data = report.data?.data

  return (
    <>
      <PageHeader title={t('accounting.reports.title')} description={t('accounting.reports.description')} />
      <Tabs value={tab} onChange={(next) => set({ report: next })} items={tabs.map((key) => ({ value: key, label: t(`accounting.reports.tabs.${key}`) }))} />
      <div className="flex flex-wrap items-end gap-3">
        {tab !== 'balance-sheet' && tab !== 'ageing' ? <TextField type="date" label={t('accounting.from')} value={from} onChange={(event) => set({ from: event.target.value })} /> : null}
        <TextField type="date" label={tab === 'balance-sheet' || tab === 'ageing' ? t('accounting.reports.asOf') : t('accounting.to')} value={to} onChange={(event) => set({ to: event.target.value })} />
        {tab === 'consolidation' ? <TextField label={t('accounting.reports.reportingCurrency')} value={currency} onChange={(event) => set({ currency: event.target.value.toUpperCase() })} maxLength={3} /> : null}
      </div>
      {report.isError ? <Alert tone="danger" title={errorMessage(report.error)} /> : null}
      {!data ? (report.isLoading ? <p className="text-ink-muted">{t('accounting.loading')}</p> : null)
        : tab === 'trial-balance' ? <TrialBalance data={data} />
          : tab === 'profit-and-loss' ? <ProfitAndLoss data={data} />
            : tab === 'balance-sheet' ? <BalanceSheet data={data} />
              : tab === 'cash-flow' ? <CashFlow data={data} />
                : tab === 'ageing' ? <Ageing data={data} kind={kind} onKind={(next) => set({ kind: next })} />
                  : <Consolidation data={data} />}
    </>
  )
}

/** ACC-17: the GL detail of one account: each posted line with a running balance, its journal and source. */
export function GeneralLedger() {
  const { t } = useTranslation()
  const company = useAccountingCompany()
  const { set, from, to, params } = useRange()
  const account = params.get('account') ?? ''
  const path = company ? companyPath(company, `reports/general-ledger?${query({ account, from, to })}`) : null
  const report = useQuery({ queryKey: ['accounting-report', path], queryFn: () => api.get(path), enabled: Boolean(path) })
  const data = report.data?.data

  if (!company) return <NoCompany />

  return (
    <>
      <PageHeader eyebrow={t('accounting.reports.ledgerEyebrow')} title={data?.account ? `${data.account.code} · ${data.account.name}` : t('accounting.reports.ledgerTitle')} />
      <div className="flex flex-wrap items-end gap-3">
        <TextField type="date" label={t('accounting.from')} value={from} onChange={(event) => set({ from: event.target.value })} />
        <TextField type="date" label={t('accounting.to')} value={to} onChange={(event) => set({ to: event.target.value })} />
      </div>
      {report.isError ? <Alert tone="danger" title={errorMessage(report.error)} /> : null}
      {data && !data.account ? (
        <AccountRows rows={data.accounts} currency={company.base_currency} caption={t('accounting.reports.ledgerTitle')} from={data.from} to={data.to} />
      ) : data ? (
        <>
          <p className="text-body text-ink-muted">{t('accounting.reports.opening')} <Minor amount={data.opening} currency={data.currency} /></p>
          <DataTable
            caption={t('accounting.reports.ledgerTitle')}
            emptyText={t('accounting.reports.noLines')}
            rows={data.lines}
            columns={[
              { key: 'date', label: t('accounting.columns.date'), render: (row) => <CalendarDate value={row.date} /> },
              { key: 'journal', label: t('accounting.columns.number'), render: (row) => <Link to={`/accounting/journals/${row.journal.id}`} className="text-primary hover:text-primary-hover">{row.journal.number}</Link> },
              { key: 'source', label: t('accounting.journalDetail.source'), render: (row) => { const link = sourceLink({ type: row.journal.source_type, id: row.journal.source_id }); return link ? <Link to={link} className="text-primary">{t('accounting.reports.openSource')}</Link> : '—' } },
              { key: 'description', label: t('accounting.columns.description'), render: (row) => [row.description, row.party?.name].filter(Boolean).join(' · ') },
              { key: 'debit', label: t('accounting.columns.debit'), align: 'end', render: (row) => (row.base_debit ? <Minor amount={row.base_debit} currency={data.currency} /> : null) },
              { key: 'credit', label: t('accounting.columns.credit'), align: 'end', render: (row) => (row.base_credit ? <Minor amount={row.base_credit} currency={data.currency} /> : null) },
              { key: 'balance', label: t('accounting.reports.balance'), align: 'end', render: (row) => <Minor amount={row.balance} currency={data.currency} /> },
            ]}
          />
          {data.truncated ? <Alert tone="info" title={t('accounting.reports.truncated')} /> : null}
        </>
      ) : null}
    </>
  )
}
