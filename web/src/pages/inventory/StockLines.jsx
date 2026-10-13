import { useTranslation } from 'react-i18next'
import { Button, DecimalInput, Select, TextField } from '@/components/ds'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { ItemPicker } from './InventoryParts'
import { useItemStock } from './inventoryData'
import { useUnitOptions } from './inventoryHelpers'

/**
 * One line of a transfer (`mode="transfer"`) or adjustment
 * (`mode="adjustment"`): item, unit (the item's, INV-03), quantity, and
 * what the item's tracking asks for (INV-06, INV-07): a batch held at
 * `locationId`, or a new batch number and expiry for an increase, and
 * serial numbers. `error(field)` gives the API's message for this line.
 */
export function StockLine({ line, index, mode, locationId, onChange, onRemove, removable, error, submitted }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const stock = useItemStock(line.item?.id)
  const detail = stock.data?.data
  const tracking = detail?.settings?.tracking ?? 'none'
  const units = useUnitOptions(line.item, detail?.item?.base_uom)
  const set = (name) => (value) => onChange({ ...line, [name]: value })
  const increase = mode === 'adjustment' && line.direction === 'in'
  const batches = (detail?.batches ?? [])
    .map((batch) => ({ ...batch, here: batch.balances.find((balance) => balance.location?.id === locationId) }))
    .filter((batch) => !locationId || batch.here)
  const batchOptions = batches.map((batch) => ({
    value: batch.id,
    label: [batch.batch_number, batch.expires_on ? t('inventory.lines.expires', { date: formatCalendarDate(batch.expires_on, locale) }) : null, batch.expired ? t('inventory.item.expired') : null].filter(Boolean).join(' · '),
  }))
  const number = index + 1

  return (
    <fieldset className="flex flex-col gap-3 rounded-md border border-border p-4">
      <legend className="px-1 text-label text-ink">{t('inventory.lines.line', { number })}</legend>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <ItemPicker
          label={t('inventory.fields.item')}
          value={line.item}
          required
          error={error('item_id') ?? (submitted && !line.item ? t('inventory.validation.item') : undefined)}
          onChange={(item) => onChange({ ...line, item, uom_id: item?.base_uom_id ?? '', batch_id: '', serials: '' })}
          className="sm:col-span-2"
        />
        {mode === 'adjustment' ? (
          <Select
            label={t('inventory.lines.direction')}
            options={[
              { value: 'out', label: t('inventory.lines.decrease') },
              { value: 'in', label: t('inventory.lines.increase') },
            ]}
            value={line.direction}
            onChange={(event) => onChange({ ...line, direction: event.target.value, batch_id: '', batch_number: '', expires_on: '', unit_cost: '' })}
          />
        ) : null}
        <Select label={t('inventory.fields.unit')} options={units} value={line.uom_id} disabled={!line.item} error={error('uom_id')} onChange={(event) => set('uom_id')(event.target.value)} />
        <DecimalInput
          label={t('inventory.fields.qty')}
          value={line.qty}
          required
          showErrors={submitted}
          error={error('qty') ?? (submitted && !line.qty ? t('inventory.validation.qty') : undefined)}
          onChange={(value) => set('qty')(value ?? '')}
        />
        {increase ? (
          <DecimalInput
            label={t('inventory.fields.unitCost')}
            help={t('inventory.fields.unitCostOptional')}
            value={line.unit_cost}
            positive={false}
            error={error('unit_cost')}
            onChange={(value) => set('unit_cost')(value ?? '')}
          />
        ) : null}
        {tracking === 'batch' && !increase ? (
          <Select
            label={t('inventory.fields.batch')}
            placeholder={batchOptions.length ? t('inventory.pickers.batch') : t('inventory.pickers.noBatch')}
            options={batchOptions}
            value={line.batch_id}
            error={error('batch_id')}
            onChange={(event) => set('batch_id')(event.target.value)}
          />
        ) : null}
        {tracking === 'batch' && increase ? (
          <>
            <TextField label={t('inventory.fields.batchNumber')} value={line.batch_number} maxLength={60} required error={error('batch_number')} onChange={(event) => set('batch_number')(event.target.value)} />
            <TextField
              type="date"
              label={t('inventory.fields.expiresOn')}
              value={line.expires_on}
              required={Boolean(detail?.settings?.track_expiry)}
              error={error('expires_on')}
              onChange={(event) => set('expires_on')(event.target.value)}
            />
          </>
        ) : null}
        {tracking === 'serial' ? (
          <TextField
            label={t('inventory.fields.serials')}
            help={t('inventory.fields.serialsHelp')}
            value={line.serials}
            required
            error={error('serials')}
            onChange={(event) => set('serials')(event.target.value)}
            className="sm:col-span-2"
          />
        ) : null}
      </div>
      {removable ? (
        <div>
          <Button variant="ghost" icon="remove" onClick={onRemove} aria-label={t('inventory.lines.remove', { number })}>
            {t('inventory.lines.removeShort')}
          </Button>
        </div>
      ) : null}
    </fieldset>
  )
}

