import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlatList, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { useLocale } from '../../lib/useLocale';
import { usePosData } from '../../pos/PosProvider';
import { localDate } from '../../sync/prices';
import { dayList, daySummary, STATUS_TONE, weekDays } from '../../salon/day';
import { useSalon } from '../../salon/context';
import { canSalon, SALON } from '../../salon/salon';
import { formatTime, useMoneyText } from '../pos/format';
import { Choice, Row, VanHeader } from '../vansales/parts';
import { WalkInDialog } from './WalkInDialog';

const LOCALE_TAGS = { en: 'en-KE', fr: 'fr-CD' };

/** "Mon 2": a day chip label, read as a calendar date (the schedule's days are the branch's local days). */
function dayLabel(date, locale) {
  try {
    return new Intl.DateTimeFormat(LOCALE_TAGS[locale] ?? 'en-KE', { weekday: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`));
  } catch {
    return date;
  }
}

/** What can be done with an appointment now, from its status and the person's rights (the server checks them again). */
export function actionsFor(view, user, now = Date.now()) {
  const serve = canSalon(user, SALON.serve);
  const cancel = canSalon(user, SALON.cancel);
  const open = ['booked', 'checked_in'].includes(view.status);
  return {
    checkIn: serve && view.status === 'booked',
    start: serve && open,
    finish: serve && ['booked', 'checked_in', 'in_service'].includes(view.status),
    charge: ['checked_in', 'in_service', 'completed'].includes(view.status) || (view.status === 'booked' && Date.parse(view.starts_at) <= now),
    noShow: cancel && view.status === 'booked' && Date.parse(view.starts_at) <= now,
    cancel: cancel && open,
    fee: ['cancelled', 'no_show'].includes(view.status) && view.fee_status === 'due' && BigInt(view.fee_minor ?? 0) > 0n,
  };
}

function AppointmentDialog({ view, onClose, onCharge }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { user, actorProof } = useSession();
  const salon = useSalon();
  const { catalogue } = usePosData();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const can = actionsFor(view, user);
  const currency = view.currency ?? catalogue?.saleCurrency;

  async function step(type, reason) {
    setBusy(true);
    setError(null);
    try {
      await salon.service.step(view.id, type, { user, actorProof, reason });
      onClose();
    } catch (problem) {
      setError(t(`salon.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('salon.errors.failed') }));
    } finally {
      setBusy(false);
    }
  }

  const buttons = [
    can.checkIn && ['primary', t('salon.actions.checkIn'), () => step('check_in')],
    can.start && ['secondary', t('salon.actions.start'), () => step('start')],
    can.finish && ['secondary', t('salon.actions.finish'), () => step('finish')],
    can.charge && ['pay', t('salon.actions.charge'), () => onCharge(view, false)],
    can.fee && ['pay', t('salon.actions.chargeFee', { amount: money(view.fee_minor, currency) }), () => onCharge(view, true)],
    can.noShow && ['danger', t('salon.actions.noShow'), () => step('no_show', 'no_show')],
    can.cancel && ['danger', t('salon.actions.cancel'), () => step('cancel', 'cancelled')],
  ].filter(Boolean);

  return (
    <Dialog open title={view.client_name || t('salon.day.unnamed')} onClose={onClose}>
      <View className="gap-3">
        <StatusBadge tone={STATUS_TONE[view.status]}>{t(`salon.status.${view.status}`)}</StatusBadge>
        {view.lines.map((line) => (
          <View key={line.id} className="flex-row items-center gap-3">
            <Text className="min-w-0 flex-1 font-sans text-body-lg text-ink">{line.name}</Text>
            <Text className="font-sans text-body text-ink-muted">{line.staff_name}</Text>
            <Text className="font-sans text-body tabular-nums text-ink">{money(line.price_minor, currency)}</Text>
          </View>
        ))}
        {BigInt(view.deposit_balance_minor ?? 0) > 0n ? <Text className="font-sans text-body text-ink-muted">{t('salon.day.depositHeld', { amount: money(view.deposit_balance_minor, currency) })}</Text> : null}
        {view.has_alerts ? <Alert tone="warning">{t('salon.day.alerts')}</Alert> : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {buttons.map(([variant, label, onPress]) => (
          <Button key={label} variant={variant} block disabled={busy} onPress={onPress}>
            {label}
          </Button>
        ))}
        {!buttons.length ? <Text className="font-sans text-body text-ink-muted">{t('salon.day.nothingToDo')}</Text> : null}
      </View>
    </Dialog>
  );
}

/**
 * SAL-13, SAL-29: the salon day. The pulled week is read from the device, so the schedule is there with no
 * connection; what the till does since (check-in, start, finish) shows at once, with a "waiting to send" mark until
 * the server has it. Each appointment is a dot and a word for its status, the client, services, staff, times, and
 * small marks for a held deposit, a fee due and notes to read.
 */
