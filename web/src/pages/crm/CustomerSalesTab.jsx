import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Card, DataTable, KpiTile, Money, StatusBadge } from '@/components/ds'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { ActivityList, Amount, CalendarDate, PerCurrency, Status, When } from './CrmParts'
import { crmKey } from './crmData'

const link = 'font-medium text-primary hover:text-primary-hover'

/** POS: the customer's sales count, totals per currency and recent sales (only when the POS part is present). */
function PosPart({ pos }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const columns = [
    { key: 'receipt_number', label: t('crm.customer.columns.receipt'), render: (sale) => <Link to={`/pos/sales/${sale.id}`} className={`${link} tabular-nums`}>{sale.receipt_number}</Link> },
    { key: 'sold_at', label: t('crm.customer.columns.soldAt'), render: (sale) => <When value={sale.sold_at} /> },
    { key: 'status', label: t('crm.columns.status'), render: (sale) => <StatusBadge tone={sale.status === 'voided' ? 'neutral' : 'success'}>{t(`crm.customer.saleStatuses.${sale.status}`, { defaultValue: sale.status })}</StatusBadge> },
    { key: 'total', label: t('crm.columns.total'), align: 'end', render: (sale) => <Money amount={sale.total_minor} currency={sale.currency} /> },
  ]
  return (
    <Card title={t('crm.customer.pos')} subtitle={pos.last_sold_at ? <span>{t('crm.customer.lastSale')} <When value={pos.last_sold_at} /></span> : null}>
      <div className="flex flex-col gap-4">
        <dl className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('crm.customer.salesCount')}</dt>
            <dd className="text-h3 tabular-nums text-ink">{formatInteger(pos.count ?? 0, locale)}</dd>
          </div>
          <div className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('crm.customer.salesTotal')}</dt>
            <dd><PerCurrency values={pos.totals} field="total_minor" /></dd>
          </div>
        </dl>
        <DataTable caption={t('crm.customer.recentSales')} columns={columns} rows={pos.recent ?? []} emptyText={t('crm.customer.noSales')} />
      </div>
    </Card>
  )
}

/** Loyalty: points and credit balances per company (only when the Loyalty part is present). */
function LoyaltyPart({ loyalty }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const points = loyalty.points ?? []
  const credit = loyalty.credit ?? []
  return (
    <Card title={t('crm.customer.loyalty')}>
      <dl className="grid gap-4 sm:grid-cols-2">
        {points.map((entry) => (
          <div key={`points-${entry.company?.id}`} className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('crm.customer.points', { company: entry.company?.name ?? '' })}</dt>
            <dd className="text-h3 tabular-nums text-ink">{formatInteger(entry.balance ?? 0, locale)}</dd>
          </div>
        ))}
        {credit.map((entry) => (
          <div key={`credit-${entry.company?.id}-${entry.currency}`} className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('crm.customer.credit', { company: entry.company?.name ?? '' })}</dt>
            <dd className="flex flex-col">
              <Money amount={entry.balance_minor} currency={entry.currency} />
              {entry.limit_minor !== null && entry.limit_minor !== undefined ? (
                <span className="text-caption text-ink-muted">
                  {t('crm.customer.limit')} <Money amount={entry.limit_minor} currency={entry.currency} />
                </span>
              ) : null}
            </dd>
          </div>
        ))}
        {!points.length && !credit.length ? <p className="text-body text-ink-muted">{t('crm.customer.noLoyalty')}</p> : null}
      </dl>
    </Card>
  )
}

