import { useTranslation } from 'react-i18next'
import { Link, useMatches } from 'react-router'
import { PageOutlet } from '@/layouts/PageOutlet'
import { StageTracker } from '@/components/ds'
import { TrialBadge } from '@/components/onboarding/TrialBanner'
import { LanguageSwitch } from '@/layouts/AuthLayout'
import { LogoMark } from '@/layouts/Sidebar'
import { STAGES, useOnboarding } from './useOnboarding'

/**
 * The onboarding screens (OnboardBusiness, OnboardModules, OnboardSetup):
 * a quiet top bar with the product name, the trial and the business, then
 * the stage tracker over the page. Each route names its stage in
 * `handle.stage`.
 */
export function OnboardingLayout() {
  const { t } = useTranslation()
  const { data: state } = useOnboarding()
  const stage = useMatches().findLast((match) => match.handle?.stage)?.handle.stage ?? 'business'
  const names = STAGES.map((key) => t(`onboarding.stages.${key}`))

  return (
    <div className="flex min-h-screen flex-col bg-surface-100 text-body text-ink">
      <header className="flex flex-wrap items-center justify-between gap-4 border-b border-border bg-surface-200 px-4 py-4 md:px-10">
        <Link to="/" className="flex min-w-0 items-center gap-3 rounded-sm">
          <LogoMark tone="page" />
          <span className="truncate text-h3 text-ink">{t('app.name')}</span>
        </Link>
        <div className="flex flex-wrap items-center gap-4">
          <TrialBadge />
          {state?.business_name ? <span className="text-caption text-ink-muted">{state.business_name}</span> : null}
          <LanguageSwitch />
        </div>
      </header>
      <main className="flex-1 px-4 py-6 md:px-10 md:py-10">
        <div className="mx-auto flex max-w-content flex-col gap-10">
          <StageTracker stages={names} current={names[STAGES.indexOf(stage)]} />
          <PageOutlet />
        </div>
      </main>
    </div>
  )
}
