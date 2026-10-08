import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useServices } from '../app/services';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { TextField } from '../components/ds/TextField';
import { filterPairingInput, isPairingCode, pairDevice } from '../device/pairing';
import { AppHeader } from './AppHeader';

/** TEN-05: pair the till with a one-time code, then show where it sells. */
export function PairingScreen({ onPaired }) {
  const { t } = useTranslation();
  const { api, credentials, store, engine } = useServices();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [paired, setPaired] = useState(null);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const result = await pairDevice({ api, credentials, store, code, deviceName: name });
      if (!result.ok) {
        setError(result.error);
        return;
      }
      let location = null;
      try {
        const bootstrap = await engine.bootstrap();
        location = bootstrap.settings?.location?.name ?? null;
      } catch {
        // offline right after pairing: the first sync fills it in
      }
      setPaired({ location });
    } catch {
      setError('failed');
    } finally {
      setBusy(false);
    }
  }

  if (paired) {
    return (
      <SafeAreaView className="flex-1">
        <AppHeader showSync={false} />
        <View className="flex-1 items-center justify-center p-6">
          <View className="w-full gap-5 rounded-lg border border-border bg-surface-200 p-6 md:w-1/2 xl:w-1/3">
            <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
              {t('pairing.pairedTitle')}
            </Text>
            <Text className="font-sans text-body-lg text-ink">
              {paired.location ? t('pairing.pairedAt', { location: paired.location }) : t('pairing.pairedNoLocation')}
            </Text>
            <Button variant="primary" block onPress={onPaired}>
              {t('common.continue')}
            </Button>
          </View>
        </View>
      </SafeAreaView>
    );
  }

  const codeError = error === 'invalid_code' ? t('pairing.errors.invalid_code') : null;
  const nameError = error === 'name_required' ? t('pairing.errors.name_required') : null;
  const otherError = error && !codeError && !nameError ? t(`pairing.errors.${error}`, { defaultValue: t('pairing.errors.failed') }) : null;

  return (
    <SafeAreaView className="flex-1">
      <AppHeader showSync={false} />
      <ScrollView contentContainerClassName="flex-grow items-center justify-center p-6">
        <View className="w-full gap-5 rounded-lg border border-border bg-surface-200 p-6 md:w-1/2 xl:w-1/3">
          <View className="gap-2">
            <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
              {t('pairing.title')}
            </Text>
            <Text className="font-sans text-body-lg text-ink-muted">{t('pairing.intro')}</Text>
          </View>
          {otherError ? <Alert tone="danger">{otherError}</Alert> : null}
          <TextField
            label={t('pairing.code')}
            help={t('pairing.codeHelp')}
            error={codeError}
            value={code}
            onChangeText={(text) => setCode(filterPairingInput(text))}
            autoCapitalize="characters"
            autoCorrect={false}
            autoComplete="off"
            maxLength={12}
          />
          <TextField
            label={t('pairing.name')}
            help={t('pairing.nameHelp')}
            error={nameError}
            value={name}
            onChangeText={setName}
            maxLength={100}
            onSubmitEditing={submit}
          />
          <Button variant="primary" block loading={busy} disabled={!isPairingCode(code) || !name.trim()} onPress={submit}>
            {t('pairing.submit')}
          </Button>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}
