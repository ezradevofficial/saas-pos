import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, Dialog, ListView, RateInput, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { localDateTimeIn, zonedToUtc } from '@/lib/dates'
import { formatDecimal } from '@/lib/money'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { pairsFromRates, ratePairs } from './finance/rates'
import { companyScope, useSettingsCompany, useTenantCurrencies } from './finance/useSettingsCompany'

const KINDS = ['reference', 'shop']

/** "1 USD = CDF 2,850" for a rate as stored. */
function RateValue({ rate, value = rate.mid }) {
  const locale = useLocale()
  return (
    <span className="tabular-nums">
      1 {rate.base} = <span className="text-ink-muted">{rate.quote}</span> {formatDecimal(value, locale)}
    </span>
  )
}

function sourceLabel(t, source) {
  if (!source) return null
  return t(`rates.sources.${source}`, { defaultValue: t('rates.sources.feed') })
}

/** The latest rate of one kind for the pair, and whether it is the one in force. */
function CurrentCard({ kind, rate, inForce, loading, company }) {
  const { t } = useTranslation()
  const locale = useLocale()
  return (
    <Card
      title={t(`rates.current.${kind}`)}
      subtitle={t(`rates.current.${kind}Help`)}
      actions={rate && inForce ? <StatusBadge tone="success">{t('rates.current.inUse')}</StatusBadge> : null}
    >
      {loading ? (
        <p className="text-ink-muted">{t('common.loading')}</p>
      ) : rate ? (
        <dl className="flex flex-col gap-2">
          <div>
            <dt className="sr-only">{t('rates.fields.mid')}</dt>
            <dd className="text-amount-lg text-ink">
              <RateValue rate={rate} />
            </dd>
          </div>
          {rate.buy || rate.sell ? (
            <div className="flex flex-wrap gap-x-5 gap-y-1 text-ink-muted">
              {rate.buy ? (
                <div className="flex gap-1">
                  <dt>{t('rates.fields.buy')}</dt>
                  <dd className="text-ink tabular-nums">{formatDecimal(rate.buy, locale)}</dd>
                </div>
              ) : null}
              {rate.sell ? (
                <div className="flex gap-1">
                  <dt>{t('rates.fields.sell')}</dt>
                  <dd className="text-ink tabular-nums">{formatDecimal(rate.sell, locale)}</dd>
                </div>
              ) : null}
            </div>
          ) : null}
          <div className="flex flex-wrap gap-1 text-caption text-ink-muted">
            <dt>{t('rates.fields.effective')}</dt>
            <dd>
              {formatCompanyTime(rate.effective_at, locale, company)} · {sourceLabel(t, rate.source)}
            </dd>
          </div>
        </dl>
      ) : (
        <p className="text-ink-muted">{t(`rates.current.${kind}Empty`)}</p>
      )}
    </Card>
  )
}

/** CUR-03: a shop rate for the pair, effective now unless a time is chosen (company time zone). */
function ShopRateDialog({ company, pair, onClose, onSaved }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [base, quote] = pair.split('/')
  const [values, setValues] = useState({ mid: '', buy: '', sell: '' })
  const [now, setNow] = useState(true)
  const [effective, setEffective] = useState(() => localDateTimeIn(company.timezone))
  const [missing, setMissing] = useState(false)
  const set = (field) => (value) => setValues((current) => ({ ...current, [field]: value }))

  const mutation = useMutation({
    mutationFn: () =>
      api.post(`companies/${company.id}/exchange-rates`, {
        base,
        quote,
        mid: values.mid,
        ...(values.buy ? { buy: values.buy } : {}),
        ...(values.sell ? { sell: values.sell } : {}),
        ...(now ? {} : { effective_at: zonedToUtc(effective, company.timezone) }),
      }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['exchange-rates', company.id] })
      onSaved(response?.meta?.warning ?? null)
    },
  })
  const errors = formErrors(mutation.error, ['mid', 'buy', 'sell', 'effective_at'])
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={t('rates.dialog.title', { pair })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {t('rates.dialog.submit')}
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
          // A field left invalid (null) shows its reason once submitted (showErrors).
          if (!values.mid || values.buy === null || values.sell === null) {
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
        <p>{t('rates.dialog.intro', { base, quote })}</p>
        <RateInput
          label={t('rates.dialog.mid', { base, quote })}
          prefix={quote}
          value={values.mid}
          onChange={set('mid')}
          error={errors.fields.mid ?? (missing && values.mid === '' ? t('rates.dialog.midRequired') : undefined)}
          showErrors={missing}
          required
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <RateInput
            label={t('rates.dialog.buy')}
            help={t('rates.dialog.buyHelp', { base })}
            prefix={quote}
            value={values.buy}
            onChange={set('buy')}
            error={errors.fields.buy}
            showErrors={missing}
          />
          <RateInput
            label={t('rates.dialog.sell')}
            help={t('rates.dialog.sellHelp', { base })}
            prefix={quote}
            value={values.sell}
            onChange={set('sell')}
            error={errors.fields.sell}
            showErrors={missing}
          />
        </div>
        <Checkbox label={t('rates.dialog.now')} checked={now} onChange={(event) => setNow(event.target.checked)} />
        {now ? null : (
          <TextField
            type="datetime-local"
            label={t('rates.dialog.effective')}
            help={t('rates.dialog.effectiveHelp', { zone: company.timezone })}
            value={effective}
            onChange={(event) => setEffective(event.target.value)}
            error={errors.fields.effective_at}
            required
          />
        )}
      </form>
    </Dialog>
  )
}

