import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, PercentInput, StatusBadge, TextField } from '@/components/ds'
import { formatCalendarDate, todayIn } from '@/lib/dates'
import { formatDecimal } from '@/lib/money'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useLocale } from '@/lib/useLocale'
import { ConfirmDialog } from '../ConfirmDialog'
import { companyScope } from '../finance/useSettingsCompany'

const taxCodesKey = (companyId) => ['tax-codes', companyId]

/** "16%" / "16 %": a rate in percent, in the UI language. */
export function Percent({ value }) {
  const { t } = useTranslation()
  const locale = useLocale()
  return <span className="tabular-nums">{t('taxes.percent', { value: formatDecimal(value, locale) })}</span>
}

/** The rate in force, "Rate needed" when it is missing or unconfirmed (never invented), or "Exempt". */
function CurrentRate({ code }) {
  const { t } = useTranslation()
  if (code.kind === 'exempt') return <span className="text-ink-muted">{t('taxes.exempt')}</span>
  if (code.rate_needed || !code.current_rate || code.current_rate.rate === null) {
    return <StatusBadge tone="warning">{t('taxes.rateNeeded')}</StatusBadge>
  }
  return <Percent value={code.current_rate.rate} />
}

/** CP-02: every rate of a code, newest first. */
function RateHistoryDialog({ code, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const rows = [...code.rates].sort((a, b) => b.effective_from.localeCompare(a.effective_from))
  const columns = [
    { key: 'rate', label: t('taxes.history.rate'), render: (row) => (row.rate === null ? '—' : <Percent value={row.rate} />) },
    { key: 'from', label: t('taxes.history.from'), render: (row) => formatCalendarDate(row.effective_from, locale) },
    { key: 'to', label: t('taxes.history.to'), render: (row) => (row.effective_to ? formatCalendarDate(row.effective_to, locale) : t('taxes.history.open')) },
    {
      key: 'status',
      label: t('taxes.history.status'),
      render: (row) =>
        row.rate === null || row.needs_confirmation ? (
          <StatusBadge tone="warning">{t('taxes.rateNeeded')}</StatusBadge>
        ) : (
          <StatusBadge tone="success">{t('taxes.history.confirmed')}</StatusBadge>
        ),
    },
    { key: 'source', label: t('taxes.history.source'), render: (row) => t(`taxes.sources.${row.source}`, { defaultValue: '—' }) },
  ]
  return (
    <Dialog open size="lg" title={t('taxes.history.title', { code: code.code })} onClose={onClose}>
      <div className="overflow-x-auto">
        <DataTable caption={t('taxes.history.title', { code: code.code })} columns={columns} rows={rows} emptyText={t('taxes.history.empty')} />
      </div>
    </Dialog>
  )
}

/** CP-02: a new rate from a date; the previous one ends the day before. */
function AddRateDialog({ code, company, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [rate, setRate] = useState('')
  const [from, setFrom] = useState(() => todayIn(company.timezone))
  const [missing, setMissing] = useState(false)

  const mutation = useMutation({
    mutationFn: () => api.post(`tax-codes/${code.id}/rates`, { rate, effective_from: from }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: taxCodesKey(company.id) })
      onClose()
    },
  })
  const errors = formErrors(mutation.error, ['rate', 'effective_from'])
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={t('taxes.addRate.title', { code: code.code })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {t('taxes.addRate.submit')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          if (!rate || !from) {
            setMissing(true)
            return
          }
          mutation.mutate()
        }}
      >
        {errors.form ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={errorMessage(mutation.error)} />
          </div>
        ) : null}
        <p>{t('taxes.addRate.intro')}</p>
        <PercentInput
          label={t('taxes.addRate.rate')}
          help={code.kind === 'zero_rated' ? t('taxes.addRate.zeroRated') : t('taxes.addRate.rateHelp')}
          value={rate}
          onChange={setRate}
          error={errors.fields.rate ?? (missing && rate === '' ? t('taxes.addRate.rateRequired') : undefined)}
          showErrors={missing}
          required
        />
        <TextField
          type="date"
          label={t('taxes.addRate.from')}
          help={t('taxes.addRate.fromHelp')}
          value={from}
          onChange={(event) => setFrom(event.target.value)}
          error={errors.fields.effective_from ?? (missing && !from ? t('taxes.addRate.fromRequired') : undefined)}
          required
        />
      </form>
    </Dialog>
  )
}

