import { useTranslation } from 'react-i18next'
import { Money } from '@/components/ds'
import { cn } from '@/lib/utils'
import { DEVICE_CLAIM_FLAGS, flagCodes, flagLabel, methodLabel } from './posData'
import { useAmountText } from './useAmountText'

/** An amount as the API sends it ({amount_minor, currency}), currency code first; a dash when absent. */
export function Amount({ value, tone, className }) {
  if (!value) return <span className="text-ink-muted">—</span>
  return <Money amount={value.amount_minor} currency={value.currency} tone={tone} className={className} />
}

/** POS-09: a record's flags as quiet chips (a hairline border and a word, never a colour). */
export function FlagChips({ flags, className }) {
  const { t } = useTranslation()
  const codes = flagCodes(flags)
  if (!codes.length) return null
  return (
    <ul aria-label={t('pos.flags.label')} className={cn('flex flex-wrap gap-1', className)}>
      {codes.map((code) => (
        <li key={code} className="rounded-md border border-border px-2 text-caption whitespace-nowrap text-ink-muted">
          {flagLabel(t, code)}
        </li>
      ))}
    </ul>
  )
}

/** AUTH-08: the approval came from the till (an offline override the device claims). */
export function DeviceClaimNote({ flags }) {
  const { t } = useTranslation()
  const codes = flagCodes(flags)
  const claim = DEVICE_CLAIM_FLAGS.find((code) => codes.includes(code))
  if (!claim) return null
  return <p className="text-caption text-ink-muted">{t(`pos.held.deviceClaim.${claim}`)}</p>
}

/** The tenders of a sale in one line: "Cash KES 1,000.00 · Mobile money KES 125.00". */
export function TendersText({ tenders }) {
  const { t } = useTranslation()
  const amount = useAmountText()
  if (!tenders?.length) return <span className="text-ink-muted">—</span>
  return <span className="tabular-nums">{tenders.map((tender) => `${methodLabel(t, tender.method_type)} ${amount(tender.amount)}`).join(' · ')}</span>
}

/** A label and its value, for detail panels. */
export function Detail({ label, children }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <dt className="text-caption text-ink-muted">{label}</dt>
      <dd className="text-body text-ink">{children}</dd>
    </div>
  )
}
