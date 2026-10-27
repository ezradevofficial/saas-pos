import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DecimalInput, MoneyInput, Select, TextField } from '@/components/ds'
import { useUoms } from '@/pages/catalogue/catalogueData'
import { PageHeader } from '@/layouts/PageHeader'
import { Back, ItemPicker } from './PharmacyParts'
import { isPositive, locationCurrency, locationOptions, pharmacyKey, usePlaces } from './pharmacyData'

let counter = 0
const blankLine = () => ({ key: `line-${(counter += 1)}`, item: null, uom_id: '', qty: '', batch_number: '', expires_on: '', manufactured_on: '', unit_cost_minor: '' })
const lineReady = (line) => line.item && isPositive(line.qty) && line.batch_number.trim() && line.expires_on && line.unit_cost_minor !== '' && line.unit_cost_minor !== null
const lineFieldNames = (count) => Array.from({ length: count }, (_, index) => ['item_id', 'uom_id', 'qty', 'batch_number', 'expires_on', 'manufactured_on', 'unit_cost_minor', 'currency'].map((name) => `lines.${index}.${name}`)).flat()

/**
 * PHA-06: receive stock by lot at one location. Each line is an item that is
 * tracked by lot, a batch number, the expiry on the pack (and the manufacture
 * date when printed), the quantity in any unit of the item and its unit
 * cost. Saving makes the lots and tells Inventory what arrived.
 */
