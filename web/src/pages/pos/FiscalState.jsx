import { useTranslation } from 'react-i18next'
import { StatusBadge } from '@/components/ds'

const TONES = { pending: 'warning', accepted: 'success', rejected: 'danger' }

/**
 * POS-10: one document's state with the tax authority, as a dot and a word:
 * not sent (the company does not transmit), pending, accepted (with the
 * authority's invoice number) or rejected.
 */
export function FiscalState({ fiscal, transmits }) {
  const { t } = useTranslation()
  if (!fiscal) return <StatusBadge tone="neutral">{transmits ? t('pos.fiscal.statuses.pending') : t('pos.fiscal.notTransmitted')}</StatusBadge>
  return (
    <span className="flex flex-col gap-1">
      <StatusBadge tone={TONES[fiscal.status] ?? 'neutral'}>{t(`pos.fiscal.statuses.${fiscal.status}`, { defaultValue: fiscal.status })}</StatusBadge>
      {fiscal.invoice_number != null ? <span className="text-caption text-ink-muted tabular-nums">{t('pos.fiscal.invoice', { number: fiscal.invoice_number })}</span> : null}
    </span>
  )
}

/** The authority's references for an accepted sale (receipt signature, internal data, QR content), as stored. */
export function FiscalReferences({ fiscal }) {
  const { t } = useTranslation()
  const authority = fiscal?.authority ?? {}
  const rows = ['receipt_number', 'receipt_signature', 'internal_data', 'control_unit_id', 'mrc_no', 'qr'].filter((key) => authority[key])
  if (fiscal?.status !== 'accepted' || !rows.length) return null
  return (
    <dl className="flex flex-col gap-2">
      {rows.map((key) => (
        <div key={key} className="flex flex-col gap-1">
          <dt className="text-caption text-ink-muted">{t(`pos.fiscal.authority.${key}`)}</dt>
          <dd className="font-mono text-caption break-all text-ink">{authority[key]}</dd>
        </div>
      ))}
    </dl>
  )
}
