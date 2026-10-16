import { useTranslation } from 'react-i18next'
import { Alert, Button, Checkbox, DecimalInput, Select, TextField } from '@/components/ds'
import { ItemPicker } from './ManufacturingParts'
import { kindLabel, LINE_KINDS, useLineUnits } from './manufacturingData'

/** One line: kind, item, unit, quantity, then yield, wastage and explode (components) or cost share (by-products), and a note. */
function LineRow({ line, index, count, readOnly, submitted, error, onChange, onMove, onRemove }) {
  const { t } = useTranslation()
  const units = useLineUnits(line.item, line.uom)
  const number = index + 1
  const component = line.kind === 'component'
  return (
    <li className="flex flex-col gap-3 rounded-md border border-border p-4" aria-label={t('manufacturing.lines.line', { number })}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-label text-ink tabular-nums">{t('manufacturing.lines.line', { number })}</span>
        {readOnly ? null : (
          <div className="flex flex-wrap gap-1">
            <Button variant="ghost" icon="up" disabled={index === 0} onClick={() => onMove(-1)} aria-label={t('manufacturing.lines.moveUp', { number })} />
            <Button variant="ghost" icon="down" disabled={index === count - 1} onClick={() => onMove(1)} aria-label={t('manufacturing.lines.moveDown', { number })} />
            <Button variant="ghost" icon="remove" onClick={onRemove} aria-label={t('manufacturing.lines.remove', { number })}>
              {t('manufacturing.lines.removeShort')}
            </Button>
          </div>
        )}
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Select
          label={t('manufacturing.fields.lineKind')}
          options={LINE_KINDS.map((value) => ({ value, label: kindLabel(t, value) }))}
          value={line.kind}
          disabled={readOnly}
          error={error('kind')}
          onChange={(event) => onChange({ ...line, kind: event.target.value, explode: event.target.value === 'component' ? line.explode : false })}
        />
        <ItemPicker
          label={t('manufacturing.fields.item')}
          placeholder={t('manufacturing.pickers.item')}
          type={component ? null : 'stock'}
          value={line.item}
          required
          disabled={readOnly}
          error={error('item_id') ?? (submitted && !line.item ? t('manufacturing.validation.item') : undefined)}
          onChange={(item) => onChange({ ...line, item, uom: null, uom_id: item?.base_uom_id ?? '' })}
          className="lg:col-span-2"
        />
        <Select label={t('manufacturing.fields.unit')} options={units} value={line.uom_id} disabled={readOnly || !line.item} error={error('uom_id')} onChange={(event) => onChange({ ...line, uom_id: event.target.value })} />
        <DecimalInput
          label={t('manufacturing.fields.qty')}
          value={line.qty}
          required
          disabled={readOnly}
          showErrors={submitted}
          error={error('qty') ?? (submitted && !line.qty ? t('manufacturing.validation.qty') : undefined)}
          onChange={(value) => onChange({ ...line, qty: value ?? '' })}
        />
        {component ? (
          <>
            <DecimalInput label={t('manufacturing.fields.yieldPercent')} suffix="%" maxDecimals={4} maxIntegerDigits={3} value={line.yield_percent} placeholder="100" disabled={readOnly} error={error('yield_percent')} onChange={(value) => onChange({ ...line, yield_percent: value ?? '' })} />
            <DecimalInput label={t('manufacturing.fields.wastagePercent')} suffix="%" maxDecimals={4} maxIntegerDigits={3} positive={false} value={line.wastage_percent} placeholder="0" disabled={readOnly} error={error('wastage_percent')} onChange={(value) => onChange({ ...line, wastage_percent: value ?? '' })} />
            <div className="flex items-end pb-2">
              <Checkbox label={t('manufacturing.fields.explode')} help={t('manufacturing.fields.explodeHelp')} checked={line.explode} disabled={readOnly} onChange={(event) => onChange({ ...line, explode: event.target.checked })} />
            </div>
          </>
        ) : (
          <DecimalInput label={t('manufacturing.fields.costSharePercent')} suffix="%" maxDecimals={4} maxIntegerDigits={3} positive={false} value={line.cost_share_percent} disabled={readOnly} error={error('cost_share_percent')} onChange={(value) => onChange({ ...line, cost_share_percent: value ?? '' })} />
        )}
        <TextField label={t('manufacturing.fields.lineNote')} value={line.note} maxLength={500} disabled={readOnly} error={error('note')} onChange={(event) => onChange({ ...line, note: event.target.value })} className="sm:col-span-2 lg:col-span-4" />
      </div>
    </li>
  )
}

