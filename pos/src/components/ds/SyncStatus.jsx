import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { cn } from '../../lib/cn';

// A dot plus words; the words carry the meaning (never colour alone).
const DOTS = {
  online: 'bg-success',
  syncing: 'bg-primary',
  offline: 'bg-warning',
};

export function SyncStatus({ state = 'online', pending, labels, className }) {
  const { t } = useTranslation();
  const current = DOTS[state] ? state : 'online';
  const text = {
    online: labels?.online ?? t('ds.syncStatus.online'),
    syncing: labels?.syncing ?? t('ds.syncStatus.syncing'),
    offline: labels?.offline ?? t('ds.syncStatus.offline'),
  }[current];

  return (
    <View
      testID="sync-status"
      role="status"
      accessibilityLiveRegion="polite"
      className={cn('flex-row items-center gap-2', className)}
    >
      <View className={cn('h-2 w-2 rounded-pill', DOTS[current])} />
      <Text className={cn('font-sans text-caption font-medium', current === 'offline' ? 'text-warning' : 'text-ink')}>{text}</Text>
      {pending ? (
        <View className="border-l border-border pl-2">
          <Text className="font-sans text-caption text-ink-muted">{t('ds.syncStatus.pending', { count: pending })}</Text>
        </View>
      ) : null}
    </View>
  );
}
