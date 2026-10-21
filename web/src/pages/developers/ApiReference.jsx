import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { errorMessage } from '@/api/errorMessage'
import { Alert, TextField } from '@/components/ds'
import { apiUrl } from '@/config'
import { usePageTitle } from '@/lib/usePageTitle'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { useOpenApi } from './useOpenApi'
import { CodeBlock } from './DevelopersLayout'
import { contentSchema, curlFor, localized, operationsByTag, resolve, schemaRows, typeLabel } from './openapi'

/** The HTTP method in the code font; POST and the other writes in ink, reads muted. */
function Method({ method }) {
  return (
    <span className={cn('inline-flex min-w-12 justify-center rounded-sm border border-border px-1 font-mono text-caption uppercase', method === 'get' ? 'text-ink-muted' : 'text-ink')}>
      {method}
    </span>
  )
}

function FieldTable({ rows, caption }) {
  const { t } = useTranslation()
  if (rows.length === 0) return null
  return (
    <div className="overflow-x-auto rounded-md border border-border">
      <table className="w-full text-left text-caption">
        <caption className="sr-only">{caption}</caption>
        <thead className="text-ink-muted">
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 font-normal">{t('developers.reference.field')}</th>
            <th scope="col" className="px-3 py-2 font-normal">{t('developers.reference.type')}</th>
            <th scope="col" className="px-3 py-2 font-normal">{t('developers.reference.notes')}</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {rows.map((row) => (
            <tr key={row.name}>
              <td className="px-3 py-2 align-top font-mono text-ink">
                {row.name}
                {row.required ? <span className="ml-2 font-sans text-ink-muted">{t('developers.reference.required')}</span> : null}
              </td>
              <td className="px-3 py-2 align-top font-mono text-ink-muted">{row.type}</td>
              <td className="px-3 py-2 align-top text-ink-muted">
                {row.description}
                {row.values.length ? <span className="block font-mono">{row.values.join(' | ')}</span> : null}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function Operation({ spec, method, path, operation }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const parameters = (operation.parameters ?? []).map((parameter) => resolve(spec, parameter))
  const body = contentSchema(spec, operation.requestBody)
  const responses = Object.entries(operation.responses ?? {})
  const success = responses.find(([status]) => status.startsWith('2'))
  const successSchema = success ? contentSchema(spec, success[1]) : null
  const description = localized(operation, 'description', locale)

  return (
    <article id={operation.operationId} className="flex scroll-mt-6 flex-col gap-4 rounded-lg border border-border bg-surface-200 p-5">
      <header className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-3">
          <Method method={method} />
          <code className="font-mono text-caption break-all text-ink">/api/v1{path}</code>
          {operation.deprecated ? <span className="text-caption text-ink-muted">{t('developers.reference.deprecated')}</span> : null}
        </div>
        <h3 className="text-h3 text-ink">{localized(operation, 'summary', locale)}</h3>
        {description ? <p className="text-ink-muted">{description}</p> : null}
        <p className="text-caption text-ink-muted">
          {t('developers.reference.scope')} <code className="font-mono text-ink">{operation['x-scope']}</code>
        </p>
      </header>

      {parameters.length ? (
        <section className="flex flex-col gap-2">
          <h4 className="text-label text-ink">{t('developers.reference.parameters')}</h4>
          <FieldTable
            caption={t('developers.reference.parameters')}
            rows={parameters.map((parameter) => ({
              name: `${parameter.name} (${parameter.in})`,
              type: typeLabel(parameter.schema),
              required: Boolean(parameter.required),
              description: parameter.description ?? '',
              values: resolve(spec, parameter.schema).enum ?? [],
            }))}
          />
        </section>
      ) : null}

      {body ? (
        <section className="flex flex-col gap-2">
          <h4 className="text-label text-ink">{t('developers.reference.body')}</h4>
          <FieldTable caption={t('developers.reference.body')} rows={schemaRows(spec, body, 1)} />
        </section>
      ) : null}

      {successSchema ? (
        <section className="flex flex-col gap-2">
          <h4 className="text-label text-ink">{t('developers.reference.response', { status: success[0] })}</h4>
          <FieldTable caption={t('developers.reference.response', { status: success[0] })} rows={schemaRows(spec, successSchema, 2)} />
        </section>
      ) : null}

      <section className="flex flex-col gap-2">
        <h4 className="text-label text-ink">{t('developers.reference.statuses')}</h4>
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-caption text-ink-muted">
          {responses.map(([status]) => (
            <li key={status} className="font-mono text-ink">
              {status}
            </li>
          ))}
        </ul>
        <p className="text-caption text-ink-muted">
          <Link to="/developers/errors" className="text-primary hover:text-primary-hover">
            {t('developers.reference.errorsLink')}
          </Link>
        </p>
      </section>

      <CodeBlock label={t('developers.example')}>{curlFor(apiUrl || window.location.origin, method, path)}</CodeBlock>
    </article>
  )
}

/**
 * API-04: the API reference, rendered from the OpenAPI document (Scramble,
 * public routes only) with design-system styles: operations by resource,
 * their parameters, request and response fields, the scope each needs,
 * and a request to copy. Texts in the reader's language.
 */
export function ApiReference() {
  const { t } = useTranslation()
  const locale = useLocale()
  usePageTitle(t('developers.reference.title'))
  const query = useOpenApi()
  const [search, setSearch] = useState('')
  const needle = search.trim().toLowerCase()
  const groups = useMemo(() => {
    const all = query.data ? operationsByTag(query.data) : []
    if (!needle) return all
    return all
      .map((group) => ({
        ...group,
        operations: group.operations.filter(
          ({ path, operation }) => path.toLowerCase().includes(needle) || group.tag.includes(needle) || localized(operation, 'summary', locale).toLowerCase().includes(needle),
        ),
      }))
      .filter((group) => group.operations.length)
  }, [query.data, needle, locale])

  return (
    <>
      <header className="flex flex-col gap-2">
        <h1 className="text-h1 text-ink">{t('developers.reference.title')}</h1>
        <p className="text-body-lg text-ink-muted">{query.data ? localized(query.data.info, 'description', locale) : t('developers.reference.description')}</p>
      </header>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {query.data ? (
        <>
          <p className="text-caption text-ink-muted">
            {t('developers.reference.base')} <code className="font-mono text-ink">{(apiUrl || window.location.origin) + (query.data.servers?.[0]?.url ?? '/api/v1')}</code> ·{' '}
            {t('developers.reference.version', { version: query.data.info?.version ?? '' })} ·{' '}
            <a href={`${apiUrl}/api/v1/developers/openapi.json`} className="text-primary hover:text-primary-hover">
              {t('developers.reference.download')}
            </a>
          </p>
          <TextField label={t('developers.reference.search')} value={search} onChange={(event) => setSearch(event.target.value)} className="max-w-field" />
          {groups.length === 0 ? <p className="text-ink-muted">{t('developers.reference.none')}</p> : null}
          {groups.map((group) => (
            <section key={group.tag} id={group.tag} className="flex flex-col gap-4" aria-labelledby={`tag-${group.tag}`}>
              <h2 id={`tag-${group.tag}`} className="font-mono text-h3 text-ink">
                {group.tag}
              </h2>
              {group.operations.map(({ method, path, operation }) => (
                <Operation key={`${method} ${path}`} spec={query.data} method={method} path={path} operation={operation} />
              ))}
            </section>
          ))}
        </>
      ) : null}
    </>
  )
}
