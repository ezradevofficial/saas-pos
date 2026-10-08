import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useServices } from '../services/services';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { PinDots, PinPad } from '../components/PinPad';
import { NetworkError } from '../sync/api';
import { AppHeader } from './AppHeader';

/**
 * AUTH-06: the server asks for a new PIN (`must_change`, after an admin
 * reset it or an approver has a short one). Online only:
 * POST pos/pin/change {user_id, pin, new_pin}. The server checks the rules
 * and answers in the till's language.
 */
export function ChangePinScreen({ location }) {
  const { t } = useTranslation();
  const { api, scheduler } = useServices();
  const { pinChange, finishPinChange } = useSession();
  const [step, setStep] = useState('new');
  const [first, setFirst] = useState('');
  const [pin, setPin] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  async function next() {
    if (pin.length < 4) return setError(t('changePin.tooShort'));
    if (step === 'new') {
      setFirst(pin);
      setPin('');
      setError(null);
      return setStep('confirm');
    }
    if (pin !== first) {
      setStep('new');
      setFirst('');
      setPin('');
      return setError(t('changePin.mismatch'));
    }
    setBusy(true);
    try {
      const response = await api.post('pos/pin/change', { user_id: pinChange.userId, pin: pinChange.currentPin, new_pin: pin });
      if (response.status === 200) {
        finishPinChange();
        scheduler.syncNow();
        return undefined;
      }
      const serverMessage = response.body?.errors?.new_pin?.[0] ?? response.body?.message;
      setError(serverMessage ?? t('changePin.failed'));
      setStep('new');
      setFirst('');
      setPin('');
    } catch (problem) {
      setError(problem instanceof NetworkError ? t('changePin.offline') : t('changePin.failed'));
    } finally {
      setBusy(false);
    }
    return undefined;
  }

  return (
    <SafeAreaView className="flex-1">
      <AppHeader location={location} />
      <View className="flex-1 items-center p-4 md:p-6">
        <View className="w-full gap-4 rounded-lg border border-border bg-surface-200 p-5 md:w-1/2 xl:w-1/3">
          <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
            {t('changePin.title')}
          </Text>
          <Text className="font-sans text-body-lg text-ink-muted">{t('changePin.intro')}</Text>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Text className="font-sans text-label text-ink">{step === 'new' ? t('changePin.newPin') : t('changePin.confirmPin')}</Text>
          <PinDots length={pin.length} />
          <PinPad value={pin} onChange={setPin} disabled={busy} />
          <Text className="font-sans text-caption text-ink-muted">{t('changePin.rules')}</Text>
          <Button variant="primary" block loading={busy} disabled={pin.length < 4} onPress={next}>
            {step === 'new' ? t('common.continue') : t('changePin.submit')}
          </Button>
        </View>
      </View>
    </SafeAreaView>
  );
}
