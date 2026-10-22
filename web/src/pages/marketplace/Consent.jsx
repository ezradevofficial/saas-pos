import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button } from '@/components/ds'
import { AssignmentFields } from '@/pages/settings/users/AssignmentFields'
import { emptyAssignment, offeredRow, useGrantOptions } from '@/pages/settings/users/assignments'
import { leaveTo } from './marketplaceData'
import { AppLogo, AppPrice, EventList, ScopeList } from './marketplaceParts'

/** The OAuth parameters the app sent, passed through unchanged. */
const OAUTH_PARAMS = ['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method']

/**
 * MKT-02, API-02 (OAuth): the consent screen of the authorization code
 * flow. The app sent the user here; they see what it asks for in plain
 * words and its price, choose the role it acts as at one place (never more
 * than they could grant: the API checks it again), then approve or deny.
 * Either way they go back to the app.
 */
export default function Consent() {
  const { t, i18n } = useTranslation()
  const [params] = useSearchParams()
  const oauth = Object.fromEntries(OAUTH_PARAMS.filter((key) => params.get(key)).map((key) => [key, params.get(key)]))
  const query = useQuery({ queryKey: ['oauth', 'authorize', params.toString(), i18n.resolvedLanguage], queryFn: () => api.get(`oauth/authorize?${new URLSearchParams(oauth)}`), retry: false })
  const [row, setRow] = useState(() => emptyAssignment())
  const grants = useGrantOptions({ rows: [row] })
  const options = { ...grants, rolesFor: (value) => grants.rolesFor(value).filter((role) => !role.is_owner) }
  const shownRow = offeredRow(row, options)
  const decide = useMutation({
    mutationFn: (decision) =>
      api.post('oauth/authorize', {
        ...oauth,
        decision,
        role_id: decision === 'approve' ? shownRow.role_id : null,
        scope_type: decision === 'approve' ? shownRow.scope_type : null,
        scope_id: decision === 'approve' && shownRow.scope_type !== 'tenant' ? shownRow.scope_id : null,
      }),
    onSuccess: (response) => leaveTo(response.data.redirect_to),
  })
  const errors = formErrors(decide.error, ['role_id', 'scope_type', 'scope_id'])
  const data = query.data?.data
  const app = data?.app

  if (query.isError) {
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-h2 text-ink">{t('marketplace.consent.errorTitle')}</h1>
        <Alert tone="danger" title={errorMessage(query.error)} />
      </div>
    )
  }
  if (!app) return <p className="text-ink-muted">{t('common.loading')}</p>

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-center gap-3">
        <AppLogo name={app.name} url={app.logo_url} size="lg" />
        <div className="flex min-w-0 flex-col">
          <h1 className="text-h2 text-ink">{t('marketplace.consent.title', { name: app.name })}</h1>
          <span className="text-caption text-ink-muted">{app.developer.first_party ? t('marketplace.byPlatform') : t('marketplace.by', { name: app.developer.name })}</span>
        </div>
      </div>
      {app.installation ? <Alert tone="info" title={t('marketplace.consent.installed')} /> : null}
      <section className="flex flex-col gap-2">
        <h2 className="text-label text-ink">{t('marketplace.consent.wants')}</h2>
        <ScopeList scopes={app.scopes} />
      </section>
      {app.webhook_events.length > 0 ? (
        <section className="flex flex-col gap-2">
          <h2 className="text-label text-ink">{t('marketplace.consent.events')}</h2>
          <EventList events={app.webhook_events} />
        </section>
      ) : null}
      <section className="flex flex-col gap-1">
        <h2 className="text-label text-ink">{t('marketplace.price')}</h2>
        <AppPrice pricing={app.pricing} />
        {app.pricing?.model === 'paid' ? <p className="text-caption text-ink-muted">{t('marketplace.billedWith')}</p> : null}
      </section>
      {data.can_install ? (
        <section className="flex flex-col gap-2">
          <h2 className="text-label text-ink">{t('marketplace.consent.actsAs')}</h2>
          <p className="text-caption text-ink-muted">{t('marketplace.consent.actsAsHelp')}</p>
          <AssignmentFields index={0} value={shownRow} onChange={setRow} options={options} errors={errors.fields} />
        </section>
      ) : (
        <Alert tone="warning" title={t('marketplace.consent.cannotInstall')} />
      )}
      {app.pricing?.model === 'paid' && data.can_install && !data.can_pay ? <Alert tone="warning" title={t('marketplace.consent.cannotPay')} /> : null}
      {decide.error && !errors.fields.role_id && !errors.fields.scope_id ? <Alert tone="danger" title={errorMessage(decide.error)} /> : null}
      <div className="flex flex-wrap justify-end gap-3">
        <Button variant="ghost" loading={decide.isPending && decide.variables === 'deny'} onClick={() => decide.mutate('deny')}>
          {t('marketplace.consent.deny')}
        </Button>
        <Button variant="pay" loading={decide.isPending && decide.variables === 'approve'} disabled={!data.can_install || !shownRow.role_id} onClick={() => decide.mutate('approve')}>
          {app.installation ? t('marketplace.consent.approveAgain') : t('marketplace.consent.approve')}
        </Button>
      </div>
    </div>
  )
}
