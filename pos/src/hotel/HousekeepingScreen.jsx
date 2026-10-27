import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Text, View } from 'react-native';
import { Alert } from '../components/ds/Alert';
import { Button } from '../components/ds/Button';
import { StatusBadge } from '../components/ds/StatusBadge';
import { useServices } from '../services/services';
import { groupByFloor } from './housekeeping';

// HOT-29: room status dot by status; never guest data.
const ROOM_TONES = { vacant_clean: 'success', vacant_dirty: 'warning', occupied: 'info', out_of_order: 'danger' };
const REFRESH_MS = 30000;

function TaskCard({ task, busy, onStart, onFinish, onRoomStatus }) {
  const { t } = useTranslation();
  const room = task.room ?? {};
  const started = task.status === 'in_progress';
  return (
    <View testID={`task-${task.id}`} className="gap-3 rounded-lg border border-border bg-surface-200 p-4">
      <View className="flex-row flex-wrap items-center justify-between gap-2">
        <Text className="font-sans text-h3 font-medium tabular-nums text-ink">{t('hotel.housekeeping.room', { number: room.number ?? '' })}</Text>
        <StatusBadge tone={ROOM_TONES[room.status] ?? 'neutral'}>{t(`hotel.housekeeping.roomStatus.${room.status}`, { defaultValue: room.status ?? '' })}</StatusBadge>
      </View>
      <View className="flex-row flex-wrap items-center justify-between gap-2">
        <Text className="font-sans text-body text-ink">{t(`hotel.housekeeping.kind.${task.kind}`, { defaultValue: task.kind })}</Text>
        <Text className="font-sans text-caption text-ink-muted">{t(`hotel.housekeeping.taskStatus.${task.status}`, { defaultValue: task.status })}</Text>
      </View>
      {task.notes ? <Text className="font-sans text-body text-ink-muted">{task.notes}</Text> : null}
      <View className="flex-row flex-wrap gap-3">
        {started ? (
          <Button variant="pay" className="min-h-12 flex-1" disabled={busy} onPress={() => onFinish(task)}>
            {t('hotel.housekeeping.finish')}
          </Button>
        ) : (
          <Button variant="primary" className="min-h-12 flex-1" disabled={busy} onPress={() => onStart(task)}>
            {t('hotel.housekeeping.start')}
          </Button>
        )}
        {room.status === 'vacant_dirty' ? (
          <Button variant="secondary" className="min-h-12 flex-1" disabled={busy} onPress={() => onRoomStatus(task, 'vacant_clean')}>
            {t('hotel.housekeeping.markClean')}
          </Button>
        ) : null}
        {room.status === 'vacant_clean' ? (
          <Button variant="secondary" className="min-h-12 flex-1" disabled={busy} onPress={() => onRoomStatus(task, 'vacant_dirty')}>
            {t('hotel.housekeeping.markDirty')}
          </Button>
        ) : null}
      </View>
    </View>
  );
}

/**
 * HOT-29: the housekeeper's list on a phone. Their open tasks and the
 * unassigned ones of their property, grouped by floor, with big buttons to
 * start, finish, or mark a room clean or dirty. Room and status only, never
 * guest data. Online only: offline, the last list stays and the screen says
 * so.
 */
export function HousekeepingScreen({ onSignedOut }) {
  const { t } = useTranslation();
  const { housekeeping, rider } = useServices();
  const [tasks, setTasks] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [name, setName] = useState(null);

  const refresh = useCallback(async () => {
    const result = await housekeeping.refresh();
    if (result.error === 'signed_out') {
      onSignedOut?.();
      return;
    }
    setTasks(result.tasks);
    setError(result.ok ? null : result.error);
  }, [housekeeping, onSignedOut]);

  useEffect(() => {
    let active = true;
    rider.user().then((user) => active && setName(user?.name ?? null));
    refresh().catch(() => active && setError('failed'));
    const timer = setInterval(() => refresh().catch(() => {}), REFRESH_MS);
    return () => {
      active = false;
      clearInterval(timer);
    };
  }, [rider, refresh]);

  async function act(run) {
    setBusy(true);
    try {
      const result = await run();
      if (result.error === 'signed_out') {
        onSignedOut?.();
        return;
      }
      if (!result.ok) {
        setError(result.error);
        return;
      }
      await refresh();
    } catch {
      setError('failed');
    } finally {
      setBusy(false);
    }
  }

  async function signOut() {
    await rider.signOut();
    onSignedOut?.();
  }

  const groups = useMemo(() => groupByFloor(tasks ?? []), [tasks]);
  const data = useMemo(() => groups.flatMap((group) => [{ key: `floor-${group.floor}`, floor: group.floor, header: true }, ...group.tasks.map((task) => ({ key: task.id, task }))]), [groups]);

  return (
    <View className="flex-1">
      <View className="flex-row flex-wrap items-center gap-4 border-b border-border bg-surface-200 px-4 py-3 md:px-6">
        <View className="min-w-0 flex-1">
          <Text accessibilityRole="header" className="font-sans text-h3 text-ink">
            {t('hotel.housekeeping.title')}
          </Text>
          {name ? <Text className="font-sans text-caption text-ink-muted">{name}</Text> : null}
        </View>
      </View>
      <FlatList
        data={data}
        keyExtractor={(entry) => entry.key}
        contentContainerClassName="gap-4 p-4 md:px-6"
        ListHeaderComponent={
          <View className="gap-3">
            {error ? <Alert tone={error === 'offline' ? 'warning' : 'danger'}>{t(`hotel.housekeeping.errors.${error}`, { defaultValue: t('hotel.housekeeping.errors.failed') })}</Alert> : null}
            <View className="flex-row flex-wrap gap-3">
              <Button variant="secondary" onPress={() => refresh().catch(() => {})}>
                {t('hotel.housekeeping.refresh')}
              </Button>
              <Button variant="ghost" onPress={signOut}>
                {t('hotel.housekeeping.signOut')}
              </Button>
            </View>
          </View>
        }
        ListEmptyComponent={tasks ? <Text className="font-sans text-body text-ink-muted">{t('hotel.housekeeping.empty')}</Text> : null}
        renderItem={({ item }) =>
          item.header ? (
            <Text accessibilityRole="header" className="font-sans text-body-lg font-medium text-ink">
              {item.floor === '' ? t('hotel.housekeeping.noFloor') : t('hotel.housekeeping.floor', { floor: item.floor })}
            </Text>
          ) : (
            <TaskCard
              task={item.task}
              busy={busy}
              onStart={(task) => act(() => housekeeping.start(task.id))}
              onFinish={(task) => act(() => housekeeping.finish(task.id))}
              onRoomStatus={(task, status) => act(() => housekeeping.setRoomStatus(task.room.id, status))}
            />
          )
        }
      />
    </View>
  );
}
