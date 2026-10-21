import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, Dialog, Money, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatInteger, formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { AppLogo, EventList, ScopeList } from '@/pages/marketplace/marketplaceParts'
import { AssignmentFields } from '../users/AssignmentFields'
import { offeredRow, scopeLabel, useGrantOptions } from '../users/assignments'

const KEY = ['apps', 'installed']

/** An install's state: active, suspended by the platform, needing consent, or uninstalled. */
function InstallState({ installation }) {
  const { t } = useTranslation()
  if (installation.status !== 'active') return <StatusBadge tone="neutral">{t('installedApps.status.uninstalled')}</StatusBadge>
  if (installation.app_status === 'suspended') return <StatusBadge tone="danger">{t('installedApps.status.suspended')}</StatusBadge>
  if (installation.needs_consent) return <StatusBadge tone="warning">{t('installedApps.status.needsConsent')}</StatusBadge>
  return <StatusBadge tone="success">{t('installedApps.status.active')}</StatusBadge>
}

function RoleCell({ installation }) {
  const { t } = useTranslation()
  if (!installation.role) return <span className="text-ink-muted">{t('installedApps.noRole')}</span>
  return (
    <div className="flex flex-col">
      <span className="text-ink">{installation.role.name}</span>
      <span className="text-caption text-ink-muted">{installation.scope.type === 'tenant' ? t('users.scopeTypes.tenant') : scopeLabel(installation.scope.type, { name: installation.scope.name })}</span>
    </div>
  )
}

/** MKT-02: Settings, Installed apps (core.marketplace.view): every app this organisation installed. */
export default function InstalledApps() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const query = useQuery({ queryKey: KEY, queryFn: () => api.get('apps/installed') })
  const columns = [
    {
      key: 'name',
      label: t('installedApps.fields.app'),
      render: (row) => (
        <div className="flex items-center gap-3">
          <AppLogo name={row.name} url={row.logo_url} />
          <span className="font-medium text-ink">{row.name}</span>
        </div>
      ),
    },
    { key: 'status', label: t('installedApps.fields.status'), render: (row) => <InstallState installation={row} /> },
    { key: 'role', label: t('installedApps.fields.actsAs'), wrap: true, render: (row) => <RoleCell installation={row} /> },
    { key: 'installed_at', label: t('installedApps.fields.installed'), render: (row) => formatWhen(row.installed_at, locale) },
  ]

  return (
    <>
      <PageHeader
        title={t('installedApps.title')}
        description={t('installedApps.description')}
        actions={
          <Link to="/marketplace" className="flex h-control items-center px-3 text-label text-primary hover:text-primary-hover">
            {t('installedApps.browse')}
          </Link>
        }
      />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        columns={columns}
        rows={query.data?.data ?? []}
        loading={query.isPending}
        caption={t('installedApps.title')}
        emptyText={t('installedApps.empty')}
        onRowClick={(row) => navigate(`/settings/apps/${row.id}`)}
      />
    </>
  )
}

