import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DecimalInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { isPositive, subtractDecimal } from '@/pages/inventory/inventoryData'
import { parseSerials } from '@/pages/inventory/inventoryHelpers'
import { Back, DetailState, LineName, Qty } from './ProcurementParts'
import { locationsOf, namedOptions, procurementKey, usePlaces } from './procurementData'

/** What is still to receive on a PO line ("10" ordered, "4" received → "6"), or "" when nothing is. */
const remaining = (line) => {
  const left = subtractDecimal(line.qty ?? '0', line.received_qty ?? '0')
  return isPositive(left) ? left : ''
}

function Form({ order }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const [location, setLocation] = useState(order.location?.id ?? '')
  const [deliveryNote, setDeliveryNote] = useState('')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState(() =>
    Object.fromEntries((order.lines ?? []).map((line) => [line.id, { received_qty: remaining(line), rejected_qty: '', reject_reason: '', batch_number: '', expires_on: '', serials: '', weight: '' }])),
  )
  const [submitted, setSubmitted] = useState(false)
  const picked = (order.lines ?? []).filter((line) => isPositive(lines[line.id]?.received_qty))
  const set = (id, patch) => setLines((current) => ({ ...current, [id]: { ...current[id], ...patch } }))

  const save = useMutation({
    mutationFn: () =>
      api.post('procurement/receipts', {
        order_id: order.id,
        ...(location ? { location_id: location } : {}),
        ...(deliveryNote.trim() ? { delivery_note: deliveryNote.trim() } : {}),
        ...(note.trim() ? { note: note.trim() } : {}),
        lines: picked.map((line) => {
          const entry = lines[line.id]
          const serials = parseSerials(entry.serials)
          return {
            order_line_id: line.id,
            received_qty: entry.received_qty,
            ...(isPositive(entry.rejected_qty) ? { rejected_qty: entry.rejected_qty } : {}),
            ...(entry.reject_reason.trim() ? { reject_reason: entry.reject_reason.trim() } : {}),
            ...(entry.batch_number.trim() ? { batch_number: entry.batch_number.trim() } : {}),
            ...(entry.expires_on ? { expires_on: entry.expires_on } : {}),
            ...(serials.length ? { serials } : {}),
            ...(entry.weight ? { weight: entry.weight } : {}),
          }
        }),
      }),
    onSuccess: async (response) => {
      toast.success(t('procurement.receipt.created'))
      await queryClient.invalidateQueries({ queryKey: procurementKey })
      navigate(`/procurement/receipts/${response.data.id}`)
    },
  })
  const lineFields = (order.lines ?? []).flatMap((_, index) => ['order_line_id', 'received_qty', 'rejected_qty', 'reject_reason', 'batch_number', 'expires_on', 'serials', 'weight'].map((name) => `lines.${index}.${name}`))
  const errors = formErrors(save.error, ['location_id', 'delivery_note', 'note', 'lines', ...lineFields])
  // The API's line errors are numbered by the lines sent.
  const error = (lineId, name) => {
    const index = picked.findIndex((line) => line.id === lineId)
    return index === -1 ? undefined : errors.fields[`lines.${index}.${name}`]
  }
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (picked.length) save.mutate()
  }

  return (
    <form noValidate onSubmit={submit} className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('procurement.fields.receivingLocation')}
            options={namedOptions(locationsOf(places, { companyId: places.branches.find((branch) => branch.id === order.branch?.id)?.company_id ?? order.company?.id }))}
            value={location}
            help={t('procurement.receipt.locationHelp')}
            error={errors.fields.location_id}
            onChange={(event) => setLocation(event.target.value)}
          />
          <TextField label={t('procurement.fields.deliveryNote')} value={deliveryNote} maxLength={100} error={errors.fields.delivery_note} onChange={(event) => setDeliveryNote(event.target.value)} />
          <TextField label={t('procurement.fields.note')} value={note} maxLength={2000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} className="sm:col-span-2" />
        </div>
      </Card>
      <section aria-label={t('procurement.lines.title')} className="flex flex-col gap-3">
        <h2 className="text-h3 text-ink">{t('procurement.lines.title')}</h2>
        {errors.fields.lines || (submitted && !picked.length) ? <Alert tone="danger" title={errors.fields.lines ?? t('procurement.validation.receiveSomething')} /> : null}
        {(order.lines ?? []).map((line) => {
          const entry = lines[line.id]
          const name = line.item?.name ?? line.description
          return (
            <fieldset key={line.id} className="flex flex-col gap-3 rounded-md border border-border p-4">
              <legend className="px-1 text-label text-ink">{t('procurement.lines.line', { number: line.line_no })}</legend>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <LineName line={line} />
                <span className="text-caption text-ink-muted">
                  {t('procurement.receipt.orderedReceived')} <Qty value={line.qty} unit={line.uom?.code} /> · <Qty value={line.received_qty} />
                </span>
              </div>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <DecimalInput label={t('procurement.receipt.receivedOf', { name })} positive={false} value={entry.received_qty} error={error(line.id, 'received_qty')} onChange={(value) => set(line.id, { received_qty: value ?? '' })} />
                <DecimalInput label={t('procurement.receipt.rejectedOf', { name })} positive={false} value={entry.rejected_qty} error={error(line.id, 'rejected_qty')} onChange={(value) => set(line.id, { rejected_qty: value ?? '' })} />
                <TextField label={t('procurement.fields.rejectReason')} value={entry.reject_reason} maxLength={500} error={error(line.id, 'reject_reason')} onChange={(event) => set(line.id, { reject_reason: event.target.value })} className="sm:col-span-2" />
                <TextField label={t('procurement.fields.batchNumber')} value={entry.batch_number} maxLength={60} error={error(line.id, 'batch_number')} onChange={(event) => set(line.id, { batch_number: event.target.value })} />
                <TextField type="date" label={t('procurement.fields.expiresOn')} value={entry.expires_on} error={error(line.id, 'expires_on')} onChange={(event) => set(line.id, { expires_on: event.target.value })} />
                <DecimalInput label={t('procurement.fields.weight')} help={t('procurement.receipt.weightHelp')} value={entry.weight} error={error(line.id, 'weight')} onChange={(value) => set(line.id, { weight: value ?? '' })} />
                <TextField label={t('procurement.fields.serials')} help={t('procurement.receipt.serialsHelp')} value={entry.serials} error={error(line.id, 'serials')} onChange={(event) => set(line.id, { serials: event.target.value })} />
              </div>
            </fieldset>
          )
        })}
      </section>
      <div className="flex flex-wrap gap-2">
        <Button variant="ghost" onClick={() => navigate(`/procurement/orders/${order.id}`)}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" variant="primary" loading={save.isPending}>
          {t('procurement.receipt.save')}
        </Button>
      </div>
    </form>
  )
}

