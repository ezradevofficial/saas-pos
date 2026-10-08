import { useTranslation } from 'react-i18next'

/** "2 d 4 h", "3 h 20 min", "5 min": an elapsed time in seconds (WF-10). */
export function useDuration() {
  const { t } = useTranslation()
  return (seconds) => {
    const minutes = Math.floor((Number(seconds) || 0) / 60)
    const days = Math.floor(minutes / 1440)
    const hours = Math.floor((minutes % 1440) / 60)
    if (days > 0) return t('documentWorkflow.duration.days', { days, hours })
    if (hours > 0) return t('documentWorkflow.duration.hours', { hours, minutes: minutes % 60 })
    return t('documentWorkflow.duration.minutes', { count: minutes })
  }
}
