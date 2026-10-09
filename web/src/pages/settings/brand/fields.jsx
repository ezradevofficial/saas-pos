import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Button, StatusBadge, TextField } from '@/components/ds'
import { Field } from '@/components/ds/Field'
import { cn } from '@/lib/utils'

const HEX = /^#[0-9a-fA-F]{6}$/

/** One of a few choices, as radio cards (preset, sidebar, corners). */
export function ChoiceGroup({ label, name, value, options, onChange, disabled, columns = 'sm:grid-cols-2' }) {
  const id = useId()
  return (
    <fieldset className="flex flex-col gap-tight" disabled={disabled}>
      <legend id={id} className="pb-1 text-label text-ink">
        {label}
      </legend>
      <div role="radiogroup" aria-labelledby={id} className={cn('grid gap-2', columns)}>
        {options.map((option) => (
          <label
            key={option.value}
            className={cn(
              'flex cursor-pointer flex-col gap-1 rounded-md border border-border bg-surface-200 px-3 py-2 transition-colors hover:border-border-strong',
              'has-checked:border-primary has-checked:bg-primary-tint has-disabled:cursor-not-allowed',
              'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-focus has-focus-visible:outline-solid',
            )}
          >
            <span className="flex items-center gap-2">
              <input
                type="radio"
                name={name}
                value={option.value}
                checked={value === option.value}
                onChange={() => onChange(option.value)}
                className="size-4 accent-primary focus-visible:outline-0"
              />
              <span className="font-medium text-ink">{option.label}</span>
            </span>
            {option.description ? <span className="pl-6 text-caption text-ink-muted">{option.description}</span> : null}
          </label>
        ))}
      </div>
    </fieldset>
  )
}

/** A swatch of a derived value: the colour and its hex, never editable. */
function Swatch({ label, value }) {
  return (
    <span className="flex items-center gap-2 text-caption text-ink-muted">
      {/* A colour the tenant chose or we derived at runtime: the one place inline style is allowed. */}
      <span aria-hidden="true" className="size-4 shrink-0 rounded-sm border border-border-strong" style={{ backgroundColor: value }} />
      <span>
        {label} <span className="font-mono text-ink">{value}</span>
      </span>
    </span>
  )
}

/**
 * BR-02, BR-03: a brand colour: a colour picker with a hex field, the
 * values derived from it (hover, tint, text on it), and a contrast meter
 * per pair in light and dark mode with a plain explanation when one fails.
 */
export function ColourField({ field, label, help, value, presetValue, derived, checks, onChange, disabled }) {
  const { t } = useTranslation()
  const [text, setText] = useState(value ?? '')
  const [seen, setSeen] = useState(value)
  if (seen !== value) {
    setSeen(value)
    if ((value ?? '') !== text && (value === null || HEX.test(value))) setText(value ?? '')
  }
  const shown = value ?? presetValue
  const invalid = text !== '' && !HEX.test(text)
  const failing = checks.filter((check) => !check.passes)

  const type = (next) => {
    setText(next)
    if (next === '') onChange(null)
    else if (HEX.test(next)) onChange(next.toLowerCase())
  }

  return (
    <div className="flex flex-col gap-3" data-testid={`colour-${field}`}>
      <div className="flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-tight text-label text-ink">
          <span className="sr-only">{t('brand.colours.pick', { name: label })}</span>
          <input
            type="color"
            value={shown}
            disabled={disabled}
            onChange={(event) => type(event.target.value)}
            className="h-control w-12 cursor-pointer rounded-md border border-border-strong bg-surface-200 p-1 disabled:cursor-not-allowed"
          />
        </label>
        <TextField
          label={label}
          help={invalid ? undefined : help}
          error={invalid ? t('brand.colours.invalid') : undefined}
          placeholder={presetValue}
          value={text}
          disabled={disabled}
          onChange={(event) => type(event.target.value.trim())}
          className="min-w-0 flex-1 max-w-field font-mono"
        />
        {value !== null && !disabled ? (
          <Button variant="ghost" onClick={() => type('')}>
            {t('brand.colours.usePreset')}
          </Button>
        ) : null}
      </div>

      <div className="flex flex-wrap gap-x-5 gap-y-2">
        <Swatch label={t('brand.colours.derived.hover')} value={derived.hover} />
        {derived.tint ? <Swatch label={t('brand.colours.derived.tint')} value={derived.tint} /> : null}
        <Swatch label={t('brand.colours.derived.on')} value={derived.on} />
        <Swatch label={t('brand.colours.derived.dark')} value={derived.dark} />
      </div>

      <ul className="flex flex-col gap-1" aria-label={t('brand.contrast.label', { name: label })}>
        {checks.map((check) => (
          <li key={`${check.mode}-${check.pair}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 text-caption">
            <StatusBadge tone={check.passes ? 'success' : 'danger'}>{check.passes ? t('brand.contrast.passes') : t('brand.contrast.fails')}</StatusBadge>
            <span className="text-ink">
              {t(`brand.contrast.pairs.${check.pair}`)} · {t(`brand.contrast.modes.${check.mode}`)}
            </span>
            <span className="text-ink-muted tabular-nums">{t('brand.contrast.ratio', { ratio: check.ratio.toFixed(2), required: check.required })}</span>
          </li>
        ))}
      </ul>
      {failing.length > 0 ? (
        <p role="alert" className="text-caption text-danger">
          {t('brand.contrast.explain', { name: label })}
        </p>
      ) : null}
    </div>
  )
}

/**
 * BR-02, BR-04: a logo, favicon or sign-in background: the image in use,
 * Upload (JPEG, PNG or WebP; the API checks type and size) and Remove.
 */
export function AssetField({ label, help, kind, value, url, onChange, disabled }) {
  const { t } = useTranslation()
  const input = useRef(null)
  const id = useId()
  const queryClient = useQueryClient()
  const upload = useMutation({
    mutationFn: (file) => {
      const form = new FormData()
      form.append('kind', kind)
      form.append('file', file)
      return api.upload('branding/assets', form)
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['branding', 'assets'] })
      onChange(response.data.id)
    },
  })

  return (
    <Field id={id} label={label} help={upload.error ? undefined : help} error={upload.error ? errorMessage(upload.error) : undefined}>
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-surface-300">
          {url ? <img src={url} alt={t('brand.assets.current', { name: label })} className="max-h-full max-w-full object-contain" /> : null}
        </div>
        <input
          ref={input}
          id={id}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          className="sr-only"
          disabled={disabled}
          onChange={(event) => {
            const file = event.target.files?.[0]
            if (file) upload.mutate(file)
            event.target.value = ''
          }}
        />
        <Button icon="image" disabled={disabled} loading={upload.isPending} onClick={() => input.current?.click()}>
          {value ? t('brand.assets.replace') : t('brand.assets.upload')}
        </Button>
        {value && !disabled ? (
          <Button variant="ghost" onClick={() => onChange(null)}>
            {t('brand.assets.remove')}
          </Button>
        ) : null}
      </div>
    </Field>
  )
}