/** Accounting: open and overdue receivables per currency and recent invoices (only when the Accounting part is present). */
function AccountingPart({ accounting }) {
  const { t } = useTranslation()
  const columns = [
    { key: 'number', label: t('crm.columns.number'), render: (row) => <Link to={`/accounting/documents/${row.id}`} className={`${link} tabular-nums`}>{row.number ?? t('crm.customer.draftInvoice')}</Link> },
    { key: 'kind', label: t('crm.customer.columns.kind'), render: (row) => t(`crm.customer.invoiceKinds.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'issue_date', label: t('crm.customer.columns.issueDate'), render: (row) => <CalendarDate value={row.issue_date} /> },
    { key: 'due_date', label: t('crm.customer.columns.dueDate'), render: (row) => <CalendarDate value={row.due_date} /> },
    { key: 'total', label: t('crm.columns.total'), align: 'end', render: (row) => <Money amount={row.total_minor} currency={row.currency} /> },
    { key: 'open', label: t('crm.customer.columns.open'), align: 'end', render: (row) => <Money amount={row.open_minor} currency={row.currency} /> },
  ]
  return (
    <Card title={t('crm.customer.accounting')}>
      <div className="flex flex-col gap-4">
        <dl className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('crm.customer.openReceivables')}</dt>
            <dd><PerCurrency values={accounting.open} field="open_minor" /></dd>
          </div>
          <div className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('crm.customer.overdue')}</dt>
            <dd><PerCurrency values={accounting.overdue} field="open_minor" /></dd>
          </div>
        </dl>
        <DataTable caption={t('crm.customer.recentInvoices')} columns={columns} rows={accounting.recent ?? []} emptyText={t('crm.customer.noInvoices')} />
      </div>
    </Card>
  )
}

/**
 * CRM-25: the customer's Sales tab: open pipeline value, quotes and the
 * next follow-up; open opportunities, recent quotes and activities within
 * the reader's CRM scope; and, only when the API sends them (the module is
 * active and the reader may see it), POS sales, Loyalty balances and
 * Accounting receivables. A null part is not drawn.
 */
export function CustomerSalesTab({ party }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const key = [...crmKey, 'customer', party.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`crm/customers/${party.id}/overview`) })
  const data = query.data?.data
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!data) return <p className="text-ink-muted">{t('common.loading')}</p>
  const crm = data.crm ?? {}
  const opportunities = (crm.opportunities ?? []).filter((opportunity) => opportunity.status === 'open')
  const quotes = crm.quotes ?? []
  const upcoming = crm.activities?.upcoming ?? []
  const recent = crm.activities?.recent ?? []
  const next = upcoming[0]
  const opportunityColumns = [
    { key: 'name', label: t('crm.columns.name'), render: (row) => <Link to={`/crm/opportunities/${row.id}`} className={link}>{row.name}</Link> },
    { key: 'stage', label: t('crm.columns.stage'), render: (row) => row.stage?.name ?? '' },
    { key: 'value', label: t('crm.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
    { key: 'expected_close_on', label: t('crm.columns.expectedCloseOn'), render: (row) => <CalendarDate value={row.expected_close_on} /> },
    { key: 'owner', label: t('crm.columns.owner'), render: (row) => row.owner?.name ?? t('crm.unassigned') },
  ]
  const quoteColumns = [
    { key: 'number', label: t('crm.columns.number'), render: (row) => <Link to={`/crm/quotes/${row.id}`} className={`${link} tabular-nums`}>{row.number ?? t('crm.quote.draftNumber')}</Link> },
    { key: 'status', label: t('crm.columns.status'), render: (row) => <Status status={row.status} /> },
    { key: 'quote_date', label: t('crm.columns.quoteDate'), render: (row) => <CalendarDate value={row.quote_date} /> },
    { key: 'total', label: t('crm.columns.total'), align: 'end', render: (row) => (row.totals ? <Money amount={row.totals.total_minor} currency={row.currency} /> : '') },
  ]

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-3">
        <KpiTile label={t('crm.customer.openValue')} value={<PerCurrency values={crm.open_value} />} />
        <KpiTile label={t('crm.customer.quotes')} value={formatInteger(quotes.length, locale)} />
        <KpiTile
          label={t('crm.customer.nextActivity')}
          value={
            next ? (
              <span className="flex flex-col text-body">
                <span className="text-ink">{next.subject}</span>
                <span className="text-caption text-ink-muted"><When value={next.starts_at} companyId={next.company?.id} /></span>
              </span>
            ) : (
              <span className="text-body text-ink-muted">{t('crm.customer.noNextActivity')}</span>
            )
          }
        />
      </div>
      <Card title={t('crm.customer.openOpportunities')}>
        <DataTable caption={t('crm.customer.openOpportunities')} columns={opportunityColumns} rows={opportunities} emptyText={t('crm.customer.noOpportunities')} />
      </Card>
      <Card title={t('crm.customer.recentQuotes')}>
        <DataTable caption={t('crm.customer.recentQuotes')} columns={quoteColumns} rows={quotes} emptyText={t('crm.quotes.none')} />
      </Card>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={t('crm.customer.upcoming')}>
          <ActivityList activities={upcoming} emptyText={t('crm.customer.noUpcoming')} onChanged={() => queryClient.invalidateQueries({ queryKey: key })} />
        </Card>
        <Card title={t('crm.customer.recent')}>
          <ActivityList activities={recent} emptyText={t('crm.customer.noRecent')} onChanged={() => queryClient.invalidateQueries({ queryKey: key })} />
        </Card>
      </div>
      {data.pos ? <PosPart pos={data.pos} /> : null}
      {data.loyalty ? <LoyaltyPart loyalty={data.loyalty} /> : null}
      {data.accounting ? <AccountingPart accounting={data.accounting} /> : null}
    </div>
  )
}
