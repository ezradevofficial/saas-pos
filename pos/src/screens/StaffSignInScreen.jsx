import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Pressable, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { attestSignIn, newSignInSession } from '../auth/attestation';
import { useServices } from '../services/services';
import { useSession } from '../auth/session';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { StatusBadge } from '../components/ds/StatusBadge';
import { PinDots, PinPad } from '../components/PinPad';
import { cn } from '../lib/cn';
import { FOCUS_RING } from '../lib/focus';
import { useSyncStatus } from '../sync/useSyncStatus';
import { AppHeader } from './AppHeader';

const PIN_MIN = 4;

/** The staff the till may sign in, with this device's lock state. */
function useStaff() {
  const { store, pinGate } = useServices();
  const { lastPulledAt } = useSyncStatus();
  const [staff, setStaff] = useState(null);

  const load = useCallback(async () => {
    const rows = await store.staff();
    const withLocks = await Promise.all(rows.map(async (member) => ({ ...member, lockedHere: await pinGate.isLocked(member) })));
    setStaff(withLocks);
  }, [store, pinGate]);

  useEffect(() => {
    load();
  }, [load, lastPulledAt]);

  return [staff, load];
}

const StaffRow = ({ member, onSelect, lockedLabel }) => (
  <Pressable
    accessibilityRole="button"
    accessibilityLabel={member.lockedHere ? `${member.name}, ${lockedLabel}` : member.name}
    onPress={() => onSelect(member)}
    className={cn(
      'min-h-12 flex-row items-center justify-between gap-3 border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300',
      FOCUS_RING,
    )}
  >
    <Text className="font-sans text-body-lg text-ink">{member.name}</Text>
    {member.lockedHere ? <StatusBadge tone="danger">{lockedLabel}</StatusBadge> : null}
  </Pressable>
);

/** AUTH-06, AUTH-07: pick your name, enter your PIN (checked offline when possible). */
export function StaffSignInScreen({ location }) {
  const { t } = useTranslation();
  const { pinGate, scheduler, engine, credentials, store } = useServices();
  const { signIn } = useSession();
  const [staff, reload] = useStaff();
  const [selected, setSelected] = useState(null);
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  function choose(member) {
    setSelected(member);
    setPin('');
    setError(null);
  }

  // AUTH-06: one check at a time; a second press before the first resolves does nothing.
  const checking = useRef(false);
  async function submit() {
    if (pin.length < PIN_MIN || checking.current) return;
    checking.current = true;
    setBusy(true);
    setError(null);
    try {
      const session = newSignInSession({ engine });
      const result = await pinGate.signIn({ userId: selected.id, kind: 'pin', input: pin, session });
      if (result.ok) {
        // AUTH-07: every record this person makes carries this attestation.
        const proof = await attestSignIn({ credentials, store, session, userId: result.user.id });
        signIn(result, pin, { ...session, proof });
        return;
      }
      setError(result);
      setPin('');
      if (result.reason === 'locked') reload();
    } catch {
      setError({ reason: 'failed' });
    } finally {
      checking.current = false;
      setBusy(false);
    }
  }

  const message = (problem) =>
    problem.reason === 'incorrect'
      ? t('signIn.errors.incorrect', { count: problem.attemptsLeft })
      : t(`signIn.errors.${problem.reason}`, { defaultValue: t('signIn.errors.failed') });

  return (
    <SafeAreaView className="flex-1">
      <AppHeader location={location} brand />
      <View className="flex-1 items-center p-4 md:p-6">
        <View className="w-full flex-1 gap-5 md:w-1/2 xl:w-1/3">
          {!selected ? (
            <>
              <View className="gap-1">
                <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
                  {t('signIn.title')}
                </Text>
                <Text className="font-sans text-body-lg text-ink-muted">{t('signIn.subtitle')}</Text>
              </View>
              {staff && staff.length === 0 ? (
                <Alert tone="info" action={<Button onPress={() => scheduler.syncNow().then(reload)}>{t('common.syncNow')}</Button>}>
                  {t('signIn.empty')}
                </Alert>
              ) : (
                <FlatList
                  data={staff ?? []}
                  keyExtractor={(member) => member.id}
                  renderItem={({ item }) => <StaffRow member={item} onSelect={choose} lockedLabel={t('signIn.locked')} />}
                  className="flex-grow-0 rounded-lg border border-border bg-surface-200"
                />
              )}
            </>
          ) : (
            <View className="gap-4 rounded-lg border border-border bg-surface-200 p-5">
              <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
                {t('signIn.pinFor', { name: selected.name })}
              </Text>
              {error ? <Alert tone={error.reason === 'incorrect' ? 'warning' : 'danger'}>{message(error)}</Alert> : null}
              <PinDots length={pin.length} />
              <PinPad value={pin} onChange={setPin} disabled={busy} />
              <Button variant="primary" block loading={busy} disabled={pin.length < PIN_MIN} onPress={submit}>
                {busy ? t('signIn.checking') : t('signIn.submit')}
              </Button>
              <Button variant="ghost" block onPress={() => choose(null)}>
                {t('signIn.otherPerson')}
              </Button>
            </View>
          )}
        </View>
      </View>
    </SafeAreaView>
  );
}
