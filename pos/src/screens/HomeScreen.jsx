import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useServices } from '../services/services';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { useLocale } from '../lib/useLocale';
import { AUTH } from '../sync/engine';
import { useSyncStatus } from '../sync/useSyncStatus';
import { AppHeader } from './AppHeader';

const LOCALE_TAGS = { en: 'en-KE', fr: 'fr-CD' };

function formatTime(ms, locale) {
  return new Date(ms).toLocaleTimeString(LOCALE_TAGS[locale] ?? 'en-KE', { hour: '2-digit', minute: '2-digit', hour12: false });
}

/** Placeholder for the selling screen (Task 5): who is signed in, sync state, switch user. */
export function HomeScreen({ location }) {
  const { t } = useTranslation();
  const locale = useLocale();
  const { scheduler } = useServices();
  const { user, switchUser, pinChange } = useSession();
  const sync = useSyncStatus();

  return (
    <SafeAreaView className="flex-1">
      <AppHeader location={location} />
      <ScrollView contentContainerClassName="flex-grow items-center p-4 md:p-6">
        <View className="w-full gap-5 md:w-1/2 xl:w-1/3">
          {sync.auth === AUTH.LOST ? (
            <Alert tone="danger" title={t('home.accessLostTitle')} action={<Button onPress={scheduler.syncNow}>{t('common.retry')}</Button>}>
              {t('home.accessLost')}
            </Alert>
          ) : null}
          {sync.secretMissing ? <Alert tone="warning">{t('home.secretMissing')}</Alert> : null}
          {sync.moduleInactive ? <Alert tone="warning">{t('home.moduleInactive')}</Alert> : null}
          {pinChange ? (
            <Alert tone="warning" title={t('home.pinChangeTitle')}>
              {t('home.pinChangeOffline')}
            </Alert>
          ) : null}
          {sync.failed ? <Alert tone="warning">{t('home.failed', { count: sync.failed })}</Alert> : null}

          <View className="gap-4 rounded-lg border border-border bg-surface-200 p-5">
            <View className="gap-1">
              <Text accessibilityRole="header" className="font-sans text-h1 font-semibold text-ink">
                {t('home.ready')}
              </Text>
              <Text className="font-sans text-body-lg text-ink">{t('home.signedInAs', { name: user?.name ?? '' })}</Text>
              <Text className="font-sans text-caption text-ink-muted">
                {sync.lastSyncedAt ? t('home.lastSynced', { time: formatTime(sync.lastSyncedAt, locale) }) : t('home.neverSynced')}
              </Text>
            </View>
            <Text className="font-sans text-body-lg text-ink-muted">{t('home.sellingSoon')}</Text>
            <View className="flex-row flex-wrap gap-3">
              <Button variant="secondary" onPress={switchUser}>
                {t('home.switchUser')}
              </Button>
              <Button variant="ghost" loading={sync.syncing} onPress={scheduler.syncNow}>
                {t('common.syncNow')}
              </Button>
            </View>
          </View>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}
