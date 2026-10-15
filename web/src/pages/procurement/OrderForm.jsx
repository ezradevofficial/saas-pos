import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { Back, DetailState, LinesEditor, TextAreaField } from './ProcurementParts'
import { blankLine, clearPrices, lineBody, lineFieldNames, lineFromRecord, lineFromRequisition, lineReady } from './procurementHelpers'
import { companyById, companyOfBranch, locationsOf, namedOptions, procurementKey, useCurrencies, usePlaces, useSourceRequisition, useSuppliers } from './procurementData'

const FIELDS = ['branch_id', 'requisition_id', 'party_id', 'location_id', 'currency', 'tax_inclusive', 'order_date', 'expected_on', 'terms', 'lines']

function Form({ order, requisition }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const suppliers = useSuppliers()
  const editing = Boolean(order)
  const [branch, setBranch] = useState(order?.branch?.id ?? requisition?.branch?.id ?? '')
  const companyId = companyOfBranch(places, branch)
  const currencies = useCurrencies(companyId)
  const [party, setParty] = useState(order?.party?.id ?? '')
  const [location, setLocation] = useState(order?.location?.id ?? requisition?.location?.id ?? '')
  const [currency, setCurrency] = useState(order?.currency ?? requisition?.currency ?? '')
  const [inclusive, setInclusive] = useState(order?.tax_inclusive ?? false)
  const [orderDate, setOrderDate] = useState(order?.order_date ?? '')
  const [expectedOn, setExpectedOn] = useState(order?.expected_on ?? requisition?.needed_by ?? '')
  const [terms, setTerms] = useState(order?.terms ?? '')
  const [lines, setLines] = useState(() => {
    if (order?.lines?.length) return order.lines.map((line) => ({ ...lineFromRecord(line, 'unit_price'), requisition_line_id: line.requisition_line_id }))
    if (requisition?.lines?.length) return requisition.lines.map(lineFromRequisition)
    return [blankLine()]
  })
  const [submitted, setSubmitted] = useState(false)
  const shownCurrency = currency || currencies.base
  const shownDate = orderDate || todayIn(companyById(places, companyId)?.timezone)

  const save = useMutation({
    mutationFn: () => {
      const body = {
        party_id: party,
        location_id: location || null,
        currency: shownCurrency,
        tax_inclusive: inclusive,
        order_date: shownDate,
        expected_on: expectedOn || null,
        terms: terms.trim() || null,
        lines: lines.map((line) => lineBody(line, { priceKey: 'unit_price_minor', tax: true, dimensions: true, requisitionLine: true })),
      }
      if (editing) return api.patch(`procurement/orders/${order.id}`, body)
      return api.post('procurement/orders', { branch_id: branch, ...(requisition ? { requisition_id: requisition.id } : {}), ...body })
    },
    onSuccess: async (response) => {
      toast.success(t(editing ? 'procurement.order.saved' : 'procurement.order.created'))
      await queryClient.invalidateQueries({ queryKey: procurementKey })
      navigate(`/procurement/orders/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, [...FIELDS, ...lineFieldNames(lines.length)])
  const ready = branch && party && lines.every((line) => lineReady(line, true))
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <form noValidate onSubmit={submit} className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      {requisition ? <Alert tone="info" title={t('procurement.fromRequisition', { number: requisition.number })} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('procurement.fields.branch')}
            placeholder={t('procurement.pickers.branch')}
            options={namedOptions(places.activeBranches)}
            value={branch}
            required
            disabled={editing || Boolean(requisition)}
            error={errors.fields.branch_id ?? (submitted && !branch ? t('procurement.validation.branch') : undefined)}
            onChange={(event) => {
              setBranch(event.target.value)
              setLocation('')
            }}
          />
          <Select
            label={t('procurement.fields.supplier')}
            placeholder={t('procurement.pickers.supplier')}
            options={namedOptions(suppliers.active)}
            value={party}
            required
            error={errors.fields.party_id ?? (submitted && !party ? t('procurement.validation.supplier') : undefined)}
            onChange={(event) => setParty(event.target.value)}
          />
          <Select
            label={t('procurement.fields.deliveryLocation')}
            options={[{ value: '', label: t('procurement.pickers.none') }, ...namedOptions(locationsOf(places, { branchId: branch }))]}
            value={location}
            disabled={!branch}
            error={errors.fields.location_id}
            onChange={(event) => setLocation(event.target.value)}
          />
          <Select label={t('procurement.fields.currency')} options={currencies.options} value={shownCurrency} error={errors.fields.currency} onChange={(event) => {
              if (event.target.value !== shownCurrency) setLines(clearPrices)
              setCurrency(event.target.value)
            }}
          />
          <TextField type="date" label={t('procurement.fields.orderDate')} value={shownDate} error={errors.fields.order_date} onChange={(event) => setOrderDate(event.target.value)} />
          <TextField type="date" label={t('procurement.fields.expectedOn')} value={expectedOn} error={errors.fields.expected_on} onChange={(event) => setExpectedOn(event.target.value)} />
          <div className="flex flex-col gap-1 sm:col-span-2">
            <Switch label={t('procurement.fields.taxInclusive')} checked={inclusive} onChange={setInclusive} />
            <p className="text-caption text-ink-muted">{t('procurement.order.taxInclusiveHelp')}</p>
          </div>
          <TextAreaField label={t('procurement.fields.terms')} value={terms} maxLength={5000} error={errors.fields.terms} onChange={(event) => setTerms(event.target.value)} className="sm:col-span-2" />
        </div>
      </Card>
      <LinesEditor lines={lines} setLines={setLines} currency={shownCurrency} price="unit" tax dimensions companyId={companyId} errors={errors} submitted={submitted} />
      <div className="flex flex-wrap gap-2">
        <Button variant="ghost" onClick={() => navigate(editing ? `/procurement/orders/${order.id}` : '/procurement/orders')}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" variant="primary" loading={save.isPending}>
          {t('procurement.order.save')}
        </Button>
      </div>
    </form>
  )
}

/**
 * PRC-13: a new purchase order (blank, or from an approved requisition)
 * or a draft's changes: branch, supplier, delivery location, currency,
 * tax inclusive or not, order and expected dates, terms, and lines with
 * an item or description, unit, quantity, unit price, tax code, cost
 * centre and project. It is submitted for approval from its page.
 */
export default function OrderForm() {
  const { t } = useTranslation()
  const { orderId } = useParams()
  const [params] = useSearchParams()
  const source = useSourceRequisition(orderId ? null : params.get('requisition'))
  const query = useQuery({ queryKey: [...procurementKey, 'order', orderId], queryFn: () => api.get(`procurement/orders/${orderId}`), enabled: Boolean(orderId) })
  const back = <Back to={orderId ? `/procurement/orders/${orderId}` : '/procurement/orders'}>{t('procurement.orders.back')}</Back>
  if (orderId && !query.data?.data) return <DetailState query={query} back={back} notFound={t('procurement.order.notFound')} />
  if (source.waiting) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <>
      {back}
      <PageHeader title={t(orderId ? 'procurement.order.editTitle' : 'procurement.order.newTitle')} description={t('procurement.order.formHelp')} />
      <Form key={orderId ?? source.requisition?.id ?? 'new'} order={query.data?.data} requisition={source.requisition} />
    </>
  )
}
