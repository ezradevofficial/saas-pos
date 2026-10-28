import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'
import { DashboardGrid } from '@/components/dashboard/DashboardGrid'
import { Widget } from '@/components/dashboard/widgets'
import { Button } from '@/components/ds'
import { GettingStarted } from '@/components/onboarding/GettingStarted'
import { useSupportSession } from '@/auth/useSupportSession'
import { resumePath, useOnboarding } from '@/pages/onboarding/useOnboarding'
import { PageHeader } from '@/layouts/PageHeader'
import { useNavigation } from '@/layouts/useNavigation'
import { useDashboard, WIDGET_TYPES } from '@/lib/dashboardData'
import { formatLongDate, partOfDay } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

/** The dashboard, under a greeting; "Customise my dashboard" opens the user's own copy in the designer. */
export default function Home() {
  const { readOnly } = useSupportSession()
  const { t } = useTranslation()
  const { user } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const locale = useLocale()
  const [now] = useState(() => new Date())
  const { home } = useNavigation()
  const { dashboard, isLoading } = useDashboard()
  // ONB-02, ONB-03: an owner who has not picked a business type or modules yet resumes there.
  const { can } = usePermissions()
  const onboarding = useOnboarding()
  const owner = can('core.onboarding.modules')
  const resume = owner ? resumePath(onboarding.data) : null

  if (resume) return <Navigate to={resume} replace />
  // The dashboard waits for the onboarding state, so an unfinished onboarding never shows it for a moment.
  if (owner && onboarding.isLoading) return <p className="text-body text-ink-muted">{t('common.loading')}</p>

  // LAY-02: straight after signing in, the role's home page when it has one.
  if (location.state?.landing && home) return <Navigate to={home} replace />

  const firstName = (user?.name ?? '').split(' ')[0]
  const greetings = {
    morning: t('home.greeting.morning', { name: firstName }),
    afternoon: t('home.greeting.afternoon', { name: firstName }),
    evening: t('home.greeting.evening', { name: firstName }),
  }
  const widgets = (dashboard?.widgets ?? []).filter((widget) => WIDGET_TYPES.includes(widget.type))

  return (
    <>
      <PageHeader
        eyebrow={formatLongDate(now, locale)}
        title={dashboard?.title || greetings[partOfDay(now.getHours())]}
        actions={
          readOnly ? null : (
            <Button icon="layouts" onClick={() => navigate('/dashboard/customise')}>
              {t('layouts.dashboard.customise')}
            </Button>
          )
        }
      />
      {/* RBAC-13: a read-only support session changes nothing, its own dashboard and onboarding steps included. */}
      {readOnly ? null : <GettingStarted />}
      {isLoading ? (
        <p className="text-body text-ink-muted">{t('common.loading')}</p>
      ) : (
        <DashboardGrid label={t('layouts.dashboard.label')} widgets={widgets} renderWidget={(widget) => <Widget widget={widget} />} />
      )}
    </>
  )
}
