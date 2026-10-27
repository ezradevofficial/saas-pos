import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, MoneyInput, Select, Switch, TextField } from '@/components/ds'
import { FOLIO_SETTLE_OPEN, hotelKey } from './hotelData'
import { Amount, ItemPicker, PaymentMethodSelect } from './HotelParts'

function useRefresh(onClose) {
  const queryClient = useQueryClient()
  return async (message) => {
    if (message) toast.success(message)
    await queryClient.invalidateQueries({ queryKey: hotelKey })
    onClose()
  }
}

function Footer({ onClose, action, loading, disabled, onConfirm }) {
  const { t } = useTranslation()
  return (
    <>
      <Button variant="ghost" onClick={onClose}>
        {t('common.cancel')}
      </Button>
      <Button variant="primary" loading={loading} disabled={disabled} onClick={onConfirm}>
        {action}
      </Button>
    </>
  )
}

/** HOT-14, HOT-24: an M-Pesa push waits here until the provider answers; "Check now" asks again. */
function PendingPayment({ folioId, payment, onSettled, onClose }) {
  const { t } = useTranslation()
  const [current, setCurrent] = useState(payment)
  const check = useMutation({
    mutationFn: () => api.post(`hotel/folios/${folioId}/payments/${payment.id}/check`),
    onSuccess: (response) => {
      setCurrent(response.data)
      if (response.data.status === 'settled') onSettled()
    },
  })
  const { mutate } = check
  useEffect(() => {
    if (current.status !== 'pending') return undefined
    const timer = setInterval(() => mutate(), 4000)
    return () => clearInterval(timer)
  }, [current.status, mutate])
  return (
    <Dialog open title={t('hotel.payment.pendingTitle')} onClose={onClose} footer={current.status === 'pending' ? <Button variant="secondary" loading={check.isPending} onClick={() => check.mutate()}>{t('hotel.payment.checkNow')}</Button> : <Button variant="primary" onClick={onClose}>{t('hotel.close')}</Button>}>
      <div className="flex flex-col gap-3">
        {current.status === 'pending' ? <Alert tone="info" title={t('hotel.payment.waiting')}>{t('hotel.payment.waitingHelp')}</Alert> : null}
        {current.status === 'failed' ? <Alert tone="danger" title={t('hotel.payment.failed')}>{current.failure ?? t('hotel.payment.failedHelp')}</Alert> : null}
        {check.error ? <Alert tone="danger" title={errorMessage(check.error)} /> : null}
        <p className="text-body text-ink">
          <Amount minor={current.amount_minor} currency={current.currency} />
        </p>
      </div>
    </Dialog>
  )
}

/**
 * HOT-14, HOT-24: take a payment or a deposit by any company method. A mobile
 * money method with a provider can send an M-Pesa push (the folio waits until
 * the provider confirms) or take a typed receipt.
 */
export function PaymentDialog({ folio, stay, kind = 'payment', onClose }) {
  const { t } = useTranslation()
  const refresh = useRefresh(onClose)
  const [method, setMethod] = useState(null)
  const [amount, setAmount] = useState('')
  const [reference, setReference] = useState('')
  const [mode, setMode] = useState('direct')
  const [phone, setPhone] = useState('')
  const [pending, setPending] = useState(null)
  const settings = useQuery({ queryKey: [...hotelKey, 'settings', folio.company_id], queryFn: () => api.get(`companies/${folio.company_id}/hotel-settings`), enabled: kind === 'deposit' && Boolean(folio.company_id), retry: false })
  const percent = settings.data?.data?.deposit_percent
  const suggested = kind === 'deposit' && stay && percent ? ((BigInt(stay.total_minor) * BigInt(percent)) / 100n).toString() : null
  const push = method?.type === 'mobile_money' && Boolean(method.provider)
  const stk = push && mode === 'stk'
  const save = useMutation({
    mutationFn: () => api.post(`hotel/folios/${folio.id}/payments`, { payment_method_id: method.id, amount_minor: amount, kind, ...(stk ? { mode: 'stk', phone: phone.trim() } : {}), ...(reference.trim() ? { reference: reference.trim() } : {}) }),
    onSuccess: async (response) => {
      if (response.data.status === 'pending') {
        setPending(response.data)
        return
      }
      await refresh(kind === 'deposit' ? t('hotel.payment.depositTaken') : t('hotel.payment.taken'))
    },
  })
  const errors = formErrors(save.error, ['payment_method_id', 'amount_minor', 'phone', 'reference'])
  if (pending) {
    return (
      <PendingPayment
        folioId={folio.id}
        payment={pending}
        onClose={() => refresh()}
        onSettled={() => refresh(t('hotel.payment.taken'))}
      />
    )
  }
  return (
    <Dialog open title={kind === 'deposit' ? t('hotel.payment.depositTitle') : t('hotel.payment.title')} onClose={onClose} footer={<Footer onClose={onClose} action={stk ? t('hotel.payment.sendPush') : kind === 'deposit' ? t('hotel.payment.takeDeposit') : t('hotel.payment.take')} loading={save.isPending} disabled={!method || !amount || (stk && !phone.trim())} onConfirm={() => save.mutate()} />}>
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <PaymentMethodSelect companyId={folio.company_id} currency={folio.currency} value={method} error={errors.fields.payment_method_id} onChange={setMethod} />
        <MoneyInput label={t('hotel.fields.amount')} currency={folio.currency} value={amount} error={errors.fields.amount_minor} onChange={(next) => setAmount(next ?? '')} />
        {suggested ? (
          <Button variant="ghost" className="w-fit" onClick={() => setAmount(suggested)}>
            {t('hotel.payment.useSuggested', { percent })}
          </Button>
        ) : null}
        {push ? <Select label={t('hotel.payment.mode')} options={[{ value: 'direct', label: t('hotel.payment.typed') }, { value: 'stk', label: t('hotel.payment.push') }]} value={mode} onChange={(event) => setMode(event.target.value)} /> : null}
        {stk ? <TextField label={t('hotel.fields.phone')} help={t('hotel.payment.phoneHelp')} inputMode="tel" value={phone} maxLength={30} required error={errors.fields.phone} onChange={(event) => setPhone(event.target.value)} /> : <TextField label={t('hotel.fields.reference')} help={t('hotel.payment.referenceHelp')} value={reference} maxLength={120} error={errors.fields.reference} onChange={(event) => setReference(event.target.value)} />}
      </div>
    </Dialog>
  )
}

