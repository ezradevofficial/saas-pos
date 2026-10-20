import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { Card } from '@/components/ds'
import { CATALOGUE_KEY, eventLabel } from './webhookShared'

function Section({ title, children }) {
  return (
    <section className="flex flex-col gap-2">
      <h3 className="text-label text-ink">{title}</h3>
      {children}
    </section>
  )
}

/**
 * HOOK-01: how to receive webhooks (the developer portal links here; the
 * same guide is docs/webhooks.md): the envelope, Standard Webhooks
 * signatures, retries, duplicates and order, and the events this
 * organisation can subscribe to with their documented fields.
 */
export function DeveloperGuide() {
  const { t } = useTranslation()
  const catalogue = useQuery({ queryKey: CATALOGUE_KEY, queryFn: () => api.get('webhooks/catalogue') })
  const meta = catalogue.data?.meta ?? {}
  const types = catalogue.data?.data ?? []

  return (
    <div className="flex flex-col gap-5">
      <Card title={t('webhooks.guide.title')}>
        <div className="flex max-w-prose flex-col gap-5 text-body text-ink">
          <p>{t('webhooks.guide.intro')}</p>
          <Section title={t('webhooks.guide.signingTitle')}>
            <p>{t('webhooks.guide.signing')}</p>
            <p>{t('webhooks.guide.rotation', { hours: meta.rotation_overlap_hours ?? 24 })}</p>
          </Section>
          <Section title={t('webhooks.guide.retriesTitle')}>
            <p>{t('webhooks.guide.retries', { count: meta.disable_after ?? 10 })}</p>
          </Section>
          <Section title={t('webhooks.guide.orderTitle')}>
            <p>{t('webhooks.guide.order')}</p>
          </Section>
          <Section title={t('webhooks.guide.dataTitle')}>
            <p>{t('webhooks.guide.data')}</p>
          </Section>
        </div>
      </Card>
      <Card title={t('webhooks.guide.catalogueTitle')}>
        <ul className="flex flex-col divide-y divide-border">
          {types.map((type) => (
            <li key={type.key} className="flex flex-col gap-1 py-3">
              <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span className="font-medium text-ink">{eventLabel(t, type.key)}</span>
                <code className="font-mono text-caption text-ink-muted">{type.key}</code>
              </div>
              <p className="text-caption text-ink-muted">
                {t('webhooks.guide.fields')}: <span className="font-mono">{type.fields.join(', ')}</span>
              </p>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  )
}