/** CUR-07: a saved rate that moved more than the company's tolerance. A warning, not an error. */
function ToleranceWarning({ warning, pair, onDismiss }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [base, quote] = pair.split('/')
  return (
    <Alert
      tone="warning"
      title={t('rates.tolerance.title', { change: formatDecimal(warning.change_percent, locale), tolerance: formatDecimal(warning.tolerance_percent, locale) })}
      action={
        <Button variant="ghost" onClick={onDismiss}>
          {t('rates.tolerance.dismiss')}
        </Button>
      }
    >
      {t('rates.tolerance.text', { base, quote, previous: formatDecimal(warning.previous_mid, locale) })}
    </Alert>
  )
}

/**
 * CUR-03: the pair's rates, newest first; the kind filter, sortable
 * headers, pages, columns and export (EXP-01, LAY-04). No text search:
 * the API filters rates by pair and kind only.
 */
function RateHistory({ company, pair }) {
  const { t } = useTranslation()
  const locale = useLocale()

  const columns = [
    {
      key: 'effective_at',
      label: t('rates.columns.effective'),
      sortKey: 'effective_at',
      hideable: false,
      render: (row) => formatCompanyTime(row.effective_at, locale, company),
    },
    { key: 'kind', label: t('rates.columns.kind'), sortKey: 'kind', render: (row) => t(`rates.kinds.${row.kind}`) },
    { key: 'mid', label: t('rates.columns.mid'), sortKey: 'mid', render: (row) => <RateValue rate={row} /> },
    { key: 'buy', label: t('rates.columns.buy'), sortKey: 'buy', numeric: true, align: 'end', render: (row) => (row.buy ? formatDecimal(row.buy, locale) : '—') },
    { key: 'sell', label: t('rates.columns.sell'), sortKey: 'sell', numeric: true, align: 'end', render: (row) => (row.sell ? formatDecimal(row.sell, locale) : '—') },
    { key: 'direction', label: t('rates.columns.direction'), render: (row) => t(`rates.directions.${row.direction ?? 'direct'}`) },
    { key: 'source', label: t('rates.columns.source'), sortKey: 'source', render: (row) => sourceLabel(t, row.source) },
    {
      key: 'created_at',
      label: t('rates.columns.entered'),
      sortKey: 'created_at',
      defaultHidden: true,
      render: (row) => (row.created_at ? formatCompanyTime(row.created_at, locale, company) : ''),
    },
  ]

  const list = useServerList({
    id: 'exchange-rates',
    endpoint: `companies/${company.id}/exchange-rates`,
    queryKey: ['exchange-rates', company.id, 'history', pair],
    params: { pair },
    filters: { kind: '' },
    columns,
  })

  return (
    <section className="flex flex-col gap-3" aria-labelledby="rate-history-title">
      <h2 id="rate-history-title" className="text-h2 text-ink">
        {t('rates.history.title')}
      </h2>
      <ListView
        list={list}
        title={t('rates.history.caption', { pair })}
        searchable={false}
        filterFields={[
          {
            name: 'kind',
            label: t('rates.history.kind'),
            options: [{ value: '', label: t('rates.history.allKinds') }, ...KINDS.map((value) => ({ value, label: t(`rates.kinds.${value}`) }))],
          },
        ]}
        emptyText={list.filters.kind ? t('rates.history.emptyKind') : t('rates.history.empty')}
      />
    </section>
  )
}

/**
 * The latest reference and shop rate of a pair, and which one is in force
 * (CUR-03: shop wins). "Latest" is up to now, so a rate set to take effect
 * later today is not shown as the latest yet.
 */
