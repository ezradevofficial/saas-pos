import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Dialog, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatAmount } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { When } from '@/pages/crm/CrmParts'
import { ecomKey, namedOptions, ORDER_CREATE, TONES, useEcomAction, WHATSAPP_REPLY } from './ecommerceData'

/**
 * ECOM-25: the WhatsApp inbox: conversations of the user's stores, a
 * thread with replies (through the provider), and a chat made into an
 * order through the same pricing as any order.
 */
export default function Inbox() {
  const { t } = useTranslation()
  const [status, setStatus] = useState('open')
  const [selected, setSelected] = useState(null)
  const list = useQuery({ queryKey: [...ecomKey, 'conversations', status], queryFn: () => api.get(`ecommerce/conversations?status=${status}&per_page=100`) })
  const conversations = list.data?.data ?? []
  const current = selected ?? conversations[0]?.id ?? null
  return (
    <>
      <PageHeader title={t('ecommerce.inbox.title')} description={t('ecommerce.inbox.description')} />
      {list.isError ? <Alert tone="danger" title={errorMessage(list.error)} /> : null}
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="flex flex-col gap-3">
          <Select label={t('ecommerce.filters.status')} options={['open', 'converted', 'closed', 'all'].map((value) => ({ value, label: t(`ecommerce.inbox.statuses.${value}`) }))} value={status} onChange={(event) => { setStatus(event.target.value); setSelected(null) }} />
          {conversations.length === 0 && !list.isPending ? <p className="text-body text-ink-muted">{t('ecommerce.inbox.empty')}</p> : null}
          <ul className="flex flex-col gap-2" aria-label={t('ecommerce.inbox.conversations')}>
            {conversations.map((conversation) => (
              <li key={conversation.id}>
                <button
                  type="button"
                  onClick={() => setSelected(conversation.id)}
                  className={cn('flex w-full flex-col gap-1 rounded-md border border-border bg-surface-200 p-3 text-left hover:bg-surface-300', current === conversation.id && 'border-primary')}
                >
                  <span className="flex items-center justify-between gap-2">
                    <span className="font-medium text-ink">{conversation.name ?? conversation.phone}</span>
                    <StatusBadge tone={TONES[conversation.status] ?? 'neutral'}>{t(`ecommerce.inbox.statuses.${conversation.status}`)}</StatusBadge>
                  </span>
                  <span className="truncate text-caption text-ink-muted">{conversation.last_message}</span>
                </button>
              </li>
            ))}
          </ul>
        </div>
        <div className="lg:col-span-2">{current ? <Thread id={current} key={current} /> : null}</div>
      </div>
    </>
  )
}

function Thread({ id }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const navigate = useNavigate()
  const query = useQuery({ queryKey: [...ecomKey, 'conversations', 'one', id], queryFn: () => api.get(`ecommerce/conversations/${id}`) })
  const [text, setText] = useState('')
  const [ordering, setOrdering] = useState(false)
  const reply = useEcomAction({ onDone: () => setText('') })
  const conversation = query.data?.data
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!conversation) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <Card
      title={conversation.name ?? conversation.phone}
      subtitle={`${conversation.phone} · ${conversation.store?.name ?? ''}`}
      actions={
        <>
          {conversation.order ? <Button variant="ghost" onClick={() => navigate(`/ecommerce/orders/${conversation.order.id}`)}>{conversation.order.number}</Button> : null}
          {can(ORDER_CREATE) && conversation.status !== 'converted' ? <Button variant="primary" onClick={() => setOrdering(true)}>{t('ecommerce.inbox.makeOrder')}</Button> : null}
          {can(WHATSAPP_REPLY) && conversation.status === 'open' ? <Button variant="ghost" onClick={() => reply.mutate({ path: `ecommerce/conversations/${id}/close`, done: 'ecommerce.inbox.closedDone' })}>{t('ecommerce.inbox.close')}</Button> : null}
        </>
      }
    >
      <ol className="flex max-h-panel flex-col gap-2 overflow-y-auto" aria-label={t('ecommerce.inbox.messages')}>
        {conversation.messages.map((message) => (
          <li key={message.id} className={cn('max-w-field rounded-md border border-border px-3 py-2 text-body', message.direction === 'out' ? 'self-end bg-primary-tint' : 'self-start bg-surface-100')}>
            <p className="whitespace-pre-wrap text-ink">{message.body}</p>
            <p className="text-caption text-ink-muted"><When value={message.at} />{message.sent_by ? ` · ${message.sent_by.name}` : ''}{message.status === 'failed' ? ` · ${t('ecommerce.inbox.failed')}` : ''}</p>
          </li>
        ))}
      </ol>
      {can(WHATSAPP_REPLY) ? (
        <div className="mt-4 flex items-end gap-2">
          <TextField className="flex-1" label={t('ecommerce.inbox.reply')} value={text} onChange={(event) => setText(event.target.value)} />
          <Button variant="primary" loading={reply.isPending} disabled={!text.trim()} onClick={() => reply.mutate({ path: `ecommerce/conversations/${id}/reply`, body: { text: text.trim() } })}>{t('ecommerce.inbox.send')}</Button>
        </div>
      ) : null}
      {ordering ? <ChatOrderDialog conversation={conversation} onClose={() => setOrdering(false)} /> : null}
    </Card>
  )
}

