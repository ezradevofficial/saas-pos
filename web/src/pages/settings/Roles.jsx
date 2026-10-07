import { useTranslation } from 'react-i18next'
import { ComingSoon } from './ComingSoon'

// Placeholder: the full page arrives with the next part of this task.
export default function Roles() {
  const { t } = useTranslation()
  return <ComingSoon title={t('settings.roles.title')} description={t('settings.roles.description')} />
}
