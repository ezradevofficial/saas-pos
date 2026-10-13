import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Select } from '@/components/ds'
import { usePageTitle } from '@/lib/usePageTitle'
import { cn } from '@/lib/utils'
import { useOnboarding, useOnboardingWrite } from './useOnboarding'

/** The design's outlet ranges, sent as the smallest number of each. */
const OUTLETS = [
  { value: '1', key: 'one' },
  { value: '2', key: 'few' },
  { value: '6', key: 'several' },
  { value: '21', key: 'many' },
]

/**
 * ONB-02 (OnboardBusiness): the business type pre-selects the till's
 * mode, the roles, approval steps and the suggested modules. Everything
 * can change later.
 */
export default function BusinessType() {
  const { t } = useTranslation()
  usePageTitle(t('onboarding.business.title'))
  const navigate = useNavigate()
  const { data: state, isLoading } = useOnboarding()
  const [picked, setPicked] = useState(null)
  const [outlets, setOutlets] = useState(null)
  const current = picked ?? state?.business_type ?? null
  const types = state?.business_types ?? []
  const save = useOnboardingWrite(() => api.put('onboarding/business', { business_type: current, outlets: Number(outlets ?? 1) }), {
    onSuccess: () => navigate('/onboarding/modules'),
  })
  const errors = formErrors(save.error, ['business_type', 'outlets'])
  const chosen = types.find((type) => type.key === current)

  return (
    <div className="flex flex-col gap-10">
      <div className="flex flex-col gap-2">
        <h1 className="text-display text-ink">{t('onboarding.business.title')}</h1>
        <p className="text-body-lg text-ink-muted">{t('onboarding.business.intro')}</p>
      </div>

      {errors.form || errors.fields.business_type ? <Alert tone="danger" title={errors.form ?? errors.fields.business_type} /> : null}

      {isLoading ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      <div role="group" aria-label={t('onboarding.business.typesLabel')} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {types.map((type) => (
          <button
            key={type.key}
            type="button"
            aria-pressed={current === type.key}
            onClick={() => setPicked(type.key)}
            className={cn(
              'flex min-h-tile flex-col items-start justify-center gap-1 rounded-lg border p-4 text-left text-ink',
              current === type.key ? 'border-primary bg-primary-tint' : 'border-border bg-surface-200 hover:bg-surface-300',
            )}
          >
            <span className="text-body-lg font-medium">{type.name}</span>
            <span className="text-caption text-ink-muted">{type.description}</span>
          </button>
        ))}
      </div>

      <div className="grid gap-4 rounded-lg border border-border bg-surface-200 p-6 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <span className="text-label text-ink">{t('onboarding.business.name')}</span>
          <span className="text-body text-ink">{state?.business_name}</span>
          <span className="text-caption text-ink-muted">{state ? t(`auth.countries.${state.country}`) : null}</span>
        </div>
        <Select
          label={t('onboarding.business.outlets')}
          value={outlets ?? '1'}
          onChange={(event) => setOutlets(event.target.value)}
          options={OUTLETS.map((option) => ({ value: option.value, label: t(`onboarding.business.outletRanges.${option.key}`) }))}
          error={errors.fields.outlets}
        />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-4">
        <span className="text-caption text-ink-muted">
          {chosen ? t('onboarding.business.selected', { name: chosen.name }) : t('onboarding.business.pickOne')}
        </span>
        <Button variant="primary" size="lg" disabled={!current} loading={save.isPending} onClick={() => save.mutate()}>
          {t('onboarding.business.continue')}
        </Button>
      </div>
    </div>
  )
}
