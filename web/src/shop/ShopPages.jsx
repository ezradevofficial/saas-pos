import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Checkbox, Money, Select, StatusBadge, Switch, TextField } from '@/components/ds'
import { formatAmount } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useSmsAvailable } from '@/lib/useSmsAvailable'
import { cn } from '@/lib/utils'
import { useCart } from './cart'
import { getShopSession, newOrderKey, requestCode, setShopSession, shop, shopPublicApi } from './shopClient'
import { useShop } from './useShop'

const TONES = { placed: 'warning', accepted: 'info', picking: 'info', ready: 'info', out_for_delivery: 'info', delivered: 'success', cancelled: 'neutral', returned: 'danger' }
const PAYMENT_TONES = { pending: 'warning', paid: 'success', cod: 'info', cod_collected: 'success', failed: 'danger', refund_due: 'warning', refunded: 'neutral' }

/** ECOM-04: availability as a dot and a word ("In stock", "Only 3 left", "Out of stock"). */
function Availability({ product }) {
  const { t } = useTranslation()
  if (!product.orderable && product.unavailable_reason) return <StatusBadge tone="neutral">{t('shop.product.unavailable')}</StatusBadge>
  const { state, left } = product.availability
  if (state === 'out_of_stock') return <StatusBadge tone="neutral">{t('shop.product.outOfStock')}</StatusBadge>
  if (state === 'low') return <StatusBadge tone="warning">{t('shop.product.left', { count: Number(left) })}</StatusBadge>
  return <StatusBadge tone="success">{t('shop.product.inStock')}</StatusBadge>
}

function ProductCard({ product, handle }) {
  const { t } = useTranslation()
  const { add } = useCart(handle)
  return (
    <li className="flex flex-col overflow-hidden rounded-lg border border-border bg-surface-200">
      <Link to={`/shop/${handle}/products/${product.slug}`} className="block aspect-square bg-surface-300">
        {product.images[0] ? <img src={product.images[0]} alt={product.name} loading="lazy" className="size-full object-cover" /> : null}
      </Link>
      <div className="flex flex-1 flex-col gap-2 p-4">
        <Link to={`/shop/${handle}/products/${product.slug}`} className="font-medium text-ink hover:underline">{product.name}</Link>
        {product.price_minor ? <Money amount={product.price_minor} currency={product.currency} /> : null}
        <Availability product={product} />
        <Button className="mt-auto" variant="primary" disabled={!product.orderable} onClick={() => add(product.id)} aria-label={t('shop.product.addNamed', { name: product.name })}>{t('shop.product.add')}</Button>
      </div>
    </li>
  )
}

