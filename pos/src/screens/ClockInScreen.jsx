import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Pressable, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { StatusBadge } from '../components/ds/StatusBadge';
import { TextField } from '../components/ds/TextField';
import { PinDots, PinPad } from '../components/PinPad';
import { cn } from '../lib/cn';
import { FOCUS_RING } from '../lib/focus';
import { useServices } from '../services/services';
import { useSyncStatus } from '../sync/useSyncStatus';
import { AppHeader } from './AppHeader';

const PIN_MIN = 4;
// How long the result stays before the list comes back.
export const RESULT_MS = 4000;

/** HH:MM in the till's local time (24-hour, as on the shop floor). */
export function formatClock(at) {
  const date = new Date(at);
  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

const fold = (text) =>
  String(text ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();

/** HR-23: whether the server offers clock-in to this till (the HR module is on). */
export function useClockOffered() {
  const { store } = useServices();
  const { lastPulledAt } = useSyncStatus();
  const [offered, setOffered] = useState(false);
  useEffect(() => {
    let active = true;
    store.meta().then((meta) => {
      if (active) setOffered(Boolean(meta.entities?.some((entity) => entity.key === 'hr_clock_staff')));
    });
    return () => {
      active = false;
    };
  }, [store, lastPulledAt]);
  return offered;
}

function useBoard() {
  const { clockGate } = useServices();
  const { lastPulledAt, pending } = useSyncStatus();
  const [board, setBoard] = useState(null);
  const load = useCallback(async () => setBoard(await clockGate.board()), [clockGate]);
  useEffect(() => {
    load();
  }, [load, lastPulledAt, pending]);
  return [board, load];
}

const ClockRow = memo(function ClockRow({ member, onSelect }) {
  const { t } = useTranslation();
  const today = member.today;
  const state = today ? t(today.kind === 'in' ? 'clockIn.inSince' : 'clockIn.outAt', { time: formatClock(today.occurredAt) }) : null;
  const label = [member.name, member.number, state, member.lockedHere ? t('clockIn.locked') : null].filter(Boolean).join(', ');
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      onPress={() => onSelect(member)}
      className={cn('min-h-12 flex-row items-center justify-between gap-3 border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}
    >
      <View className="min-w-0 flex-1 gap-1">
        <Text className="font-sans text-body-lg text-ink">{member.name}</Text>
        <Text className="font-sans text-caption text-ink-muted">{[member.number, state].filter(Boolean).join(' · ')}</Text>
      </View>
      <View className="items-end gap-1">
        {member.lockedHere ? <StatusBadge tone="danger">{t('clockIn.locked')}</StatusBadge> : null}
        {today?.upload === 'rejected' ? <StatusBadge tone="warning">{t('clockIn.notAccepted')}</StatusBadge> : null}
      </View>
    </Pressable>
  );
});

/**
 * HR-22, HR-23, NFR-04: clock in or out on the till. Pick your name, type
 * your clock-in PIN (checked on the till, works offline), and the punch is
 * recorded here and uploads with the next sync.
 */
export function ClockInScreen({ location, onClose }) {
  const { t } = useTranslation();
  const { clockGate, scheduler } = useServices();
  const [board, reload] = useBoard();
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState(null);
  const [kind, setKind] = useState('in');
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [result, setResult] = useState(null);
  const checking = useRef(false);

  const visible = useMemo(() => {
    const words = fold(query).trim();
    if (!words) return board ?? [];
    return (board ?? []).filter((member) => fold(member.name).includes(words) || fold(member.number).includes(words));
  }, [board, query]);

  const backToList = useCallback(() => {
    setSelected(null);
    setResult(null);
    setPin('');
    setError(null);
    setQuery('');
    reload();
  }, [reload]);

  // The result shows for a moment, then the list is ready for the next person.
  useEffect(() => {
    if (!result) return undefined;
    const timer = setTimeout(backToList, RESULT_MS);
    return () => clearTimeout(timer);
  }, [result, backToList]);

  async function choose(member) {
    setSelected(member);
    setPin('');
    setError(member.lockedHere ? { reason: 'locked' } : null);
    setKind(await clockGate.nextKindFor(member.id));
  }

  async function submit() {
    if (pin.length < PIN_MIN || checking.current) return;
    checking.current = true;
    setBusy(true);
    setError(null);
    try {
      const answer = await clockGate.punch({ employeeId: selected.id, input: pin });
      setPin('');
      if (answer.ok) setResult(answer);
      else setError(answer);
    } catch {
      setPin('');
      setError({ reason: 'failed' });
    } finally {
      checking.current = false;
      setBusy(false);
    }
  }

  const message = (problem) =>
    problem.reason === 'incorrect'
      ? t('clockIn.errors.incorrect', { count: problem.attemptsLeft })
      : t(`clockIn.errors.${problem.reason}`, { defaultValue: t('clockIn.errors.failed') });

  let body;
  if (result) {
    body = (
      <View className="gap-4 rounded-lg border border-border bg-surface-200 p-5">
        <Alert tone="success" title={result.employee.name}>
          {t(result.punch.kind === 'in' ? 'clockIn.clockedIn' : 'clockIn.clockedOut', { time: formatClock(result.punch.occurredAt) })}
        </Alert>
        <Button variant="secondary" block onPress={backToList}>
          {t('clockIn.next')}
        </Button>
      </View>
    );
  } else if (selected) {
    const locked = error?.reason === 'locked';
    body = (
      <View className="gap-4 rounded-lg border border-border bg-surface-200 p-5">
        <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
          {t('clockIn.pinFor', { name: selected.name })}
        </Text>
        {error ? <Alert tone={error.reason === 'incorrect' ? 'warning' : 'danger'}>{message(error)}</Alert> : null}
        <PinDots length={pin.length} />
        <PinPad value={pin} onChange={setPin} disabled={busy || locked} />
        <Button variant="primary" block loading={busy} disabled={pin.length < PIN_MIN || locked} onPress={submit}>
          {busy ? t('clockIn.checking') : t(kind === 'out' ? 'clockIn.clockOut' : 'clockIn.clockIn')}
        </Button>
        <Button variant="ghost" block onPress={backToList}>
          {t('clockIn.otherPerson')}
        </Button>
      </View>
    );
  } else {
    body = (
      <>
        <View className="gap-1">
          <Text accessibilityRole="header" className="font-sans text-h2 font-semibold text-ink">
            {t('clockIn.title')}
          </Text>
          <Text className="font-sans text-body-lg text-ink-muted">{t('clockIn.subtitle')}</Text>
        </View>
        {board && board.length === 0 ? (
          <Alert tone="info" action={<Button onPress={() => scheduler.syncNow().then(reload)}>{t('common.syncNow')}</Button>}>
            {t('clockIn.empty')}
          </Alert>
        ) : (
          <>
            <TextField label={t('clockIn.search')} value={query} onChangeText={setQuery} autoCorrect={false} autoCapitalize="none" />
            {board && visible.length === 0 ? <Text className="font-sans text-body text-ink-muted">{t('clockIn.noMatch')}</Text> : null}
            <FlatList
              data={visible}
              keyExtractor={(member) => member.id}
              renderItem={({ item }) => <ClockRow member={item} onSelect={choose} />}
              keyboardShouldPersistTaps="handled"
              className="flex-grow-0 rounded-lg border border-border bg-surface-200"
            />
          </>
        )}
        <Button variant="ghost" block onPress={onClose}>
          {t('clockIn.back')}
        </Button>
      </>
    );
  }

  return (
    <SafeAreaView className="flex-1">
      <AppHeader location={location} brand />
      <View className="flex-1 items-center p-4 md:p-6">
        <View className="w-full flex-1 gap-5 md:w-1/2 xl:w-1/3">{body}</View>
      </View>
    </SafeAreaView>
  );
}
