import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, MultiSelect, Select, Switch, TextField } from '@/components/ds'
import { Field } from '@/components/ds/Field'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import { ALIGNS, blockLabel, fieldOptions, inRange, insertMerge, RANGES, TOTALS } from './blocks'

const TEXTAREA = cn(
  'min-h-textbox rounded-md border-border-strong bg-surface-200 px-3 py-2 text-body text-ink placeholder:text-ink-muted hover:border-ink-muted',
  'focus-visible:border-focus focus-visible:ring-0 focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-1 focus-visible:outline-focus',
  'aria-invalid:border-danger aria-invalid:ring-0 read-only:bg-surface-300 md:text-body dark:bg-surface-200',
)

/** A whole number in a range; typing keeps what was typed and the field says the range. */
function NumberField({ label, value, range, onChange, disabled }) {
  const { t } = useTranslation()
  const [min, max] = range
  const invalid = value !== undefined && value !== null && !inRange(value, range)
  return (
    <TextField
      label={label}
      type="number"
      inputMode="numeric"
      min={min}
      max={max}
      step={1}
      value={value ?? ''}
      disabled={disabled}
      help={t('documentTemplates.inspector.range', { min, max })}
      error={invalid ? t('documentTemplates.inspector.range', { min, max }) : undefined}
      onChange={(event) => {
        const raw = event.target.value
        onChange(raw === '' ? null : Math.trunc(Number(raw)))
      }}
    />
  )
}

/**
 * Text with merge fields: a text box (or one line) and a picker that puts
 * `{{path}}` where the cursor was.
 */
function MergeText({ label, value, onChange, fields, multiline = false, disabled, maxLength }) {
  const { t } = useTranslation()
  const id = useId()
  const ref = useRef(null)
  const insert = (path) => {
    if (!path) return
    const element = ref.current
    const { text, caret } = insertMerge(value, path, element?.selectionStart, element?.selectionEnd)
    onChange(text)
    requestAnimationFrame(() => {
      element?.focus()
      element?.setSelectionRange?.(caret, caret)
    })
  }

  return (
    <div className="flex flex-col gap-2">
      {multiline ? (
        <Field id={id} label={label}>
          <Textarea ref={ref} id={id} value={value ?? ''} readOnly={disabled} maxLength={maxLength} onChange={(event) => onChange(event.target.value)} className={TEXTAREA} />
        </Field>
      ) : (
        <TextField ref={ref} label={label} value={value ?? ''} readOnly={disabled} maxLength={maxLength} onChange={(event) => onChange(event.target.value)} />
      )}
      {!disabled ? (
        <Select
          label={t('documentTemplates.inspector.insertField')}
          placeholder={t('documentTemplates.inspector.chooseField')}
          options={fieldOptions(t, fields)}
          value=""
          onChange={(event) => insert(event.target.value)}
        />
      ) : null}
    </div>
  )
}

function AlignSelect({ value, onChange, disabled }) {
  const { t } = useTranslation()
  return (
    <Select
      label={t('documentTemplates.inspector.align')}
      options={ALIGNS.map((align) => ({ value: align, label: t(`documentTemplates.aligns.${align}`) }))}
      value={value ?? 'left'}
      disabled={disabled}
      onChange={(event) => onChange(event.target.value)}
    />
  )
}

/**
 * The settings of the selected block (TPL-01, TPL-02). Every picker
 * searches (ds Select). The totals block always prints its tax lines and
 * the tax authority block has nothing to set (TPL-03).
 */