/** ECOM-05: the store's home: featured products, categories and a search. */
export function ShopHome() {
  const { t } = useTranslation()
  const { handle, store } = useShop()
  const [params, setParams] = useSearchParams()
  const category = params.get('category') ?? ''
  const search = params.get('q') ?? ''
  const [term, setTerm] = useState(search)
  const query = useQuery({
    queryKey: ['shop', handle, 'products', category, search],
    queryFn: () => shop(handle, 'GET', `products?per_page=48${category ? `&category=${category}` : ''}${search ? `&search=${encodeURIComponent(search)}` : ''}`),
  })
  const products = query.data?.data ?? []
  return (
    <>
      <header className="flex flex-col gap-2">
        <h1 className="text-h1 text-ink">{store.name}</h1>
        <form
          role="search"
          className="flex max-w-field gap-2"
          onSubmit={(event) => {
            event.preventDefault()
            setParams(term ? { ...(category ? { category } : {}), q: term } : category ? { category } : {})
          }}
        >
          <TextField className="flex-1" label={t('shop.search')} value={term} onChange={(event) => setTerm(event.target.value)} />
          <Button type="submit" className="self-end">{t('shop.searchButton')}</Button>
        </form>
      </header>
      {store.categories.length ? (
        <nav aria-label={t('shop.categories')} className="flex flex-wrap gap-2">
          {[{ id: '', name: t('shop.allProducts') }, ...store.categories].map((entry) => (
            <Button key={entry.id || 'all'} variant={category === entry.id ? 'primary' : 'secondary'} onClick={() => setParams(entry.id ? { category: entry.id } : {})}>{entry.name}</Button>
          ))}
        </nav>
      ) : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {!query.isPending && products.length === 0 ? <p className="text-ink-muted">{t('shop.noProducts')}</p> : null}
      <ul className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4" aria-label={t('shop.products')}>
        {products.map((product) => <ProductCard key={product.id} product={product} handle={handle} />)}
      </ul>
    </>
  )
}

/** ECOM-02, ECOM-23: one product, its images, description, price, availability and share links. */
export function ShopProduct() {
  const { t } = useTranslation()
  const { handle } = useShop()
  const { slug } = useParams()
  const { add } = useCart(handle)
  const [qty, setQty] = useState('1')
  const navigate = useNavigate()
  const query = useQuery({ queryKey: ['shop', handle, 'product', slug], queryFn: () => shop(handle, 'GET', `products/${slug}`) })
  const product = query.data?.data
  if (query.isError) return <Alert tone="danger" title={query.error?.status === 404 ? t('shop.product.notFound') : errorMessage(query.error)} />
  if (!product) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <div className="grid gap-6 md:grid-cols-2">
      <div className="flex flex-col gap-2">
        <div className="aspect-square overflow-hidden rounded-lg border border-border bg-surface-300">
          {product.images[0] ? <img src={product.images[0]} alt={product.name} className="size-full object-cover" /> : null}
        </div>
      </div>
      <div className="flex flex-col gap-4">
        <Link to={`/shop/${handle}`} className="text-body text-ink-muted hover:text-ink">{t('shop.backToStore')}</Link>
        <h1 className="text-h1 text-ink">{product.name}</h1>
        {product.price_minor ? <Money size="lg" amount={product.price_minor} currency={product.currency} /> : null}
        <p className="text-caption text-ink-muted">{t(product.tax_inclusive ? 'shop.product.taxIncluded' : 'shop.product.taxAdded')}</p>
        <Availability product={product} />
        {product.description ? <p className="whitespace-pre-line text-body text-ink">{product.description}</p> : null}
        <div className="flex items-end gap-2">
          <TextField label={t('shop.qty')} inputMode="numeric" value={qty} onChange={(event) => setQty(event.target.value.replace(/[^\d]/g, ''))} className="w-palette" />
          <Button variant="primary" disabled={!product.orderable || !Number(qty)} onClick={() => { add(product.id, Number(qty)); navigate(`/shop/${handle}/cart`) }}>{t('shop.product.add')}</Button>
        </div>
        <a className="text-body text-primary hover:underline" href={product.share.whatsapp} target="_blank" rel="noreferrer noopener">{t('shop.product.shareWhatsapp')}</a>
      </div>
    </div>
  )
}

/** The server's price of a cart (ECOM-09): lines, totals and problems; nothing is computed here. */
function useQuote(handle, body, enabled = true) {
  return useQuery({ queryKey: ['shop', handle, 'quote', JSON.stringify(body)], queryFn: () => shop(handle, 'POST', 'quote', { body }), enabled: enabled && body.lines.length > 0, retry: false })
}

function Totals({ quote }) {
  const { t } = useTranslation()
  const rows = [
    ['subtotal', quote.totals.subtotal_minor],
    ['discount', quote.totals.discount_minor],
    ['tax', quote.totals.tax_minor],
    ['delivery', quote.delivery_minor],
    ['loyalty', quote.loyalty_paid_minor],
  ].filter(([key, value]) => key === 'subtotal' || value !== '0')
  return (
    <dl className="flex flex-col gap-1 text-body">
      {rows.map(([key, value]) => (
        <div key={key} className="flex justify-between gap-4">
          <dt className="text-ink-muted">{t(`shop.totals.${key}`)}</dt>
          <dd><Money amount={value} currency={quote.currency} /></dd>
        </div>
      ))}
      <div className="flex justify-between gap-4 border-t border-border pt-2">
        <dt className="font-medium text-ink">{t('shop.totals.total')}</dt>
        <dd><Money size="lg" amount={quote.totals.total_minor} currency={quote.currency} /></dd>
      </div>
    </dl>
  )
}

