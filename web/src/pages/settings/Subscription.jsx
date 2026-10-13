import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, Dialog, ListView, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { TRIAL_KEY } from '@/pages/onboarding/useOnboarding'
import { browser } from '@/lib/browser'
import { formatCalendarDate } from '@/lib/dates'
import { downloadFile } from '@/lib/files'
import { formatMoney } from '@/lib/format'
import { useDebounced } from '@/lib/useDebounced'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import {
  defaultQuantity,
  INVOICE_TONES,
  INVOICES_KEY,
  METHODS_KEY,
  PAYMENT_FINAL,
  STATUS_KEY,
  STATUS_TONES,
  SUBSCRIPTION_KEY,
  cleanPlan,
  planOf,
  samePlan,
  unitsFor,
} from './subscription/subscriptionData'

const day = (value, locale) => (value ? formatCalendarDate(value, locale) : '')

/** Refresh everything billing shows after a change or a payment, the trial banner included (ONB-04). */
function useRefreshBilling() {
  const queryClient = useQueryClient()
  return () => Promise.all([queryClient.invalidateQueries({ queryKey: SUBSCRIPTION_KEY }), queryClient.invalidateQueries({ queryKey: TRIAL_KEY })])
}

function nameOf(catalogue, key, kind = 'module') {
  const list = kind === 'bundle' ? catalogue?.bundles : catalogue?.modules
  return list?.find((entry) => entry.key === key)?.name ?? key
}

/** The plan, its status and the next invoice (SUB-02, SUB-05). */
function Overview({ subscription, catalogue, canManage, onAction }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const refresh = useRefreshBilling()
  const subscribe = useMutation({ mutationFn: () => api.post('subscription/subscribe'), onMutate: onAction, onSuccess: refresh })
  const cancelPending = useMutation({ mutationFn: () => api.post('subscription/pending/cancel'), onMutate: onAction, onSuccess: refresh })
  const pending = subscription.pending_change
  const modules = subscription.modules.filter((key) => !(subscription.bundle && catalogue?.bundles?.find((b) => b.key === subscription.bundle)?.modules?.includes(key)))

  return (
    <Card title={t('subscription.overview.title')}>
      <div className="flex flex-col gap-4 px-5 py-4">
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-h3 text-ink">
            {[subscription.bundle ? nameOf(catalogue, subscription.bundle, 'bundle') : null, ...modules.map((key) => nameOf(catalogue, key))].filter(Boolean).join(' + ') ||
              t('subscription.overview.noModules')}
          </span>
          <StatusBadge tone={STATUS_TONES[subscription.status] ?? 'neutral'}>{t(`subscription.status.${subscription.status}`)}</StatusBadge>
        </div>
        <dl className="grid gap-4 sm:grid-cols-3">
          <div className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('subscription.overview.cycle')}</dt>
            <dd className="text-ink">{t(`subscription.cycles.${subscription.cycle}`)}</dd>
          </div>
          <div className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('subscription.overview.quantities')}</dt>
            <dd className="text-ink">
              {Object.entries(subscription.quantities ?? {})
                .map(([unit, count]) => t(`subscription.units.${unit}`, { count }))
                .join(', ') || t('subscription.overview.none')}
            </dd>
          </div>
          <div className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('subscription.overview.period')}</dt>
            <dd className="text-ink">
              {subscription.current_period_start
                ? t('subscription.overview.periodRange', { from: day(subscription.current_period_start, locale), to: day(subscription.current_period_end, locale) })
                : t('subscription.overview.trialUntil', { date: day(subscription.trial_ends_at, locale) })}
            </dd>
          </div>
          <div className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('subscription.overview.nextInvoice')}</dt>
            <dd className="flex flex-wrap items-center gap-2 text-ink">
              {subscription.next_invoice?.date ? <span>{day(subscription.next_invoice.date, locale)}</span> : null}
              {subscription.next_invoice?.amount ? <Money amount={subscription.next_invoice.amount.amount_minor} currency={subscription.next_invoice.amount.currency} /> : null}
            </dd>
          </div>
          <div className="flex flex-col gap-1">
            <dt className="text-caption text-ink-muted">{t('subscription.overview.monthly')}</dt>
            <dd className="text-ink">
              <Money amount={subscription.monthly_amount.amount_minor} currency={subscription.monthly_amount.currency} />
            </dd>
          </div>
        </dl>

        {pending ? (
          <Alert
            tone="info"
            title={t('subscription.overview.pending', { date: day(pending.effective_on, locale) })}
            action={
              canManage ? (
                <Button variant="ghost" loading={cancelPending.isPending} onClick={() => cancelPending.mutate()}>
                  {t('subscription.overview.cancelPending')}
                </Button>
              ) : null
            }
          >
            {[pending.bundle ? nameOf(catalogue, pending.bundle, 'bundle') : null, ...pending.modules.map((key) => nameOf(catalogue, key))].filter(Boolean).join(' + ')}
          </Alert>
        ) : null}

        {subscription.status === 'trial' && !subscription.current_period_start && canManage ? (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-surface-100 p-4">
            <p className="text-ink">{t('subscription.overview.subscribeText', { date: day(subscription.trial_ends_at, locale) })}</p>
            <Button variant="primary" loading={subscribe.isPending} onClick={() => subscribe.mutate()}>
              {t('subscription.overview.subscribe')}
            </Button>
          </div>
        ) : null}
        {subscribe.error ?? cancelPending.error ? <Alert tone="danger" title={errorMessage(subscribe.error ?? cancelPending.error)} /> : null}
      </div>
    </Card>
  )
}

