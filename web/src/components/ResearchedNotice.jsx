import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Alert, StatusBadge } from '@/components/ds'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

/**
 * CP-01: shown wherever a run, tax line or leave record used a country
 * pack value that comes from public research and is not yet confirmed.
 * Links to the page that lists each value's source and confidence.
 */
export function ResearchedNotice({ to, className }) {
  const { t } = useTranslation()
  return (
    <Alert
      tone="warning"
      title={t('packs.researched.title')}
      className={className}
      action={
        to ? (
          <Link to={to} className="font-medium text-primary hover:text-primary-hover">
            {t('packs.researched.link')}
          </Link>
        ) : null
      }
    >
      {t('packs.researched.body')}
    </Alert>
  )
}

const host = (url) => {
  try {
    return new URL(url).hostname.replace(/^www\./, '')
  } catch {
    return url
  }
}

/**
 * CP-01: a pack value's status, with its research confidence, date and
 * sources when it comes from public research.
 */
export function ResearchStatus({ status, confidence, researchedOn, source = [], assumed = [] }) {
  const { t } = useTranslation()
  const locale = useLocale()
  if (status === 'researched') {
    return (
      <span className="flex flex-col gap-1">
        <StatusBadge tone="warning">{t('packs.status.researched')}</StatusBadge>
        <span className="text-caption text-ink-muted">
          {t('packs.confidence', { level: t(`packs.confidenceLevels.${confidence}`, { defaultValue: confidence ?? '' }) })}
          {researchedOn ? ` · ${t('packs.researchedOn', { date: formatCalendarDate(researchedOn, locale) })}` : null}
        </span>
        {assumed.length ? <span className="text-caption text-ink-muted">{t('packs.assumed', { fields: assumed.join(', ') })}</span> : null}
        {source.length ? (
          <span className="flex flex-wrap gap-x-2 text-caption">
            {source.map((url) => (
              <a key={url} href={url} target="_blank" rel="noreferrer" className="text-primary hover:text-primary-hover">
                {host(url)}
              </a>
            ))}
          </span>
        ) : null}
      </span>
    )
  }
  if (status === 'confirmed') return <StatusBadge tone="success">{t('packs.status.confirmed')}</StatusBadge>
  return (
    <span className="flex flex-col gap-1">
      <StatusBadge tone="danger">{t('packs.status.needs_confirmation')}</StatusBadge>
      {confidence === 'low' ? <span className="text-caption text-ink-muted">{t('packs.lowConfidence')}</span> : null}
    </span>
  )
}
