import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Checkbox, Dialog, MoneyInput, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { zonedToUtc } from '@/lib/dates'
import { useTimeZone } from '@/lib/useTimeZone'
import { Amount, ConfirmDialog, FilterBar, FormFooter, FuelList, ProductSelect, StationSelect, When } from './FuelParts'
import { currencyAt, fuelKey, PRICE_MANAGE, productLabel, useProducts, useStations } from './fuelData'

const FIELDS = ['location_id', 'product_id', 'price_minor', 'effective_from', 'reference']

/** FUEL-08: a new price per litre, typed from the regulator's notice (or the tenant's own decision), in the station's currency. */
function PriceDialog({ stations, products, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const zone = useTimeZone()
  const [form, setForm] = useState({ location_id: '', product_id: '', price: '', from: '', reference: '' })
  const [shown, setShown] = useState(false)
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const currency = currencyAt(stations, form.location_id)
  const save = useMutation({
    mutationFn: () =>
      api.post('fuel/prices', {
        location_id: form.location_id,
        product_id: form.product_id,
        price_minor: form.price,
        effective_from: form.from ? zonedToUtc(form.from, zone) : null,
        reference: form.reference.trim() || null,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const close = () => {
    save.reset()
    onClose()
  }
  const ready = form.location_id && form.product_id && form.price
  return (
    <Dialog
      open
      title={t('fuel.prices.new')}
      onClose={close}
      footer={
        <FormFooter
          action={t('fuel.prices.save')}
          loading={save.isPending}
          disabled={!ready}
          onClose={close}
          onSubmit={() => {
            setShown(true)
            save.mutate()
          }}
        />
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('fuel.prices.help')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <StationSelect stations={stations} value={form.location_id} error={errors.fields.location_id} required onChange={set('location_id')} />
        <ProductSelect products={products} value={form.product_id} error={errors.fields.product_id} required onChange={set('product_id')} />
        {currency ? (
          <MoneyInput key={currency} label={t('fuel.prices.perLitre')} currency={currency} value={form.price} showErrors={shown} error={errors.fields.price_minor} required onChange={set('price')} />
        ) : (
          <TextField label={t('fuel.prices.perLitre')} help={t('fuel.prices.pickStation')} value="" disabled readOnly />
        )}
        <TextField type="datetime-local" label={t('fuel.prices.effectiveFrom')} help={t('fuel.prices.effectiveHelp')} value={form.from} error={errors.fields.effective_from} onChange={(event) => set('from')(event.target.value)} />
        <TextField label={t('fuel.prices.reference')} help={t('fuel.prices.referenceHelp')} value={form.reference} maxLength={200} error={errors.fields.reference} onChange={(event) => set('reference')(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** FUEL-08: the price per litre in force at each station and product, the history and the scheduled prices; a scheduled price can be withdrawn. */
export default function Prices() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { stations } = useStations()
  const { products } = useProducts()
  const [location, setLocation] = useState('')
  const [product, setProduct] = useState('')
  const [withdrawn, setWithdrawn] = useState(false)
  const [adding, setAdding] = useState(false)
  const [withdrawing, setWithdrawing] = useState(null)
  const manage = can(PRICE_MANAGE)
  const withdraw = useMutation({
    mutationFn: (price) => api.post(`fuel/prices/${price.id}/archive`),
    onSuccess: async () => {
      setWithdrawing(null)
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const filters = { location_id: location, product_id: product }
  const base = [
    { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' },
    { key: 'product', label: t('fuel.fields.product'), render: (row) => productLabel(row.product) },
    { key: 'price', label: t('fuel.prices.perLitre'), align: 'end', render: (row) => <Amount minor={row.price_minor} currency={row.currency} className="font-medium text-ink" /> },
    { key: 'from', label: t('fuel.prices.effectiveFrom'), render: (row) => <When value={row.effective_from} /> },
    { key: 'reference', label: t('fuel.prices.reference'), wrap: true, render: (row) => row.reference ?? '' },
    { key: 'by', label: t('fuel.prices.enteredBy'), render: (row) => row.entered_by?.name ?? '' },
  ]
  const history = [
    ...base,
    {
      key: 'state',
      label: t('fuel.columns.status'),
      render: (row) => {
        if (row.archived_at) return <StatusBadge tone="neutral">{t('fuel.prices.states.withdrawn')}</StatusBadge>
        return new Date(row.effective_from).getTime() > Date.now() ? <StatusBadge tone="info">{t('fuel.prices.states.scheduled')}</StatusBadge> : <StatusBadge tone="neutral">{t('fuel.prices.states.started')}</StatusBadge>
      },
    },
    ...(manage
      ? [
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              !row.archived_at && new Date(row.effective_from).getTime() > Date.now() ? (
                <Button variant="ghost" aria-label={t('fuel.prices.withdrawLabel', { product: productLabel(row.product), station: row.location?.name })} onClick={() => setWithdrawing(row)}>
                  {t('fuel.prices.withdraw')}
                </Button>
              ) : null,
          },
        ]
      : []),
  ]
  return (
    <>
      <PageHeader
        title={t('fuel.prices.title')}
        description={t('fuel.prices.description')}
        actions={
          manage ? (
            <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>
              {t('fuel.prices.new')}
            </Button>
          ) : null
        }
      />
      <Alert tone="info" title={t('fuel.prices.regulated')} />
      <FilterBar>
        <StationSelect all stations={stations} value={location} onChange={setLocation} className="w-56" />
        <ProductSelect all products={products} value={product} onChange={setProduct} className="w-56" />
      </FilterBar>
      <section className="flex flex-col gap-3">
        <h2 className="text-h3 text-ink">{t('fuel.prices.current')}</h2>
        <FuelList queryKey="prices-current" endpoint="fuel/prices" params={{ ...filters, current: 1 }} columns={base} caption={t('fuel.prices.current')} emptyText={t('fuel.prices.emptyCurrent')} />
      </section>
      <section className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-h3 text-ink">{t('fuel.prices.history')}</h2>
          <Checkbox label={t('fuel.prices.showWithdrawn')} checked={withdrawn} onChange={(event) => setWithdrawn(event.target.checked)} />
        </div>
        <FuelList queryKey="prices-history" endpoint="fuel/prices" params={{ ...filters, archived: withdrawn ? 1 : '' }} columns={history} caption={t('fuel.prices.history')} emptyText={t('fuel.prices.emptyHistory')} />
      </section>
      {adding ? <PriceDialog stations={stations} products={products} onClose={() => setAdding(false)} /> : null}
      <ConfirmDialog
        open={Boolean(withdrawing)}
        title={t('fuel.prices.withdrawTitle', { product: productLabel(withdrawing?.product), station: withdrawing?.location?.name })}
        text={t('fuel.prices.withdrawHelp')}
        action={t('fuel.prices.withdraw')}
        variant="danger"
        loading={withdraw.isPending}
        error={withdraw.error ? errorMessage(withdraw.error) : null}
        onClose={() => {
          withdraw.reset()
          setWithdrawing(null)
        }}
        onConfirm={() => withdraw.mutate(withdrawing)}
      />
    </>
  )
}
