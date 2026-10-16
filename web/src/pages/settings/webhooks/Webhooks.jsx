import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Dialog, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { DeliveryLog } from './DeliveryLog'
import { DeveloperGuide } from './DeveloperGuide'
import { SecretReveal } from './SecretReveal'
import { WebhookFormDialog } from './WebhookFormDialog'
import { CATALOGUE_KEY, subscriptionState, WEBHOOKS_KEY } from './webhookShared'

const TABS = ['webhooks', 'deliveries', 'guide']

function WebhookRow({ webhook }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const state = subscriptionState(webhook)

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 py-4">
      <div className="flex min-w-0 flex-col gap-1">
        <div className="flex min-w-0 flex-wrap items-center gap-3">
          <Link
            to={`/settings/webhooks/${webhook.id}`}
            className="font-medium break-all text-ink hover:underline"
            aria-label={t('webhooks.row.open', { url: webhook.url_display })}
          >
            {webhook.url_display}
          </Link>
          <StatusBadge tone={state.tone}>{t(`webhooks.status.${state.key}`)}</StatusBadge>
        </div>
        <p className="text-caption text-ink-muted">
          {[
            webhook.description,
            t('webhooks.row.events', { count: webhook.events.length }),
            webhook.last_delivery_at ? t('webhooks.row.lastDelivery', { when: formatWhen(webhook.last_delivery_at, locale) }) : t('webhooks.row.never'),
            webhook.consecutive_failures > 0 ? t('webhooks.row.failures', { count: webhook.consecutive_failures }) : null,
          ]
            .filter(Boolean)
            .join(' · ')}
        </p>
      </div>
    </li>
  )
}

/**
 * HOOK-01: Settings → Webhooks (`core.webhook.manage`, Owners and Admins):
 * the organisation's endpoints, adding one (its signing secret is shown
 * once), the delivery log of all of them, and the developer guide.
 */
export default function Webhooks() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const tab = TABS.includes(params.get('tab')) ? params.get('tab') : 'webhooks'
  const [adding, setAdding] = useState(false)
  const [created, setCreated] = useState(null)
  const query = useQuery({ queryKey: WEBHOOKS_KEY, queryFn: () => api.get('webhooks') })
  const catalogue = useQuery({ queryKey: CATALOGUE_KEY, queryFn: () => api.get('webhooks/catalogue') })
  const webhooks = query.data?.data ?? []

  return (
    <>
      <PageHeader
        title={t('webhooks.title')}
        description={t('webhooks.description')}
        actions={
          <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>
            {t('webhooks.add')}
          </Button>
        }
      />
      <div className="flex flex-col gap-5">
        <Tabs
          value={tab}
          onChange={(next) => setParams(next === 'webhooks' ? {} : { tab: next }, { replace: true })}
          items={TABS.map((value) => ({ value, label: t(`webhooks.tabs.${value}`) }))}
        />
        {tab === 'webhooks' ? (
          <Card>
            {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} action={<Button onClick={() => query.refetch()}>{t('common.retry')}</Button>} /> : null}
            {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
            {!query.isPending && !query.isError && webhooks.length === 0 ? <p className="text-ink-muted">{t('webhooks.empty')}</p> : null}
            {webhooks.length ? (
              <ul aria-label={t('webhooks.listLabel')} className="flex flex-col divide-y divide-border">
                {webhooks.map((webhook) => (
                  <WebhookRow key={webhook.id} webhook={webhook} />
                ))}
              </ul>
            ) : null}
          </Card>
        ) : null}
        {tab === 'deliveries' ? <DeliveryLog retentionDays={catalogue.data?.meta?.retention_days} /> : null}
        {tab === 'guide' ? <DeveloperGuide /> : null}
      </div>
      {adding ? <WebhookFormDialog open onClose={() => setAdding(false)} onCreated={setCreated} /> : null}
      <Dialog
        open={Boolean(created)}
        title={t('webhooks.secret.title')}
        onClose={() => setCreated(null)}
        footer={
          <Button variant="primary" onClick={() => setCreated(null)}>
            {t('webhooks.secret.done')}
          </Button>
        }
      >
        {created ? <SecretReveal secret={created.secret} /> : null}
      </Dialog>
    </>
  )
}
