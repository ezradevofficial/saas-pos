import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { PageHeader } from '@/layouts/PageHeader'

export default function NotFound() {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('notFound.title')} description={t('notFound.text')} />
      <p>
        <Link to="/" className="font-medium text-primary hover:text-primary-hover">
          {t('notFound.home')}
        </Link>
      </p>
    </>
  )
}

/** A page the user's roles do not reach (the navigation hides it too). */
export function NoAccess() {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('noAccess.title')} description={t('noAccess.text')} />
      <p>
        <Link to="/" className="font-medium text-primary hover:text-primary-hover">
          {t('notFound.home')}
        </Link>
      </p>
    </>
  )
}
