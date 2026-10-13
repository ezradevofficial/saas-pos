import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, MoneyInput, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { decimalsOf, minorToDecimal } from '@/lib/money'
import { Minor, NoCompany } from './AccountingParts'
import { companyPath, today, useAccountingCompany, useAccounts } from './accountingData'

const MONTHS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]

/** ACC-16: the company's budgets (Accounting Pro). */
export default function Budgets() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const company = useAccountingCompany()
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [year, setYear] = useState(() => today().slice(0, 4))
  const budgets = useQuery({ queryKey: ['accounting-budgets', company?.id], queryFn: () => api.get(companyPath(company, 'budgets')), enabled: Boolean(company) })
  const create = useMutation({
    mutationFn: () => api.post(companyPath(company, 'budgets'), { name, fiscal_year: Number(year) }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-budgets'] })
      navigate(`/accounting/budgets/${result.data.id}`)
    },
  })

  if (!company) return <NoCompany />

  return (
    <>
      <PageHeader title={t('accounting.budgets.title')} description={t('accounting.budgets.description')}
        actions={can('accounting.budget.manage') ? <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>{t('accounting.budgets.add')}</Button> : null} />
      {budgets.isError ? <Alert tone="danger" title={errorMessage(budgets.error)} /> : null}
      <DataTable
        caption={t('accounting.budgets.title')}
        emptyText={t('accounting.budgets.empty')}
        rows={budgets.data?.data ?? []}
        onRowClick={(row) => navigate(`/accounting/budgets/${row.id}`)}
        columns={[
          { key: 'name', label: t('accounting.columns.name'), render: (row) => row.name },
          { key: 'year', label: t('accounting.budgets.fiscalYear'), render: (row) => <span className="tabular-nums">{row.fiscal_year}</span> },
        ]}
      />
      {adding ? (
        <Dialog open title={t('accounting.budgets.addTitle')} onClose={() => setAdding(false)}
          footer={<><Button variant="ghost" onClick={() => setAdding(false)}>{t('accounting.cancel')}</Button><Button variant="primary" loading={create.isPending} disabled={!name} onClick={() => create.mutate()}>{t('accounting.budgets.add')}</Button></>}>
          <div className="flex flex-col gap-4">
            {create.isError ? <Alert tone="danger" title={errorMessage(create.error)} /> : null}
            <TextField label={t('accounting.columns.name')} value={name} onChange={(event) => setName(event.target.value)} maxLength={120} required />
            <TextField type="number" label={t('accounting.budgets.fiscalYear')} value={year} onChange={(event) => setYear(event.target.value)} />
          </div>
        </Dialog>
      ) : null}
    </>
  )
}