/** Change the plan: a live quote, then a confirm with the amount shown (SUB-02). */
function ChangePlan({ subscription, catalogue, done, onDone }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const refresh = useRefreshBilling()
  const [plan, setEditedPlan] = useState(() => planOf(subscription))
  const [coupon, setEditedCoupon] = useState('')
  // "Your plan was changed." lasts until the next action.
  const setPlan = (next) => {
    if (done) onDone(null)
    setEditedPlan(next)
  }
  const setCoupon = (next) => {
    if (done) onDone(null)
    setEditedCoupon(next)
  }
  const wanted = useMemo(() => cleanPlan(plan, catalogue), [plan, catalogue])
  const body = useDebounced(JSON.stringify({ ...wanted, coupon: coupon.trim() || null }), 400)
  const dirty = !samePlan(wanted, subscription) || coupon.trim() !== ''

  const quote = useQuery({
    queryKey: [...SUBSCRIPTION_KEY, 'quote', body],
    queryFn: () => api.post('subscription/quote', JSON.parse(body)),
    enabled: dirty,
    retry: false,
  })
  const change = useMutation({
    mutationFn: () => api.post('subscription/change', { ...JSON.parse(body), expected_charge: quote.data.data.charge_now }),
    onSuccess: async (response) => {
      setEditedCoupon('')
      onDone(response.data)
      await refresh()
    },
    onError: (error) => {
      if (error?.code === 'quote_changed') quote.refetch()
    },
  })

  const sellable = (catalogue?.modules ?? []).filter((module) => module.kind !== 'extra')
  const inBundle = (key) => Boolean(plan.bundle && catalogue?.bundles?.find((bundle) => bundle.key === plan.bundle)?.modules?.includes(key))
  const toggle = (key, on) => setPlan((current) => ({ ...current, modules: on ? [...current.modules, key].sort() : current.modules.filter((k) => k !== key) }))
  const setBundle = (key) =>
    setPlan((current) => {
      const bundle = catalogue?.bundles?.find((entry) => entry.key === key)
      return { ...current, bundle: key || null, modules: bundle ? [...new Set([...current.modules, ...bundle.modules])].sort() : current.modules }
    })
  const result = quote.data?.data
  const errors = formErrors(quote.error)

  return (
    <Card title={t('subscription.change.title')} subtitle={t('subscription.change.subtitle')}>
      <div className="flex flex-col gap-5 px-5 py-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('subscription.change.bundle')}
            value={plan.bundle ?? ''}
            onChange={(event) => setBundle(event.target.value)}
            options={[
              { value: '', label: t('subscription.change.noBundle') },
              ...(catalogue?.bundles ?? []).filter((bundle) => !bundle.quoted).map((bundle) => ({ value: bundle.key, label: bundle.name })),
            ]}
          />
          <Select
            label={t('subscription.change.cycle')}
            value={plan.cycle}
            onChange={(event) => setPlan((current) => ({ ...current, cycle: event.target.value }))}
            options={['monthly', 'annual'].map((cycle) => ({ value: cycle, label: t(`subscription.cycles.${cycle}`) }))}
          />
        </div>

        <fieldset className="flex flex-col gap-2">
          <legend className="mb-2 text-label text-ink">{t('subscription.change.modules')}</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {sellable.map((module) => (
              <Checkbox
                key={module.key}
                label={module.name}
                help={inBundle(module.key) ? t('subscription.change.inBundle') : null}
                checked={plan.modules.includes(module.key)}
                disabled={inBundle(module.key)}
                onChange={(event) => toggle(module.key, event.target.checked)}
              />
            ))}
          </div>
        </fieldset>

        <div className="grid gap-4 sm:grid-cols-3">
          {unitsFor(plan, catalogue).map((unit) => (
            <TextField
              key={unit}
              type="number"
              min={0}
              inputMode="numeric"
              label={t(`subscription.unitLabels.${unit}`)}
              value={String(plan.quantities[unit] ?? defaultQuantity(unit, plan, catalogue))}
              error={errors[`quantities.${unit}`]}
              onChange={(event) => setPlan((current) => ({ ...current, quantities: { ...current.quantities, [unit]: event.target.value } }))}
            />
          ))}
          <TextField label={t('subscription.change.coupon')} value={coupon} error={errors.coupon} onChange={(event) => setCoupon(event.target.value)} autoComplete="off" />
        </div>

        {dirty && quote.error && !errors.coupon ? <Alert tone="danger" title={errorMessage(quote.error)} /> : null}
        {dirty && result?.new ? (
          <div className="flex flex-col gap-3 rounded-md border border-border bg-surface-100 p-4" aria-live="polite">
            <p className="text-ink">
              {result.kind === 'upgrade'
                ? t('subscription.change.upgrade', { days: result.remaining_days, period: result.period_days })
                : result.kind === 'trial'
                  ? t('subscription.change.trial')
                  : result.kind === 'scheduled'
                    ? t('subscription.change.scheduled', { date: day(result.effective_on, locale) })
                    : t('subscription.change.none')}
            </p>
            <dl className="grid gap-3 sm:grid-cols-2">
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('subscription.change.newTotal', { cycle: t(`subscription.cycles.${result.new.cycle}`) })}</dt>
                <dd>
                  <Money amount={result.new.total} currency={result.currency} size="lg" />
                </dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('subscription.change.chargeNow')}</dt>
                <dd>
                  <Money amount={result.charge_now} currency={result.currency} size="lg" />
                </dd>
              </div>
            </dl>
            {result.new.coupon?.applied ? <p className="text-caption text-ink-muted">{t('subscription.change.couponApplied', { code: result.new.coupon.code })}</p> : null}
            {result.kind !== 'none' ? (
              <div>
                <Button variant="pay" loading={change.isPending} disabled={quote.isFetching} onClick={() => change.mutate()}>
                  {result.charge_now > 0 ? t('subscription.change.confirmCharge', { amount: formatMoney(result.charge_now, result.currency, locale) }) : t('subscription.change.confirm')}
                </Button>
              </div>
            ) : null}
          </div>
        ) : null}
        {change.error ? <Alert tone="danger" title={errorMessage(change.error)} /> : null}
        {done && !dirty ? (
          <Alert tone="success" title={done.invoice ? t('subscription.change.doneInvoice', { number: done.invoice.number }) : t('subscription.change.done')} />
        ) : null}
      </div>
    </Card>
  )
}

