import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DecimalInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useUnitOptions } from '@/pages/inventory/inventoryHelpers'
import { Back, ItemPicker } from './StoresParts'
import { storeCompany, storeOptions, storesKey, useDimensions, useStorePlaces } from './storesData'

let nextKey = 0
const blankLine = () => ({ key: `line-${(nextKey += 1)}`, item: null, uom_id: '', qty: '', note: '' })

/** One line of a requisition: item, unit (the item's), quantity and a note. */
function RequisitionLine({ line, index, onChange, onRemove, removable, error, submitted }) {
  const { t } = useTranslation()
  const units = useUnitOptions(line.item)
  const number = index + 1
  return (
    <fieldset className="flex flex-col gap-3 rounded-md border border-border p-4">
      <legend className="px-1 text-label text-ink">{t('stores.lines.line', { number })}</legend>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <ItemPicker
          label={t('stores.fields.item')}
          placeholder={t('stores.pickers.item')}
          value={line.item}
          required
          error={error('item_id') ?? (submitted && !line.item ? t('stores.validation.item') : undefined)}
          onChange={(item) => onChange({ ...line, item, uom_id: item?.base_uom_id ?? '' })}
          className="sm:col-span-2"
        />
        <Select label={t('stores.fields.unit')} options={units} value={line.uom_id} disabled={!line.item} error={error('uom_id')} onChange={(event) => onChange({ ...line, uom_id: event.target.value })} />
        <DecimalInput
          label={t('stores.fields.qty')}
          value={line.qty}
          required
          showErrors={submitted}
          error={error('qty') ?? (submitted && !line.qty ? t('stores.validation.qty') : undefined)}
          onChange={(value) => onChange({ ...line, qty: value ?? '' })}
        />
        <TextField label={t('stores.fields.lineNote')} value={line.note} maxLength={500} error={error('note')} onChange={(event) => onChange({ ...line, note: event.target.value })} className="sm:col-span-2 lg:col-span-4" />
      </div>
      {removable ? (
        <div>
          <Button variant="ghost" icon="remove" onClick={onRemove} aria-label={t('stores.lines.remove', { number })}>
            {t('stores.lines.removeShort')}
          </Button>
        </div>
      ) : null}
    </fieldset>
  )
}

/**
 * STR-02: a new requisition: the store to ask, the department (and cost
 * centre) of the store's company, the date needed, a note and the lines.
 * Sending it starts its approval (STR-03).
 */
export default function RequisitionForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = useStorePlaces()
  const [store, setStore] = useState('')
  const [department, setDepartment] = useState('')
  const [costCentre, setCostCentre] = useState('')
  const [neededBy, setNeededBy] = useState('')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState(() => [blankLine()])
  const [submitted, setSubmitted] = useState(false)
  const company = storeCompany(places, places.stores.find((entry) => entry.id === store))
  const departments = useDimensions(company, 'departments')
  const costCentres = useDimensions(company, 'cost-centres')

  const save = useMutation({
    mutationFn: () =>
      api.post('stores/requisitions', {
        location_id: store,
        department_id: department,
        ...(costCentre ? { cost_centre_id: costCentre } : {}),
        ...(neededBy ? { needed_by: neededBy } : {}),
        ...(note.trim() ? { note: note.trim() } : {}),
        lines: lines.map((line) => ({ item_id: line.item.id, ...(line.uom_id ? { uom_id: line.uom_id } : {}), qty: line.qty, ...(line.note.trim() ? { note: line.note.trim() } : {}) })),
      }),
    onSuccess: async (response) => {
      toast.success(t('stores.requisitions.created', { number: response?.data?.number ?? '' }))
      await queryClient.invalidateQueries({ queryKey: storesKey })
      navigate(`/stores/requisitions/${response.data.id}`)
    },
  })
  const lineFields = lines.flatMap((_, index) => ['item_id', 'uom_id', 'qty', 'note'].map((name) => `lines.${index}.${name}`))
  const errors = formErrors(save.error, ['location_id', 'department_id', 'cost_centre_id', 'needed_by', 'note', 'lines', ...lineFields])
  const ready = store && department && lines.every((line) => line.item && line.qty)

  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <>
      <Back to="/stores/requisitions">{t('stores.requisitions.back')}</Back>
      <PageHeader title={t('stores.requisitions.newTitle')} description={t('stores.requisitions.newHelp')} />
      <form noValidate onSubmit={submit} className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('stores.fields.store')}
              placeholder={t('stores.pickers.store')}
              options={storeOptions(places.activeStores)}
              value={store}
              required
              error={errors.fields.location_id ?? (submitted && !store ? t('stores.validation.store') : undefined)}
              onChange={(event) => {
                setStore(event.target.value)
                setDepartment('')
                setCostCentre('')
              }}
            />
            <Select
              label={t('stores.fields.department')}
              placeholder={store ? t('stores.pickers.department') : t('stores.pickers.storeFirst')}
              options={departments.map((row) => ({ value: row.id, label: `${row.name} · ${row.code}` }))}
              value={department}
              required
              disabled={!store}
              error={errors.fields.department_id ?? (submitted && !department ? t('stores.validation.department') : undefined)}
              onChange={(event) => setDepartment(event.target.value)}
            />
            <Select
              label={t('stores.fields.costCentre')}
              placeholder={t('stores.pickers.costCentre')}
              options={[{ value: '', label: t('stores.pickers.noCostCentre') }, ...costCentres.map((row) => ({ value: row.id, label: `${row.name} · ${row.code}` }))]}
              value={costCentre}
              disabled={!store}
              error={errors.fields.cost_centre_id}
              onChange={(event) => setCostCentre(event.target.value)}
            />
            <TextField type="date" label={t('stores.fields.neededBy')} value={neededBy} error={errors.fields.needed_by} onChange={(event) => setNeededBy(event.target.value)} />
            <TextField label={t('stores.fields.note')} value={note} maxLength={1000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} className="sm:col-span-2" />
          </div>
        </Card>
        <section aria-label={t('stores.lines.title')} className="flex flex-col gap-3">
          <h2 className="text-h3 text-ink">{t('stores.lines.title')}</h2>
          {errors.fields.lines ? <Alert tone="danger" title={errors.fields.lines} /> : null}
          {lines.map((line, index) => (
            <RequisitionLine
              key={line.key}
              line={line}
              index={index}
              submitted={submitted}
              removable={lines.length > 1}
              error={(name) => errors.fields[`lines.${index}.${name}`]}
              onChange={(next) => setLines((current) => current.map((entry) => (entry.key === line.key ? next : entry)))}
              onRemove={() => setLines((current) => current.filter((entry) => entry.key !== line.key))}
            />
          ))}
          <div>
            <Button icon="plus" onClick={() => setLines((current) => [...current, blankLine()])}>
              {t('stores.lines.add')}
            </Button>
          </div>
        </section>
        <div className="flex flex-wrap gap-2">
          <Button variant="ghost" onClick={() => navigate('/stores/requisitions')}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('stores.requisitions.submit')}
          </Button>
        </div>
      </form>
    </>
  )
}