/** ECOM-25: the chat as an order: products, quantities, the zone or pickup, cash on delivery or an M-Pesa push. */
function ChatOrderDialog({ conversation, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const storeId = conversation.store?.id
  const products = useQuery({ queryKey: [...ecomKey, 'products', storeId], queryFn: () => api.get(`ecommerce/stores/${storeId}/products?per_page=200`), enabled: Boolean(storeId) })
  const zones = useQuery({ queryKey: [...ecomKey, 'zones', storeId], queryFn: () => api.get(`ecommerce/stores/${storeId}/zones`), enabled: Boolean(storeId) })
  const [lines, setLines] = useState([{ product_id: '', qty: '1' }])
  const [zone, setZone] = useState('')
  const [payment, setPayment] = useState('cod')
  const [name, setName] = useState(conversation.name ?? '')
  const [address, setAddress] = useState('')
  const cart = { lines: lines.filter((line) => line.product_id), fulfilment: 'delivery', zone_id: zone || undefined }
  const quote = useQuery({
    queryKey: [...ecomKey, 'quote', storeId, JSON.stringify(cart)],
    queryFn: () => api.post(`ecommerce/stores/${storeId}/quote`, cart),
    enabled: Boolean(storeId) && cart.lines.length > 0 && Boolean(zone),
    retry: false,
  })
  const place = useMutation({
    mutationFn: () => api.post(`ecommerce/conversations/${conversation.id}/order`, { ...cart, payment, contact: { name, phone: conversation.phone }, address: { line: address }, expected_total_minor: quote.data?.data?.totals?.total_minor }),
    onSuccess: (response) => navigate(`/ecommerce/orders/${response.data.id}`),
  })
  const errors = formErrors(place.error, ['lines', 'zone_id', 'payment', 'contact.name', 'address.line'])
  const productOptions = (products.data?.data ?? []).filter((product) => product.published && !product.archived_at).map((product) => ({ value: product.id, label: product.item?.name ?? product.slug }))
  const totals = quote.data?.data?.totals
  return (
    <Dialog
      open
      size="lg"
      title={t('ecommerce.inbox.makeOrder')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('ecommerce.actions.back')}</Button>
          <Button variant="pay" loading={place.isPending} disabled={!totals || !name || !address} onClick={() => place.mutate()}>{totals ? t('ecommerce.inbox.placeFor', { amount: `${quote.data.data.currency} ${formatAmount(totals.total_minor, quote.data.data.currency, locale)}` }) : t('ecommerce.inbox.place')}</Button>
        </>
      }
    >
      <div className="grid gap-4 pt-1 sm:grid-cols-2">
        {errors.form ? <Alert tone="danger" title={errors.form} className="sm:col-span-2" /> : null}
        {lines.map((line, index) => (
          <div key={index} className="contents">
            <Select label={t('ecommerce.fields.product')} placeholder={t('ecommerce.inbox.chooseProduct')} options={productOptions} value={line.product_id} onChange={(event) => setLines(lines.map((l, i) => (i === index ? { ...l, product_id: event.target.value } : l)))} />
            <TextField label={t('ecommerce.fields.qty')} inputMode="decimal" value={line.qty} onChange={(event) => setLines(lines.map((l, i) => (i === index ? { ...l, qty: event.target.value } : l)))} />
          </div>
        ))}
        <Button variant="ghost" icon="plus" className="sm:col-span-2 justify-self-start" onClick={() => setLines([...lines, { product_id: '', qty: '1' }])}>{t('ecommerce.inbox.addLine')}</Button>
        <Select label={t('ecommerce.fields.zone')} placeholder={t('ecommerce.inbox.chooseZone')} options={namedOptions((zones.data?.data ?? []).filter((z) => z.active && !z.archived_at))} value={zone} error={errors.fields.zone_id} onChange={(event) => setZone(event.target.value)} />
        <Select label={t('ecommerce.fields.payment')} options={['cod', 'mpesa'].map((value) => ({ value, label: t(`ecommerce.paymentKinds.${value}`) }))} value={payment} onChange={(event) => setPayment(event.target.value)} />
        <TextField label={t('ecommerce.fields.customerName')} value={name} required error={errors.fields['contact.name']} onChange={(event) => setName(event.target.value)} />
        <TextField label={t('ecommerce.fields.address')} value={address} required error={errors.fields['address.line']} onChange={(event) => setAddress(event.target.value)} />
        {quote.data?.data?.problems?.length ? <Alert tone="warning" title={quote.data.data.problems[0].message} className="sm:col-span-2" /> : null}
        {totals ? (
          <p className="flex items-center justify-between text-body sm:col-span-2">
            <span className="text-ink-muted">{t('ecommerce.totals.total')}</span>
            <Money amount={totals.total_minor} currency={quote.data.data.currency} />
          </p>
        ) : null}
      </div>
    </Dialog>
  )
}