/** ECOM-09: the cart, priced by the server. */
export function ShopCart() {
  const { t } = useTranslation()
  const { handle, store } = useShop()
  const { lines, setQty } = useCart(handle)
  const navigate = useNavigate()
  const quote = useQuote(handle, { lines, fulfilment: store.delivery_enabled ? 'delivery' : 'pickup', zone_id: store.zones[0]?.id })
  const priced = quote.data?.data
  const names = new Map((priced?.lines ?? []).map((line) => [line.product_id, line]))
  return (
    <>
      <h1 className="text-h1 text-ink">{t('shop.cart.title')}</h1>
      {lines.length === 0 ? (
        <p className="text-ink-muted">{t('shop.cart.empty')} <Link className="text-primary hover:underline" to={`/shop/${handle}`}>{t('shop.backToStore')}</Link></p>
      ) : (
        <div className="grid gap-6 md:grid-cols-3">
          <ul className="flex flex-col gap-3 md:col-span-2" aria-label={t('shop.cart.lines')}>
            {lines.map((line) => {
              const quoted = names.get(line.product_id)
              return (
                <li key={line.product_id} className="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface-200 p-4">
                  <span className="flex min-w-0 flex-col">
                    <span className="font-medium text-ink">{quoted?.name ?? t('common.loading')}</span>
                    {quoted ? <Money amount={quoted.unit_price_minor} currency={priced.currency} /> : null}
                  </span>
                  <span className="flex items-center gap-2">
                    <Button variant="ghost" icon="minus" aria-label={t('shop.cart.less')} onClick={() => setQty(line.product_id, Number(line.qty) - 1)} />
                    <span className="w-6 text-center tabular-nums">{line.qty}</span>
                    <Button variant="ghost" icon="plus" aria-label={t('shop.cart.more')} onClick={() => setQty(line.product_id, Number(line.qty) + 1)} />
                    {quoted ? <Money amount={quoted.total_minor} currency={priced.currency} /> : null}
                  </span>
                </li>
              )
            })}
          </ul>
          <Card title={t('shop.cart.summary')}>
            {quote.isError ? <Alert tone="danger" title={errorMessage(quote.error)} /> : null}
            {priced ? <Totals quote={priced} /> : null}
            {priced?.problems?.filter((problem) => problem.code !== 'zone_required').map((problem, index) => <Alert key={index} tone="warning" title={problem.message} className="mt-3" />)}
            <p className="mt-2 text-caption text-ink-muted">{t('shop.cart.deliveryNote')}</p>
            <Button className="mt-4" variant="pay" block onClick={() => navigate(`/shop/${handle}/checkout`)}>{t('shop.cart.checkout')}</Button>
          </Card>
        </div>
      )}
    </>
  )
}

