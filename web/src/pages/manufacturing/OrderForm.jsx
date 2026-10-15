import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DecimalInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { usePlaces } from '@/pages/inventory/inventoryData'
import { Back, DetailState } from './ManufacturingParts'
import { itemText, manufacturingKey, useLineUnits } from './manufacturingData'

/** "Bakery · Westlands": a location with its branch, for pickers. */
const locationLabel = (location) => (location.branch?.name ? `${location.name} · ${location.branch.name}` : location.name)

/** MFG-10: the products a company can make: items with a production BOM in force. */
function useProducts(companyId) {
  const query = useQuery({
    queryKey: [...manufacturingKey, 'boms', 'products'],
    queryFn: () => api.get('manufacturing/boms?kind=production&status=active&per_page=200&sort=item'),
    staleTime: 60_000,
  })
  return (query.data?.data ?? []).filter((bom) => bom.current_version && (!companyId || bom.company?.id === companyId))
}

/**
 * MFG-10: plan a production order (a location, a product with a
 * production BOM of the location's company, a quantity in one of its
 * units, planned dates and a note) or change a planned one (quantity,
 * unit, dates and note; its materials are exploded again).
 */
function OrderFormBody({ order }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const editing = Boolean(order)
  const [location, setLocation] = useState(order?.location?.id ?? '')
  const branchOf = (id) => places.branches.find((branch) => branch.id === id)
  const chosen = places.locations.find((entry) => entry.id === location)
  const companyId = order?.company?.id ?? branchOf(chosen?.branch_id ?? chosen?.branch?.id)?.company_id ?? null
  const products = useProducts(companyId)
  const [item, setItem] = useState(order?.item ?? null)
  const units = useLineUnits(item, order?.uom)
  const [uom, setUom] = useState(order?.uom?.id ?? '')
  const [qty, setQty] = useState(order?.qty ?? '')
  const [start, setStart] = useState(order?.planned_start ?? '')
  const [end, setEnd] = useState(order?.planned_end ?? '')
  const [note, setNote] = useState(order?.note ?? '')
  const [submitted, setSubmitted] = useState(false)

  const body = () => ({
    ...(editing ? {} : { location_id: location, item_id: item.id }),
    qty,
    ...(uom ? { uom_id: uom } : {}),
    ...(start ? { planned_start: start } : editing ? { planned_start: null } : {}),
    ...(end ? { planned_end: end } : editing ? { planned_end: null } : {}),
    ...(note.trim() ? { note: note.trim() } : editing ? { note: null } : {}),
  })
  const save = useMutation({
    mutationFn: () => (editing ? api.put(`manufacturing/orders/${order.id}`, body()) : api.post('manufacturing/orders', body())),
    onSuccess: async (response) => {
      toast.success(editing ? t('manufacturing.orders.saved') : t('manufacturing.orders.planned', { number: response?.data?.number ?? '' }))
      await queryClient.invalidateQueries({ queryKey: manufacturingKey })
      navigate(`/manufacturing/orders/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, ['location_id', 'item_id', 'qty', 'uom_id', 'planned_start', 'planned_end', 'note'])
  const ready = location && item && qty

  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }
  const backTo = editing ? `/manufacturing/orders/${order.id}` : '/manufacturing/orders'

  return (
    <>
      <Back to={backTo}>{editing ? t('manufacturing.orders.backToOrder') : t('manufacturing.orders.back')}</Back>
      <PageHeader title={editing ? t('manufacturing.orders.editTitle', { number: order.number }) : t('manufacturing.orders.newTitle')} description={t('manufacturing.orders.newHelp')} />
      <form noValidate onSubmit={submit} className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('manufacturing.fields.location')}
              placeholder={t('manufacturing.pickers.location')}
              options={(editing ? places.locations : places.activeLocations).map((entry) => ({ value: entry.id, label: locationLabel(entry) }))}
              value={location}
              required
              disabled={editing}
              error={errors.fields.location_id ?? (submitted && !location ? t('manufacturing.validation.location') : undefined)}
              onChange={(event) => {
                setLocation(event.target.value)
                setItem(null)
                setUom('')
              }}
            />
            <Select
              label={t('manufacturing.fields.product')}
              placeholder={location ? t('manufacturing.pickers.product') : t('manufacturing.pickers.locationFirst')}
              options={(editing && item ? [{ item }] : products).map((bom) => ({ value: bom.item.id, label: itemText(bom.item) }))}
              value={item?.id ?? ''}
              required
              disabled={editing || !location}
              help={!editing && location && !products.length ? t('manufacturing.orders.noProducts') : undefined}
              error={errors.fields.item_id ?? (submitted && !item ? t('manufacturing.validation.product') : undefined)}
              onChange={(event) => {
                const picked = products.find((bom) => bom.item.id === event.target.value)?.item ?? null
                setItem(picked)
                setUom('')
              }}
            />
            <DecimalInput
              label={t('manufacturing.fields.qty')}
              value={qty}
              required
              showErrors={submitted}
              error={errors.fields.qty ?? (submitted && !qty ? t('manufacturing.validation.qty') : undefined)}
              onChange={(value) => setQty(value ?? '')}
            />
            <Select label={t('manufacturing.fields.unit')} placeholder={t('manufacturing.pickers.baseUnit')} options={units} value={uom} disabled={!item} error={errors.fields.uom_id} onChange={(event) => setUom(event.target.value)} />
            <TextField type="date" label={t('manufacturing.fields.plannedStart')} value={start} error={errors.fields.planned_start} onChange={(event) => setStart(event.target.value)} />
            <TextField type="date" label={t('manufacturing.fields.plannedEnd')} value={end} error={errors.fields.planned_end} onChange={(event) => setEnd(event.target.value)} />
            <TextField label={t('manufacturing.fields.note')} value={note} maxLength={1000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} className="sm:col-span-2" />
          </div>
        </Card>
        <div className="flex flex-wrap gap-2">
          <Button variant="ghost" onClick={() => navigate(backTo)}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {editing ? t('manufacturing.orders.saveChanges') : t('manufacturing.orders.plan')}
          </Button>
        </div>
      </form>
    </>
  )
}

/** MFG-10: the plan form, new or for a planned order. */
export default function OrderForm() {
  const { t } = useTranslation()
  const { orderId } = useParams()
  const query = useQuery({ queryKey: [...manufacturingKey, 'order', orderId], queryFn: () => api.get(`manufacturing/orders/${orderId}`), enabled: Boolean(orderId) })
  if (!orderId) return <OrderFormBody />
  const order = query.data?.data
  if (!order) return <DetailState query={query} back={<Back to="/manufacturing/orders">{t('manufacturing.orders.back')}</Back>} notFound={t('manufacturing.orders.notFound')} />
  return <OrderFormBody key={order.id} order={order} />
}