/** Poll one payment until it is final; card checkouts open the provider's page. */
function PaymentProgress({ paymentId, onFinished }) {
  const { t } = useTranslation()
  const refresh = useRefreshBilling()
  const finished = useRef(false)
  const payment = useQuery({
    queryKey: [...SUBSCRIPTION_KEY, 'payment', paymentId],
    queryFn: () => api.get(`subscription/payments/${paymentId}`),
    refetchInterval: (query) => (PAYMENT_FINAL.includes(query.state.data?.data?.status) ? false : 3000),
  })
  const status = payment.data?.data?.status

  useEffect(() => {
    if (PAYMENT_FINAL.includes(status) && !finished.current) {
      finished.current = true
      refresh()
      onFinished?.(status)
    }
  }, [status, refresh, onFinished])

  if (!status) return <p className="text-ink-muted">{t('subscription.pay.starting')}</p>
  if (status === 'succeeded') return <Alert tone="success" title={t('subscription.pay.succeeded')} />
  if (PAYMENT_FINAL.includes(status)) return <Alert tone="danger" title={payment.data.data.message ?? t('subscription.pay.failed')} />

  return (
    <div className="flex flex-col gap-2" aria-live="polite">
      <StatusBadge tone="warning">{t('subscription.pay.waiting')}</StatusBadge>
      <p className="text-ink">{payment.data.data.redirect_url ? t('subscription.pay.checkout') : t('subscription.pay.approveOnPhone', { label: payment.data.data.label })}</p>
    </div>
  )
}