function useLatestRates(company, pair) {
  const latest = (kind) => ({
    queryKey: ['exchange-rates', company.id, 'latest', pair, kind],
    queryFn: () => api.get(`companies/${company.id}/exchange-rates?${new URLSearchParams({ pair, kind, per_page: '1', to: new Date().toISOString() })}`),
  })
  const reference = useQuery(latest('reference'))
  const shop = useQuery(latest('shop'))
  const any = Boolean(reference.data?.data?.length || shop.data?.data?.length)
  const current = useQuery({
    queryKey: ['exchange-rates', company.id, 'current', pair],
    // Only asked once the pair has a rate, so an empty pair makes no failing request.
    enabled: any,
    // No rate yet answers 422 rate_unavailable: nothing is in force.
    queryFn: () => api.get(`companies/${company.id}/exchange-rates/current?${new URLSearchParams({ pair })}`).catch((error) => (error.code === 'rate_unavailable' ? { data: null } : Promise.reject(error))),
  })
  return {
    reference: reference.data?.data?.[0] ?? null,
    shop: shop.data?.data?.[0] ?? null,
    inForceId: current.data?.data?.id ?? null,
    loading: reference.isPending || shop.isPending,
    error: reference.error ?? shop.error ?? current.error,
  }
}

function PairRates({ company, pair }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const canOverride = can('core.exchange_rate.override', companyScope(company))
  const latest = useLatestRates(company, pair)
  const [dialog, setDialog] = useState(false)
  const [warning, setWarning] = useState(null)

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-h2 text-ink">{t('rates.current.title', { pair })}</h2>
        {canOverride ? (
          <Button variant="primary" icon="plus" onClick={() => setDialog(true)}>
            {t('rates.dialog.action')}
          </Button>
        ) : null}
      </div>
      {warning ? <ToleranceWarning warning={warning} pair={pair} onDismiss={() => setWarning(null)} /> : null}
      {latest.error ? <Alert tone="danger" title={errorMessage(latest.error)} /> : null}
      <div className="grid gap-5 md:grid-cols-2">
        {KINDS.map((kind) => (
          <CurrentCard
            key={kind}
            kind={kind}
            rate={latest[kind]}
            inForce={latest[kind]?.id === latest.inForceId}
            loading={latest.loading}
            company={company}
          />
        ))}
      </div>
      <RateHistory key={pair} company={company} pair={pair} />
      {dialog ? (
        <ShopRateDialog
          company={company}
          pair={pair}
          onClose={() => setDialog(false)}
          onSaved={(next) => {
            setWarning(next)
            setDialog(false)
          }}
        />
      ) : null}
    </>
  )
}

/**
 * The pairs to show: from the tenant's active currencies, or, for a user
 * who may read rates but not the currency settings (403 on
 * tenant/currencies), from the company's rates themselves.
 */
function usePairs(company) {
  const { can } = usePermissions()
  const readsCurrencies = can('core.currency.view')
  const currencies = useTenantCurrencies({ enabled: readsCurrencies })
  const forbidden = !readsCurrencies || currencies.error?.status === 403
  const rates = useQuery({
    queryKey: ['exchange-rates', company?.id, 'pairs'],
    queryFn: () => api.get(`companies/${company.id}/exchange-rates?per_page=200`),
    enabled: Boolean(company) && forbidden,
  })
  if (!company) return { pairs: [], loading: false, error: null }
  if (forbidden) {
    return { pairs: pairsFromRates(rates.data?.data ?? [], company.base_currency), loading: rates.isPending, error: rates.error }
  }
  return {
    pairs: ratePairs(currencies.active.map((currency) => currency.code), company.base_currency),
    loading: currencies.isPending,
    error: currencies.error,
  }
}

/**
 * CUR-03, CUR-07: a company's exchange rates per pair: the latest
 * reference and shop rates (the shop rate wins), entering a shop rate (a
 * warning when it moves more than the company's tolerance) and the history.
 */
export default function ExchangeRates() {
  const { t } = useTranslation()
  const { company, picker, ready } = useSettingsCompany()
  const { pairs, loading, error } = usePairs(company)
  const [chosen, setChosen] = useState('')
  const pair = pairs.includes(chosen) ? chosen : (pairs.find((value) => value.startsWith('USD/')) ?? pairs[0] ?? '')

  return (
    <>
      <PageHeader title={t('settings.exchangeRates.title')} description={t('settings.exchangeRates.description')} />
      {picker}
      {error ? <Alert tone="danger" title={errorMessage(error)} /> : null}
      {!ready || loading ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {ready && !company ? <Alert tone="info" title={t('finance.company.none')} /> : null}
      {company && !loading && !error && pairs.length === 0 ? <Alert tone="info" title={t('rates.noPairs')} /> : null}
      {company && pair ? (
        <>
          <Select
            label={t('rates.pair')}
            help={t('rates.pairHelp', { zone: company.timezone })}
            className="max-w-field"
            options={pairs.map((value) => ({ value, label: value }))}
            value={pair}
            onChange={(event) => setChosen(event.target.value)}
          />
          <PairRates key={`${company.id}:${pair}`} company={company} pair={pair} />
        </>
      ) : null}
    </>
  )
}
