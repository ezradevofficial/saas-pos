import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { Dialog } from '../components/ds/Dialog';
import { PinDots, PinPad } from '../components/PinPad';
import { useServices } from '../services/services';

const PIN_MIN = 4;

/**
 * LAY-05: leaving the in-app customer display. The display faces the
 * customer, so closing it takes the PIN of the staff member signed in on
 * the till, checked like a sign-in by the pin gate (offline against the
 * synced material, with the same lockout, AUTH-06, AUTH-07).
 */
export function DisplayCloseDialog({ onCancel, onUnlocked }) {
  const { t } = useTranslation();
  const { pinGate } = useServices();
  const { user } = useSession();
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  // One check at a time; a second press before the first resolves does nothing.
  const checking = useRef(false);

  async function unlock() {
    if (!user || pin.length < PIN_MIN || checking.current) return;
    checking.current = true;
    setBusy(true);
    setError(null);
    try {
      const result = await pinGate.signIn({ userId: user.id, kind: 'pin', input: pin });
      if (result.ok) {
        onUnlocked();
        return;
      }
      setError(result.reason === 'incorrect' ? t('signIn.errors.incorrect', { count: result.attemptsLeft }) : t(`signIn.errors.${result.reason}`, { defaultValue: t('signIn.errors.failed') }));
      setPin('');
    } catch {
      setError(t('signIn.errors.failed'));
      setPin('');
    } finally {
      checking.current = false;
      setBusy(false);
    }
  }

  return (
    <Dialog open title={t('pos.display.closeTitle')} onClose={onCancel}>
      <View className="gap-3">
        <Text className="font-sans text-body-lg text-ink-muted">{user ? t('pos.display.closePin', { name: user.name }) : t('pos.display.closeNobody')}</Text>
        {error ? <Alert tone="warning">{error}</Alert> : null}
        {user ? (
          <>
            <PinDots length={pin.length} />
            <PinPad value={pin} onChange={setPin} disabled={busy} />
            <Button variant="primary" block loading={busy} disabled={pin.length < PIN_MIN} onPress={unlock}>
              {t('pos.display.close')}
            </Button>
          </>
        ) : null}
        <Button variant="ghost" block onPress={onCancel}>
          {t('common.cancel')}
        </Button>
      </View>
    </Dialog>
  );
}
