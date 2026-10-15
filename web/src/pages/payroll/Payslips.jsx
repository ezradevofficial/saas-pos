import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { saveFile } from '@/lib/files'
import { useDebounced } from '@/lib/useDebounced'
import { useDay } from '@/pages/hr/hrForms'
import { employeeLabel, enumLabel, payrollKey } from './payrollData'
import { Amount, Back, CalendarDate, Detail, DetailState, LineBreakdown } from './PayrollParts'

/** The two homes of payslips: the back office (`payroll.payslip.view`) and self-service (PAY-24). */
const BACK_OFFICE = { api: 'payroll/payslips', path: '/payroll/payslips', own: false }
const SELF = { api: 'payroll/me/payslips', path: '/payroll/me/payslips', own: true }

/** PAY-24: the API's answer for a user not linked to an employee. */
const notLinked = (error) => error?.code === 'not_an_employee'

/** Downloads a payslip's PDF (audited by the API, PAY-19). */
function useDownloadPdf(home) {
  const [busy, setBusy] = useState(null)
  const download = async (payslip) => {
    setBusy(payslip.id)
    try {
      const { blob, filename } = await api.download(`${home.api}/${payslip.id}/pdf`)
      saveFile(blob, filename ?? `${payslip.number}.pdf`)
    } catch (error) {
      toast.error(errorMessage(error))
    } finally {
      setBusy(null)
    }
  }
  return { download, busy }
}

/**
 * PAY-19, PAY-24: payslips (of posted runs) as a table: search, the run
 * from `?run=`, a page at a time; a row opens the payslip.
 */
function PayslipList({ home, title, description }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const day = useDay()
  const [searchParams] = useSearchParams()
  const run = home.own ? '' : (searchParams.get('run') ?? '')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const term = useDebounced(search.trim(), 300)
  const params = new URLSearchParams({ per_page: '25', page: String(page) })
  if (term) params.set('search', term)
  if (run) params.set('run', run)
  const query = useQuery({ queryKey: [...payrollKey, 'payslips', home.api, params.toString()], queryFn: () => api.get(`${home.api}?${params}`), retry: false, placeholderData: (previous) => previous })
  const pdf = useDownloadPdf(home)
  const lastPage = Number(query.data?.meta?.last_page ?? 1)

  if (home.own && notLinked(query.error)) {
    return (
      <>
        <PageHeader title={title} description={description} />
        <Alert tone="info" title={t('payroll.me.notLinkedTitle')}>
          {t('payroll.me.notLinkedHelp')}
        </Alert>
      </>
    )
  }

  return (
    <>
      <PageHeader title={title} description={description} />
      {run ? (
        <Alert tone="info" title={t('payroll.payslips.ofRun', { number: query.data?.data?.[0]?.run?.number ?? '' })} action={<Button onClick={() => navigate(home.path)}>{t('payroll.payslips.showAll')}</Button>} />
      ) : null}
      {home.own ? null : (
        <div className="flex flex-wrap items-end gap-3">
          <TextField
            type="search"
            label={t('payroll.payslips.search')}
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>
      )}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={title}
        loading={query.isLoading}
        stale={query.isPlaceholderData}
        rows={query.data?.data ?? []}
        emptyText={home.own ? t('payroll.me.empty') : t('payroll.payslips.empty')}
        onRowClick={(row) => navigate(`${home.path}/${row.id}`)}
        columns={[
          { key: 'number', label: t('payroll.columns.number'), render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
          ...(home.own ? [] : [{ key: 'employee', label: t('payroll.columns.employee'), render: (row) => employeeLabel(row.employee) }]),
          { key: 'period', label: t('payroll.columns.period'), render: (row) => (row.run ? <span className="tabular-nums">{t('payroll.run.period', { start: day(row.run.period_start), end: day(row.run.period_end) })}</span> : '') },
          { key: 'pay_date', label: t('payroll.columns.payDate'), render: (row) => <CalendarDate value={row.run?.pay_date} /> },
          { key: 'gross', label: t('payroll.columns.gross'), align: 'end', render: (row) => <Amount value={row.gross} /> },
          { key: 'net', label: t('payroll.columns.net'), align: 'end', render: (row) => <Amount value={row.net} /> },
          {
            key: 'pdf',
            label: '',
            align: 'end',
            render: (row) => (
              <Button
                variant="ghost"
                icon="download"
                loading={pdf.busy === row.id}
                onClick={(event) => {
                  event.stopPropagation()
                  pdf.download(row)
                }}
              >
                {t('payroll.payslips.pdf')}
              </Button>
            ),
          },
        ]}
      />
      {lastPage > 1 ? (
        <nav aria-label={t('payroll.payslips.pages')} className="flex items-center justify-end gap-2">
          <Button variant="ghost" icon="chevronLeft" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t('payroll.payslips.previous')}
          </Button>
          <span className="text-caption text-ink-muted tabular-nums">{t('payroll.payslips.pageOf', { page, last: lastPage })}</span>
          <Button variant="ghost" icon="chevronRight" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
            {t('payroll.payslips.next')}
          </Button>
        </nav>
      ) : null}
    </>
  )
}

