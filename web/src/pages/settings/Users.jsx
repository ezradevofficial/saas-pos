import { useTranslation } from 'react-i18next'
import { ComingSoon } from './ComingSoon'

// Placeholder: the full page arrives with the next part of this task.
export default function Users() {
  const { t } = useTranslation()
  return <ComingSoon title={t('settings.users.title')} description={t('settings.users.description')} />
}
