import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { Icon } from './Icon'

const STATES = {
  online: { icon: 'cloud', iconColour: 'text-success' },
  syncing: { icon: 'sync', iconColour: 'text-primary animate-spin motion-reduce:animate-none' },
  offline: { icon: 'offline', iconColour: 'text-warning' },
}

export function SyncStatus({ state = 'online', pending, labels, className }) {
  const { t } = useTranslation()
  const current = STATES[state] ? state : 'online'
  const text = {
    online: labels?.online ?? t('ds.syncStatus.online'),
    syncing: labels?.syncing ?? t('ds.syncStatus.syncing'),
    offline: labels?.offline ?? t('ds.syncStatus.offline'),
  }[current]
  return (
    <span
      role="status"
      data-state={current}
      className={cn('inline-flex items-center gap-2 text-caption font-medium text-ink', current === 'offline' && 'text-warning', className)}
    >
      <Icon name={STATES[current].icon} size={14} className={STATES[current].iconColour} />
      <span>{text}</span>
      {pending ? (
        <span className="border-l border-border pl-2 font-normal text-ink-muted">{t('ds.syncStatus.pending', { count: pending })}</span>
      ) : null}
    </span>
  )
}