function ConsentDialog({ installation, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const grants = useGrantOptions()
  const options = { ...grants, rolesFor: (row) => grants.rolesFor(row).filter((role) => !role.is_owner) }
  const [row, setRow] = useState({ role_id: installation.role?.id ?? '', scope_type: installation.scope?.type ?? 'location', scope_id: installation.scope?.type === 'tenant' ? '' : (installation.scope?.id ?? '') })
  const shownRow = offeredRow(row, options)
  const consent = useMutation({
    mutationFn: () =>
      api.post(`apps/installed/${installation.id}/consent`, {
        role_id: shownRow.role_id,
        scope_type: shownRow.scope_type,
        scope_id: shownRow.scope_type === 'tenant' ? null : shownRow.scope_id,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: KEY })
      onClose()
    },
  })
  const errors = formErrors(consent.error, ['role_id', 'scope_type', 'scope_id'])

  return (
    <Dialog
      open
      size="lg"
      title={t('installedApps.consent.title', { name: installation.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={consent.isPending} disabled={!shownRow.role_id} onClick={() => consent.mutate()}>
            {t('installedApps.consent.confirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {installation.new_scopes.length > 0 ? (
          <section className="flex flex-col gap-2">
            <h3 className="text-label text-ink">{t('installedApps.consent.newScopes')}</h3>
            <ScopeList scopes={installation.new_scopes} />
          </section>
        ) : null}
        <p className="text-caption text-ink-muted">{t('marketplace.consent.actsAsHelp')}</p>
        <AssignmentFields index={0} value={shownRow} onChange={setRow} options={options} errors={errors.fields} />
        {consent.error && !errors.fields.role_id ? <Alert tone="danger" title={errorMessage(consent.error)} /> : null}
      </div>
    </Dialog>
  )
}

function UninstallDialog({ installation, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const uninstall = useMutation({
    mutationFn: () => api.post(`apps/installed/${installation.id}/uninstall`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: KEY })
      onClose()
    },
  })

  return (
    <Dialog
      open
      title={t('installedApps.uninstall.title', { name: installation.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="danger" loading={uninstall.isPending} onClick={() => uninstall.mutate()}>
            {t('installedApps.uninstall.confirm')}
          </Button>
        </>
      }
    >
      {uninstall.error ? <Alert tone="danger" title={errorMessage(uninstall.error)} /> : null}
      <p>{t('installedApps.uninstall.body')}</p>
    </Dialog>
  )
}

/**
 * MKT-02: one installed app: what it was given (in plain words), the role
 * it acts as, its events and price, its API use; consent to a newer
 * version, change its role, or uninstall it (core.marketplace.install).
 */
export function InstalledApp() {
  const { t, i18n } = useTranslation()
  const locale = useLocale()
  const { installationId } = useParams()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...KEY, installationId, i18n.resolvedLanguage], queryFn: () => api.get(`apps/installed/${installationId}`) })
  const installation = query.data?.data

  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!installation) return <p className="text-ink-muted">{t('common.loading')}</p>
  const active = installation.status === 'active'

  return (
    <>
      <PageHeader
        title={installation.name}
        description={t('installedApps.version', { version: installation.version })}
        actions={
          active ? (
            <>
              <Button onClick={() => setDialog('consent')}>{installation.needs_consent ? t('installedApps.consent.review') : t('installedApps.consent.changeRole')}</Button>
              <Button variant="danger" onClick={() => setDialog('uninstall')}>
                {t('installedApps.uninstall.action')}
              </Button>
            </>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        {installation.app_status === 'suspended' ? <Alert tone="danger" title={t('installedApps.suspendedTitle')}>{t('installedApps.suspendedText')}</Alert> : null}
        {installation.needs_consent && active ? (
          <Alert tone="warning" title={t('installedApps.needsConsentTitle', { version: installation.listed_version })}>
            {t('installedApps.needsConsentText')}
          </Alert>
        ) : null}
        <div className="grid gap-5 lg:grid-cols-2">
          <Card title={t('installedApps.given')}>
            <ScopeList scopes={installation.scopes} />
          </Card>
          <Card title={t('installedApps.details')}>
            <dl className="flex flex-col gap-3 text-body">
              <div className="flex flex-col">
                <dt className="text-caption text-ink-muted">{t('installedApps.fields.status')}</dt>
                <dd>
                  <InstallState installation={installation} />
                </dd>
              </div>
              <div className="flex flex-col">
                <dt className="text-caption text-ink-muted">{t('installedApps.fields.actsAs')}</dt>
                <dd>
                  <RoleCell installation={installation} />
                </dd>
              </div>
              <div className="flex flex-col">
                <dt className="text-caption text-ink-muted">{t('marketplace.price')}</dt>
                <dd>
                  {installation.price ? (
                    <span className="inline-flex items-baseline gap-1">
                      <Money amount={installation.price.monthly_minor} currency={installation.price.currency} locale={locale} />
                      <span className="text-caption text-ink-muted">{t('marketplace.perMonth')}</span>
                    </span>
                  ) : (
                    <span className="text-ink-muted">{t('marketplace.free')}</span>
                  )}
                </dd>
              </div>
              <div className="flex flex-col">
                <dt className="text-caption text-ink-muted">{t('installedApps.fields.installed')}</dt>
                <dd className="text-ink">{t('installedApps.installedBy', { when: formatWhen(installation.installed_at, locale), name: installation.installed_by ?? '' })}</dd>
              </div>
              <div className="flex flex-col">
                <dt className="text-caption text-ink-muted">{t('installedApps.usage')}</dt>
                <dd className="tabular-nums text-ink">{t('installedApps.requests', { count: installation.usage.total, total: formatInteger(installation.usage.total, locale) })}</dd>
              </div>
            </dl>
          </Card>
        </div>
        <Card title={t('marketplace.events')}>
          <EventList events={installation.webhook_events} />
        </Card>
      </div>
      {dialog === 'consent' ? <ConsentDialog installation={installation} onClose={() => setDialog(null)} /> : null}
      {dialog === 'uninstall' ? <UninstallDialog installation={installation} onClose={() => setDialog(null)} /> : null}
    </>
  )
}
