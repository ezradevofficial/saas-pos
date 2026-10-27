import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ScrollView, Text, View } from 'react-native';
import { useSession } from '../../auth/session';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { StatusBadge } from '../../components/ds/StatusBadge';
import { TextField } from '../../components/ds/TextField';
import { usePosData } from '../../pos/PosProvider';
import { useSalon } from '../../salon/context';
import { useMoneyText } from '../pos/format';
import { Choice, Row } from '../vansales/parts';

/**
 * SAL-12: a walk-in, either booked now with a free staff member (`mode` book) or put in the queue (`mode` queue).
 * The services come from the menu pulled to the till; the staff member is checked here against the pulled hours and the
 * schedule, and again by the server under its locks (a clash comes back as a refused record, `slot_taken`).
 */
export function WalkInDialog({ mode, onClose }) {
  const { t } = useTranslation();
  const money = useMoneyText();
  const { user, actorProof } = useSession();
  const { catalogue } = usePosData();
  const salon = useSalon();
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [picked, setPicked] = useState([]);
  const [staffId, setStaffId] = useState('any');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const menu = salon.services.filter((service) => service.kind !== 'addon' || picked.length);
  const toggle = (id) => setPicked((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

  async function save() {
    setBusy(true);
    setError(null);
    try {
      const chosen = staffId === 'any' ? null : staffId;
      if (mode === 'book') {
        await salon.service.bookWalkIn({ wants: picked.map((id) => ({ service_id: id, staff_id: chosen })), guestName: name, guestPhone: phone, user, actorProof });
      } else {
        await salon.service.joinQueue({ guestName: name, guestPhone: phone, serviceIds: picked, staffId: chosen, user, actorProof });
      }
      onClose();
    } catch (problem) {
      setError(t(`salon.errors.${problem?.code ?? 'failed'}`, { defaultValue: t('salon.errors.failed') }));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog
      open
      title={mode === 'book' ? t('salon.walkIn.bookTitle') : t('salon.walkIn.queueTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onPress={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={busy} disabled={!picked.length || !name.trim()} onPress={save}>
            {mode === 'book' ? t('salon.walkIn.book') : t('salon.walkIn.join')}
          </Button>
        </>
      }
    >
      <View className="gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <TextField label={t('salon.walkIn.name')} value={name} onChangeText={setName} maxLength={120} autoCorrect={false} />
        <TextField label={t('salon.walkIn.phone')} value={phone} onChangeText={setPhone} keyboardType="phone-pad" maxLength={30} />
        <Text className="font-sans text-body font-medium text-ink">{t('salon.walkIn.services')}</Text>
        <ScrollView className="max-h-64 rounded-md border border-border">
          {menu.map((service) => (
            <Row key={service.id} label={service.name} onPress={() => toggle(service.id)}>
              <Text className="min-w-0 flex-1 font-sans text-body-lg text-ink">{service.name}</Text>
              <Text className="font-sans text-caption text-ink-muted">{t('salon.walkIn.minutes', { count: service.duration_minutes })}</Text>
              {service.price_minor ? <Text className="font-sans text-body tabular-nums text-ink">{money(service.price_minor, catalogue?.saleCurrency)}</Text> : null}
              <StatusBadge tone={picked.includes(service.id) ? 'info' : 'neutral'}>{picked.includes(service.id) ? t('salon.walkIn.chosen') : t('salon.walkIn.choose')}</StatusBadge>
            </Row>
          ))}
        </ScrollView>
        <Text className="font-sans text-body font-medium text-ink">{t('salon.walkIn.staff')}</Text>
        <Choice options={[{ value: 'any', label: t('salon.walkIn.anyone') }, ...salon.staff.map((member) => ({ value: member.id, label: member.name }))]} value={staffId} onChange={setStaffId} label={t('salon.walkIn.staff')} />
      </View>
    </Dialog>
  );
}