/**
 * PRC-14: a goods received note from an approved PO (`?order=`): the
 * receiving location (the PO's delivery location by default), and per PO
 * line the quantity received and rejected (with a reason), batch and
 * expiry, serials and weight. Saved as a draft; posted from its page.
 */
export default function ReceiptForm() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const orderId = params.get('order')
  const query = useQuery({ queryKey: [...procurementKey, 'order', orderId], queryFn: () => api.get(`procurement/orders/${orderId}`), enabled: Boolean(orderId) })
  const order = query.data?.data
  const back = <Back to={orderId ? `/procurement/orders/${orderId}` : '/procurement/receipts'}>{t(orderId ? 'procurement.orders.backToOrder' : 'procurement.receipts.back')}</Back>
  if (!orderId) {
    return (
      <>
        {back}
        <Alert tone="info" title={t('procurement.receipt.fromOrder')} />
      </>
    )
  }
  if (!order) return <DetailState query={query} back={back} notFound={t('procurement.order.notFound')} />
  return (
    <>
      {back}
      <PageHeader title={t('procurement.receipt.newTitle', { number: order.number })} description={t('procurement.receipt.formHelp', { supplier: order.party?.name ?? '' })} />
      {order.can?.receive ? <Form order={order} /> : <Alert tone="warning" title={t('procurement.receipt.notReceivable')} />}
    </>
  )
}
