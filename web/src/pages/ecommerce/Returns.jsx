// ECOM-19: partial returns and exchanges of a delivered order, priced by the
// server (a debounced quote), and the refund or collection each one leaves.
// Nothing is computed here: the API prices, checks stock and limits (RBAC-06).
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Dialog, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { moneyText } from '@/lib/money'
import { useDebounced } from '@/lib/useDebounced'
import { useLocale } from '@/lib/useLocale'
import { When } from '@/pages/crm/CrmParts'
import { ecomKey, ORDER_MANAGE, ORDER_REFUND, qtyText, returnableLines } from './ecommerceData'

const QTY = /^\d+(\.\d{1,3})?$/
const STATUS_TONES = { due: 'warning', refunded: 'success', collected: 'success' }
const positive = (minor) => Boolean(minor) && minor !== '0' && !String(minor).startsWith('-')

/** A changed order from the API replaces the shown one; the module's lists refresh. */
function useOrderChange(order, onDone) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ path, body }) => api.post(path, body),
    onSuccess: async (response, variables) => {
      queryClient.setQueryData([...ecomKey, 'orders', order.id], response)
      toast.success(t(variables.done))
      onDone?.()
      await queryClient.invalidateQueries({ queryKey: ecomKey })
    },
  })
}

/** The quantity as typed, with a French comma read as a decimal point. */
const normalQty = (text) => String(text ?? '').trim().replace(',', '.')

