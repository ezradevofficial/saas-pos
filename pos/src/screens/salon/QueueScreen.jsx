import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { useLocale } from '../../lib/useLocale';
import { usePosData } from '../../pos/PosProvider';
import { planWalkIn } from '../../salon/day';
import { useSalon } from '../../salon/context';
import { canSalon, SALON } from '../../salon/salon';
import { formatTime } from '../pos/format';
import { Row, VanHeader } from '../vansales/parts';
import { WalkInDialog } from './WalkInDialog';

/** SAL-12: who among the staff is free now for everything this person wants, as the till can tell from the pulled data. */
function SeatDialog({ entry, onClose }) {
  const { t } = useTranslation();
  const { user, actorProof } = useSession();
  const salon = useSalon();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const options = useMemo(
    () =>
      salon.staff.map((member) => {
        const plan = planWalkIn({
          wants: entry.service_ids.map((id) => ({ service_id: id, staff_id: member.id, id: 'x' })),
          startMs: Date.now(),
          staff: salon.staff,
          services: salon.services,
          views: salon.appointments,
        });
        return { member, problem: plan.error ?? null };
      }),
    [entry, salon.staff, salon.services, salon.appointments],
  );

  async function seat(staffId) {
    setBusy(true);
    setError(null);
    try {
      await salon.service.seatQueue({ entry, staffId, user, actorProof });
      onClose();
    } catch (problem) {
      setError(t(`salon.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('salon.errors.failed') }));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open title={t('salon.queue.seatTitle', { name: entry.guest_name })} onClose={onClose}>
      <View className="gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <View className="rounded-md border border-border">
          {options.map(({ member, problem }) => (
            <Row key={member.id} disabled={busy || Boolean(problem)} onPress={() => seat(member.id)} label={member.name}>
              <Text className="min-w-0 flex-1 font-sans text-body-lg text-ink">{member.name}</Text>
              <Text className="font-sans text-caption text-ink-muted">{problem ? t(`salon.errors.${problem}`, { defaultValue: problem }) : t('salon.queue.free')}</Text>
            </Row>
          ))}
        </View>
      </View>
    </Dialog>
  );
}

/** SAL-12, SAL-29: the walk-in queue in order of arrival with the estimated wait; join, seat and leave work offline. */
export function QueueScreen({ onBack }) {
  const { t } = useTranslation();
  const locale = useLocale();
  const { user, actorProof } = useSession();
  const { catalogue } = usePosData();
  const salon = useSalon();
  const [seating, setSeating] = useState(null);
  const [adding, setAdding] = useState(false);
  const services = new Map(salon.services.map((service) => [service.id, service.name]));
  const timeZone = catalogue?.timeZone ?? 'UTC';
  const manage = canSalon(user, SALON.queue);
  const book = canSalon(user, SALON.create);

  return (
    <SafeAreaView className="flex-1 bg-surface-100">
      <VanHeader title={t('salon.queue.title')} subtitle={t('salon.queue.count', { count: salon.queue.length })} onBack={onBack} backLabel={t('salon.day.title')} />
      <FlatList
        data={salon.queue}
        keyExtractor={(entry) => entry.id}
        contentContainerClassName="gap-3 p-4 md:px-6"
        ListHeaderComponent={
          manage ? (
            <View className="flex-row flex-wrap gap-3">
              <Button variant="primary" onPress={() => setAdding(true)}>
                {t('salon.queue.add')}
              </Button>
            </View>
          ) : null
        }
        ListEmptyComponent={<Text className="font-sans text-body text-ink-muted">{t('salon.queue.empty')}</Text>}
        renderItem={({ item: entry }) => (
          <View className="gap-2 rounded-lg border border-border bg-surface-200 p-4">
            <View className="flex-row items-center gap-3">
              <Text className="w-8 font-sans text-h3 font-semibold tabular-nums text-ink">{entry.position}</Text>
              <View className="min-w-0 flex-1">
                <Text numberOfLines={1} className="font-sans text-body-lg font-medium text-ink">
                  {entry.guest_name}
                </Text>
                <Text numberOfLines={1} className="font-sans text-caption text-ink-muted">
                  {[entry.service_ids.map((id) => services.get(id)).filter(Boolean).join(', '), t('salon.queue.since', { time: formatTime(entry.joined_at, timeZone, locale) })].join(' · ')}
                </Text>
              </View>
              {entry.wait_minutes != null ? <Text className="font-sans text-body tabular-nums text-ink-muted">{t('salon.queue.wait', { count: entry.wait_minutes })}</Text> : null}
            </View>
            {manage ? (
              <View className="flex-row flex-wrap gap-3">
                {book ? (
                  <Button variant="primary" onPress={() => setSeating(entry)}>
                    {t('salon.queue.seat')}
                  </Button>
                ) : null}
                <Button variant="ghost" onPress={() => salon.service.leaveQueue({ entry, user, actorProof })}>
                  {t('salon.queue.leave')}
                </Button>
              </View>
            ) : null}
          </View>
        )}
      />
      {seating ? <SeatDialog entry={seating} onClose={() => setSeating(null)} /> : null}
      {adding ? <WalkInDialog mode="queue" onClose={() => setAdding(false)} /> : null}
    </SafeAreaView>
  );
}