export default function ReceiveStock() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const uoms = useUoms()
  const [location, setLocation] = useState('')
  const [reference, setReference] = useState('')
  const [lines, setLines] = useState(() => [blankLine()])
  const [submitted, setSubmitted] = useState(false)
  const currency = locationCurrency(places, location)

  const save = useMutation({
    mutationFn: () =>
      api.post('pharmacy/receipts', {
        location_id: location,
        ...(reference.trim() ? { reference: reference.trim() } : {}),
        lines: lines.map((line) => ({
          item_id: line.item.id,
          ...(line.uom_id && line.uom_id !== line.item.base_uom_id ? { uom_id: line.uom_id } : {}),
          qty: line.qty,
          batch_number: line.batch_number.trim(),
          expires_on: line.expires_on,
          ...(line.manufactured_on ? { manufactured_on: line.manufactured_on } : {}),
          unit_cost_minor: String(line.unit_cost_minor),
          currency,
        })),
      }),
    onSuccess: async () => {
      toast.success(t('pharmacy.receive.saved'))
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
      navigate('/pharmacy/lots')
    },
  })
  const errors = formErrors(save.error, ['location_id', 'reference', 'lines', ...lineFieldNames(lines.length)])
  const ready = location && currency && lines.every(lineReady)
  const set = (key, name) => (value) => setLines((current) => current.map((line) => (line.key === key ? { ...line, [name]: value } : line)))
  const unitOptions = (item) => {
    const base = uoms.all.find((uom) => uom.id === item?.base_uom_id)
    return [
      { value: item?.base_uom_id ?? '', label: base ? t('pharmacy.receive.baseUnit', { unit: base.code }) : t('pharmacy.receive.baseUnitUnknown') },
      ...(item?.uoms ?? []).filter((uom) => uom.uom_id !== item.base_uom_id).map((uom) => ({ value: uom.uom_id, label: uoms.all.find((entry) => entry.id === uom.uom_id)?.code ?? uom.code ?? uom.uom_id })),
    ].filter((option) => option.value)
  }

  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <>
      <Back to="/pharmacy/lots">{t('pharmacy.lots.back')}</Back>
      <PageHeader title={t('pharmacy.receive.title')} description={t('pharmacy.receive.help')} />
      <form noValidate onSubmit={submit} className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('pharmacy.fields.location')}
              placeholder={t('pharmacy.pickers.location')}
              options={locationOptions(places.activeLocations)}
              value={location}
              required
              error={errors.fields.location_id ?? (submitted && !location ? t('pharmacy.validation.location') : undefined)}
              onChange={(event) => setLocation(event.target.value)}
            />
            <TextField label={t('pharmacy.receive.reference')} help={t('pharmacy.receive.referenceHelp')} value={reference} maxLength={100} error={errors.fields.reference} onChange={(event) => setReference(event.target.value)} />
          </div>
        </Card>
        <section aria-label={t('pharmacy.receive.lines')} className="flex flex-col gap-3">
          <h2 className="text-h3 text-ink">{t('pharmacy.receive.lines')}</h2>
          {errors.fields.lines ? <Alert tone="danger" title={errors.fields.lines} /> : null}
          {lines.map((line, index) => {
            const error = (name) => errors.fields[`lines.${index}.${name}`]
            return (
              <fieldset key={line.key} className="flex flex-col gap-3 rounded-md border border-border p-4">
                <legend className="px-1 text-label text-ink">{t('pharmacy.receive.line', { number: index + 1 })}</legend>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <ItemPicker
                    label={t('pharmacy.fields.item')}
                    value={line.item}
                    required
                    error={error('item_id') ?? (submitted && !line.item ? t('pharmacy.validation.item') : undefined)}
                    onChange={(item) => setLines((current) => current.map((entry) => (entry.key === line.key ? { ...entry, item, uom_id: item?.base_uom_id ?? '' } : entry)))}
                    className="sm:col-span-2"
                  />
                  <Select label={t('pharmacy.fields.unit')} options={unitOptions(line.item)} value={line.uom_id} disabled={!line.item} error={error('uom_id')} onChange={(event) => set(line.key, 'uom_id')(event.target.value)} />
                  <DecimalInput
                    label={t('pharmacy.fields.qty')}
                    value={line.qty}
                    required
                    showErrors={submitted}
                    error={error('qty') ?? (submitted && !isPositive(line.qty) ? t('pharmacy.validation.qty') : undefined)}
                    onChange={(value) => set(line.key, 'qty')(value ?? '')}
                  />
                  <TextField
                    label={t('pharmacy.fields.batch')}
                    value={line.batch_number}
                    maxLength={60}
                    required
                    error={error('batch_number') ?? (submitted && !line.batch_number.trim() ? t('pharmacy.validation.batch') : undefined)}
                    onChange={(event) => set(line.key, 'batch_number')(event.target.value)}
                  />
                  <TextField
                    type="date"
                    label={t('pharmacy.fields.expires')}
                    value={line.expires_on}
                    required
                    error={error('expires_on') ?? (submitted && !line.expires_on ? t('pharmacy.validation.expires') : undefined)}
                    onChange={(event) => set(line.key, 'expires_on')(event.target.value)}
                  />
                  <TextField type="date" label={t('pharmacy.fields.manufactured')} value={line.manufactured_on} error={error('manufactured_on')} onChange={(event) => set(line.key, 'manufactured_on')(event.target.value)} />
                  <MoneyInput
                    key={currency ?? 'none'}
                    label={t('pharmacy.fields.unitCost')}
                    currency={currency}
                    value={line.unit_cost_minor}
                    required
                    error={error('unit_cost_minor') ?? error('currency') ?? (submitted && (line.unit_cost_minor === '' || line.unit_cost_minor === null) ? t('pharmacy.validation.unitCost') : undefined)}
                    onChange={(value) => set(line.key, 'unit_cost_minor')(value ?? '')}
                  />
                </div>
                {lines.length > 1 ? (
                  <div>
                    <Button variant="ghost" icon="remove" onClick={() => setLines((current) => current.filter((entry) => entry.key !== line.key))}>
                      {t('pharmacy.receive.remove')}
                    </Button>
                  </div>
                ) : null}
              </fieldset>
            )
          })}
          <div>
            <Button icon="plus" onClick={() => setLines((current) => [...current, blankLine()])}>
              {t('pharmacy.receive.add')}
            </Button>
          </div>
        </section>
        <div className="flex flex-wrap gap-2">
          <Button variant="ghost" onClick={() => navigate('/pharmacy/lots')}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('pharmacy.receive.submit')}
          </Button>
        </div>
      </form>
    </>
  )
}