/** ECOM-19: return some of a delivered order's lines, and optionally exchange them for other products. */
export function ReturnDialog({ order, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const lines = useMemo(() => returnableLines(order), [order])
  const [qty, setQty] = useState({})
  const [reason, setReason] = useState('')
  const [exchange, setExchange] = useState([])
  const products = useQuery({
    queryKey: [...ecomKey, 'products', order.store?.id],
    queryFn: () => api.get(`ecommerce/stores/${order.store.id}/products?per_page=200`),
    enabled: exchange.length > 0 && Boolean(order.store?.id),
    staleTime: 30_000,
    retry: false,
  })
  const productOptions = (products.data?.data ?? []).filter((product) => !product.archived_at).map((product) => ({ value: product.id, label: product.item?.name ?? product.name ?? product.slug }))

  const qtyError = (line) => {
    const value = normalQty(qty[line.id])
    if (value === '') return null
    if (!QTY.test(value)) return t('ecommerce.returns.qtyFormat')
    if (Number(value) > Number(line.returnable_qty)) return t('ecommerce.returns.qtyAbove', { qty: qtyText(line.returnable_qty) })
    return null
  }
  const invalid = lines.some((line) => qtyError(line)) || exchange.some((row) => row.qty !== '' && !QTY.test(normalQty(row.qty)))
  const body = useMemo(() => {
    const chosen = lines.filter((line) => Number(normalQty(qty[line.id]) || 0) > 0).map((line) => ({ line_id: line.id, qty: normalQty(qty[line.id]) }))
    const swaps = exchange.filter((row) => row.product_id && Number(normalQty(row.qty) || 0) > 0).map((row) => ({ product_id: row.product_id, qty: normalQty(row.qty) }))
    return { lines: chosen, ...(swaps.length ? { exchange: swaps } : {}) }
  }, [lines, qty, exchange])
  const current = JSON.stringify(body)
  const settled = useDebounced(current, 400)
  const quoteReady = body.lines.length > 0 && !invalid && reason.trim() !== ''
  // The quote is keyed on what changes the amounts; the reason only has to be there.
  const quote = useQuery({
    // Outside ecomKey: saving the return invalidates the module's queries, and a stale quote must not be asked again.
    queryKey: ['ecommerce-return-quote', order.id, settled],
    queryFn: () => api.post(`ecommerce/orders/${order.id}/returns/quote`, { reason: reason.trim(), ...JSON.parse(settled) }),
    enabled: quoteReady && settled === current,
    retry: false,
  })
  const priced = quote.data?.data
  const fresh = quoteReady && settled === current && Boolean(priced) && !quote.isFetching
  const create = useOrderChange(order, onClose)

  const amount = (minor) => moneyText(minor, priced?.currency ?? order.currency, locale)
  let confirm = t('ecommerce.returns.confirmReturn')
  if (priced && positive(priced.refund_minor)) confirm = t('ecommerce.returns.confirmRefund', { amount: amount(priced.refund_minor) })
  else if (priced && positive(priced.collect_minor)) confirm = t('ecommerce.returns.confirmCollect', { amount: amount(priced.collect_minor) })
  else if (body.exchange) confirm = t('ecommerce.returns.confirmExchange')

  const setRow = (index, patch) => setExchange((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  return (
    <Dialog
      open
      size="lg"
      title={t('ecommerce.returns.title', { number: order.number })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('ecommerce.actions.back')}</Button>
          <Button
            variant="pay"
            loading={create.isPending}
            disabled={!fresh}
            onClick={() => create.mutate({ path: `ecommerce/orders/${order.id}/returns`, body: { reason: reason.trim(), ...body }, done: 'ecommerce.returns.recorded' })}
          >
            {confirm}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {create.isError ? <Alert tone="danger" title={errorMessage(create.error)} /> : null}
        <fieldset className="flex flex-col gap-3">
          <legend className="pb-2 text-label text-ink">{t('ecommerce.returns.linesBack')}</legend>
          {lines.map((line) => (
            <TextField
              key={line.id}
              label={line.name}
              help={t('ecommerce.returns.upTo', { qty: qtyText(line.returnable_qty) })}
              inputMode="decimal"
              placeholder="0"
              value={qty[line.id] ?? ''}
              error={qtyError(line) ?? undefined}
              onChange={(event) => setQty((values) => ({ ...values, [line.id]: event.target.value }))}
              className="max-w-field"
            />
          ))}
        </fieldset>
        <TextField label={t('ecommerce.fields.reason')} value={reason} required maxLength={500} onChange={(event) => setReason(event.target.value)} />
        <fieldset className="flex flex-col gap-3">
          <legend className="pb-2 text-label text-ink">{t('ecommerce.returns.exchange')}</legend>
          {exchange.map((row, index) => (
            <div key={row.key} className="flex flex-wrap items-end gap-2">
              <Select
                className="min-w-48 flex-1"
                label={t('ecommerce.fields.product')}
                placeholder={t('ecommerce.returns.chooseProduct')}
                options={productOptions}
                value={row.product_id}
                onChange={(event) => setRow(index, { product_id: event.target.value })}
              />
              <TextField className="w-palette" label={t('ecommerce.fields.qty')} inputMode="decimal" value={row.qty} onChange={(event) => setRow(index, { qty: event.target.value })} />
              <Button variant="ghost" icon="x" aria-label={t('ecommerce.returns.removeExchange')} onClick={() => setExchange((rows) => rows.filter((_, i) => i !== index))} />
            </div>
          ))}
          <Button className="w-fit" icon="plus" onClick={() => setExchange((rows) => [...rows, { key: `${Date.now()}-${rows.length}`, product_id: '', qty: '1' }])}>
            {t(exchange.length ? 'ecommerce.returns.addExchangeMore' : 'ecommerce.returns.addExchange')}
          </Button>
        </fieldset>
        <section aria-label={t('ecommerce.returns.quote')} className="flex flex-col gap-2 border-t border-border pt-3 text-body">
          {quote.isError ? <Alert tone="danger" title={errorMessage(quote.error)} /> : null}
          {!quoteReady ? <p className="text-caption text-ink-muted">{t('ecommerce.returns.quoteHint')}</p> : null}
          {quoteReady && !priced && !quote.isError ? <p className="text-caption text-ink-muted">{t('ecommerce.returns.pricing')}</p> : null}
          {quoteReady && priced ? (
            <dl className="grid grid-cols-2 gap-x-6 gap-y-1">
              <dt className="text-ink-muted">{t('ecommerce.returns.returnedValue')}</dt>
              <dd className="text-right"><Money amount={priced.returned_total_minor} currency={priced.currency} /></dd>
              {body.exchange ? (
                <>
                  <dt className="text-ink-muted">{t('ecommerce.returns.exchangeValue')}</dt>
                  <dd className="text-right"><Money amount={priced.exchange_total_minor} currency={priced.currency} /></dd>
                </>
              ) : null}
              <dt className="font-medium text-ink">{t('ecommerce.returns.difference')}</dt>
              <dd className="text-right font-medium text-ink tabular-nums">
                {positive(priced.refund_minor) ? t('ecommerce.returns.refundAmount', { amount: amount(priced.refund_minor) }) : null}
                {positive(priced.collect_minor) ? t('ecommerce.returns.collectAmount', { amount: amount(priced.collect_minor) }) : null}
                {!positive(priced.refund_minor) && !positive(priced.collect_minor) ? t('ecommerce.returns.noDifference') : null}
              </dd>
            </dl>
          ) : null}
        </section>
      </div>
    </Dialog>
  )
}

/** Paying a return's refund back; a reference is asked when the money goes back by hand. */
function RefundDialog({ order, entry, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [reference, setReference] = useState('')
  const action = useOrderChange(order, onClose)
  const needsReference = action.error?.code === 'refund_reference_required' || reference !== ''
  return (
    <Dialog
      open
      title={t('ecommerce.returns.refundTitle', { number: entry.number })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('ecommerce.actions.back')}</Button>
          <Button
            variant="primary"
            loading={action.isPending}
            disabled={action.error?.code === 'refund_reference_required' && !reference.trim()}
            onClick={() => action.mutate({ path: `ecommerce/returns/${entry.id}/refund`, body: reference.trim() ? { reference: reference.trim() } : {}, done: 'ecommerce.returns.refunded' })}
          >
            {t('ecommerce.returns.confirmRefund', { amount: moneyText(entry.refund_minor, entry.currency, locale) })}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p className="text-body text-ink-muted">{t('ecommerce.returns.refundHelp')}</p>
        {action.isError ? <Alert tone={action.error?.code === 'refund_reference_required' ? 'warning' : 'danger'} title={errorMessage(action.error)} /> : null}
        {needsReference ? <TextField label={t('ecommerce.fields.reference')} value={reference} required onChange={(event) => setReference(event.target.value)} /> : null}
      </div>
    </Dialog>
  )
}

/** Taking the difference an exchange leaves, with one of the company's payment methods (MD-04). */
function CollectDialog({ order, entry, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const companyId = order.company?.id
  const [method, setMethod] = useState('')
  const [reference, setReference] = useState('')
  const methods = useQuery({ queryKey: ['ecommerce-options', 'payment-methods', companyId], queryFn: () => api.get(`companies/${companyId}/payment-methods`), enabled: Boolean(companyId), staleTime: 60_000, retry: false })
  const options = (methods.data?.data ?? []).filter((entryMethod) => entryMethod.active !== false && !entryMethod.archived_at).map((entryMethod) => ({ value: entryMethod.id, label: entryMethod.name }))
  const action = useOrderChange(order, onClose)
  return (
    <Dialog
      open
      title={t('ecommerce.returns.collectTitle', { number: entry.number })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('ecommerce.actions.back')}</Button>
          <Button
            variant="primary"
            loading={action.isPending}
            disabled={!method}
            onClick={() => action.mutate({ path: `ecommerce/returns/${entry.id}/collect`, body: { payment_method_id: method, ...(reference.trim() ? { reference: reference.trim() } : {}) }, done: 'ecommerce.returns.collected' })}
          >
            {t('ecommerce.returns.confirmCollect', { amount: moneyText(entry.collect_minor, entry.currency, locale) })}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {action.isError ? <Alert tone="danger" title={errorMessage(action.error)} /> : null}
        {methods.isError ? <Alert tone="danger" title={errorMessage(methods.error)} /> : null}
        <Select label={t('ecommerce.returns.paymentMethod')} placeholder={t('ecommerce.returns.chooseMethod')} options={options} value={method} required onChange={(event) => setMethod(event.target.value)} />
        <TextField label={t('ecommerce.returns.collectReference')} value={reference} onChange={(event) => setReference(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** A return line, back from the customer ("in") or out to them ("out"). */
function ReturnLine({ line, currency }) {
  const { t } = useTranslation()
  return (
    <li className="flex justify-between gap-4">
      <span>
        <span className="text-ink-muted">{t(`ecommerce.returns.directions.${line.direction}`)}</span> {qtyText(line.qty)} × {line.name}
      </span>
      <Money amount={line.total_minor} currency={currency} />
    </li>
  )
}

/** ECOM-19: the order's returns and exchanges, with the refund or collection each one leaves. */
export function Returns({ order }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [open, setOpen] = useState(null)
  const entries = order.returns ?? []
  if (entries.length === 0) return null
  return (
    <Card title={t('ecommerce.returns.listTitle')}>
      <ul className="flex flex-col gap-4 text-body" aria-label={t('ecommerce.returns.listTitle')}>
        {entries.map((entry) => (
          <li key={entry.id} className="flex flex-col gap-2 border-t border-border pt-4 first:border-t-0 first:pt-0">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <span className="flex flex-col">
                <span className="font-medium text-ink">{entry.number}</span>
                <span className="text-caption text-ink-muted">
                  {t(`ecommerce.returns.kinds.${entry.kind}`)} · <When value={entry.created_at} companyId={order.company?.id} />{entry.created_by ? ` · ${entry.created_by.name}` : ''}
                </span>
              </span>
              <span className="flex flex-wrap gap-3">
                {entry.refund_status !== 'none' ? <StatusBadge tone={STATUS_TONES[entry.refund_status] ?? 'neutral'}>{t(`ecommerce.returns.refundStatuses.${entry.refund_status}`)}</StatusBadge> : null}
                {entry.collect_status !== 'none' ? <StatusBadge tone={STATUS_TONES[entry.collect_status] ?? 'neutral'}>{t(`ecommerce.returns.collectStatuses.${entry.collect_status}`)}</StatusBadge> : null}
              </span>
            </div>
            {entry.reason ? <p className="text-ink">{entry.reason}</p> : null}
            <ul className="flex flex-col gap-1">
              {entry.lines.map((line) => <ReturnLine key={line.id} line={line} currency={entry.currency} />)}
            </ul>
            <dl className="grid grid-cols-2 gap-x-6 gap-y-1">
              <dt className="text-ink-muted">{t('ecommerce.returns.returnedValue')}</dt>
              <dd className="text-right"><Money amount={entry.returned_total_minor} currency={entry.currency} /></dd>
              {entry.kind === 'exchange' ? (
                <>
                  <dt className="text-ink-muted">{t('ecommerce.returns.exchangeValue')}</dt>
                  <dd className="text-right"><Money amount={entry.exchange_total_minor} currency={entry.currency} /></dd>
                </>
              ) : null}
              {positive(entry.refund_minor) ? (
                <>
                  <dt className="text-ink-muted">{t('ecommerce.returns.toRefund')}</dt>
                  <dd className="text-right"><Money amount={entry.refund_minor} currency={entry.currency} /></dd>
                </>
              ) : null}
              {positive(entry.collect_minor) ? (
                <>
                  <dt className="text-ink-muted">{t('ecommerce.returns.toCollect')}</dt>
                  <dd className="text-right"><Money amount={entry.collect_minor} currency={entry.currency} /></dd>
                </>
              ) : null}
              {entry.refund_reference ? (
                <>
                  <dt className="text-ink-muted">{t('ecommerce.fields.reference')}</dt>
                  <dd className="text-right">{entry.refund_reference}</dd>
                </>
              ) : null}
              {entry.collect_reference ? (
                <>
                  <dt className="text-ink-muted">{t('ecommerce.returns.collectReference')}</dt>
                  <dd className="text-right">{entry.collect_reference}</dd>
                </>
              ) : null}
            </dl>
            {(entry.refund_status === 'due' && can(ORDER_REFUND)) || (entry.collect_status === 'due' && can(ORDER_MANAGE)) ? (
              <div className="flex flex-wrap gap-2">
                {entry.refund_status === 'due' && can(ORDER_REFUND) ? (
                  <Button aria-label={t('ecommerce.returns.refundNamed', { number: entry.number })} onClick={() => setOpen({ type: 'refund', entry })}>{t('ecommerce.returns.refund')}</Button>
                ) : null}
                {entry.collect_status === 'due' && can(ORDER_MANAGE) ? (
                  <Button aria-label={t('ecommerce.returns.collectNamed', { number: entry.number })} onClick={() => setOpen({ type: 'collect', entry })}>{t('ecommerce.returns.collect')}</Button>
                ) : null}
              </div>
            ) : null}
          </li>
        ))}
      </ul>
      {open?.type === 'refund' ? <RefundDialog order={order} entry={open.entry} onClose={() => setOpen(null)} /> : null}
      {open?.type === 'collect' ? <CollectDialog order={order} entry={open.entry} onClose={() => setOpen(null)} /> : null}
    </Card>
  )
}
