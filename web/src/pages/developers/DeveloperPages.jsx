import { useTranslation } from 'react-i18next'
import { Link, Navigate, useParams } from 'react-router'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Card } from '@/components/ds'
import { usePageTitle } from '@/lib/usePageTitle'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { CodeBlock } from './DevelopersLayout'
import { CHANGELOG, GUIDES, guideById } from './guides'
import { deprecatedOperations, localized } from './openapi'
import { useOpenApi } from './useOpenApi'

function Heading({ title, description }) {
  usePageTitle(title)
  return (
    <header className="flex flex-col gap-2">
      <h1 className="text-h1 text-ink">{title}</h1>
      {description ? <p className="text-body-lg text-ink-muted">{description}</p> : null}
    </header>
  )
}

/** API-04: the portal's home: what the API is, a quick start and the guides. */
export function DevelopersHome() {
  const { t } = useTranslation()

  return (
    <>
      <Heading title={t('developers.home.title')} description={t('developers.home.description', { name: t('app.name') })} />
      <Card title={t('developers.home.quickstart.title')}>
        <ol className="flex list-decimal flex-col gap-2 pl-5 text-ink">
          <li>{t('developers.home.quickstart.step1')}</li>
          <li>{t('developers.home.quickstart.step2')}</li>
          <li>{t('developers.home.quickstart.step3')}</li>
        </ol>
        <div className="mt-4 flex flex-wrap gap-4">
          <Link to="/welcome?sandbox=1" className="text-label text-primary hover:text-primary-hover">
            {t('developers.home.sandbox')}
          </Link>
          <Link to="/developers/reference" className="text-label text-primary hover:text-primary-hover">
            {t('developers.home.reference')}
          </Link>
        </div>
      </Card>
      <div className="grid gap-4 sm:grid-cols-2">
        {GUIDES.map((guide) => (
          <Link key={guide.id} to={`/developers/guides/${guide.id}`} className="flex flex-col gap-1 rounded-lg border border-border bg-surface-200 p-4 hover:bg-surface-300">
            <span className="font-medium text-ink">{t(`developers.guides.${guide.id}.title`)}</span>
            <span className="text-caption text-ink-muted">{t(`developers.guides.${guide.id}.summary`)}</span>
          </Link>
        ))}
      </div>
    </>
  )
}

/** One guide: its paragraphs, its example and, for errors, the list of codes (anchors the API's `type` links to). */
export function Guide({ id: fixedId }) {
  const { t } = useTranslation()
  const params = useParams()
  const guide = guideById(fixedId ?? params.guide)

  if (!guide) return <Navigate to="/developers" replace />

  return (
    <>
      <Heading title={t(`developers.guides.${guide.id}.title`)} description={t(`developers.guides.${guide.id}.summary`)} />
      <article className="flex flex-col gap-4">
        {guide.paragraphs.map((key) => (
          <p key={key} className="text-ink">
            {t(`developers.guides.${guide.id}.${key}`)}
          </p>
        ))}
        {guide.code ? <CodeBlock label={t('developers.example')}>{guide.code}</CodeBlock> : null}
        {guide.errors ? (
          <dl className="flex flex-col divide-y divide-border rounded-lg border border-border bg-surface-200">
            {guide.errors.map((code) => (
              <div key={code} id={code} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:gap-6">
                <dt className="font-mono text-caption text-ink sm:w-sidebar">{code}</dt>
                <dd className="text-ink-muted">{t(`developers.errorCodes.${code}`)}</dd>
              </div>
            ))}
          </dl>
        ) : null}
      </article>
    </>
  )
}

/** API-04: what changed, newest first, and the operations announced for removal (Sunset). */
export function Changelog() {
  const { t } = useTranslation()
  const locale = useLocale()
  const spec = useOpenApi()
  const deprecated = spec.data ? deprecatedOperations(spec.data) : []

  return (
    <>
      <Heading title={t('developers.changelog.title')} description={t('developers.changelog.description')} />
      {spec.isError ? <Alert tone="danger" title={errorMessage(spec.error)} /> : null}
      <Card title={t('developers.changelog.deprecated')}>
        {deprecated.length === 0 ? (
          <p className="text-ink-muted">{t('developers.changelog.noDeprecations')}</p>
        ) : (
          <ul className="flex flex-col divide-y divide-border">
            {deprecated.map(({ method, path, operation }) => (
              <li key={`${method} ${path}`} className="flex flex-wrap justify-between gap-2 py-2">
                <code className="font-mono text-caption text-ink">
                  {method.toUpperCase()} {path}
                </code>
                <span className="text-caption text-ink-muted">
                  {operation['x-sunset'] ? t('developers.changelog.sunset', { date: formatCalendarDate(operation['x-sunset'], locale) }) : localized(operation, 'summary', locale)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {CHANGELOG.map((entry) => (
        <section key={entry.id} id={entry.id} className="flex flex-col gap-2">
          <h2 className="text-h2 text-ink">{formatCalendarDate(entry.date, locale)}</h2>
          <ul className="flex list-disc flex-col gap-1 pl-5 text-ink">
            {entry.items.map((item) => (
              <li key={item}>{t(`developers.changelog.entries.${entry.id}.${item}`)}</li>
            ))}
          </ul>
        </section>
      ))}
    </>
  )
}
