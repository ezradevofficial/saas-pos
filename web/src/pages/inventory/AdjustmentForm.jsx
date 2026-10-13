import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Back } from './InventoryParts'
import { ADJUSTMENT_REASONS, inventoryKey, locationOptions, usePlaces } from './inventoryData'
import { blankLine, lineBody, lineFieldNames, lineReady } from './inventoryHelpers'
import { StockLine } from './StockLines'

/**
 * INV-10: a new adjustment at one location: a reason from the fixed list
 * and a note, and lines that decrease or increase stock (an increase of a
 * batch item names its batch and expiry and may carry a unit cost; serial
 * items name their serials). Sending it starts its flow; it posts on
 * approval.
 */
export default function AdjustmentForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const [location, setLocation] = useState('')
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState(() => [blankLine()])
  const [submitted, setSubmitted] = useState(false)

  const save = useMutation({
    mutationFn: () =>
      api.post('inventory/adjustments', {
        location_id: location,
        reason,
        ...(note.trim() ? { note: note.trim() } : {}),
        lines: lines.map((line) => lineBody(line, 'adjustment')),
      }),
    onSuccess: async (response) => {
      toast.success(t('inventory.adjustments.created', { number: response?.data?.number ?? '' }))
      await queryClient.invalidateQueries({ queryKey: inventoryKey })
      navigate(`/inventory/adjustments/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, ['location_id', 'reason', 'note', 'lines', ...lineFieldNames(lines.length)])
  const ready = location && reason && lines.every(lineReady)

  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <>
      <Back to="/inventory/adjustments">{t('inventory.adjustments.back')}</Back>
      <PageHeader title={t('inventory.adjustments.newTitle')} description={t('inventory.adjustments.newHelp')} />
      <form noValidate onSubmit={submit} className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('inventory.fields.location')}
              placeholder={t('inventory.pickers.location')}
              options={locationOptions(places.activeLocations)}
              value={location}
              required
              error={errors.fields.location_id ?? (submitted && !location ? t('inventory.validation.location') : undefined)}
              onChange={(event) => {
                setLocation(event.target.value)
                setLines((current) => current.map((line) => ({ ...line, batch_id: '' })))
              }}
            />
            <Select
              label={t('inventory.fields.reason')}
              placeholder={t('inventory.pickers.reason')}
              options={ADJUSTMENT_REASONS.map((value) => ({ value, label: t(`inventory.reasons.${value}`) }))}
              value={reason}
              required
              error={errors.fields.reason ?? (submitted && !reason ? t('inventory.validation.reason') : undefined)}
              onChange={(event) => setReason(event.target.value)}
            />
            <TextField label={t('inventory.fields.note')} value={note} maxLength={1000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} className="sm:col-span-2" />
          </div>
        </Card>
        <section aria-label={t('inventory.lines.title')} className="flex flex-col gap-3">
          <h2 className="text-h3 text-ink">{t('inventory.lines.title')}</h2>
          {errors.fields.lines ? <Alert tone="danger" title={errors.fields.lines} /> : null}
          {lines.map((line, index) => (
            <StockLine
              key={line.key}
              line={line}
              index={index}
              mode="adjustment"
              locationId={location}
              submitted={submitted}
              removable={lines.length > 1}
              error={(name) => errors.fields[`lines.${index}.${name}`]}
              onChange={(next) => setLines((current) => current.map((entry) => (entry.key === line.key ? next : entry)))}
              onRemove={() => setLines((current) => current.filter((entry) => entry.key !== line.key))}
            />
          ))}
          <div>
            <Button icon="plus" onClick={() => setLines((current) => [...current, blankLine()])}>
              {t('inventory.lines.add')}
            </Button>
          </div>
        </section>
        <div className="flex flex-wrap gap-2">
          <Button variant="ghost" onClick={() => navigate('/inventory/adjustments')}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('inventory.adjustments.submit')}
          </Button>
        </div>
      </form>
    </>
  )
}
