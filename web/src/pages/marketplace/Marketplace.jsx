import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Card, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { CATEGORIES } from './marketplaceData'
import { AppLogo, AppPrice, EventList, ScopeList } from './marketplaceParts'

/** The state of an app in the listing: installed, not set up yet (a connector), or nothing to say. */
function AppState({ app }) {
  const { t } = useTranslation()
  if (app.installed) return <StatusBadge tone="success">{t('marketplace.installed')}</StatusBadge>
  if (!app.configured) return <StatusBadge tone="neutral">{t('marketplace.notConfigured')}</StatusBadge>
  return null
}

/**
 * MKT-01, MKT-04: the marketplace (core.marketplace.view): listed apps,
 * the first-party connectors among them, by name and category.
 */
export default function Marketplace() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const params = new URLSearchParams()
  if (search.trim()) params.set('search', search.trim())
  if (category) params.set('category', category)
  const query = useQuery({ queryKey: ['marketplace', 'apps', search.trim(), category], queryFn: () => api.get(`marketplace/apps?${params}`) })
  const apps = query.data?.data ?? []

  return (
    <>
      <PageHeader
        title={t('marketplace.title')}
        description={t('marketplace.description')}
        actions={
          <Link to="/settings/apps" className="flex h-control items-center px-3 text-label text-primary hover:text-primary-hover">
            {t('marketplace.installedLink')}
          </Link>
        }
      />
      <div className="mb-5 flex flex-wrap gap-4">
        <TextField label={t('marketplace.search')} value={search} onChange={(event) => setSearch(event.target.value)} className="max-w-field" type="search" />
        <Select
          label={t('marketplace.category')}
          options={[{ value: '', label: t('marketplace.allCategories') }, ...CATEGORIES.map((key) => ({ value: key, label: t(`marketplace.categories.${key}`) }))]}
          value={category}
          onChange={(event) => setCategory(event.target.value)}
          className="max-w-field"
        />
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {!query.isPending && !query.isError && apps.length === 0 ? <p className="text-ink-muted">{t('marketplace.empty')}</p> : null}
      <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {apps.map((app) => (
          <li key={app.id}>
            <Link to={`/marketplace/${app.id}`} className="flex h-full flex-col gap-3 rounded-lg border border-border bg-surface-200 p-4 hover:border-border-strong focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-focus">
              <div className="flex items-start gap-3">
                <AppLogo name={app.name} url={app.logo_url} />
                <div className="flex min-w-0 flex-col">
                  <span className="font-medium text-ink">{app.name}</span>
                  <span className="text-caption text-ink-muted">{app.developer.first_party ? t('marketplace.byPlatform') : t('marketplace.by', { name: app.developer.name })}</span>
                </div>
              </div>
              {app.tagline ? <p className="text-body text-ink-muted">{app.tagline}</p> : null}
              <div className="mt-auto flex flex-wrap items-center justify-between gap-2">
                <AppPrice pricing={app.pricing} />
                <AppState app={app} />
              </div>
            </Link>
          </li>
        ))}
      </ul>
    </>
  )
}

/**
 * MKT-01, MKT-02, MKT-04: an app's page: what it does, what it may see and
 * change (in plain words), the events it receives, its price; the install
 * starts from the app (it sends you to the consent screen), and a
 * connector says what it still lacks.
 */
export function MarketplaceApp() {
  const { t } = useTranslation()
  const { appId } = useParams()
  const query = useQuery({ queryKey: ['marketplace', 'app', appId], queryFn: () => api.get(`marketplace/apps/${appId}`) })
  const connectors = useQuery({ queryKey: ['marketplace', 'connectors'], queryFn: () => api.get('marketplace/connectors'), enabled: Boolean(query.data?.data?.developer?.first_party) })
  const app = query.data?.data
  const connector = connectors.data?.data?.find((row) => row.app_id === appId)

  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!app) return <p className="text-ink-muted">{t('common.loading')}</p>

  return (
    <>
      <PageHeader title={app.name} description={app.tagline} />
      <div className="grid gap-5 lg:grid-cols-3">
        <div className="flex flex-col gap-5 lg:col-span-2">
          <Card title={t('marketplace.about')}>
            <div className="flex flex-col gap-3">
              <div className="flex items-center gap-3">
                <AppLogo name={app.name} url={app.logo_url} size="lg" />
                <span className="text-caption text-ink-muted">{app.developer.first_party ? t('marketplace.byPlatform') : t('marketplace.by', { name: app.developer.name })}</span>
              </div>
              <p className="whitespace-pre-line text-body text-ink">{app.description}</p>
            </div>
          </Card>
          <Card title={t('marketplace.permissions')} subtitle={t('marketplace.permissionsText')}>
            <ScopeList scopes={app.scopes} />
          </Card>
          <Card title={t('marketplace.events')} subtitle={t('marketplace.eventsText')}>
            <EventList events={app.webhook_events} />
          </Card>
        </div>
        <div className="flex flex-col gap-5">
          <Card title={t('marketplace.price')}>
            <div className="flex flex-col gap-2">
              <AppPrice pricing={app.pricing} />
              {app.pricing?.model === 'paid' ? <p className="text-caption text-ink-muted">{t('marketplace.billedWith')}</p> : null}
            </div>
          </Card>
          <Card title={t('marketplace.install')}>
            <div className="flex flex-col gap-3">
              {app.installation ? (
                <>
                  <StatusBadge tone="success">{t('marketplace.installed')}</StatusBadge>
                  <Link to={`/settings/apps/${app.installation.id}`} className="text-label text-primary hover:text-primary-hover">
                    {t('marketplace.manageInstall')}
                  </Link>
                </>
              ) : !app.configured ? (
                <Alert tone="info" title={t('marketplace.notConfiguredTitle')}>
                  {t('marketplace.notConfiguredText')}
                </Alert>
              ) : (
                <>
                  <p className="text-body text-ink-muted">{t('marketplace.installText')}</p>
                  {app.website ? (
                    <a href={app.website} target="_blank" rel="noreferrer noopener" className="text-label text-primary hover:text-primary-hover">
                      {t('marketplace.openApp')}
                    </a>
                  ) : null}
                </>
              )}
              {connector && connector.missing.length > 0 ? (
                <ul className="flex flex-col gap-1">
                  {connector.missing.map((item) => (
                    <li key={item} className="text-caption text-ink-muted">
                      {t(`marketplace.missing.${item}`)}
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          </Card>
          <Card title={t('marketplace.support')}>
            <dl className="flex flex-col gap-2 text-body">
              {app.website ? (
                <div className="flex flex-col">
                  <dt className="text-caption text-ink-muted">{t('marketplace.website')}</dt>
                  <dd className="break-all text-ink">{app.website}</dd>
                </div>
              ) : null}
              {app.support_email ? (
                <div className="flex flex-col">
                  <dt className="text-caption text-ink-muted">{t('marketplace.supportEmail')}</dt>
                  <dd className="break-all text-ink">{app.support_email}</dd>
                </div>
              ) : null}
              {app.privacy_url ? (
                <div className="flex flex-col">
                  <dt className="text-caption text-ink-muted">{t('marketplace.privacy')}</dt>
                  <dd className="break-all text-ink">{app.privacy_url}</dd>
                </div>
              ) : null}
              <div className="flex flex-col">
                <dt className="text-caption text-ink-muted">{t('marketplace.version')}</dt>
                <dd className="tabular-nums text-ink">{app.version}</dd>
              </div>
            </dl>
          </Card>
        </div>
      </div>
    </>
  )
}