/** ECOM-10 to ECOM-15: delivery or pickup, the contact, the payment; the order is placed at the total the customer saw. */
export function ShopCheckout() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { handle, store } = useShop()
  const { lines, clear } = useCart(handle)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [orderKey] = useState(newOrderKey)
  const [values, setValues] = useState({
    fulfilment: store.delivery_enabled ? 'delivery' : 'pickup',
    zone_id: store.zones[0]?.id ?? '',
    pickup_location_id: store.pickup_locations[0]?.id ?? '',
    payment: store.payment_kinds.includes('mpesa') ? 'mpesa' : (store.payment_kinds[0] ?? 'cod'),
    name: '',
    phone: '',
    email: '',
    line: '',
    area: '',
    coupon: '',
  })
  const set = (name, value) => setValues((current) => ({ ...current, [name]: value }))
  const cart = useMemo(() => ({
    lines,
    fulfilment: values.fulfilment,
    ...(values.fulfilment === 'delivery' ? { zone_id: values.zone_id || undefined } : { pickup_location_id: values.pickup_location_id || undefined }),
    ...(values.coupon ? { coupon: values.coupon } : {}),
  }), [lines, values.fulfilment, values.zone_id, values.pickup_location_id, values.coupon])
  const quote = useQuote(handle, cart)
  const priced = quote.data?.data
  const place = useMutation({
    mutationFn: () => shop(handle, 'POST', 'orders', {
      body: {
        ...cart,
        payment: values.payment,
        contact: { name: values.name, phone: values.phone, email: values.email || undefined },
        address: values.fulfilment === 'delivery' ? { line: values.line, area: values.area || undefined } : undefined,
        expected_total_minor: priced.totals.total_minor,
      },
      headers: { 'Idempotency-Key': orderKey },
    }),
    onError: (error) => {
      // ECOM-12: the server's total changed: show the new quote before the customer tries again.
      if (error.code === 'total_changed') queryClient.invalidateQueries({ queryKey: ['shop', handle, 'quote'] })
    },
    onSuccess: (response) => {
      const order = response.data
      clear()
      if (order.payment?.redirect_url) {
        window.location.assign(order.payment.redirect_url)
        return
      }
      navigate(`/shop/${handle}/orders/${encodeURIComponent(order.number)}${order.tracking_token ? `?t=${order.tracking_token}` : ''}`)
    },
  })
  const errors = formErrors(place.error, ['contact.name', 'contact.phone', 'contact.email', 'address.line', 'payment', 'zone_id'])
  const changed = place.error?.code === 'total_changed'
  if (lines.length === 0) return <p className="text-ink-muted">{t('shop.cart.empty')}</p>
  const ready = priced && priced.problems.length === 0 && values.name && values.phone && (values.fulfilment === 'pickup' || values.line)
  return (
    <>
      <h1 className="text-h1 text-ink">{t('shop.checkout.title')}</h1>
      <div className="grid gap-6 md:grid-cols-3">
        <div className="flex flex-col gap-4 md:col-span-2">
          <Card title={t('shop.checkout.how')}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Select label={t('shop.checkout.fulfilment')} options={[...(store.delivery_enabled ? ['delivery'] : []), ...(store.pickup_enabled ? ['pickup'] : [])].map((value) => ({ value, label: t(`shop.checkout.${value}`) }))} value={values.fulfilment} onChange={(event) => set('fulfilment', event.target.value)} />
              {values.fulfilment === 'delivery' ? (
                <Select
                  label={t('shop.checkout.zone')}
                  options={store.zones.map((zone) => ({ value: zone.id, label: `${zone.name} · ${zone.fee_minor === '0' ? t('shop.checkout.free') : `${store.currency} ${formatAmount(zone.fee_minor, store.currency, locale)}`}` }))}
                  value={values.zone_id}
                  error={errors.fields.zone_id}
                  onChange={(event) => set('zone_id', event.target.value)}
                />
              ) : (
                <Select label={t('shop.checkout.pickupAt')} options={store.pickup_locations.map((location) => ({ value: location.id, label: location.name }))} value={values.pickup_location_id} onChange={(event) => set('pickup_location_id', event.target.value)} />
              )}
              {values.fulfilment === 'delivery' ? (
                <>
                  <TextField label={t('shop.checkout.address')} value={values.line} required error={errors.fields['address.line']} onChange={(event) => set('line', event.target.value)} />
                  <TextField label={t('shop.checkout.area')} value={values.area} onChange={(event) => set('area', event.target.value)} />
                </>
              ) : null}
            </div>
          </Card>
          <Card title={t('shop.checkout.you')}>
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField label={t('shop.checkout.name')} value={values.name} required autoComplete="name" error={errors.fields['contact.name']} onChange={(event) => set('name', event.target.value)} />
              <TextField label={t('shop.checkout.phone')} help={t('shop.checkout.phoneHelp')} value={values.phone} required inputMode="tel" autoComplete="tel" error={errors.fields['contact.phone']} onChange={(event) => set('phone', event.target.value)} />
              <TextField label={t('shop.checkout.email')} value={values.email} type="email" autoComplete="email" error={errors.fields['contact.email']} onChange={(event) => set('email', event.target.value)} />
              <TextField label={t('shop.checkout.coupon')} value={values.coupon} onChange={(event) => set('coupon', event.target.value)} />
            </div>
          </Card>
          <Card title={t('shop.checkout.payment')}>
            <div className="flex flex-col gap-2" role="radiogroup" aria-label={t('shop.checkout.payment')}>
              {store.payment_kinds.filter((kind) => ['mpesa', 'card', 'cod'].includes(kind)).map((kind) => (
                // The label wraps its radio and visible text (translated), which the rule cannot see.
                // oxlint-disable-next-line jsx-a11y/label-has-associated-control
                <label key={kind} className={cn('flex cursor-pointer items-center gap-3 rounded-md border border-border p-3', values.payment === kind && 'border-primary')}>
                  <input type="radio" name="payment" value={kind} checked={values.payment === kind} onChange={() => set('payment', kind)} />
                  <span className="flex flex-col">
                    <span className="text-ink">{t(`shop.payments.${kind}`)}</span>
                    <span className="text-caption text-ink-muted">{t(`shop.payments.${kind}Help`)}</span>
                  </span>
                </label>
              ))}
            </div>
          </Card>
        </div>
        <Card title={t('shop.cart.summary')}>
          {quote.isError ? <Alert tone="danger" title={errorMessage(quote.error)} /> : null}
          {priced ? <Totals quote={priced} /> : null}
          {priced?.problems?.map((problem, index) => <Alert key={index} tone="warning" title={problem.message} className="mt-3" />)}
          {errors.form ? <Alert tone={changed ? 'warning' : 'danger'} title={errors.form} className="mt-3" /> : null}
          <Button className="mt-4" variant="pay" block loading={place.isPending} disabled={!ready} onClick={() => place.mutate()}>
            {priced ? t('shop.checkout.place', { amount: `${priced.currency} ${formatAmount(priced.due_minor, priced.currency, locale)}` }) : t('shop.checkout.placeLoading')}
          </Button>
        </Card>
      </div>
    </>
  )
}

