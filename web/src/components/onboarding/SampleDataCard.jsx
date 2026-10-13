import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { Alert, Button, Card } from '@/components/ds'
import { useOnboarding, useOnboardingWrite } from '@/pages/onboarding/useOnboarding'

/**
 * ONB-06: load sample products, categories and customers to explore, and
 * remove them in one click (refused while your own records use them).
 */
export function SampleDataCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { data: state } = useOnboarding()
  const refresh = () => {
    for (const key of [['items'], ['item-categories'], ['parties']]) queryClient.invalidateQueries({ queryKey: key })
  }
  const load = useOnboardingWrite(() => api.post('onboarding/sample-data'), { onSuccess: refresh })
  const remove = useOnboardingWrite(() => api.post('onboarding/sample-data/remove'), { onSuccess: refresh })
  const sample = state?.sample
  const error = load.error ?? remove.error

  return (
    <Card title={t('onboarding.sample.title')} subtitle={t('onboarding.sample.subtitle')}>
      <div className="flex flex-col items-start gap-3">
        {sample ? (
          <p className="text-ink-muted">{t('onboarding.sample.loaded', { items: sample.counts.item ?? 0, customers: sample.counts.party ?? 0 })}</p>
        ) : (
          <p className="text-ink-muted">{t('onboarding.sample.text')}</p>
        )}
        {error ? <Alert tone="danger" title={error.message} /> : null}
        {sample ? (
          <Button variant="danger" loading={remove.isPending} onClick={() => remove.mutate()}>
            {t('onboarding.sample.remove')}
          </Button>
        ) : (
          <Button loading={load.isPending} onClick={() => load.mutate()}>
            {t('onboarding.sample.load')}
          </Button>
        )}
      </div>
    </Card>
  )
}
