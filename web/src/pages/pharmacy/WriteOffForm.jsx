import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DecimalInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { Back } from './PharmacyParts'
import { isPositive, locationOptions, pharmacyKey, usePlaces, WRITE_OFF_REASONS } from './pharmacyData'

let counter = 0
const blankLine = () => ({ key: `line-${(counter += 1)}`, lot_id: '', qty: '' })

/**
 * PHA-12: ask to write off lot stock at one location: a reason, a note and
 * the lots with the quantity of each (the lots with stock there are offered,
 * expired ones first). Sending it starts its flow; stock leaves when a
 * branch manager approves.
 */
export default function WriteOffForm() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const [location, setLocation] = useState('')
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState(() => [blankLine()])
  const [submitted, setSubmitted] = useState(false)

  const stock = useQuery({
    queryKey: [...pharmacyKey, 'write-off-lots', location],
    queryFn: () => api.get(`pharmacy/lots?location=${location}&per_page=200&sort=expires_on`),
    enabled: Boolean(location),
  })
  const lotOptions = (stock.data?.data ?? []).map((row) => ({
    value: row.lot_id,
    label: [row.item?.name, row.batch_number, t('pharmacy.writeOffs.lotExpires', { date: formatCalendarDate(row.expires_on, locale) }), t('pharmacy.writeOffs.lotQty', { qty: row.qty })].join(' · '),
  }))

  const save = useMutation({
    mutationFn: () => api.post('pharmacy/write-offs', { location_id: location, reason, ...(note.trim() ? { note: note.trim() } : {}), lines: lines.map((line) => ({ lot_id: line.lot_id, qty: line.qty })) }),
    onSuccess: async (response) => {
      toast.success(t('pharmacy.writeOffs.created', { number: response?.data?.number ?? '' }))
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
      navigate(`/pharmacy/write-offs/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, ['location_id', 'reason', 'note', 'lines', ...lines.flatMap((_, index) => [`lines.${index}.lot_id`, `lines.${index}.qty`])])
  const ready = location && reason && lines.every((line) => line.lot_id && isPositive(line.qty))
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <>
      <Back to="/pharmacy/write-offs">{t('pharmacy.writeOffs.back')}</Back>
      <PageHeader title={t('pharmacy.writeOffs.newTitle')} description={t('pharmacy.writeOffs.newHelp')} />
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
              onChange={(event) => {
                setLocation(event.target.value)
                setLines([blankLine()])
              }}
            />
            <Select
              label={t('pharmacy.fields.reason')}
              placeholder={t('pharmacy.pickers.reason')}
              options={WRITE_OFF_REASONS.map((value) => ({ value, label: t(`pharmacy.reasons.${value}`) }))}
              value={reason}
              required
              error={errors.fields.reason ?? (submitted && !reason ? t('pharmacy.validation.reason') : undefined)}
              onChange={(event) => setReason(event.target.value)}
            />
            <TextField label={t('pharmacy.fields.note')} value={note} maxLength={1000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} className="sm:col-span-2" />
          </div>
        </Card>
        <section aria-label={t('pharmacy.writeOffs.lines')} className="flex flex-col gap-3">
          <h2 className="text-h3 text-ink">{t('pharmacy.writeOffs.lines')}</h2>
          {errors.fields.lines ? <Alert tone="danger" title={errors.fields.lines} /> : null}
          {lines.map((line, index) => (
            <fieldset key={line.key} className="flex flex-col gap-3 rounded-md border border-border p-4">
              <legend className="px-1 text-label text-ink">{t('pharmacy.receive.line', { number: index + 1 })}</legend>
              <div className="grid gap-3 sm:grid-cols-3">
                <Select
                  label={t('pharmacy.fields.lot')}
                  placeholder={t('pharmacy.pickers.lot')}
                  options={lotOptions}
                  value={line.lot_id}
                  disabled={!location}
                  required
                  error={errors.fields[`lines.${index}.lot_id`] ?? (submitted && !line.lot_id ? t('pharmacy.validation.lot') : undefined)}
                  onChange={(event) => setLines((current) => current.map((entry) => (entry.key === line.key ? { ...entry, lot_id: event.target.value } : entry)))}
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
          <Button variant="ghost" onClick={() => navigate('/pharmacy/write-offs')}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('pharmacy.writeOffs.submit')}
          </Button>
        </div>
      </form>
    </>
  )
}
