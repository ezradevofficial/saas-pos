import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'
import { Card, Icon } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatLongDate, partOfDay } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

function Step({ to, icon, title, text }) {
  return (
    <li>
      <Link
        to={to}
        className="flex items-start gap-3 rounded-md border border-border px-4 py-3 transition-colors hover:border-border-strong hover:bg-surface-100"
      >
        <Icon name={icon} size={18} className="mt-px text-ink-muted" />
        <span className="flex min-w-0 flex-col">
          <span className="font-medium text-primary">{title}</span>
          <span className="text-ink-muted">{text}</span>
        </span>
      </Link>
    </li>
  )
}

/** The dashboard. Sprint 1 has no figures yet, so it shows how to get started. */
export default function Home() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { can } = usePermissions()
  const locale = useLocale()
  const [now] = useState(() => new Date())
  const firstName = (user?.name ?? '').split(' ')[0]
  const greetings = {
    morning: t('home.greeting.morning', { name: firstName }),
    afternoon: t('home.greeting.afternoon', { name: firstName }),
    evening: t('home.greeting.evening', { name: firstName }),
  }
  const today = formatLongDate(now, locale)
  const steps = [
    can('core.company.view') && (
      <Step key="org" to="/settings/organisation" icon="organisation" title={t('home.start.organisation')} text={t('home.start.organisationText')} />
    ),
    can('core.user.view') && (
      <Step key="users" to="/settings/users" icon="users" title={t('home.start.users')} text={t('home.start.usersText')} />
    ),
    <Step key="look" to="/settings/appearance" icon="appearance" title={t('home.start.appearance')} text={t('home.start.appearanceText')} />,
  ].filter(Boolean)

  return (
    <>
      <PageHeader eyebrow={today} title={greetings[partOfDay(now.getHours())]} />
      <div className="grid gap-5 lg:grid-cols-2">
        <Card title={t('home.start.title')} subtitle={t('home.start.subtitle')}>
          <ul className="flex flex-col gap-2">{steps}</ul>
        </Card>
        <Card title={t('home.empty.title')}>
          <div className="flex flex-col items-start gap-2 py-6">
            <p className="text-body text-ink">{t('home.empty.heading')}</p>
            <p className="text-body text-ink-muted">{t('home.empty.text')}</p>
          </div>
        </Card>
      </div>
    </>
  )
}
