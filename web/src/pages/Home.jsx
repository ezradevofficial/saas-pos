import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { useAuth } from '@/auth/AuthProvider'
import { DashboardGrid } from '@/components/dashboard/DashboardGrid'
import { Widget } from '@/components/dashboard/widgets'
import { Button } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useNavigation } from '@/layouts/useNavigation'
import { useDashboard, WIDGET_TYPES } from '@/lib/dashboardData'
import { formatLongDate, partOfDay } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

/** The dashboard, under a greeting; "Customise my dashboard" opens the user's own copy in the designer. */
export default function Home() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const locale = useLocale()
  const [now] = useState(() => new Date())
  const { home } = useNavigation()
  const { dashboard, isLoading } = useDashboard()

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
          <Button icon="layouts" onClick={() => navigate('/dashboard/customise')}>
            {t('layouts.dashboard.customise')}
          </Button>
        }
      />
      {isLoading ? (
        <p className="text-body text-ink-muted">{t('common.loading')}</p>
      ) : (
        <DashboardGrid label={t('layouts.dashboard.label')} widgets={widgets} renderWidget={(widget) => <Widget widget={widget} />} />
      )}
    </>
  )
}
