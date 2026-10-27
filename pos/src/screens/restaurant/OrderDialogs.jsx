import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Pressable, Text, View } from 'react-native';
import { Alert } from '../../components/ds/Alert';
import { Button } from '../../components/ds/Button';
import { Dialog } from '../../components/ds/Dialog';
import { TextField } from '../../components/ds/TextField';
import { cn } from '../../lib/cn';
import { FOCUS_RING } from '../../lib/focus';
import { sentLines } from '../../restaurant/orderModel';
import { Segmented, Stepper } from './parts';

const COURSES = [1, 2, 3, 4, 5, 6, 7, 8, 9];

const Row = ({ onPress, children, label }) => (
  <Pressable accessibilityRole="button" accessibilityLabel={label} onPress={onPress} className={cn('min-h-12 flex-row items-center gap-3 border-b border-border px-4 py-3 hover:bg-surface-300 active:bg-surface-300', FOCUS_RING)}>
    {children}
  </Pressable>
);

/** REST-06: change a line until it is sent: quantity, course, seat, note; or remove it. */
export function LineEditDialog({ line, covers, onSave, onRemove, onOptions, hasOptions, onClose }) {
  const { t } = useTranslation();
  const whole = /^\d+$/.test(line.qty);
  const [qty, setQty] = useState(whole ? Number(line.qty) : 1);
  const [course, setCourse] = useState(line.course);
  const [seat, setSeat] = useState(line.seat ?? 0);
  const [note, setNote] = useState(line.note ?? '');
  const seats = Math.min(12, Math.max(covers, 1));
  return (
    <Dialog
      open
      title={line.name}
      onClose={onClose}
      footer={
        <>
          <Button variant="danger" onPress={onRemove}>
            {t('restaurant.line.remove')}
          </Button>
          <Button variant="primary" onPress={() => onSave({ qty: whole ? String(qty) : line.qty, course, seat: seat || null, note })}>
            {t('restaurant.line.save')}
          </Button>
        </>
      }
    >
      {whole ? (
        <View className="gap-2">
          <Text className="font-sans text-label text-ink">{t('restaurant.line.quantity')}</Text>
          <Stepper value={qty} min={1} max={99} onChange={setQty} lessLabel={t('restaurant.line.less')} moreLabel={t('restaurant.line.more')} label={t('restaurant.line.quantity')} />
        </View>
      ) : null}
      <View className="gap-2">
        <Text className="font-sans text-label text-ink">{t('restaurant.line.course')}</Text>
        <Segmented label={t('restaurant.line.course')} value={course} onChange={setCourse} options={COURSES.map((value) => ({ value, label: String(value) }))} />
      </View>
      <View className="gap-2">
        <Text className="font-sans text-label text-ink">{t('restaurant.line.seat')}</Text>
        <Segmented label={t('restaurant.line.seat')} value={seat} onChange={setSeat} options={[{ value: 0, label: t('restaurant.line.noSeat') }, ...Array.from({ length: seats }, (_, index) => ({ value: index + 1, label: String(index + 1) }))]} />
      </View>
      <TextField label={t('restaurant.line.note')} placeholder={t('restaurant.line.notePlaceholder')} value={note} onChangeText={setNote} maxLength={120} />
      {hasOptions ? (
        <Button variant="secondary" onPress={onOptions}>
          {t('restaurant.line.options')}
        </Button>
      ) : null}
    </Dialog>
  );
}

/** REST-11: a sent line voided: a reason is required; the person's right or a manager decides. */
export function VoidDialog({ line, onVoid, onResend, onClose }) {
  const { t } = useTranslation();
  const [reason, setReason] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  async function confirm() {
    if (!reason.trim()) {
      setError(t('restaurant.void.reasonRequired'));
      return;
    }
    setBusy(true);
    setError(null);
    const outcome = await onVoid(reason.trim());
    setBusy(false);
    if (outcome === 'refused') setError(t('restaurant.void.refused'));
    else if (outcome) setError(t('restaurant.errors.failed'));
  }
  return (
    <Dialog
      open
      title={t('restaurant.void.title', { name: line.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onPress={onResend}>
            {t('restaurant.resend.line')}
          </Button>
          <Button variant="danger" loading={busy} onPress={() => void confirm()}>
            {t('restaurant.void.confirm')}
          </Button>
        </>
      }
    >
      <Text className="font-sans text-body-lg text-ink-muted">{t('restaurant.void.help')}</Text>
      {error ? <Alert tone="warning">{error}</Alert> : null}
      <TextField label={t('restaurant.void.reason')} placeholder={t('restaurant.void.reasonPlaceholder')} value={reason} onChangeText={setReason} maxLength={120} />
    </Dialog>
  );
}

/** REST-10: send the lines of a round to the station again (a lost print). */
export function ResendDialog({ order, rounds, onResend, onClose }) {
  const { t } = useTranslation();
  const sent = sentLines(order);
  return (
    <Dialog open title={t('restaurant.resend.title')} onClose={onClose}>
      <Text className="font-sans text-body-lg text-ink-muted">{t('restaurant.resend.help')}</Text>
      <View className="rounded-md border border-border">
        {rounds.map((round) => {
          const lines = sent.filter((line) => line.round === round);
          return (
            <Row key={round} onPress={() => onResend(lines.map((line) => line.id))} label={t('restaurant.resend.round', { round, count: lines.length })}>
              <Text className="min-w-0 flex-1 font-sans text-body-lg text-ink">{t('restaurant.resend.round', { round, count: lines.length })}</Text>
            </Row>
          );
        })}
      </View>
    </Dialog>
  );
}

/** REST-05: another waiter takes the table or tab. */
export function TransferDialog({ staff, current, onTransfer, onClose }) {
  const { t } = useTranslation();
  const people = staff.filter((member) => !member.locked && member.id !== current);
  return (
    <Dialog open title={t('restaurant.transfer.title')} onClose={onClose}>
      <Text className="font-sans text-body-lg text-ink-muted">{t('restaurant.transfer.help')}</Text>
      {people.length ? (
        <View className="rounded-md border border-border">
          {people.map((member) => (
            <Row key={member.id} onPress={() => onTransfer(member)}>
              <Text className="font-sans text-body-lg text-ink">{member.name}</Text>
            </Row>
          ))}
        </View>
      ) : (
        <Alert tone="info">{t('restaurant.transfer.nobody')}</Alert>
      )}
    </Dialog>
  );
}

/** REST-15: every live line moves to another open order. */
export function MergeDialog({ orders, label, onMerge, onClose }) {
  const { t } = useTranslation();
  return (
    <Dialog open title={t('restaurant.merge.title')} onClose={onClose}>
      <Text className="font-sans text-body-lg text-ink-muted">{t('restaurant.merge.help')}</Text>
      {orders.length ? (
        <View className="rounded-md border border-border">
          {orders.map((order) => (
            <Row key={order.id} onPress={() => onMerge(order)}>
              <Text className="font-sans text-body-lg text-ink">{label(order)}</Text>
            </Row>
          ))}
        </View>
      ) : (
        <Alert tone="info">{t('restaurant.merge.none')}</Alert>
      )}
    </Dialog>
  );
}
