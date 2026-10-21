import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { goToIdp, ssoReason } from '@/auth/sso'
import { Alert, Button, Card } from '@/components/ds'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'

const MINE_KEY = ['me', 'sso']

/** `#sso_link=ok` or `#sso_link=failed&reason=…`, left by the callback of a self-link. */
function readLinkOutcome() {
  const params = new URLSearchParams((window.location.hash ?? '').replace(/^#/, ''))
  const outcome = params.get('sso_link')
  return outcome ? { ok: outcome === 'ok', reason: params.get('reason') } : null
}

/**
 * AUTH-11, ADR 023: Settings, Security: the signed-in user links their own
 * account to an enabled connection (the only way an Owner is ever linked)
 * and sees or removes their links. Shown only while the organisation has
 * an enabled connection.
 */
export function MySsoLinks() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: MINE_KEY, queryFn: () => api.get('me/sso') })
  const [outcome] = useState(readLinkOutcome)

  useEffect(() => {
    if (outcome) window.history.replaceState(window.history.state, '', window.location.pathname)
  }, [outcome])

  const link = useMutation({
    mutationFn: (connectionId) => api.post('me/sso/link', { connection_id: connectionId, origin: window.location.origin }),
    onSuccess: (response) => goToIdp(response.data),
  })
  const unlink = useMutation({
    mutationFn: (id) => api.post(`me/sso/identities/${id}/archive`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: MINE_KEY }),
  })

  const data = query.data?.data
  if (!data || (data.connections.length === 0 && data.identities.length === 0)) return null
  const linked = new Set(data.identities.map((identity) => identity.connection_id))
  const failure = link.error ?? unlink.error

  return (
    <Card title={t('sso.myLinks.title')} subtitle={t('sso.myLinks.text')}>
      <div className="flex flex-col gap-4">
        {outcome?.ok ? <Alert tone="success" title={t('sso.myLinks.done')} /> : null}
        {outcome && !outcome.ok ? (
          <Alert tone="danger" title={t('sso.myLinks.failed')}>
            {ssoReason(t, outcome.reason)}
          </Alert>
        ) : null}
        {failure ? <Alert tone="danger" title={errorMessage(failure)} /> : null}
        {data.identities.length === 0 ? <p className="text-ink-muted">{t('sso.myLinks.empty')}</p> : null}
        <ul className="divide-y divide-border">
          {data.identities.map((identity) => (
            <li key={identity.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <span className="text-ink">{t('sso.myLinks.linked', { email: identity.email, name: identity.connection_name })}</span>
              <Button
                variant="ghost"
                loading={unlink.isPending && unlink.variables === identity.id}
                onClick={() => unlink.mutate(identity.id)}
                aria-label={t('sso.myLinks.unlinkFor', { name: identity.connection_name })}
              >
                {t('sso.myLinks.unlink')}
              </Button>
            </li>
          ))}
        </ul>
        <div className="flex flex-wrap gap-2">
          {data.connections
            .filter((connection) => !linked.has(connection.id))
            .map((connection) => (
              <Button key={connection.id} icon="key" loading={link.isPending && link.variables === connection.id} onClick={() => link.mutate(connection.id)}>
                {t('sso.myLinks.link', { name: connection.name })}
              </Button>
            ))}
        </div>
      </div>
    </Card>
  )
}

/** AUTH-11, ADR 023: a user's SSO links as an SSO manager sees them, with Unlink (audited). */
export function UserSsoLinks({ userId }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const key = ['users', userId, 'sso-identities']
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`users/${userId}/sso-identities`) })
  const unlink = useMutation({
    mutationFn: (id) => api.post(`users/${userId}/sso-identities/${id}/archive`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  })
  const links = query.data?.data ?? []

  return (
    <Card title={t('sso.userLinks.title')} subtitle={t('sso.userLinks.text')}>
      <div className="flex flex-col gap-3">
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {unlink.error ? <Alert tone="danger" title={errorMessage(unlink.error)} /> : null}
        {query.isSuccess && links.length === 0 ? <p className="text-ink-muted">{t('sso.userLinks.empty')}</p> : null}
        <ul className="divide-y divide-border">
          {links.map((link) => (
            <li key={link.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div className="flex min-w-0 flex-col gap-1">
                <span className="text-ink">{t('sso.userLinks.linked', { email: link.email, name: link.connection_name })}</span>
                {link.last_sign_in_at ? <span className="text-caption text-ink-muted">{t('sso.userLinks.lastUsed', { when: formatWhen(link.last_sign_in_at, locale) })}</span> : null}
              </div>
              <Button
                variant="ghost"
                loading={unlink.isPending && unlink.variables === link.id}
                onClick={() => unlink.mutate(link.id)}
                aria-label={t('sso.userLinks.unlinkFor', { email: link.email })}
              >
                {t('sso.userLinks.unlink')}
              </Button>
            </li>
          ))}
        </ul>
      </div>
    </Card>
  )
}