/** ECOM-15, ECOM-21, ECOM-29: an order's status, its history and, while unpaid, the payment and its retry. */
export function ShopOrder() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { handle } = useShop()
  const { number } = useParams()
  const [params] = useSearchParams()
  const token = params.get('t') ?? ''
  const queryClient = useQueryClient()
  const key = ['shop', handle, 'order', number, token]
  const query = useQuery({ queryKey: key, queryFn: () => shop(handle, 'GET', `orders/${encodeURIComponent(number)}${token ? `?t=${token}` : ''}`), retry: false })
  const order = query.data?.data
  const waiting = order?.payment_status === 'pending' && ['mpesa', 'card'].includes(order?.payment_kind)
  const check = useMutation({ mutationFn: () => shop(handle, 'POST', `orders/${encodeURIComponent(number)}/check`, { body: { t: token } }), onSuccess: (response) => queryClient.setQueryData(key, response) })
  const pay = useMutation({ mutationFn: () => shop(handle, 'POST', `orders/${encodeURIComponent(number)}/pay`, { body: { t: token } }), onSuccess: (response) => queryClient.setQueryData(key, response) })
  useEffect(() => {
    if (!waiting) return undefined
    const timer = window.setInterval(() => check.mutate(), 5000)
    return () => window.clearInterval(timer)
  }, [waiting]) // eslint-disable-line react-hooks/exhaustive-deps -- polls while waiting, with the latest check
  if (query.isError) return <Alert tone="danger" title={query.error?.status === 404 ? t('shop.order.notFound') : errorMessage(query.error)} />
  if (!order) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <div className="flex flex-col gap-4">
      <header className="flex flex-col gap-2">
        <h1 className="text-h1 text-ink">{t('shop.order.title', { number: order.number })}</h1>
        <span className="flex flex-wrap gap-4">
          <StatusBadge tone={TONES[order.status] ?? 'neutral'}>{t(`shop.order.statuses.${order.status}`)}</StatusBadge>
          <StatusBadge tone={PAYMENT_TONES[order.payment_status] ?? 'neutral'}>{t(`shop.order.payments.${order.payment_status}`)}</StatusBadge>
        </span>
      </header>
      {waiting ? <Alert tone="info" title={t(order.payment_kind === 'mpesa' ? 'shop.order.waitingMpesa' : 'shop.order.waitingCard')} /> : null}
      {order.payment_status === 'failed' && order.status === 'placed' ? (
        <Alert tone="warning" title={t('shop.order.paymentFailed')} action={<Button onClick={() => pay.mutate()} loading={pay.isPending}>{t('shop.order.payAgain')}</Button>} />
      ) : null}
      {pay.isError ? <Alert tone="danger" title={errorMessage(pay.error)} /> : null}
      <div className="grid gap-4 md:grid-cols-2">
        <Card title={t('shop.order.items')}>
          <ul className="flex flex-col gap-2 text-body">
            {order.lines.map((line, index) => (
              <li key={index} className="flex justify-between gap-4">
                <span>{line.qty.replace(/\.?0+$/, '')} × {line.name}</span>
                <Money amount={line.total_minor} currency={order.currency} />
              </li>
            ))}
            {order.totals.delivery_minor !== '0' ? (
              <li className="flex justify-between gap-4 text-ink-muted"><span>{t('shop.totals.delivery')}</span><Money amount={order.totals.delivery_minor} currency={order.currency} /></li>
            ) : null}
            <li className="flex justify-between gap-4 border-t border-border pt-2 font-medium"><span>{t('shop.totals.total')}</span><Money amount={order.totals.total_minor} currency={order.currency} /></li>
          </ul>
        </Card>
        <Card title={t('shop.order.progress')}>
          <ol className="flex flex-col gap-2 text-body">
            {order.history.map((step, index) => (
              <li key={index} className="flex justify-between gap-4">
                <span>{t(`shop.order.statuses.${step.status}`)}</span>
                <span className="text-ink-muted tabular-nums">{new Date(step.at).toLocaleString(locale)}</span>
              </li>
            ))}
          </ol>
          {order.rider ? <p className="mt-3 text-body">{t('shop.order.rider', { name: order.rider.first_name })}</p> : null}
          {order.last_location_at ? <p className="text-caption text-ink-muted">{t('shop.order.lastSeen', { time: new Date(order.last_location_at).toLocaleTimeString(locale) })}</p> : null}
        </Card>
      </div>
    </div>
  )
}