export function BlockInspector({ block, type, paper, canEdit, onChange, onAddToRow, paletteTypes = [] }) {
  const { t } = useTranslation()
  const [childType, setChildType] = useState('text')
  if (!block) return <p className="text-body text-ink-muted">{t('documentTemplates.inspector.none')}</p>

  const set = (patch) => onChange({ ...block, ...patch })
  const disabled = !canEdit
  const columns = (type.columns ?? []).map((column) => ({ value: column.key, label: column.label }))

  let body = null
  switch (block.type) {
    case 'text':
      body = (
        <>
          <MergeText label={t('documentTemplates.inspector.text')} value={block.text} fields={type.fields} multiline disabled={disabled} maxLength={2000} onChange={(text) => set({ text })} />
          <AlignSelect value={block.align} disabled={disabled} onChange={(align) => set({ align })} />
          <Select
            label={t('documentTemplates.inspector.size')}
            options={['small', 'normal', 'large'].map((size) => ({ value: size, label: t(`documentTemplates.sizes.${size}`) }))}
            value={block.size ?? 'normal'}
            disabled={disabled}
            onChange={(event) => set({ size: event.target.value })}
          />
          <Select
            label={t('documentTemplates.inspector.weight')}
            options={['regular', 'medium'].map((weight) => ({ value: weight, label: t(`documentTemplates.weights.${weight}`) }))}
            value={block.weight ?? 'regular'}
            disabled={disabled}
            onChange={(event) => set({ weight: event.target.value })}
          />
        </>
      )
      break
    case 'field':
      body = (
        <>
          <Select label={t('documentTemplates.inspector.field')} options={fieldOptions(t, type.fields)} value={block.field} disabled={disabled} onChange={(event) => set({ field: event.target.value })} />
          <Switch label={t('documentTemplates.inspector.showLabel')} checked={block.label !== false} disabled={disabled} onChange={(label) => set({ label })} />
          <AlignSelect value={block.align} disabled={disabled} onChange={(align) => set({ align })} />
        </>
      )
      break
    case 'logo':
      body = (
        <>
          <AlignSelect value={block.align} disabled={disabled} onChange={(align) => set({ align })} />
          <NumberField label={t('documentTemplates.inspector.heightMm')} value={block.height} range={RANGES.logoHeight} disabled={disabled} onChange={(height) => set({ height })} />
        </>
      )
      break
    case 'lines':
      body = (
        <MultiSelect
          label={t('documentTemplates.inspector.columns')}
          help={t('documentTemplates.inspector.columnsHelp')}
          options={columns}
          value={block.columns ?? []}
          disabled={disabled}
          error={(block.columns ?? []).length === 0 ? t('documentTemplates.inspector.columnsRequired') : undefined}
          onChange={(next) => set({ columns: next })}
        />
      )
      break
    case 'totals':
      body = (
        <>
          <MultiSelect
            label={t('documentTemplates.inspector.totals')}
            options={TOTALS.map((key) => ({ value: key, label: t(`documentTemplates.totals.${key}`) }))}
            value={block.show ?? []}
            disabled={disabled}
            onChange={(show) => set({ show })}
          />
          <Switch label={t('documentTemplates.inspector.taxLines')} checked disabled />
          <p className="text-caption text-ink-muted">{t('documentTemplates.inspector.taxLinesLocked')}</p>
        </>
      )
      break
    case 'payments':
      body = <Switch label={t('documentTemplates.inspector.showChange')} checked={block.show_change !== false} disabled={disabled} onChange={(show_change) => set({ show_change })} />
      break
    case 'qr':
      body = (
        <>
          <MergeText label={t('documentTemplates.inspector.content')} value={block.content} fields={type.fields} disabled={disabled} maxLength={500} onChange={(content) => set({ content })} />
          <NumberField label={t('documentTemplates.inspector.sizeMm')} value={block.size} range={RANGES.qrSize} disabled={disabled} onChange={(size) => set({ size })} />
          <AlignSelect value={block.align} disabled={disabled} onChange={(align) => set({ align })} />
        </>
      )
      break
    case 'barcode':
      body = (
        <>
          <MergeText label={t('documentTemplates.inspector.content')} value={block.content} fields={type.fields} disabled={disabled} maxLength={200} onChange={(content) => set({ content })} />
          <NumberField label={t('documentTemplates.inspector.heightMm')} value={block.height} range={RANGES.barcodeHeight} disabled={disabled} onChange={(height) => set({ height })} />
          <AlignSelect value={block.align} disabled={disabled} onChange={(align) => set({ align })} />
        </>
      )
      break
    case 'signature':
      body = <TextField label={t('documentTemplates.inspector.signatureLabel')} value={block.label ?? ''} readOnly={disabled} maxLength={100} onChange={(event) => set({ label: event.target.value })} />
      break
    case 'terms':
      body = <MergeText label={t('documentTemplates.inspector.terms')} value={block.text} fields={type.fields} multiline disabled={disabled} maxLength={4000} onChange={(text) => set({ text })} />
      break
    case 'spacer':
      body = <NumberField label={t('documentTemplates.inspector.spaceMm')} value={block.size} range={RANGES.spacer} disabled={disabled} onChange={(size) => set({ size })} />
      break
    case 'divider':
      body = (
        <Select
          label={t('documentTemplates.inspector.style')}
          options={['solid', 'dashed'].map((style) => ({ value: style, label: t(`documentTemplates.dividers.${style}`) }))}
          value={block.style ?? 'solid'}
          disabled={disabled}
          onChange={(event) => set({ style: event.target.value })}
        />
      )
      break
    case 'fiscal':
      body = (
        <p className="text-body text-ink-muted">
          {type.fiscal?.required
            ? t('documentTemplates.inspector.fiscalRequired', { authority: t(`documentTemplates.authorities.${type.fiscal?.authority ?? 'other'}`) })
            : t('documentTemplates.inspector.fiscalOptional')}
        </p>
      )
      break
    case 'row':
      body = (
        <>
          <p className="text-body text-ink-muted">{t(paper === '58mm' || paper === '80mm' ? 'documentTemplates.inspector.rowThermal' : 'documentTemplates.inspector.rowHelp')}</p>
          {canEdit ? (
            <>
              <Select
                label={t('documentTemplates.inspector.childType')}
                options={paletteTypes.filter((entry) => entry !== 'row').map((entry) => ({ value: entry, label: t(`documentTemplates.blocks.${entry}`) }))}
                value={childType}
                onChange={(event) => setChildType(event.target.value)}
              />
              <div className="flex flex-wrap gap-2">
                <Button icon="plus" onClick={() => onAddToRow(block.id, 0, childType)}>
                  {t('documentTemplates.inspector.addLeft')}
                </Button>
                <Button icon="plus" onClick={() => onAddToRow(block.id, 1, childType)}>
                  {t('documentTemplates.inspector.addRight')}
                </Button>
              </div>
            </>
          ) : null}
        </>
      )
      break
    default:
      body = null
  }

  return (
    <section aria-label={t('documentTemplates.inspector.label')} className="flex flex-col gap-4">
      <h3 className="text-h3 text-ink">{blockLabel(t, block)}</h3>
      {body}
    </section>
  )
}
