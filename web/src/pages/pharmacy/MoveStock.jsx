import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DecimalInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Back, CalendarDate, ItemPicker, LotState, Qty } from './PharmacyParts'
import { isPositive, locationOptions, pharmacyKey, usePlaces } from './pharmacyData'

let counter = 0
const blankLine = () => ({ key: `line-${(counter += 1)}`, item: null, qty: '' })

/** PHA-07: how a quantity would be covered, first expiry first, from the source location (a preview; the server decides). */
function Suggestion({ itemId, locationId, qty }) {
  const { t } = useTranslation()
  const ready = Boolean(itemId && locationId && isPositive(qty))
  const query = useQuery({
    queryKey: [...pharmacyKey, 'fefo', itemId, locationId, qty],
    queryFn: () => api.get(`pharmacy/fefo?item_id=${itemId}&location_id=${locationId}&qty=${encodeURIComponent(qty)}`),
    enabled: ready,
    staleTime: 10_000,
  })
  const result = query.data?.data
  if (!ready || !result) return null
  const taken = result.lots.filter((lot) => isPositive(lot.take))
  return (
    <div className="flex flex-col gap-1 text-caption">
      <span className="text-ink-muted">{t('pharmacy.move.suggestion')}</span>
      <ul className="flex flex-col gap-1">
        {taken.map((lot) => (
          <li key={lot.lot_id} className="flex flex-wrap items-center gap-2">
            <span className="font-medium text-ink">{lot.batch_number}</span>
            <span className="text-ink-muted">
              <CalendarDate value={lot.expires_on} />
            </span>
            <Qty value={lot.take} />
            {lot.warn ? <LotState state="near_expiry" days={lot.days_left} /> : null}
          </li>
        ))}
      </ul>
      {isPositive(result.shortfall) ? <Alert tone="warning" title={t('pharmacy.move.shortfall', { qty: result.shortfall })} /> : null}
    </div>
  )
}

/**
 * PHA-13: move lots between two locations of a company in one step. Each line
 * is an item and a quantity; the lots leave the source first expiry first
 * (shown as they would be taken), expired and blocked lots are never moved.
 */
export default function MoveStock() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState(() => [blankLine()])
  const [submitted, setSubmitted] = useState(false)

  const save = useMutation({
    mutationFn: () => api.post('pharmacy/transfers', { from_location_id: from, to_location_id: to, ...(note.trim() ? { note: note.trim() } : {}), lines: lines.map((line) => ({ item_id: line.item.id, qty: line.qty })) }),
    onSuccess: async () => {
      toast.success(t('pharmacy.move.saved'))
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
      navigate('/pharmacy/lots')
    },
  })
  const errors = formErrors(save.error, ['from_location_id', 'to_location_id', 'note', 'lines', ...lines.flatMap((_, index) => [`lines.${index}.item_id`, `lines.${index}.qty`])])
  const ready = from && to && from !== to && lines.every((line) => line.item && isPositive(line.qty))
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }
  const options = locationOptions(places.activeLocations)

  return (
    <>
      <Back to="/pharmacy/lots">{t('pharmacy.lots.back')}</Back>
      <PageHeader title={t('pharmacy.move.title')} description={t('pharmacy.move.help')} />
      <form noValidate onSubmit={submit} className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Select label={t('pharmacy.move.from')} placeholder={t('pharmacy.pickers.location')} options={options} value={from} required error={errors.fields.from_location_id ?? (submitted && !from ? t('pharmacy.validation.location') : undefined)} onChange={(event) => setFrom(event.target.value)} />
            <Select
              label={t('pharmacy.move.to')}
              placeholder={t('pharmacy.pickers.location')}
              options={options.filter((option) => option.value !== from)}
              value={to}
              required
              error={errors.fields.to_location_id ?? (submitted && !to ? t('pharmacy.validation.location') : undefined)}
              onChange={(event) => setTo(event.target.value)}
            />
            <TextField label={t('pharmacy.fields.note')} value={note} maxLength={1000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} className="sm:col-span-2" />
          </div>
        </Card>
        <section aria-label={t('pharmacy.move.lines')} className="flex flex-col gap-3">
          <h2 className="text-h3 text-ink">{t('pharmacy.move.lines')}</h2>
          {errors.fields.lines ? <Alert tone="danger" title={errors.fields.lines} /> : null}
          {lines.map((line, index) => (
            <fieldset key={line.key} className="flex flex-col gap-3 rounded-md border border-border p-4">
              <legend className="px-1 text-label text-ink">{t('pharmacy.receive.line', { number: index + 1 })}</legend>
              <div className="grid gap-3 sm:grid-cols-3">
                <ItemPicker
                  label={t('pharmacy.fields.item')}
                  value={line.item}
                  required
                  error={errors.fields[`lines.${index}.item_id`] ?? (submitted && !line.item ? t('pharmacy.validation.item') : undefined)}
                  onChange={(item) => setLines((current) => current.map((entry) => (entry.key === line.key ? { ...entry, item } : entry)))}
                  className="sm:col-span-2"
                />
                <DecimalInput
                  label={t('pharmacy.fields.qty')}
                  value={line.qty}
                  required
                  showErrors={submitted}
                  error={errors.fields[`lines.${index}.qty`] ?? (submitted && !isPositive(line.qty) ? t('pharmacy.validation.qty') : undefined)}
                  onChange={(value) => setLines((current) => current.map((entry) => (entry.key === line.key ? { ...entry, qty: value ?? '' } : entry)))}
                />
              </div>
              <Suggestion itemId={line.item?.id} locationId={from} qty={line.qty} />
              {lines.length > 1 ? (
                <div>
                  <Button variant="ghost" icon="remove" onClick={() => setLines((current) => current.filter((entry) => entry.key !== line.key))}>
                    {t('pharmacy.receive.remove')}
                  </Button>
                </div>
              ) : null}
            </fieldset>
          ))}
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
            {t('pharmacy.move.submit')}
          </Button>
        </div>
      </form>
    </>
  )
}