/** PAY-19: one payslip: its run, the employee, the line (earnings, statutory, deductions, loans, rates) and its PDF. */
function PayslipPage({ home }) {
  const { t } = useTranslation()
  const { payslipId } = useParams()
  const day = useDay()
  const query = useQuery({ queryKey: [...payrollKey, 'payslip', home.api, payslipId], queryFn: () => api.get(`${home.api}/${payslipId}`), retry: false })
  const pdf = useDownloadPdf(home)
  const payslip = query.data?.data
  const back = <Back to={home.path}>{home.own ? t('payroll.me.back') : t('payroll.payslips.back')}</Back>
  if (home.own && notLinked(query.error)) {
    return (
      <>
        {back}
        <Alert tone="info" title={t('payroll.me.notLinkedTitle')}>
          {t('payroll.me.notLinkedHelp')}
        </Alert>
      </>
    )
  }
  if (!payslip) return <DetailState query={query} back={back} notFound={t('payroll.payslip.notFound')} />
  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('payroll.payslip.eyebrow')}
        title={payslip.number}
        description={[employeeLabel(payslip.employee), payslip.run ? t('payroll.run.period', { start: day(payslip.run.period_start), end: day(payslip.run.period_end) }) : null].filter(Boolean).join(' · ')}
        actions={
          <Button variant="primary" icon="download" loading={pdf.busy === payslip.id} onClick={() => pdf.download(payslip)}>
            {t('payroll.payslips.downloadPdf')}
          </Button>
        }
      />
      <div className="flex flex-col gap-5">
        <Card title={t('payroll.payslip.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('payroll.fields.employee')}>{employeeLabel(payslip.employee)}</Detail>
            <Detail label={t('payroll.fields.run')}>{payslip.run ? [payslip.run.number, enumLabel(t, 'kinds', payslip.run.kind)].join(' · ') : '—'}</Detail>
            <Detail label={t('payroll.fields.payDate')}>
              <CalendarDate value={payslip.run?.pay_date} />
            </Detail>
          </dl>
        </Card>
        {payslip.line ? <LineBreakdown line={payslip.line} /> : null}
      </div>
    </>
  )
}

/** PAY-19: the back office's payslips (`payroll.payslip.view`). */
export default function Payslips() {
  const { t } = useTranslation()
  return <PayslipList home={BACK_OFFICE} title={t('payroll.payslips.title')} description={t('payroll.payslips.description')} />
}

export function PayslipDetail() {
  return <PayslipPage home={BACK_OFFICE} />
}

/** PAY-24: "My payslips": the linked employee's own payslips; no permission needed. */
export function MyPayslips() {
  const { t } = useTranslation()
  return <PayslipList home={SELF} title={t('payroll.me.title')} description={t('payroll.me.description')} />
}

export function MyPayslipDetail() {
  return <PayslipPage home={SELF} />
}