/**
 * MFG-04: a version's lines, in order: add a component or a by-product,
 * move a line up or down, remove one; read-only once published.
 */
export function LineEditor({ lines, onChange, readOnly = false, submitted = false, errors = {}, prefix = '' }) {
  const { t } = useTranslation()
  const move = (index, step) => {
    const next = [...lines]
    const [line] = next.splice(index, 1)
    next.splice(index + step, 0, line)
    onChange(next)
  }
  return (
    <section aria-label={t('manufacturing.lines.title')} className="flex flex-col gap-3">
      <h2 className="text-h3 text-ink">{t('manufacturing.lines.title')}</h2>
      {errors[`${prefix}lines`] ? <Alert tone="danger" title={errors[`${prefix}lines`]} /> : null}
      {lines.length === 0 ? <p className="text-ink-muted">{t('manufacturing.lines.empty')}</p> : null}
      <ol className="flex flex-col gap-3">
        {lines.map((line, index) => (
          <LineRow
            key={line.key}
            line={line}
            index={index}
            count={lines.length}
            readOnly={readOnly}
            submitted={submitted}
            error={(name) => errors[`${prefix}lines.${index}.${name}`]}
            onChange={(next) => onChange(lines.map((entry) => (entry.key === line.key ? next : entry)))}
            onMove={(step) => move(index, step)}
            onRemove={() => onChange(lines.filter((entry) => entry.key !== line.key))}
          />
        ))}
      </ol>
      {readOnly ? null : (
        <div className="flex flex-wrap gap-2">
          <Button icon="plus" onClick={() => onChange([...lines, blankLine('component')])}>
            {t('manufacturing.lines.addComponent')}
          </Button>
          <Button variant="ghost" icon="plus" onClick={() => onChange([...lines, blankLine('by_product')])}>
            {t('manufacturing.lines.addByProduct')}
          </Button>
        </div>
      )}
    </section>
  )
}

/** MFG-04: what one batch yields (quantity in a unit of the output item), its labour minutes and a note. */
export function VersionFields({ item, outputUom, values, onChange, readOnly = false, submitted = false, errors = {}, prefix = '' }) {
  const { t } = useTranslation()
  const units = useLineUnits(item, outputUom)
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <DecimalInput
        label={t('manufacturing.fields.outputQty')}
        value={values.output_qty}
        required
        disabled={readOnly}
        showErrors={submitted}
        help={t('manufacturing.fields.outputQtyHelp')}
        error={errors[`${prefix}output_qty`] ?? (submitted && !values.output_qty ? t('manufacturing.validation.qty') : undefined)}
        onChange={(value) => onChange({ ...values, output_qty: value ?? '' })}
      />
      <Select label={t('manufacturing.fields.outputUnit')} options={units} value={values.output_uom_id} disabled={readOnly || !item} error={errors[`${prefix}output_uom_id`]} onChange={(event) => onChange({ ...values, output_uom_id: event.target.value })} />
      <DecimalInput
        label={t('manufacturing.fields.labourMinutes')}
        value={values.labour_minutes}
        maxDecimals={0}
        maxIntegerDigits={7}
        positive={false}
        disabled={readOnly}
        error={errors[`${prefix}labour_minutes`]}
        onChange={(value) => onChange({ ...values, labour_minutes: value ?? '' })}
      />
      <TextField label={t('manufacturing.fields.note')} value={values.note} maxLength={2000} disabled={readOnly} error={errors[`${prefix}note`]} onChange={(event) => onChange({ ...values, note: event.target.value })} />
    </div>
  )
}
