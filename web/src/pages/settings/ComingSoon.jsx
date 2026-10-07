import { useTranslation } from 'react-i18next'
import { Card } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'

/** Holds a settings page's place in the navigation until the page is built. */
export function ComingSoon({ title, description }) {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={title} description={description} />
      <Card>
        <p className="py-6 text-center text-ink-muted">{t('settings.comingSoon')}</p>
      </Card>
    </>
  )
}