/**
 * NFR-08: the marketing consent wording the storefront shows, by version. The
 * API records the version with the answer (config privacy.wording_versions);
 * a changed text gets a new version and a new key, never an edited one.
 */
export const MARKETING_WORDING = '2026_10'

function MarketingWording({ store }) {
  const { t } = useTranslation()
  return t('shop.consent.marketing_2026_10', { store: store?.name ?? '' })
}

/** ECOM-08: sign in with a code by SMS or email; the customer's orders; marketing consent (NFR-08). */
export function ShopAccount() {
  const { t } = useTranslation()
  const { handle, store } = useShop()
  const queryClient = useQueryClient()
  const [signedIn, setSignedIn] = useState(() => Boolean(getShopSession(handle)))
  const [contact, setContact] = useState('')
  // ECOM-08, AUTH-01: in email-only mode the code goes to an email address.
  const sms = useSmsAvailable(shopPublicApi)
  const [challenge, setChallenge] = useState(null)
  const [code, setCode] = useState('')
  // Unticked by default: consent is a positive act (KE DPA).
  const [marketing, setMarketing] = useState(false)
  const account = useQuery({ queryKey: ['shop', handle, 'account'], queryFn: () => shop(handle, 'GET', 'account'), enabled: signedIn, retry: false })
  const send = useMutation({ mutationFn: () => requestCode(handle, contact), onSuccess: (response) => setChallenge(response.data) })
  const verify = useMutation({
    mutationFn: () => shop(handle, 'POST', 'auth/verify', {
      body: { challenge_id: challenge.challenge_id, code, ...(marketing ? { marketing_consent: true, wording_version: MARKETING_WORDING } : {}) },
    }),
    onSuccess: (response) => {
      setShopSession(handle, response.data.token)
      setSignedIn(true)
    },
  })
  const consent = useMutation({
    mutationFn: (granted) => shop(handle, 'PUT', 'account/marketing-consent', { body: granted ? { granted, wording_version: MARKETING_WORDING } : { granted } }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['shop', handle, 'account'] }),
  })
  const signOut = useMutation({
    mutationFn: () => shop(handle, 'POST', 'auth/sign-out'),
    onSettled: () => {
      setShopSession(handle, null)
      setSignedIn(false)
      queryClient.removeQueries({ queryKey: ['shop', handle, 'account'] })
    },
  })
  // A session the server no longer knows (expired, signed out elsewhere) shows the sign-in again.
  const active = signedIn && account.error?.status !== 401
  if (active && account.data) {
    const data = account.data.data
    return (
      <div className="flex flex-col gap-4">
        <header className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-h1 text-ink">{t('shop.account.hello', { name: data.customer.name })}</h1>
            <p className="text-ink-muted">{data.customer.phone ?? data.customer.email}</p>
          </div>
          <Button variant="ghost" onClick={() => signOut.mutate()}>{t('shop.account.signOut')}</Button>
        </header>
        <Card title={t('shop.account.orders')}>
          {data.orders.length === 0 ? <p className="text-ink-muted">{t('shop.account.noOrders')}</p> : null}
          <ul className="flex flex-col gap-2">
            {data.orders.map((order) => (
              <li key={order.number} className="flex flex-wrap items-center justify-between gap-3">
                <Link className="font-medium text-primary hover:underline" to={`/shop/${handle}/orders/${encodeURIComponent(order.number)}`}>{order.number}</Link>
                <StatusBadge tone={TONES[order.status] ?? 'neutral'}>{t(`shop.order.statuses.${order.status}`)}</StatusBadge>
                <Money amount={order.total_minor} currency={order.currency} />
              </li>
            ))}
          </ul>
        </Card>
        <Card title={t('shop.account.marketing')}>
          <div className="flex flex-col gap-2">
            <Switch
              checked={data.customer.marketing_consent}
              disabled={consent.isPending}
              onChange={(next) => consent.mutate(next)}
              label={<MarketingWording store={store} />}
            />
            <p className="text-caption text-ink-muted">{data.customer.marketing_consent ? t('shop.account.marketingOn') : t('shop.account.marketingOff')}</p>
            {consent.error ? <Alert tone="danger">{errorMessage(consent.error)}</Alert> : null}
          </div>
        </Card>
      </div>
    )
  }
  return (
    <Card title={t('shop.account.signIn')} className="max-w-auth">
      {challenge ? (
        <form className="flex flex-col gap-4" onSubmit={(event) => { event.preventDefault(); verify.mutate() }}>
          <p className="text-body text-ink-muted">{t('shop.account.codeSent', { destination: challenge.destination })}</p>
          <TextField label={t('shop.account.code')} inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} error={verify.error ? errorMessage(verify.error) : undefined} />
          <Checkbox checked={marketing} onChange={(event) => setMarketing(event.target.checked)} label={<MarketingWording store={store} />} />
          <Button type="submit" variant="primary" disabled={code.length !== 6} loading={verify.isPending}>{t('shop.account.verify')}</Button>
        </form>
      ) : (
        <form className="flex flex-col gap-4" onSubmit={(event) => { event.preventDefault(); send.mutate() }}>
          <TextField
            label={sms ? t('shop.account.contact') : t('shop.account.email')}
            help={sms ? t('shop.account.contactHelp') : t('shop.account.emailHelp')}
            type={sms ? 'text' : 'email'}
            autoComplete={sms ? undefined : 'email'}
            value={contact}
            onChange={(event) => setContact(event.target.value)}
            error={send.error ? errorMessage(send.error) : undefined}
          />
          <Button type="submit" variant="primary" disabled={!contact.trim()} loading={send.isPending}>{t('shop.account.sendCode')}</Button>
        </form>
      )}
    </Card>
  )
}
