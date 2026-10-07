import { useTranslation } from 'react-i18next'
import { TextField } from '@/components/ds'

/** A 6-digit one-time code: numeric keyboard on phones, filled from SMS where supported. */
export function CodeField({ value, onChange, error, label }) {
  const { t } = useTranslation()
  return (
    <TextField
      label={label ?? t('auth.fields.code')}
      inputMode="numeric"
      autoComplete="one-time-code"
      maxLength={16}
      value={value}
      onChange={(event) => onChange(event.target.value.replace(/\s/g, ''))}
      error={error}
      required
    />
  )
}
