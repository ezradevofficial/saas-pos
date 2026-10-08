import { useMutation, useQuery } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, DecimalInput, Dialog, MoneyInput, Select, TextField } from '@/components/ds'
import { formatMoney } from '@/lib/format'
import { compareDecimal, decimalsOf } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useTenantCurrencies } from '@/pages/settings/finance/useSettingsCompany'
import { useItemUnitOptions } from './catalogueData'

const FIELDS = ['item_id', 'uom_id', 'amount_minor', 'effective_from', 'min_quantity']

/** Items the user may price in this list (shared or the list's company's: `?company=`), searched on the server. */
function useItemSearch(search, companyId, enabled) {
  const query = useQuery({
    queryKey: ['items', 'price-picker', companyId, search],
    queryFn: () => api.get(`items?per_page=20&company=${companyId}${search ? `&search=${encodeURIComponent(search)}` : ''}`),
    enabled,
  })
  return { ...query, rows: query.data?.data ?? [] }
}

/**
 * The price in force for one unit of the item in this list (quantity 1),
 * read from the item's detail (its `prices`); null when unknown.
 */
function useUnitPrice(itemId, priceListId, uomId, enabled) {
  const query = useQuery({ queryKey: ['items', 'detail', itemId], queryFn: () => api.get(`items/${itemId}`), enabled: enabled && Boolean(itemId) })
  const list = (query.data?.data?.prices ?? []).find((entry) => entry.price_list_id === priceListId)
  return list?.prices.find((entry) => entry.uom_id === uomId && entry.min_quantity === '1') ?? null
}

/**
 * Set a price in a price list (MD-03 follow-up): item (unless given),
 * unit (the item's), price in the list's currency (MoneyInput, sent as a
 * string of minor units, never a float), start date (today in the
 * company's time zone by default; a later date schedules the change) and
 * the quantity it applies from (1 by default). Editing an existing price
 * (`price`) changes its amount only: the price in force gets a new price
 * from today (the old one stays as history), a scheduled one changes in
 * place. A quantity break priced above the single-unit price is warned
 * about, not refused. Saves with
 * `POST price-lists/{id}/prices`, which updates the price with the same
 * item, unit, date and quantity, or adds one.
 */
