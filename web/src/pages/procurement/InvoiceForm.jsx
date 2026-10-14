import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DecimalInput, MoneyInput, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { useTimeZone } from '@/lib/useTimeZone'
import { isPositive, subtractDecimal } from '@/pages/inventory/inventoryData'
import { Back, DetailState, LineName, Qty } from './ProcurementParts'
import { procurementKey, useTaxCodes } from './procurementData'

/** What was accepted and not yet invoiced on a PO line, or "". */
const toInvoice = (line) => {
  const accepted = subtractDecimal(line.received_qty ?? '0', line.rejected_qty ?? '0')
  const left = subtractDecimal(accepted, line.invoiced_qty ?? '0')
  return isPositive(left) ? left : ''
}

function Form({ order }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const zone = useTimeZone(order.company?.id)
  const taxCodes = useTaxCodes(order.company?.id)
  const [reference, setReference] = useState('')
  const [invoiceDate, setInvoiceDate] = useState(() => todayIn(zone))
  const [dueDate, setDueDate] = useState('')
  const [inclusive, setInclusive] = useState(order.tax_inclusive)
  const [lines, setLines] = useState(() =>
    Object.fromEntries((order.lines ?? []).map((line) => [line.id, { qty: toInvoice(line), unit_price_minor: line.unit_price?.amount_minor ?? '', tax_code_id: line.tax_code?.id ?? '' }])),
  )
  const [submitted, setSubmitted] = useState(false)
  const picked = (order.lines ?? []).filter((line) => isPositive(lines[line.id]?.qty))
  const set = (id, patch) => setLines((current) => ({ ...current, [id]: { ...current[id], ...patch } }))
  const taxOptions = [
    { value: '', label: t('procurement.lines.noTax') },
    ...taxCodes.active.map((code) => ({ value: code.id, label: code.rate_needed ? t('procurement.lines.rateNeededCode', { name: code.name }) : code.name })),
  ]

  const save = useMutation({
    mutationFn: () =>
      api.post('procurement/invoices', {
        order_id: order.id,
        supplier_reference: reference.trim(),
        invoice_date: invoiceDate,
        due_date: dueDate || null,
        tax_inclusive: inclusive,
        lines: picked.map((line) => {
          const entry = lines[line.id]
          return {
            order_line_id: line.id,
            qty: entry.qty,
            ...(entry.unit_price_minor !== '' && entry.unit_price_minor !== null ? { unit_price_minor: entry.unit_price_minor } : {}),
            ...(entry.tax_code_id ? { tax_code_id: entry.tax_code_id } : {}),
          }
        }),
      }),
    onSuccess: async (response) => {
      toast.success(t('procurement.invoice.created'))
      await queryClient.invalidateQueries({ queryKey: procurementKey })
      navigate(`/procurement/invoices/${response.data.id}`)
    },
  })
  const lineFields = (order.lines ?? []).flatMap((_, index) => ['order_line_id', 'qty', 'unit_price_minor', 'tax_code_id'].map((name) => `lines.${index}.${name}`))
  const errors = formErrors(save.error, ['supplier_reference', 'invoice_date', 'due_date', 'tax_inclusive', 'lines', ...lineFields])
  const error = (lineId, name) => {
    const index = picked.findIndex((line) => line.id === lineId)
    return index === -1 ? undefined : errors.fields[`lines.${index}.${name}`]
  }
  const ready = reference.trim() && invoiceDate && picked.length > 0
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <form noValidate onSubmit={submit} className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField
            label={t('procurement.fields.supplierReference')}
            help={t('procurement.invoice.referenceHelp')}
            value={reference}
            maxLength={100}
            required
            error={errors.fields.supplier_reference ?? (submitted && !reference.trim() ? t('procurement.validation.supplierReference') : undefined)}
            onChange={(event) => setReference(event.target.value)}
          />
          <TextField type="date" label={t('procurement.fields.invoiceDate')} value={invoiceDate} required error={errors.fields.invoice_date} onChange={(event) => setInvoiceDate(event.target.value)} />
          <TextField type="date" label={t('procurement.fields.dueDate')} value={dueDate} error={errors.fields.due_date} onChange={(event) => setDueDate(event.target.value)} />
          <div className="flex items-end">
            <Switch label={t('procurement.fields.taxInclusive')} checked={inclusive} onChange={setInclusive} />
          </div>
        </div>
      </Card>
      <section aria-label={t('procurement.lines.title')} className="flex flex-col gap-3">
        <h2 className="text-h3 text-ink">{t('procurement.lines.title')}</h2>
        {errors.fields.lines || (submitted && !picked.length) ? <Alert tone="danger" title={errors.fields.lines ?? t('procurement.validation.invoiceSomething')} /> : null}
        {(order.lines ?? []).map((line) => {
          const entry = lines[line.id]
          const name = line.item?.name ?? line.description
          return (
            <fieldset key={line.id} className="flex flex-col gap-3 rounded-md border border-border p-4">
              <legend className="px-1 text-label text-ink">{t('procurement.lines.line', { number: line.line_no })}</legend>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <LineName line={line} />
                <span className="text-caption text-ink-muted">
                  {t('procurement.invoice.orderedReceivedInvoiced')} <Qty value={line.qty} unit={line.uom?.code} /> · <Qty value={line.received_qty} /> · <Qty value={line.invoiced_qty} />
                </span>
              </div>
              <div className="grid gap-3 sm:grid-cols-3">
                <DecimalInput label={t('procurement.invoice.qtyOf', { name })} positive={false} value={entry.qty} error={error(line.id, 'qty')} onChange={(value) => set(line.id, { qty: value ?? '' })} />
                <MoneyInput label={t('procurement.invoice.priceOf', { name })} currency={order.currency} value={entry.unit_price_minor} error={error(line.id, 'unit_price_minor')} onChange={(value) => set(line.id, { unit_price_minor: value ?? '' })} />
                <Select label={t('procurement.fields.taxCode')} options={taxOptions} value={entry.tax_code_id} error={error(line.id, 'tax_code_id')} onChange={(event) => set(line.id, { tax_code_id: event.target.value })} />
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
          {t('procurement.invoice.save')}
        </Button>
      </div>
    </form>
  )
}

/**
 * PRC-15: a supplier invoice from a PO (`?order=`): the supplier's own
 * number, invoice and due dates, and per PO line the quantity invoiced,
 * unit price and tax code (the PO line's by default). Saving matches it
 * three ways; it is approved from its page.
 */
export default function InvoiceForm() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const orderId = params.get('order')
  const query = useQuery({ queryKey: [...procurementKey, 'order', orderId], queryFn: () => api.get(`procurement/orders/${orderId}`), enabled: Boolean(orderId) })
  const order = query.data?.data
  const back = <Back to={orderId ? `/procurement/orders/${orderId}` : '/procurement/invoices'}>{t(orderId ? 'procurement.orders.backToOrder' : 'procurement.invoices.back')}</Back>
  if (!orderId) {
    return (
      <>
        {back}
        <Alert tone="info" title={t('procurement.invoice.fromOrder')} />
      </>
    )
  }
  if (!order) return <DetailState query={query} back={back} notFound={t('procurement.order.notFound')} />
  return (
    <>
      {back}
      <PageHeader title={t('procurement.invoice.newTitle', { number: order.number })} description={t('procurement.invoice.formHelp', { supplier: order.party?.name ?? '', currency: order.currency })} />
      {order.can?.invoice ? <Form order={order} /> : <Alert tone="warning" title={t('procurement.invoice.notInvoiceable')} />}
    </>
  )
}