/** MD-03, CP-01, CP-02: the company's tax codes with the rate in force. */
export function TaxCodes({ company }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const canEdit = can('core.tax.edit', companyScope(company))
  const codes = useQuery({ queryKey: taxCodesKey(company.id), queryFn: () => api.get(`companies/${company.id}/tax-codes?per_page=200`) })
  const [history, setHistory] = useState(null)
  const [adding, setAdding] = useState(null)
  const [applying, setApplying] = useState(false)
  const [applied, setApplied] = useState(null)

  const apply = useMutation({
    mutationFn: () => api.post(`companies/${company.id}/tax-codes/apply-pack`),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: taxCodesKey(company.id) })
      setApplied(response.data)
      setApplying(false)
    },
  })

  const rows = codes.data?.data ?? []
  const needed = rows.filter((code) => code.rate_needed).length

  const columns = [
    { key: 'code', label: t('taxes.columns.code'), render: (row) => <span className="font-mono text-caption text-ink">{row.code}</span> },
    { key: 'name', label: t('taxes.columns.name'), render: (row) => <span className="whitespace-normal">{locale === 'fr' ? row.name_fr : row.name_en}</span> },
    { key: 'kind', label: t('taxes.columns.kind'), render: (row) => t(`taxes.kinds.${row.kind}`) },
    { key: 'rate', label: t('taxes.columns.rate'), render: (row) => <CurrentRate code={row} /> },
    {
      key: 'since',
      label: t('taxes.columns.since'),
      render: (row) => (row.current_rate ? formatCalendarDate(row.current_rate.effective_from, locale) : '—'),
    },
    {
      key: 'actions',
      label: <span className="sr-only">{t('taxes.columns.actions')}</span>,
      align: 'end',
      render: (row) => (
        <div className="flex justify-end gap-1">
          {row.kind !== 'exempt' ? (
            <Button variant="ghost" onClick={() => setHistory(row)} aria-label={t('taxes.ratesOf', { code: row.code })}>
              {t('taxes.rates')}
            </Button>
          ) : null}
          {canEdit && row.kind !== 'exempt' ? (
            <Button variant="ghost" icon="plus" onClick={() => setAdding(row)} aria-label={t('taxes.addRate.actionFor', { code: row.code })}>
              {t('taxes.addRate.action')}
            </Button>
          ) : null}
        </div>
      ),
    },
  ]

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-ink-muted">{t('taxes.codesIntro', { name: company.name })}</p>
        {canEdit ? (
          <Button icon="download" onClick={() => setApplying(true)}>
            {t('taxes.pack.action')}
          </Button>
        ) : null}
      </div>
      {needed > 0 ? <Alert tone="warning" title={t('taxes.neededAlert', { count: needed })} /> : null}
      {applied ? (
        <Alert tone="success" title={applied.added.length ? t('taxes.pack.added', { count: applied.added.length, codes: applied.added.join(', ') }) : t('taxes.pack.nothing')} />
      ) : null}
      {codes.isError ? <Alert tone="danger" title={errorMessage(codes.error)} action={<Button onClick={() => codes.refetch()}>{t('common.retry')}</Button>} /> : null}
      <div className="overflow-x-auto">
        <DataTable
          caption={t('taxes.tabs.codes')}
          columns={columns}
          rows={rows}
          emptyText={codes.isPending ? t('common.loading') : canEdit ? t('taxes.emptyEdit') : t('taxes.empty')}
        />
      </div>

      {history ? <RateHistoryDialog code={history} onClose={() => setHistory(null)} /> : null}
      {adding ? <AddRateDialog key={adding.id} code={adding} company={company} onClose={() => setAdding(null)} /> : null}
      <ConfirmDialog
        open={applying}
        tone="primary"
        title={t('taxes.pack.title', { country: t(`auth.countries.${company.country}`, { defaultValue: company.country }) })}
        confirmLabel={t('taxes.pack.confirm')}
        pending={apply.isPending}
        error={apply.error ? errorMessage(apply.error) : null}
        failure={apply.error}
        onConfirm={() => apply.mutate()}
        onClose={() => {
          setApplying(false)
          apply.reset()
        }}
      >
        {t('taxes.pack.text')}
      </ConfirmDialog>
    </div>
  )
}