export function PriceDialog({ priceList, item: fixedItem = null, price = null, today, onClose, onSaved }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const formId = useId()
  const formRef = useRef(null)
  const currencies = useTenantCurrencies()
  const decimals = decimalsOf(priceList.currency, currencies.all)
  const [search, setSearch] = useState('')
  const [chosen, setChosen] = useState(fixedItem)
  const editing = Boolean(price)
  // Past prices are history: changing the price in force adds a price from
  // today (the old one stays in the history); a scheduled one changes in place.
  const scheduled = editing && Boolean(today) && price.effective_from > today
  const [values, setValues] = useState({
    item_id: fixedItem?.id ?? '',
    uom_id: price?.uom_id ?? fixedItem?.base_uom_id ?? '',
    amount_minor: price?.amount_minor ?? '',
    effective_from: editing && !scheduled ? (today ?? '') : (price?.effective_from ?? today ?? ''),
    min_quantity: price?.min_quantity ?? '1',
  })
  const [missing, setMissing] = useState({})
  const items = useItemSearch(search, priceList.company_id, !fixedItem)
  const item = chosen
  const unitOptions = useItemUnitOptions(item)
  // An edited price keeps its unit.
  const units = editing ? [{ value: price.uom_id, label: price.uom_code || price.uom_id }] : unitOptions
  const itemOptions = [...(chosen && !items.rows.some((row) => row.id === chosen.id) ? [chosen] : []), ...items.rows].map((row) => ({
    value: row.id,
    label: `${row.code} · ${row.name ?? ''}`,
  }))

  // A quantity break priced above the single-unit price is allowed, with a warning.
  const isBreak = typeof values.min_quantity === 'string' && values.min_quantity !== '' && compareDecimal(values.min_quantity, '1') > 0
  const unitPrice = useUnitPrice(values.item_id, priceList.id, values.uom_id, isBreak)
  const breakAbove =
    isBreak && unitPrice && typeof values.amount_minor === 'string' && /^\d+$/.test(values.amount_minor) && BigInt(values.amount_minor) > BigInt(unitPrice.amount_minor)

  const save = useMutation({
    mutationFn: () =>
      api.post(`price-lists/${priceList.id}/prices`, {
        item_id: values.item_id,
        uom_id: values.uom_id,
        amount_minor: values.amount_minor,
        currency: priceList.currency,
        effective_from: values.effective_from || null,
        min_quantity: values.min_quantity,
      }),
    onSuccess: (response) => onSaved?.(response),
  })
  const errors = formErrors(save.error, FIELDS, { currency: 'amount_minor' })
  const error = (name) => missing[name] ?? errors.fields[name]
  const set = (name) => (value) => setValues((current) => ({ ...current, [name]: value }))

  const submit = () => {
    const problems = {}
    if (!values.item_id) problems.item_id = t('prices.dialog.itemRequired')
    if (!values.uom_id) problems.uom_id = t('prices.dialog.unitRequired')
    if (values.amount_minor === '' || values.amount_minor === null) problems.amount_minor = t('prices.dialog.amountRequired')
    if (values.min_quantity === '' || values.min_quantity === null) problems.min_quantity = t('prices.dialog.quantityRequired')
    setMissing(problems)
    if (Object.keys(problems).length === 0) save.mutate()
  }

  return (
    <Dialog
      open
      title={editing ? t('prices.dialog.editTitle', { list: priceList.name }) : t('prices.dialog.addTitle', { list: priceList.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={save.isPending}>
            {t('prices.dialog.submit')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        aria-label={t('prices.dialog.formLabel')}
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          submit()
        }}
      >
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {fixedItem ? (
          <p className="text-ink">
            <span className="font-mono text-caption">{fixedItem.code}</span> {fixedItem.name}
          </p>
        ) : (
          <Select
            label={t('prices.dialog.item')}
            placeholder={items.isPending ? t('common.loading') : t('prices.dialog.chooseItem')}
            options={itemOptions}
            value={values.item_id}
            onChange={(event) => {
              const next = [chosen, ...items.rows].find((row) => row?.id === event.target.value) ?? null
              setChosen(next)
              setValues((current) => ({ ...current, item_id: event.target.value, uom_id: next?.base_uom_id ?? '' }))
            }}
            onSearchChange={setSearch}
            error={error('item_id')}
            disabled={editing}
            required
          />
        )}
        <Select
          label={t('prices.dialog.unit')}
          placeholder={t('prices.dialog.chooseUnit')}
          options={units}
          value={values.uom_id}
          onChange={(event) => set('uom_id')(event.target.value)}
          error={error('uom_id')}
          disabled={editing || !item}
          required
        />
        <MoneyInput
          label={t('prices.dialog.amount')}
          help={t('prices.dialog.amountHelp', { currency: priceList.currency })}
          currency={priceList.currency}
          decimals={decimals}
          value={values.amount_minor}
          onChange={set('amount_minor')}
          error={error('amount_minor')}
          showErrors={Boolean(missing.amount_minor)}
          required
        />
        {editing && !scheduled ? <Alert tone="info" title={t('prices.dialog.newFromToday')} /> : null}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <TextField
            type="date"
            label={t('prices.dialog.from')}
            help={t('prices.dialog.fromHelp')}
            value={values.effective_from}
            onChange={(event) => set('effective_from')(event.target.value)}
            error={error('effective_from')}
            disabled={editing}
          />
          <DecimalInput
            label={t('prices.dialog.minQuantity')}
            help={t('prices.dialog.minQuantityHelp')}
            value={values.min_quantity}
            onChange={set('min_quantity')}
            error={error('min_quantity')}
            disabled={editing}
            required
          />
        </div>
        {breakAbove ? (
          <Alert tone="warning" title={t('prices.dialog.breakAbove', { price: formatMoney(unitPrice.amount_minor, unitPrice.currency, locale, decimals) })} />
        ) : null}
      </form>
    </Dialog>
  )
}