/** Pay an open invoice: a saved method, a wallet and phone, or a card checkout (SUB-04). */
function PayDialog({ invoice, subscription, methods, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [choice, setChoice] = useState(() => methods.find((m) => m.is_default)?.id ?? (methods[0]?.id || `wallet:${subscription.wallets[0]}`))
  const [phone, setPhone] = useState('')
  const [paymentId, setPaymentId] = useState(null)
  const pay = useMutation({
    mutationFn: () => {
      if (choice === 'card') return api.post(`subscription/invoices/${invoice.id}/pay`, { provider: 'card_aggregator' })
      if (choice.startsWith('wallet:')) return api.post(`subscription/invoices/${invoice.id}/pay`, { provider: choice.slice(7), phone })
      return api.post(`subscription/invoices/${invoice.id}/pay`, { payment_method_id: choice })
    },
    onSuccess: (response) => {
      if (response.data.redirect_url && response.data.status !== 'succeeded') browser.go(response.data.redirect_url)
      setPaymentId(response.data.id)
    },
  })
  const errors = formErrors(pay.error)
  const options = [
    ...methods.map((method) => ({ value: method.id, label: `${t(`subscription.providers.${method.provider}`)} · ${method.label}` })),
    ...subscription.wallets.map((provider) => ({ value: `wallet:${provider}`, label: t('subscription.pay.otherPhone', { provider: t(`subscription.providers.${provider}`) }) })),
    { value: 'card', label: t('subscription.pay.card') },
  ]

  return (
    <Dialog
      open
      title={t('subscription.pay.title', { number: invoice.number })}
      onClose={onClose}
      footer={
        paymentId ? (
          <Button onClick={onClose}>{t('subscription.pay.close')}</Button>
        ) : (
          <>
            <Button variant="ghost" onClick={onClose}>
              {t('subscription.pay.cancel')}
            </Button>
            <Button variant="pay" loading={pay.isPending} onClick={() => pay.mutate()}>
              {t('subscription.pay.submit', { amount: formatMoney(invoice.total.amount_minor, invoice.total.currency, locale) })}
            </Button>
          </>
        )
      }
    >
      <div className="flex flex-col gap-4">
        <Money amount={invoice.total.amount_minor} currency={invoice.total.currency} size="lg" />
        {paymentId ? (
          <PaymentProgress paymentId={paymentId} />
        ) : (
          <>
            <Select label={t('subscription.pay.method')} value={choice} onChange={(event) => setChoice(event.target.value)} options={options} />
            {choice.startsWith('wallet:') ? (
              <TextField label={t('subscription.pay.phone')} value={phone} inputMode="tel" autoComplete="tel" error={errors.phone} onChange={(event) => setPhone(event.target.value)} />
            ) : null}
            {pay.error && !errors.phone ? <Alert tone="danger" title={errorMessage(pay.error)} /> : null}
          </>
        )}
      </div>
    </Dialog>
  )
}

/** The invoices from the platform: list, PDF, pay (SUB-03). */
function Invoices({ subscription, methods, canManage, onAction }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [paying, setPaying] = useState(null)
  const columns = [
    { key: 'number', label: t('subscription.invoices.columns.number'), sortKey: 'number', hideable: false, render: (invoice) => <span className="font-medium">{invoice.number}</span> },
    { key: 'issued_at', label: t('subscription.invoices.columns.issued'), sortKey: 'issued_at', render: (invoice) => day(invoice.issued_at, locale) },
    { key: 'kind', label: t('subscription.invoices.columns.kind'), render: (invoice) => t(`subscription.invoices.kinds.${invoice.kind}`) },
    {
      key: 'period',
      label: t('subscription.invoices.columns.period'),
      render: (invoice) => t('subscription.overview.periodRange', { from: day(invoice.period_start, locale), to: day(invoice.period_end, locale) }),
    },
    { key: 'total', label: t('subscription.invoices.columns.total'), sortKey: 'total', align: 'right', render: (invoice) => <Money amount={invoice.total.amount_minor} currency={invoice.total.currency} /> },
    { key: 'due_at', label: t('subscription.invoices.columns.due'), sortKey: 'due_at', render: (invoice) => day(invoice.due_at, locale) },
    {
      key: 'status',
      label: t('subscription.invoices.columns.status'),
      sortKey: 'status',
      render: (invoice) => <StatusBadge tone={INVOICE_TONES[invoice.status]}>{t(`subscription.invoices.statuses.${invoice.status}`)}</StatusBadge>,
    },
    {
      key: 'actions',
      label: t('subscription.invoices.columns.actions'),
      inMenu: false,
      render: (invoice) => (
        <div className="flex justify-end gap-2">
          <Button variant="ghost" icon="download" onClick={() => downloadFile(`subscription/invoices/${invoice.id}/pdf`, `invoice-${invoice.number}.pdf`)} aria-label={t('subscription.invoices.pdfFor', { number: invoice.number })}>
            {t('subscription.invoices.pdf')}
          </Button>
          {invoice.status === 'open' && canManage ? (
            <Button variant="primary" onClick={() => { onAction?.(); setPaying(invoice) }} aria-label={t('subscription.invoices.payFor', { number: invoice.number })}>
              {t('subscription.invoices.pay')}
            </Button>
          ) : null}
        </div>
      ),
    },
  ]
  const list = useServerList({ id: 'subscription-invoices', endpoint: 'subscription/invoices', queryKey: INVOICES_KEY, filters: { status: '' }, defaultSort: '-issued_at', columns })

  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-h2 text-ink">{t('subscription.invoices.title')}</h2>
      <ListView
        list={list}
        title={t('subscription.invoices.title')}
        searchLabel={t('subscription.invoices.search')}
        filterFields={[
          {
            name: 'status',
            label: t('subscription.invoices.columns.status'),
            options: [{ value: '', label: t('subscription.invoices.allStatuses') }, ...['open', 'paid', 'void'].map((status) => ({ value: status, label: t(`subscription.invoices.statuses.${status}`) }))],
          },
        ]}
        emptyText={t('subscription.invoices.empty')}
      />
      {paying ? <PayDialog invoice={paying} subscription={subscription} methods={methods} onClose={() => setPaying(null)} /> : null}
    </section>
  )
}

