import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Alert, Button } from '@/components/ds'

/**
 * MD-06: records that may be the same as the one just saved. A warning
 * with links to each match, never a block: the record is already saved.
 * `kind` is "item" or "party"; `linkTo(match)` gives each match's page.
 */
export function DuplicatesAlert({ kind, matches, linkTo, onDismiss }) {
  const { t } = useTranslation()
  if (!matches?.length) return null
  return (
    <Alert
      tone="warning"
      title={t(`duplicates.title.${kind}`, { count: matches.length })}
      action={
        onDismiss ? (
          <Button variant="ghost" onClick={onDismiss}>
            {t('duplicates.dismiss')}
          </Button>
        ) : null
      }
    >
      <p>{t(`duplicates.text.${kind}`)}</p>
      <ul className="mt-1 flex flex-col gap-1">
        {matches.map((match) => (
          <li key={match.id}>
            <Link to={linkTo(match)} className="text-primary underline-offset-2 hover:text-primary-hover hover:underline">
              {match.code ? `${match.code} · ${match.name}` : match.name}
            </Link>{' '}
            <span className="text-ink-muted">{t(`duplicates.reasons.${match.reason}`, { defaultValue: t('duplicates.reasons.name') })}</span>
          </li>
        ))}
      </ul>
    </Alert>
  )
}
