import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { DataTable, Money, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { PerCurrency, QueryNotes, TextLink, When } from './VanSalesParts'
import { vanSalesKey } from './vansalesData'

/** Balance per currency next to the limit; the limit is read from the customer's core record. */
function Limit({ account }) {
  if (account.credit_limit_minor == null) return <span className="text-ink-muted">—</span>
  return <Money amount={account.credit_limit_minor} currency={account.credit_limit_currency} />
}

/** VAN-13: route credit balances of customers against their limits. */
export function Accounts() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const query = useQuery({ queryKey: [...vanSalesKey, 'accounts'], queryFn: () => api.get('van-sales/accounts') })
  const needle = search.trim().toLowerCase()
  const rows = (query.data?.data ?? []).filter((account) => !needle || account.customer_name.toLowerCase().includes(needle)).map((account) => ({ ...account, id: account.customer_id }))
  const columns = [
    { key: 'customer', label: t('vansales.stops.customer'), render: (account) => account.customer_name },
    { key: 'balance', label: t('vansales.credit.balance'), align: 'end', render: (account) => <PerCurrency values={account.balances} /> },
    { key: 'limit', label: t('vansales.credit.limit'), align: 'end', render: (account) => <Limit account={account} /> },
  ]
  return (
    <>
      <PageHeader title={t('vansales.credit.accountsTitle')} description={t('vansales.credit.accountsDescription')} />
      <div className="flex flex-col gap-5">
        <TextField label={t('vansales.credit.search')} placeholder={t('vansales.credit.searchPlaceholder')} type="search" value={search} onChange={(event) => setSearch(event.target.value)} className="max-w-field" />
        {query.isError ? <QueryNotes query={query} /> : null}
        {!query.isError ? <DataTable caption={t('vansales.credit.accountsTitle')} columns={columns} rows={rows} loading={query.isPending} onRowClick={(account) => navigate(`/van-sales/accounts/${account.customer_id}`)} emptyText={t('vansales.credit.accountsEmpty')} /> : null}
      </div>
    </>
  )
}

/** VAN-13: one customer's entries (sales add, collections and refunds subtract). */
export function AccountDetail() {
  const { t } = useTranslation()
  const { customerId } = useParams()
  const query = useQuery({ queryKey: [...vanSalesKey, 'account', customerId], queryFn: () => api.get(`van-sales/accounts/${customerId}`) })
  const account = query.data?.data
  const columns = [
    { key: 'at', label: t('vansales.credit.occurredAt'), render: (entry) => <When value={entry.occurred_at} /> },
    { key: 'kind', label: t('vansales.credit.kind'), render: (entry) => t(`vansales.credit.kinds.${entry.kind}`, { defaultValue: entry.kind }) },
    { key: 'amount', label: t('vansales.credit.amount'), align: 'end', render: (entry) => <Money amount={entry.amount_minor} currency={entry.currency} /> },
    { key: 'run', label: t('vansales.credit.run'), render: (entry) => (entry.run_id ? <TextLink to={`/van-sales/runs/${entry.run_id}`}>{t('vansales.credit.openRun')}</TextLink> : '') },
  ]
  return (
    <>
      <TextLink to="/van-sales/accounts">{t('vansales.credit.back')}</TextLink>
      <PageHeader title={account?.customer_name ?? t('vansales.credit.accountsTitle')} />
      <div className="flex flex-col gap-6">
        <QueryNotes query={query} />
        {account ? (
          <>
            <dl className="grid max-w-field grid-cols-[auto_1fr] gap-x-6 gap-y-2">
              <dt className="text-ink-muted">{t('vansales.credit.balance')}</dt>
              <dd><PerCurrency values={account.balances} /></dd>
              <dt className="text-ink-muted">{t('vansales.credit.limit')}</dt>
              <dd><Limit account={account} /></dd>
            </dl>
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('vansales.credit.entries')}</h2>
              <DataTable caption={t('vansales.credit.entries')} columns={columns} rows={account.entries ?? []} emptyText={t('vansales.credit.noEntries')} />
            </section>
          </>
        ) : null}
      </div>
    </>
  )
}
