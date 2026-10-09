import { useTranslation } from 'react-i18next';
import { Image, Text, View } from 'react-native';
import { SyncStatus } from '../components/ds/SyncStatus';
import { useSyncStatus } from '../sync/useSyncStatus';
import { useMedia } from '../theme/media';
import { useTillTheme } from '../theme/TillTheme';

/**
 * The till's top bar: app name, where the till sits, and the sync state.
 * With `brand` (the sign-in screen), the tenant's logo for the mode replaces
 * the app name once the till has it (BR-02).
 */
export function AppHeader({ location, showSync = true, brand = false }) {
  const { t } = useTranslation();
  const sync = useSyncStatus();
  const { logo } = useTillTheme();
  const uri = useMedia(brand ? logo : null);
  return (
    <View className="flex-row flex-wrap items-center gap-4 border-b border-border bg-surface-200 px-4 py-3 md:px-6">
      <View className="min-w-0 flex-1">
        {uri ? (
          <Image testID="brand-logo" source={{ uri }} accessibilityLabel={t('app.name')} resizeMode="contain" className="h-12 w-1/2" />
        ) : (
          <Text accessibilityRole="header" className="font-sans text-h3 text-ink">
            {t('app.name')}
          </Text>
        )}
        {location ? <Text className="font-sans text-caption text-ink-muted">{location}</Text> : null}
      </View>
      {showSync ? <SyncStatus state={sync.state} pending={sync.pending} /> : null}
    </View>
  );
}
