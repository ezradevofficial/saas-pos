import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Card, DataTable, Select, Tabs, TextField } from '@/components/ds'
import { useCompanySelection } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, CalendarDate } from './FixedAssetsParts'
import { faKey, TAX_VIEW } from './fixedAssetsData'

const yearStart = () => `${new Date().getFullYear()}-01-01`
const today = () => new Date().toISOString().slice(0, 10)

/**
 * FA-26, FA-20: a company's reports: net book value by category,
 * depreciation of a period by category, branch or cost centre, additions
 * and disposals, and the tax book (refused while the rates are
 * unconfirmed). Money only for value holders (RBAC-05).
 */
export default function Reports() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const { company } = useCompanySelection()
  const [tab, setTab] = useState('nbv')
  const [from, setFrom] = useState(yearStart)
  const [to, setTo] = useState(today)
  const [group, setGroup] = useState('category')
  const [year, setYear] = useState(() => String(new Date().getFullYear()))
  const query = useQuery({
    queryKey: [...faKey, 'report', tab, company?.id, from, to, group, year],
    queryFn: () => {
      const id = company.id
      if (tab === 'nbv') return api.get(`fixed-assets/reports/net-book-value?company=${id}`)
      if (tab === 'depreciation') return api.get(`fixed-assets/reports/depreciation?company=${id}&from=${from}&to=${to}&group=${group}`)
      if (tab === 'movements') return api.get(`fixed-assets/reports/movements?company=${id}&from=${from}&to=${to}`)
      return api.get(`fixed-assets/companies/${id}/tax-book?year=${year}`)
    },
    enabled: Boolean(company),
    retry: false,
  })
  const data = query.data?.data
  const tabs = ['nbv', 'depreciation', 'movements', ...(can(TAX_VIEW) ? ['tax'] : [])]
  const period = (
    <div className="flex flex-wrap items-end gap-3">
      <TextField type="date" label={t('fixedAssets.filters.from')} value={from} onChange={(event) => setFrom(event.target.value)} />
      <TextField type="date" label={t('fixedAssets.filters.to')} value={to} onChange={(event) => setTo(event.target.value)} />
      {tab === 'depreciation' ? (
        <Select label={t('fixedAssets.reports.groupBy')} options={['category', 'branch', 'cost_centre'].map((value) => ({ value, label: t(`fixedAssets.reports.groups.${value}`) }))} value={group} onChange={(event) => setGroup(event.target.value)} />
      ) : null}
    </div>
  )

  return (
    <>
      <PageHeader title={t('fixedAssets.reports.title')} description={t('fixedAssets.reports.description')} />
      <div className="flex flex-col gap-5">
        {!company ? <Alert tone="info" title={t('fixedAssets.chooseCompany')} /> : null}
        <Tabs value={tab} onChange={setTab} items={tabs.map((value) => ({ value, label: t(`fixedAssets.reports.tabs.${value}`) }))} />
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {tab === 'nbv' ? (
          <Card title={t('fixedAssets.reports.tabs.nbv')}>
            <DataTable
              caption={t('fixedAssets.reports.tabs.nbv')}
              columns={[
                { key: 'category', label: t('fixedAssets.fields.category'), render: (row) => row.category?.name ?? t('fixedAssets.reports.noCategory') },
                { key: 'count', label: t('fixedAssets.reports.count'), align: 'end', render: (row) => <span className="tabular-nums">{row.count}</span> },
                { key: 'cost', label: t('fixedAssets.fields.cost'), align: 'end', render: (row) => <Amount value={row.cost} /> },
                { key: 'accumulated', label: t('fixedAssets.columns.accumulated'), align: 'end', render: (row) => <Amount value={row.accumulated} /> },
                { key: 'impairment', label: t('fixedAssets.columns.impairment'), align: 'end', render: (row) => <Amount value={row.impairment} /> },
                { key: 'book_value', label: t('fixedAssets.columns.bookValue'), align: 'end', render: (row) => <Amount value={row.book_value} /> },
              ]}
              rows={(Array.isArray(data) ? data : []).map((row) => ({ ...row, id: row.category?.id ?? 'none' }))}
              loading={query.isPending}
              emptyText={t('fixedAssets.reports.empty')}
            />
          </Card>
        ) : null}
        {tab === 'depreciation' ? (
          <Card title={t('fixedAssets.reports.tabs.depreciation')}>
            <div className="flex flex-col gap-4">
              {period}
              <DataTable
                caption={t('fixedAssets.reports.tabs.depreciation')}
                columns={[
                  { key: 'name', label: t(`fixedAssets.reports.groups.${group}`), render: (row) => row.name ?? t('fixedAssets.reports.none') },
                  { key: 'amount', label: t('fixedAssets.schedule.charge'), align: 'end', render: (row) => <Amount value={row.amount} /> },
                ]}
                rows={(Array.isArray(data) ? data : []).map((row) => ({ ...row, id: row.id ?? 'none' }))}
                loading={query.isPending}
                emptyText={t('fixedAssets.reports.empty')}
              />
            </div>
          </Card>
        ) : null}
        {tab === 'movements' ? (
          <Card title={t('fixedAssets.reports.tabs.movements')}>
            <div className="flex flex-col gap-4">
              {period}
              <DataTable
                caption={t('fixedAssets.reports.additions')}
                columns={[
                  { key: 'asset', label: t('fixedAssets.columns.asset'), render: (row) => `${row.asset.number} · ${row.asset.name}` },
                  { key: 'category', label: t('fixedAssets.fields.category'), render: (row) => row.category ?? '' },
                  { key: 'date', label: t('fixedAssets.fields.inServiceOn'), render: (row) => <CalendarDate value={row.date} /> },
                  { key: 'cost', label: t('fixedAssets.fields.cost'), align: 'end', render: (row) => <Amount value={row.cost} /> },
                ]}
                rows={(data?.additions ?? []).map((row) => ({ ...row, id: row.asset.id }))}
                emptyText={t('fixedAssets.reports.noAdditions')}
              />
              <DataTable
                caption={t('fixedAssets.reports.disposals')}
                columns={[
                  { key: 'number', label: t('fixedAssets.columns.number'), render: (row) => <span className="tabular-nums">{row.number}</span> },
                  { key: 'asset', label: t('fixedAssets.columns.asset'), render: (row) => `${row.asset.number} · ${row.asset.name}` },
                  { key: 'date', label: t('fixedAssets.disposals.date'), render: (row) => <CalendarDate value={row.date} /> },
                  { key: 'book_value', label: t('fixedAssets.columns.bookValue'), align: 'end', render: (row) => <Amount value={row.book_value} /> },
                  { key: 'proceeds', label: t('fixedAssets.disposals.proceeds'), align: 'end', render: (row) => <Amount value={row.proceeds} /> },
                  { key: 'gain', label: t('fixedAssets.disposals.gain'), align: 'end', render: (row) => <Amount value={row.gain} /> },
                ]}
                rows={data?.disposals ?? []}
                emptyText={t('fixedAssets.reports.noDisposals')}
              />
            </div>
          </Card>
        ) : null}
        {tab === 'tax' ? (
          <Card title={t('fixedAssets.reports.tabs.tax')} subtitle={t('fixedAssets.reports.taxHelp')}>
            <div className="flex flex-col gap-4">
              <TextField type="number" min={2000} max={2100} label={t('fixedAssets.reports.year')} value={year} onChange={(event) => setYear(event.target.value)} />
              {query.data?.status === 'unconfirmed' ? <Alert tone="warning" title={query.data.message} /> : null}
              <DataTable
                caption={t('fixedAssets.reports.tabs.tax')}
                columns={[
                  { key: 'number', label: t('fixedAssets.columns.number'), render: (row) => <span className="tabular-nums">{row.number}</span> },
                  { key: 'name', label: t('fixedAssets.columns.name'), render: (row) => row.name },
                  { key: 'tax_class', label: t('fixedAssets.categories.taxClass'), render: (row) => t(`fixedAssets.taxClasses.${row.tax_class}`, { defaultValue: row.tax_class }) },
                  { key: 'opening', label: t('fixedAssets.reports.opening'), align: 'end', render: (row) => <Amount value={row.opening} /> },
                  { key: 'allowance', label: t('fixedAssets.reports.allowance'), align: 'end', render: (row) => <Amount value={row.allowance} /> },
                  { key: 'closing', label: t('fixedAssets.reports.closing'), align: 'end', render: (row) => <Amount value={row.closing} /> },
                ]}
                rows={(Array.isArray(data) ? data : []).map((row) => ({ ...row, id: row.asset_id }))}
                loading={query.isPending}
                emptyText={t('fixedAssets.reports.empty')}
              />
            </div>
          </Card>
        ) : null}
      </div>
    </>
  )
}
