import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { useSession } from '../auth/session';
import { Button } from '../components/ds/Button';
import { Dialog } from '../components/ds/Dialog';
import { TextField } from '../components/ds/TextField';
import { uuidv7 } from '../lib/random';
import { usePosActions, usePosCart, usePosData } from '../pos/PosProvider';
import { available, byExpiry, lotVerdict, plain, toBase } from './engine';
import { isZero } from '../pos/exact';
import { planSale } from './plan';

const EMPTY = { prescriber_name: '', prescriber_licence: '', facility: '', patient_name: '', patient_phone: '', prescribed_on: '' };

/**
 * PHA-07, PHA-08, PHA-16, PHA-17, PHA-20 on the sale panel: for each batch
 * tracked line the batches it takes (first expiry first out, with a warning
 * near expiry) and a way to choose another; the prescription to take down;
 * and the pharmacist's approval. Shown only while the sale holds an item the
 * Pharmacy module profiles (RBAC-08). The cashier supplies what is missing;
 * the sale cannot be charged until every line is cleared.
 */
export function PharmacyCartPanel() {
  const { t } = useTranslation();
  const { user } = useSession();
  const { catalogue } = usePosData();
  const { cart } = usePosCart();
  const actions = usePosActions();
  const [choosing, setChoosing] = useState(null);
  const [writing, setWriting] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [notice, setNotice] = useState(null);
  const plan = useMemo(() => planSale({ lines: cart.lines, cart, catalogue, at: Date.now(), user }), [cart, catalogue, user]);
  if (!plan) return null;

  const mine = cart.ext?.pharmacy ?? {};
  const patch = (changes) => actions.setExtension('pharmacy', { ...mine, ...changes });
  const problemText = (reason) => t(`pos.unsellable.${reason}`);
  const entries = [...plan.perLine.values()];

  const setPick = (lineId, lotId, qty) => patch({ lots: { ...mine.lots, [lineId]: lotId ? [{ lot_id: lotId, qty }] : [] } });
  const approve = async () => {
    const result = await actions.authorize('dispense', null, cart.id);
    if (!result) return setNotice(t('pos.override.cancelled'));
    setNotice(null);
    return patch({ approval: result.override ?? null });
  };
  const savePrescription = () => {
    const prescription = { id: mine.prescription?.id ?? uuidv7(), ...form, patient_phone: form.patient_phone || undefined };
    patch({ prescription });
    setWriting(false);
  };
  const missing = !form.prescriber_name.trim() || !form.prescriber_licence.trim() || !form.facility.trim() || !form.patient_name.trim() || !/^\d{4}-\d{2}-\d{2}$/.test(form.prescribed_on);
  const field = (key, label, extra = {}) => <TextField label={label} value={form[key]} onChangeText={(text) => setForm((current) => ({ ...current, [key]: text }))} autoCorrect={false} {...extra} />;
  const choice = choosing ? plan.perLine.get(choosing) : null;
  const data = catalogue.ext.pharmacy;

  return (
    <View testID="pharmacy-panel" className="gap-3 border-b border-border px-4 py-3">
      {entries.map((entry) => (
        <View key={entry.line.id} className="gap-1">
          <Text className="font-sans text-caption font-medium text-ink">{entry.line.name}</Text>
          {entry.profile.usage_notes ? <Text className="font-sans text-caption text-ink-muted">{entry.profile.usage_notes}</Text> : null}
          {entry.alloc?.taken.map((row) => (
            <Text key={row.lot_id} className="font-sans text-caption tabular-nums text-ink-muted">
              {t('pos.pharmacy.batchLine', { batch: row.batch_number, date: row.expires_on, qty: row.qty })}
              {row.warn ? ` · ${t('pos.pharmacy.nearExpiry')}` : ''}
            </Text>
          ))}
          {entry.problem ? (
            <Text role="alert" className="font-sans text-caption text-danger">
              {problemText(entry.problem)}
            </Text>
          ) : null}
          {entry.rules.lots ? (
            <View className="flex-row">
              <Button variant="ghost" accessibilityLabel={t('pos.pharmacy.chooseFor', { name: entry.line.name })} onPress={() => setChoosing(entry.line.id)}>
                {t('pos.pharmacy.chooseBatch')}
              </Button>
            </View>
          ) : null}
        </View>
      ))}

      {plan.sale.rx ? (
        <View className="gap-1">
          <Text className="font-sans text-caption text-ink-muted">
            {plan.prescription && !plan.rxProblem ? t('pos.pharmacy.rxSummary', { patient: plan.prescription.patient_name, prescriber: plan.prescription.prescriber_name, date: plan.prescription.prescribed_on }) : t('pos.pharmacy.rxNeeded')}
          </Text>
          <View className="flex-row">
            <Button
              variant="secondary"
              onPress={() => {
                setForm({ ...EMPTY, ...mine.prescription, patient_phone: mine.prescription?.patient_phone ?? '', prescribed_on: mine.prescription?.prescribed_on ?? plan.today });
                setWriting(true);
              }}
            >
              {plan.prescription ? t('pos.pharmacy.rxEdit') : t('pos.pharmacy.rxAdd')}
            </Button>
          </View>
        </View>
      ) : null}

      {plan.sale.pharmacist && !plan.ownApproval ? (
        <View className="gap-1">
          <Text className="font-sans text-caption text-ink-muted">{plan.approval ? t('pos.pharmacy.approved') : t('pos.pharmacy.approvalNeeded')}</Text>
          {plan.approval ? null : (
            <View className="flex-row">
              <Button variant="secondary" onPress={approve}>
                {t('pos.pharmacy.approve')}
              </Button>
            </View>
          )}
        </View>
      ) : null}
      {plan.sale.buyer && !cart.customer ? <Text className="font-sans text-caption text-ink-muted">{t('pos.unsellable.buyer_needed')}</Text> : null}
      {notice ? <Text className="font-sans text-caption text-ink-muted">{notice}</Text> : null}

      <Dialog
        open={Boolean(choice)}
        title={t('pos.pharmacy.chooseTitle', { name: choice?.line.name ?? '' })}
        onClose={() => setChoosing(null)}
        footer={
          <Button
            variant="ghost"
            onPress={() => {
              setPick(choosing, null);
              setChoosing(null);
            }}
          >
            {t('pos.pharmacy.useEarliest')}
          </Button>
        }
      >
        {choice
          ? [...(data.lots.get(choice.line.itemId) ?? [])].sort(byExpiry).map((lot) => {
              const verdict = lotVerdict(lot, plan.settings, plan.today);
              const left = available(lot, data.used);
              const want = plain(toBase(catalogue.itemById.get(choice.line.itemId), choice.line.uomId, choice.line.qty));
              const disabled = Boolean(verdict.refuse) || isZero(left);
              return (
                <View key={lot.lot_id} className="gap-1">
                  <Text className="font-sans text-body text-ink">{t('pos.pharmacy.batchOption', { batch: lot.batch_number, date: lot.expires_on, qty: plain(left) })}</Text>
                  {verdict.refuse ? <Text className="font-sans text-caption text-danger">{problemText(verdict.refuse)}</Text> : null}
                  {verdict.warn ? <Text className="font-sans text-caption text-ink-muted">{t('pos.pharmacy.nearExpiry')}</Text> : null}
                  <View className="flex-row">
                    <Button
                      variant="secondary"
                      disabled={disabled}
                      accessibilityLabel={t('pos.pharmacy.useBatch', { batch: lot.batch_number })}
                      onPress={() => {
                        setPick(choosing, lot.lot_id, want);
                        setChoosing(null);
                      }}
                    >
                      {t('pos.pharmacy.useBatch', { batch: lot.batch_number })}
                    </Button>
                  </View>
                </View>
              );
            })
          : null}
      </Dialog>

      <Dialog
        open={writing}
        title={t('pos.pharmacy.rxTitle')}
        onClose={() => setWriting(false)}
        footer={
          <Button variant="primary" disabled={missing} onPress={savePrescription}>
            {t('pos.pharmacy.rxSave')}
          </Button>
        }
      >
        {field('patient_name', t('pos.pharmacy.patientName'), { required: true })}
        {field('patient_phone', t('pos.pharmacy.patientPhone'), { keyboardType: 'phone-pad' })}
        {field('prescriber_name', t('pos.pharmacy.prescriberName'), { required: true })}
        {field('prescriber_licence', t('pos.pharmacy.prescriberLicence'), { required: true, autoCapitalize: 'characters' })}
        {field('facility', t('pos.pharmacy.facility'), { required: true })}
        {field('prescribed_on', t('pos.pharmacy.prescribedOn'), { required: true, help: t('pos.pharmacy.dateHelp'), keyboardType: 'numbers-and-punctuation' })}
      </Dialog>
    </View>
  );
}