/** Saved payment methods: wallets by phone, cards through the hosted checkout (SUB-04). */
function PaymentMethods({ subscription, methods, canManage, onAction }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [provider, setProvider] = useState(subscription.wallets[0])
  const [phone, setPhone] = useState('')
  const refresh = () => queryClient.invalidateQueries({ queryKey: SUBSCRIPTION_KEY })
  const add = useMutation({
    mutationFn: () => api.post('subscription/payment-methods', { kind: 'mobile_money', provider, phone }),
    onMutate: onAction,
    onSuccess: async () => {
      setAdding(false)
      setPhone('')
      await refresh()
    },
  })
  const addCard = useMutation({ mutationFn: () => api.post('subscription/payment-methods', { kind: 'card' }), onMutate: onAction, onSuccess: (response) => browser.go(response.data.redirect_url) })
  const makeDefault = useMutation({ mutationFn: (id) => api.post(`subscription/payment-methods/${id}/default`), onMutate: onAction, onSuccess: refresh })
  const remove = useMutation({ mutationFn: (id) => api.post(`subscription/payment-methods/${id}/archive`), onMutate: onAction, onSuccess: refresh })
  const errors = formErrors(add.error)
  const failure = addCard.error ?? makeDefault.error ?? remove.error

  return (
    <Card
      title={t('subscription.methods.title')}
      subtitle={t('subscription.methods.subtitle')}
      actions={
        canManage ? (
          <div className="flex flex-wrap gap-2">
            <Button icon="plus" onClick={() => setAdding(true)}>
              {t('subscription.methods.addWallet')}
            </Button>
            <Button icon="plus" loading={addCard.isPending} onClick={() => addCard.mutate()}>
              {t('subscription.methods.addCard')}
            </Button>
          </div>
        ) : null
      }
    >
      <div className="flex flex-col px-5 py-2">
        {methods.length === 0 ? <p className="py-3 text-ink-muted">{t('subscription.methods.empty')}</p> : null}
        <ul className="divide-y divide-border">
          {methods.map((method) => (
            <li key={method.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div className="flex min-w-0 flex-wrap items-center gap-3">
                <span className="text-ink">{t(`subscription.providers.${method.provider}`)}</span>
                <span className="font-mono text-caption text-ink-muted">{method.label}</span>
                {method.is_default ? <StatusBadge tone="info">{t('subscription.methods.default')}</StatusBadge> : null}
              </div>
              {canManage ? (
                <div className="flex gap-2">
                  {!method.is_default ? (
                    <Button variant="ghost" onClick={() => makeDefault.mutate(method.id)}>
                      {t('subscription.methods.makeDefault')}
                    </Button>
                  ) : null}
                  <Button variant="ghost" onClick={() => remove.mutate(method.id)} aria-label={t('subscription.methods.removeFor', { label: method.label })}>
                    {t('subscription.methods.remove')}
                  </Button>
                </div>
              ) : null}
            </li>
          ))}
        </ul>
        {failure ? <Alert tone="danger" title={errorMessage(failure)} /> : null}
      </div>
      <Dialog
        open={adding}
        title={t('subscription.methods.addWallet')}
        onClose={() => setAdding(false)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setAdding(false)}>
              {t('subscription.pay.cancel')}
            </Button>
            <Button variant="primary" loading={add.isPending} onClick={() => add.mutate()}>
              {t('subscription.methods.save')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          <Select label={t('subscription.pay.method')} value={provider} onChange={(event) => setProvider(event.target.value)} options={subscription.wallets.map((p) => ({ value: p, label: t(`subscription.providers.${p}`) }))} />
          <TextField label={t('subscription.pay.phone')} value={phone} inputMode="tel" error={errors.phone} onChange={(event) => setPhone(event.target.value)} />
          {add.error && !errors.phone ? <Alert tone="danger" title={errorMessage(add.error)} /> : null}
        </div>
      </Dialog>
    </Card>
  )
}

/** Back from the hosted card page: keep the card, or follow a card payment. */
function CheckoutReturn() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()
  const setup = params.get('card_setup')
  const payment = params.get('payment')
  const started = useRef(false)
  const complete = useMutation({
    mutationFn: (reference) => api.post('subscription/payment-methods/card/complete', { reference }),
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: SUBSCRIPTION_KEY })
      queryClient.invalidateQueries({ queryKey: TRIAL_KEY })
      setParams((current) => {
        const next = new URLSearchParams(current)
        next.delete('card_setup')
        return next
      })
    },
  })

  useEffect(() => {
    if (setup && !started.current) {
      started.current = true
      complete.mutate(setup)
    }
  }, [setup, complete])

  if (payment) return <PaymentProgress paymentId={payment} />
  if (complete.isSuccess) return <Alert tone="success" title={t('subscription.methods.cardSaved')} />
  if (complete.error) return <Alert tone="danger" title={errorMessage(complete.error)} />
  return null
}