/** ACC-16: a budget's grid (income and expense accounts by month) and its comparison with actual. */
export function BudgetDetail() {
  const { t } = useTranslation()
  const { budgetId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const company = useAccountingCompany()
  const accounts = useAccounts(company)
  const [tab, setTab] = useState('grid')
  const [edits, setEdits] = useState({})
  const [costCentre, setCostCentre] = useState('')
  const budget = useQuery({ queryKey: ['accounting-budget', budgetId], queryFn: () => api.get(`accounting/budgets/${budgetId}`) })
  const centres = useQuery({ queryKey: ['cost-centres', company?.id], queryFn: () => api.get(`companies/${company.id}/cost-centres?per_page=200`), enabled: Boolean(company) })
  const vsActual = useQuery({ queryKey: ['accounting-budget-vs-actual', budgetId], queryFn: () => api.get(`accounting/budgets/${budgetId}/vs-actual`), enabled: tab === 'actual' })
  const base = company?.base_currency ?? 'KES'
  const save = useMutation({
    mutationFn: () => api.put(`accounting/budgets/${budgetId}/cells`, {
      cells: Object.entries(edits).map(([key, minor]) => {
        const [accountId, month] = key.split('|')
        return { account_id: accountId, cost_centre_id: costCentre || null, month: Number(month), amount: minor === '' ? null : minorToDecimal(minor, decimalsOf(base)) }
      }),
    }),
    onSuccess: async (result) => {
      queryClient.setQueryData(['accounting-budget', budgetId], result)
      setEdits({})
      await queryClient.invalidateQueries({ queryKey: ['accounting-budget-vs-actual', budgetId] })
    },
  })
  const data = budget.data?.data

  if (!data) return budget.isError ? <Alert tone="danger" title={errorMessage(budget.error)} /> : <p className="text-ink-muted">{t('accounting.loading')}</p>

  const cell = (accountId, month) => {
    const key = `${accountId}|${month}`
    if (key in edits) return edits[key]
    return data.cells?.find((c) => c.account_id === accountId && c.month === month && (c.cost_centre_id ?? '') === costCentre)?.amount_minor ?? ''
  }
  const rows = (accounts.data?.data ?? []).filter((account) => account.postable && ['income', 'expense'].includes(account.type) && !account.archived_at)
  const editable = can('accounting.budget.manage')

  return (
    <>
      <PageHeader eyebrow={t('accounting.budgets.fiscalYearOf', { year: data.fiscal_year })} title={data.name}
        actions={tab === 'grid' && editable ? <Button variant="primary" loading={save.isPending} disabled={!Object.keys(edits).length} onClick={() => save.mutate()}>{t('accounting.budgets.save')}</Button> : null} />
      {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
      <Tabs value={tab} onChange={setTab} items={[{ value: 'grid', label: t('accounting.budgets.grid') }, { value: 'actual', label: t('accounting.budgets.vsActual') }]} />
      {tab === 'grid' ? (
        <Card>
          <div className="flex flex-col gap-4">
            <Select label={t('accounting.budgets.costCentre')} options={[{ value: '', label: t('accounting.budgets.noCostCentre') }, ...(centres.data?.data ?? []).map((c) => ({ value: c.id, label: c.name }))]} value={costCentre} onChange={(event) => { setCostCentre(event.target.value); setEdits({}) }} />
            <div className="overflow-x-auto">
              <table className="w-full text-body" aria-label={t('accounting.budgets.grid')}>
                <thead>
                  <tr className="text-caption text-ink-muted">
                    <th scope="col" className="px-2 py-2 text-left font-normal">{t('accounting.columns.account')}</th>
                    {MONTHS.map((month) => <th key={month} scope="col" className="px-2 py-2 text-right font-normal">{t('accounting.budgets.month', { number: month })}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((account) => (
                    <tr key={account.id} className="border-t border-border">
                      <th scope="row" className="whitespace-nowrap px-2 py-2 text-left font-normal">{`${account.code} · ${account.name}`}</th>
                      {MONTHS.map((month) => (
                        <td key={month} className="min-w-32 px-1 py-1">
                          <MoneyInput aria-label={t('accounting.budgets.cellLabel', { account: account.code, number: month })} currency={base} value={cell(account.id, month)} disabled={!editable}
                            onChange={(value) => setEdits((current) => ({ ...current, [`${account.id}|${month}`]: value ?? '' }))} />
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </Card>
      ) : (
        <DataTable
          caption={t('accounting.budgets.vsActual')}
          emptyText={t('accounting.budgets.noLines')}
          loading={vsActual.isLoading}
          rows={(vsActual.data?.data.rows ?? []).map((row, index) => ({ ...row, id: index }))}
          columns={[
            { key: 'account', label: t('accounting.columns.account'), render: (row) => (row.account ? `${row.account.code} · ${row.account.name}` : '') },
            { key: 'centre', label: t('accounting.budgets.costCentre'), render: (row) => row.cost_centre?.name ?? '—' },
            { key: 'budget', label: t('accounting.budgets.budget'), align: 'end', render: (row) => <Minor amount={row.budget} currency={base} /> },
            { key: 'actual', label: t('accounting.budgets.actual'), align: 'end', render: (row) => <Minor amount={row.actual} currency={base} /> },
            { key: 'variance', label: t('accounting.budgets.variance'), align: 'end', render: (row) => <Minor amount={row.variance} currency={base} /> },
          ]}
        />
      )}
    </>
  )
}
