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
import { inventoryKey, locationOptions, usePlaces } from './inventoryData'
import { blankLine, lineBody, lineFieldNames, lineReady } from './inventoryHelpers'
import { StockLine } from './StockLines'

/**
 * INV-08: a new transfer between two locations of one company: from, to,
 * a note and the lines (item, unit, quantity, the batch held at the
 * source for batch items, serials for serial items). Sending it starts
 * its flow (approval by default); the transfer opens afterwards.
 */
export default function TransferForm() {
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
    mutationFn: () =>
      api.post('inventory/transfers', {
        from_location_id: from,
        to_location_id: to,
        ...(note.trim() ? { note: note.trim() } : {}),
        lines: lines.map((line) => lineBody(line, 'transfer')),
      }),
    onSuccess: async (response) => {
      toast.success(t('inventory.transfers.created', { number: response?.data?.number ?? '' }))
      await queryClient.invalidateQueries({ queryKey: inventoryKey })
      navigate(`/inventory/transfers/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, ['from_location_id', 'to_location_id', 'note', 'lines', ...lineFieldNames(lines.length)])
  const sameLocation = Boolean(from) && from === to
  const ready = from && to && !sameLocation && lines.every(lineReady)
  const options = locationOptions(places.activeLocations)

  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <>
      <Back to="/inventory/transfers">{t('inventory.transfers.back')}</Back>
      <PageHeader title={t('inventory.transfers.newTitle')} description={t('inventory.transfers.newHelp')} />
      <form noValidate onSubmit={submit} className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('inventory.fields.from')}
              placeholder={t('inventory.pickers.location')}
              options={options}
              value={from}
              required
              error={errors.fields.from_location_id ?? (submitted && !from ? t('inventory.validation.location') : undefined)}
              onChange={(event) => {
                setFrom(event.target.value)
                setLines((current) => current.map((line) => ({ ...line, batch_id: '' })))
              }}
            />
            <Select
              label={t('inventory.fields.to')}
              placeholder={t('inventory.pickers.location')}
              options={options}
              value={to}
              required
              error={errors.fields.to_location_id ?? (sameLocation ? t('inventory.validation.sameLocation') : submitted && !to ? t('inventory.validation.location') : undefined)}
              onChange={(event) => setTo(event.target.value)}
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
              mode="transfer"
              locationId={from}
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
          <Button variant="ghost" onClick={() => navigate('/inventory/transfers')}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('inventory.transfers.submit')}
          </Button>
        </div>
      </form>
    </>
  )
}