/** HOT-21: post an extra from a core item, or a fee without one. */
export function ChargeDialog({ folio, onClose }) {
  const { t } = useTranslation()
  const refresh = useRefresh(onClose)
  const [mode, setMode] = useState('item')
  const [item, setItem] = useState(null)
  const [qty, setQty] = useState('1')
  const [price, setPrice] = useState('')
  const [amount, setAmount] = useState('')
  const [description, setDescription] = useState('')
  const [reason, setReason] = useState('')
  const fee = mode === 'fee'
  const save = useMutation({
    mutationFn: () =>
      api.post(
        `hotel/folios/${folio.id}/charges`,
        fee
          ? { amount_minor: amount, description: description.trim(), ...(reason.trim() ? { reason: reason.trim() } : {}) }
          : { item_id: item.id, qty, unit_price_minor: price, ...(description.trim() ? { description: description.trim() } : {}), ...(reason.trim() ? { reason: reason.trim() } : {}) },
      ),
    onSuccess: () => refresh(t('hotel.charge.posted')),
  })
  const errors = formErrors(save.error, ['item_id', 'qty', 'unit_price_minor', 'amount_minor', 'description', 'reason'])
  const ready = fee ? amount && description.trim() : item && price && /^\d+(\.\d+)?$/.test(qty) && Number(qty) > 0
  return (
    <Dialog open title={t('hotel.charge.title')} onClose={onClose} footer={<Footer onClose={onClose} action={t('hotel.charge.post')} loading={save.isPending} disabled={!ready} onConfirm={() => save.mutate()} />}>
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select label={t('hotel.charge.kind')} options={[{ value: 'item', label: t('hotel.charge.item') }, { value: 'fee', label: t('hotel.charge.fee') }]} value={mode} onChange={(event) => setMode(event.target.value)} />
        {fee ? (
          <>
            <TextField label={t('hotel.charge.description')} value={description} maxLength={240} required error={errors.fields.description} onChange={(event) => setDescription(event.target.value)} />
            <MoneyInput label={t('hotel.fields.amount')} currency={folio.currency} value={amount} error={errors.fields.amount_minor} onChange={(next) => setAmount(next ?? '')} />
            <p className="text-caption text-ink-muted">{t('hotel.charge.feeUntaxed')}</p>
          </>
        ) : (
          <>
            <ItemPicker label={t('hotel.charge.itemLabel')} value={item} required error={errors.fields.item_id} onChange={setItem} />
            <div className="grid gap-3 sm:grid-cols-2">
              <TextField label={t('hotel.charge.qty')} inputMode="decimal" value={qty} error={errors.fields.qty} onChange={(event) => setQty(event.target.value)} />
              <MoneyInput label={t('hotel.charge.unitPrice')} currency={folio.currency} value={price} error={errors.fields.unit_price_minor} onChange={(next) => setPrice(next ?? '')} />
            </div>
            <TextField label={t('hotel.charge.descriptionOptional')} value={description} maxLength={240} error={errors.fields.description} onChange={(event) => setDescription(event.target.value)} />
          </>
        )}
        <TextField label={t('hotel.charge.reason')} help={t('hotel.charge.reasonHelp')} value={reason} maxLength={500} error={errors.fields.reason} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** HOT-24: give money back, never more than the guest has paid in. */
export function RefundDialog({ folio, onClose }) {
  const { t } = useTranslation()
  const refresh = useRefresh(onClose)
  const [method, setMethod] = useState(null)
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [reference, setReference] = useState('')
  const save = useMutation({
    mutationFn: () => api.post(`hotel/folios/${folio.id}/refunds`, { payment_method_id: method.id, amount_minor: amount, reason: reason.trim(), ...(reference.trim() ? { reference: reference.trim() } : {}) }),
    onSuccess: () => refresh(t('hotel.refund.done')),
  })
  const errors = formErrors(save.error, ['payment_method_id', 'amount_minor', 'reason', 'reference'])
  return (
    <Dialog open title={t('hotel.refund.title')} onClose={onClose} footer={<Footer onClose={onClose} action={t('hotel.refund.action')} loading={save.isPending} disabled={!method || !amount || !reason.trim()} onConfirm={() => save.mutate()} />}>
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <PaymentMethodSelect companyId={folio.company_id} currency={folio.currency} value={method} error={errors.fields.payment_method_id} onChange={setMethod} />
        <MoneyInput label={t('hotel.fields.amount')} currency={folio.currency} value={amount} error={errors.fields.amount_minor} onChange={(next) => setAmount(next ?? '')} />
        <TextField label={t('hotel.refund.reason')} value={reason} maxLength={500} required error={errors.fields.reason} onChange={(event) => setReason(event.target.value)} />
        <TextField label={t('hotel.fields.reference')} value={reference} maxLength={120} error={errors.fields.reference} onChange={(event) => setReference(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** HOT-25: move the chosen charges to another open folio of the property; both sides are audited. */
export function TransferDialog({ folio, lineIds, onClose }) {
  const { t } = useTranslation()
  const refresh = useRefresh(onClose)
  const [toId, setToId] = useState('')
  const [reason, setReason] = useState('')
  const folios = useQuery({ queryKey: [...hotelKey, 'folio-targets', folio.branch_id], queryFn: () => api.get(`hotel/folios?branch=${folio.branch_id}&status=open&per_page=100`) })
  const targets = (folios.data?.data ?? []).filter((entry) => entry.id !== folio.id && entry.currency === folio.currency)
  const save = useMutation({
    mutationFn: () => api.post(`hotel/folios/${folio.id}/transfers`, { to_folio_id: toId, line_ids: lineIds, reason: reason.trim() }),
    onSuccess: () => refresh(t('hotel.transfer.done')),
  })
  const errors = formErrors(save.error, ['to_folio_id', 'line_ids', 'reason'])
  const label = (entry) => [entry.number, entry.room_number ? t('hotel.reservation.roomNumber', { number: entry.room_number }) : null, entry.guest?.name].filter(Boolean).join(' · ')
  return (
    <Dialog open title={t('hotel.transfer.title', { count: lineIds.length })} onClose={onClose} footer={<Footer onClose={onClose} action={t('hotel.transfer.action')} loading={save.isPending} disabled={!toId || !reason.trim()} onConfirm={() => save.mutate()} />}>
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select label={t('hotel.transfer.to')} placeholder={t('hotel.pickers.folio')} options={targets.map((entry) => ({ value: entry.id, label: label(entry) }))} value={toId} required error={errors.fields.to_folio_id} onChange={(event) => setToId(event.target.value)} />
        <TextField label={t('hotel.transfer.reason')} value={reason} maxLength={500} required error={errors.fields.reason} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** HOT-19, HOT-27: settle the folio into its invoice; a balance can stay on account only with `hotel.folio.settle_open`. */
export function SettleDialog({ folio, onClose }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const refresh = useRefresh(onClose)
  const [leave, setLeave] = useState(false)
  const owed = BigInt(folio.balance_minor) !== 0n
  const save = useMutation({ mutationFn: () => api.post(`hotel/folios/${folio.id}/settle`, leave ? { leave_balance: true } : {}), onSuccess: () => refresh(t('hotel.settle.done')) })
  const errors = formErrors(save.error, ['leave_balance'])
  return (
    <Dialog open title={t('hotel.settle.title')} onClose={onClose} footer={<Footer onClose={onClose} action={t('hotel.settle.action')} loading={save.isPending} disabled={owed && !leave} onConfirm={() => save.mutate()} />}>
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <p className="text-body text-ink-muted">{t('hotel.settle.help')}</p>
        {owed ? (
          <>
            <p className="text-body text-ink">
              {t('hotel.folio.balance')}: <Amount minor={folio.balance_minor} currency={folio.currency} tone="danger" />
            </p>
            {can(FOLIO_SETTLE_OPEN) ? <Switch label={t('hotel.settle.leave')} checked={leave} onChange={setLeave} /> : <p className="text-caption text-ink-muted">{t('hotel.frontDesk.balanceBlocks')}</p>}
          </>
        ) : null}
      </div>
    </Dialog>
  )
}