export function SalonDayScreen({ onCharge, onQueue, onMenu }) {
  const { t } = useTranslation();
  const locale = useLocale();
  const money = useMoneyText();
  const { user } = useSession();
  const { catalogue } = usePosData();
  const salon = useSalon();
  const timeZone = catalogue?.timeZone ?? salon.settings?.timezone ?? 'UTC';
  const today = localDate(Date.now(), timeZone);
  const days = useMemo(() => weekDays(today, 8), [today]);
  const [date, setDate] = useState(today);
  const [staffId, setStaffId] = useState('all');
  const [open, setOpen] = useState(null);
  const [walkIn, setWalkIn] = useState(false);
  const list = useMemo(() => dayList(salon.appointments, { date, timeZone, staffId: staffId === 'all' ? null : staffId }), [salon.appointments, date, timeZone, staffId]);
  const summary = daySummary(list);
  const selected = open ? salon.appointments.find((view) => view.id === open) : null;

  const header = (
    <View className="gap-3">
      {salon.rejected.length ? (
        <Alert
          tone="warning"
          title={t('salon.day.rejectedTitle', { count: salon.rejected.length })}
          action={
            <Button variant="ghost" onPress={() => salon.rejected.forEach((record) => salon.service.retry(record.id))}>
              {t('salon.day.rejectedRetry')}
            </Button>
          }
        >
          {salon.rejected.map((record) => t(`salon.errors.${record.local.error?.code ?? 'failed'}`, { defaultValue: record.local.error?.code ?? '' })).join(' · ')}
        </Alert>
      ) : null}
      <View className="flex-row flex-wrap gap-3">
        {canSalon(user, SALON.create) ? (
          <Button variant="primary" onPress={() => setWalkIn(true)}>
            {t('salon.day.walkIn')}
          </Button>
        ) : null}
        <Button variant="secondary" onPress={onQueue}>
          {t('salon.day.queue', { count: salon.queue.length })}
        </Button>
      </View>
      <Choice options={days.map((value) => ({ value, label: value === today ? t('salon.day.today') : dayLabel(value, locale) }))} value={date} onChange={setDate} label={t('salon.day.pickDay')} />
      <Choice options={[{ value: 'all', label: t('salon.day.everyone') }, ...salon.staff.map((member) => ({ value: member.id, label: member.name }))]} value={staffId} onChange={setStaffId} label={t('salon.day.pickStaff')} />
      <Text className="font-sans text-body text-ink-muted">{t('salon.day.summary', { total: summary.total, done: summary.paid + summary.completed })}</Text>
    </View>
  );

  return (
    <SafeAreaView className="flex-1 bg-surface-100">
      <VanHeader title={t('salon.day.title')} subtitle={[salon.settings?.timezone, user?.name].filter(Boolean).join(' · ')} onBack={onMenu} backLabel={t('pos.menu.title')} />
      <FlatList
        data={list}
        keyExtractor={(view) => view.id}
        contentContainerClassName="gap-3 p-4 md:px-6"
        ListHeaderComponent={header}
        ListEmptyComponent={<Text className="font-sans text-body text-ink-muted">{t('salon.day.empty')}</Text>}
        renderItem={({ item: view }) => {
          const currency = view.currency ?? catalogue?.saleCurrency;
          const staff = [...new Set(view.lines.map((line) => line.staff_name))].join(', ');
          return (
            <View className="rounded-lg border border-border bg-surface-200">
              <Row label={`${view.client_name}, ${t(`salon.status.${view.status}`)}`} onPress={() => setOpen(view.id)} className="border-b-0">
                <Text className="w-24 font-sans text-body tabular-nums text-ink">
                  {formatTime(view.starts_at, timeZone, locale)}–{formatTime(view.ends_at, timeZone, locale)}
                </Text>
                <View className="min-w-0 flex-1">
                  <Text numberOfLines={1} className="font-sans text-body-lg font-medium text-ink">
                    {view.client_name || t('salon.day.unnamed')}
                  </Text>
                  <Text numberOfLines={1} className="font-sans text-caption text-ink-muted">
                    {[view.lines.map((line) => line.name).join(', '), staff].filter(Boolean).join(' · ')}
                  </Text>
                  <View className="flex-row flex-wrap gap-x-3">
                    {BigInt(view.deposit_balance_minor ?? 0) > 0n ? <Text className="font-sans text-caption text-ink-muted">{t('salon.day.depositHeld', { amount: money(view.deposit_balance_minor, currency) })}</Text> : null}
                    {view.fee_status === 'due' ? <Text className="font-sans text-caption text-warning">{t('salon.day.feeDue', { amount: money(view.fee_minor, currency) })}</Text> : null}
                    {view.has_alerts ? <Text className="font-sans text-caption text-warning">{t('salon.day.alertsShort')}</Text> : null}
                    {view.local?.pending ? <Text className="font-sans text-caption text-ink-muted">{t('salon.day.waiting')}</Text> : null}
                  </View>
                </View>
                <StatusBadge tone={STATUS_TONE[view.status]}>{t(`salon.status.${view.status}`)}</StatusBadge>
              </Row>
            </View>
          );
        }}
      />
      {walkIn ? <WalkInDialog mode="book" onClose={() => setWalkIn(false)} /> : null}
      {selected ? (
        <AppointmentDialog
          view={selected}
          onClose={() => setOpen(null)}
          onCharge={(view, fee) => {
            setOpen(null);
            onCharge(view.id, fee);
          }}
        />
      ) : null}
    </SafeAreaView>
  );
}
