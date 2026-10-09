import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Icon, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'

/** Where the organisation-wide template of a type stands: published, a draft only, or the built-in layout. */
function TemplateState({ document }) {
  const { t } = useTranslation()
  if (document?.published) return <StatusBadge tone="success">{t('documentTemplates.state.published', { version: document.published.version })}</StatusBadge>
  if (document?.draft) return <StatusBadge tone="info">{t('documentTemplates.state.draft')}</StatusBadge>
  return <StatusBadge tone="neutral">{t('documentTemplates.state.default')}</StatusBadge>
}

/**
 * TPL-01: the document types a template can be designed for, with their
 * paper, whether the preview uses sample data (a module not built yet),
 * and whether the organisation published its own layout.
 */
export default function DocumentTemplates() {
  const { t } = useTranslation()
  const types = useQuery({ queryKey: ['templates', 'types', 'tenant', null], queryFn: () => api.get('templates/types?scope_type=tenant') })
  const documents = useQuery({ queryKey: ['config', 'template', 'list', 'all'], queryFn: () => api.get('config/template?per_page=200') })
  const list = types.data?.data ?? []
  const saved = documents.data?.data ?? []

  return (
    <>
      <PageHeader title={t('documentTemplates.title')} description={t('documentTemplates.description')} />
      {types.isError ? <Alert tone="danger" title={errorMessage(types.error)} action={<Button onClick={() => types.refetch()}>{t('common.retry')}</Button>} /> : null}
      {types.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {types.isSuccess && list.length === 0 ? <p className="text-ink-muted">{t('documentTemplates.empty')}</p> : null}
      {list.length > 0 ? (
        <Card>
          <ul aria-label={t('documentTemplates.types')} className="flex flex-col divide-y divide-border">
            {list.map((type) => {
              const own = saved.filter((document) => document.key === type.key)
              const tenant = own.find((document) => document.scope?.type === 'tenant')
              const places = own.length - (tenant ? 1 : 0)
              return (
                <li key={type.key} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div className="flex min-w-0 flex-col gap-1">
                    <Link to={`/settings/document-templates/${encodeURIComponent(type.key)}`} className="w-fit text-body font-medium text-primary hover:text-primary-hover">
                      {type.label}
                    </Link>
                    <span className="text-caption text-ink-muted">
                      {[
                        t(`documentTemplates.papers.${type.paper}`, { defaultValue: type.paper }),
                        type.live ? null : t('documentTemplates.sampleData'),
                        type.locked?.includes('fiscal') ? t('documentTemplates.fiscalLocked', { authority: t(`documentTemplates.authorities.${type.fiscal?.authority ?? 'other'}`) }) : null,
                        places > 0 ? t('documentTemplates.places', { count: places }) : null,
                      ]
                        .filter(Boolean)
                        .join(' · ')}
                    </span>
                  </div>
                  <span className="flex items-center gap-3">
                    {documents.isSuccess ? <TemplateState document={tenant} /> : null}
                    <Icon name="chevronRight" className="text-ink-muted" />
                  </span>
                </li>
              )
            })}
          </ul>
        </Card>
      ) : null}
    </>
  )
}
