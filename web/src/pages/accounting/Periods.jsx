import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Checkbox, DataTable, Dialog, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { NoCompany } from './AccountingParts'
import { companyPath, useAccountingCompany } from './accountingData'

/**
 * ACC-08, ACC-15: the periods of a fiscal year. Closing a period closes
 * the open ones before it, optionally revalues foreign items first, and
 * at year end posts the closing entry; only the latest closed period
 * reopens, with a reason.
 */
export default function Periods() {
  const { t } = useTranslation()
  const locale = useLocale()
  const day = (value) => formatCalendarDate(value, locale)
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const company = useAccountingCompany()
  const [year, setYear] = useState('')
  const [closing, setClosing] = useState(null)
  const [reopening, setReopening] = useState(null)
  const [revalue, setRevalue] = useState(false)
  const [reason, setReason] = useState('')
  const key = ['accounting-periods', company?.id, year]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(companyPath(company, `periods${year ? `?year=${year}` : ''}`)), enabled: Boolean(company) })
  const done = async () => {
    setClosing(null)
    setReopening(null)
    setReason('')
    await queryClient.invalidateQueries({ queryKey: ['accounting-periods'] })
  }
  const close = useMutation({ mutationFn: (period) => api.post(`accounting/periods/${period.id}/close`, { revalue }), onSuccess: done })
  const reopen = useMutation({ mutationFn: (period) => api.post(`accounting/periods/${period.id}/reopen`, { reason }), onSuccess: done })

  if (!company) return <NoCompany />

  const fiscalYear = query.data?.meta?.fiscal_year
  const years = fiscalYear ? [fiscalYear - 1, fiscalYear, fiscalYear + 1] : []
  const rows = query.data?.data ?? []
  const lastClosed = [...rows].reverse().find((period) => period.status === 'closed')

  return (
    <>
      <PageHeader
        title={t('accounting.periods.title')}
        description={t('accounting.periods.description')}
        actions={fiscalYear ? <Select label={t('accounting.periods.year')} options={years.map((y) => ({ value: String(y), label: String(y) }))} value={String(year || fiscalYear)} onChange={(event) => setYear(event.target.value)} /> : null}
      />
      <DataTable
        caption={t('accounting.periods.title')}
        loading={query.isLoading}
        rows={rows}
        columns={[
          { key: 'number', label: t('accounting.periods.period'), render: (row) => <span className="tabular-nums">{row.number}</span> },
          { key: 'range', label: t('accounting.periods.dates'), render: (row) => <span className="tabular-nums">{`${day(row.starts_on)} – ${day(row.ends_on)}`}</span> },
          { key: 'status', label: t('accounting.columns.status'), render: (row) => <StatusBadge tone={row.status === 'closed' ? 'neutral' : 'success'}>{t(`accounting.periodStatuses.${row.status}`)}</StatusBadge> },
          { key: 'closed', label: t('accounting.periods.closedBy'), render: (row) => (row.closed_by ? `${row.closed_by.name}` : row.reopened_by ? t('accounting.periods.reopenedBy', { name: row.reopened_by.name }) : '—') },
          { key: 'revalued', label: t('accounting.periods.revalued'), render: (row) => (row.revalued ? t('accounting.yes') : '—') },
          {
            key: 'actions', label: '', align: 'end',
            render: (row) => row.status === 'open'
              ? (can('accounting.period.close') ? <Button size="sm" onClick={() => setClosing(row)}>{t('accounting.periods.close')}</Button> : null)
              : (row.id === lastClosed?.id && can('accounting.period.reopen') ? <Button size="sm" variant="ghost" onClick={() => setReopening(row)}>{t('accounting.periods.reopen')}</Button> : null),
          },
        ]}
      />
      {closing ? (
        <Dialog open title={t('accounting.periods.closeTitle', { start: day(closing.starts_on) })} onClose={() => setClosing(null)}
          footer={<><Button variant="ghost" onClick={() => setClosing(null)}>{t('accounting.cancel')}</Button><Button variant="pay" loading={close.isPending} onClick={() => close.mutate(closing)}>{t('accounting.periods.close')}</Button></>}>
          <div className="flex flex-col gap-4">
            {close.isError ? <Alert tone="danger" title={errorMessage(close.error)} /> : null}
            <p>{t('accounting.periods.closeText')}</p>
            {closing.number === 12 ? <Alert tone="info" title={t('accounting.periods.yearEnd')} /> : null}
            <Checkbox label={t('accounting.periods.revalueFirst')} help={t('accounting.periods.revalueHelp')} checked={revalue} onChange={(event) => setRevalue(event.target.checked)} />
          </div>
        </Dialog>
      ) : null}
      {reopening ? (
        <Dialog open title={t('accounting.periods.reopenTitle', { start: day(reopening.starts_on) })} onClose={() => setReopening(null)}
          footer={<><Button variant="ghost" onClick={() => setReopening(null)}>{t('accounting.cancel')}</Button><Button variant="primary" loading={reopen.isPending} disabled={reason.trim().length < 3} onClick={() => reopen.mutate(reopening)}>{t('accounting.periods.reopen')}</Button></>}>
          <div className="flex flex-col gap-4">
            {reopen.isError ? <Alert tone="danger" title={errorMessage(reopen.error)} /> : null}
            <p>{t('accounting.periods.reopenText')}</p>
            <TextField label={t('accounting.periods.reason')} value={reason} onChange={(event) => setReason(event.target.value)} maxLength={500} required />
          </div>
        </Dialog>
      ) : null}
    </>
  )
}
