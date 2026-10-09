import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Pressable, Text, View } from 'react-native';
import { signOverride } from '../../auth/pinCrypto';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { PinDots, PinPad } from '../../components/PinPad';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { uuidv7 } from '../../lib/random';
import { approvers, check } from '../../pos/authority';
import { usePosActions, usePosData } from '../../pos/PosProvider';
import { useServices } from '../../services/services';

const PIN_MIN = 4;

/**
 * AUTH-08: a manager approves on this till. The manager picks their name
 * and types their PIN; it is checked like a sign-in (offline against the
 * synced material, else online, with the same lockout), the manager's
 * permission and limit are checked from their staff row, and the till
 * signs the override (`override:v2`) for the record it allows. The server
 * redeems it once and keeps offline overrides for review.
 */
export function OverrideDialog() {
  const { t } = useTranslation();
  const { pinGate, credentials, store, engine } = useServices();
  const { user } = useSession();
  const { overrideRequest: request, catalogue } = usePosData();
  const { finishOverride } = usePosActions();
  const [manager, setManager] = useState(null);
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  // AUTH-06, AUTH-08: one check at a time; a second press before the first resolves does nothing.
  const checking = useRef(false);

  useEffect(() => {
    setManager(null);
    setPin('');
    setError(null);
  }, [request]);

  if (!request) return null;
  const candidates = approvers(catalogue?.staff ?? [], request.action, request.value, user?.id);
  const permission = check({ permissions: [] }, request.action, request.value).permission;

  async function approve() {
    if (pin.length < PIN_MIN || checking.current) return;
    checking.current = true;
    setBusy(true);
    setError(null);
    try {
      const result = await pinGate.signIn({ userId: manager.id, kind: 'pin', input: pin });
      if (!result.ok) {
        setError(result.reason === 'incorrect' ? t('signIn.errors.incorrect', { count: result.attemptsLeft }) : t(`signIn.errors.${result.reason}`, { defaultValue: t('signIn.errors.failed') }));
        setPin('');
        return;
      }
      const [deviceSecret, device] = await Promise.all([credentials.secret(), store.device()]);
      if (!deviceSecret?.secret || !device?.id) {
        setError(t('pos.override.noSecret'));
        return;
      }
      const id = uuidv7();
      const fields = { deviceId: device.id, id, managerUserId: manager.id, cashierUserId: user.id, permission, reference: request.reference };
      const { signature, authorisedAt } = signOverride({ deviceSecret, serverNow: engine.serverNow, ...fields });
      finishOverride({
        override: { id, kid: deviceSecret.kid, manager_user_id: manager.id, cashier_user_id: user.id, permission, reference: request.reference, authorised_at: authorisedAt, signature },
        approvedBy: manager.id,
        managerName: manager.name,
      });
    } catch {
      setError(t('signIn.errors.failed'));
    } finally {
      checking.current = false;
      setBusy(false);
    }
  }

  return (
    <Dialog open title={t('pos.override.title')} onClose={() => finishOverride(null)}>
      <Text className="font-sans text-body-lg text-ink-muted">{t(`pos.override.why.${request.action}`)}</Text>
      {!manager ? (
        candidates.length ? (
          <View className="rounded-md border border-border">
            {candidates.map((member) => (
              <Pressable
                key={member.id}
                accessibilityRole="button"
                onPress={() => setManager(member)}
                className={cn('min-h-12 flex-row items-center border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}
              >
                <Text className="font-sans text-body-lg text-ink">{member.name}</Text>
              </Pressable>
            ))}
          </View>
        ) : (
          <Alert tone="warning">{t('pos.override.nobody')}</Alert>
        )
      ) : (
        <View className="gap-3">
          <Text className="font-sans text-body-lg font-medium text-ink">{t('pos.override.pinFor', { name: manager.name })}</Text>
          {error ? <Alert tone="warning">{error}</Alert> : null}
          <PinDots length={pin.length} />
          <PinPad value={pin} onChange={setPin} disabled={busy} />
          <Button variant="primary" block loading={busy} disabled={pin.length < PIN_MIN} onPress={approve}>
            {t('pos.override.approve')}
          </Button>
          <Button variant="ghost" block onPress={() => setManager(null)}>
            {t('signIn.otherPerson')}
          </Button>
        </View>
      )}
    </Dialog>
  );
}
