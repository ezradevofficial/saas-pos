import { useTranslation } from 'react-i18next'
import { MoneyInput, Select, TextField } from '@/components/ds'
import { CHARGE_KINDS } from './hotelData'

/**
 * A cancellation or no-show charge (HOT-15): none, a number of nights, a
 * percent of the stay or a fixed amount. `inherit` offers "use the company
 * rule" for a rate plan's own override. `onChange({ kind, value })`.
 */
export function ChargeRule({ legend, kind, value, currency, onChange, inherit = false, disabled = false, error }) {
  const { t } = useTranslation()
  const options = [...(inherit ? [{ value: '', label: t('hotel.rule.inherit') }] : []), ...CHARGE_KINDS.map((entry) => ({ value: entry, label: t(`hotel.rule.${entry}`) }))]
  const change = (next, nextValue) => onChange({ kind: next, value: nextValue })
  return (
    <fieldset className="grid gap-3 sm:grid-cols-2">
      <legend className="mb-1 text-label text-ink">{legend}</legend>
      <Select label={t('hotel.rule.kind')} options={options} value={kind} disabled={disabled} onChange={(event) => change(event.target.value, event.target.value === 'fixed' ? '' : '0')} />
      {kind === 'nights' || kind === 'percent' ? (
        <TextField
          label={kind === 'nights' ? t('hotel.rule.nightsCount') : t('hotel.rule.percentOfStay')}
          inputMode="numeric"
          suffix={kind === 'percent' ? '%' : undefined}
          value={value ?? ''}
          disabled={disabled}
          error={error}
          onChange={(event) => change(kind, event.target.value)}
        />
      ) : null}
      {kind === 'fixed' ? <MoneyInput label={t('hotel.rule.fixedAmount')} currency={currency} value={value ?? ''} disabled={disabled} error={error} onChange={(next) => change(kind, next ?? '')} /> : null}
    </fieldset>
  )
}