/** Placeholder while the subscription or the catalogue loads: never raw module keys. */
function SubscriptionSkeleton() {
  const { t } = useTranslation()
  return (
    <Card title={t('subscription.overview.title')}>
      <div role="status" aria-busy="true" aria-label={t('subscription.loading')} className="flex flex-col gap-4 px-5 py-4">
        <div className="h-6 w-1/2 animate-pulse rounded-md bg-surface-300" />
        <div className="grid gap-4 sm:grid-cols-3">
          {[0, 1, 2].map((index) => (
            <div key={index} className="h-10 animate-pulse rounded-md bg-surface-300" />
          ))}
        </div>
      </div>
    </Card>
  )
}

/** Settings → Subscription (SUB-02..SUB-06). */
export default function Subscription() {
  const { t } = useTranslation()
  const { tenantWide } = usePermissions()
  const canManage = tenantWide('core.subscription.manage')
  const subscription = useQuery({ queryKey: SUBSCRIPTION_KEY, queryFn: () => api.get('subscription'), retry: false })
  const data = subscription.data?.data
  const catalogue = useQuery({ queryKey: ['catalogue', data?.country], queryFn: async () => (await api.get(`catalogue?country=${data.country}`)).data, enabled: Boolean(data?.country), staleTime: 300_000 })
  const methods = useQuery({ queryKey: METHODS_KEY, queryFn: () => api.get('subscription/payment-methods'), enabled: Boolean(data) })
  const queryClient = useQueryClient()
  // Kept here: the editor starts afresh from the new plan after a change.
  const [changed, setChanged] = useState(null)
  const clearChanged = () => setChanged(null)

  useEffect(() => {
    if (data) queryClient.invalidateQueries({ queryKey: STATUS_KEY })
  }, [data, queryClient])

  return (
    <>
      <PageHeader title={t('subscription.title')} description={t('subscription.description')} />
      <CheckoutReturn />
      {subscription.error ? <Alert tone={subscription.error.status === 404 ? 'info' : 'danger'} title={errorMessage(subscription.error)} /> : null}
      {subscription.isPending || (data && catalogue.isPending) ? <SubscriptionSkeleton /> : null}
      {data && !catalogue.isPending ? (
        <>
          <Overview subscription={data} catalogue={catalogue.data} canManage={canManage} onAction={clearChanged} />
          {canManage && catalogue.data ? <ChangePlan key={JSON.stringify(planOf(data))} subscription={data} catalogue={catalogue.data} done={changed} onDone={setChanged} /> : null}
          <Invoices subscription={data} methods={methods.data?.data ?? []} canManage={canManage} onAction={clearChanged} />
          <PaymentMethods subscription={data} methods={methods.data?.data ?? []} canManage={canManage} onAction={clearChanged} />
        </>
      ) : null}
    </>
  )
}
