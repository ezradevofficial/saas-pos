import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { SyncStatus } from '../components/ds/SyncStatus';
import { useSyncStatus } from '../sync/useSyncStatus';

/** The till's top bar: app name, where the till sits, and the sync state. */
export function AppHeader({ location, showSync = true }) {
  const { t } = useTranslation();
  const sync = useSyncStatus();
  return (
    <View className="flex-row flex-wrap items-center gap-4 border-b border-border bg-surface-200 px-4 py-3 md:px-6">
      <View className="min-w-0 flex-1">
        <Text accessibilityRole="header" className="font-sans text-h3 text-ink">
          {t('app.name')}
        </Text>
        {location ? <Text className="font-sans text-caption text-ink-muted">{location}</Text> : null}
      </View>
      {showSync ? <SyncStatus state={sync.state} pending={sync.pending} /> : null}
    </View>
  );
}
